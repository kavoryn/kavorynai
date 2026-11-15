-- Private persistence foundation for Phase A general-purpose AI chat.
-- This migration only defines schema and server-only RPCs. It does not enable chat
-- routes, deploy the API, or change any feature flags.

create table if not exists private.chat_conversations (
  id uuid primary key,
  user_id uuid not null references auth.users(id) on delete cascade,
  title text null check (title is null or char_length(title) <= 120),
  provider text not null check (provider in ('openai', 'gemini')),
  language text not null check (language in ('en', 'ha', 'ar', 'fr')),
  created_at timestamptz not null default pg_catalog.now(),
  updated_at timestamptz not null default pg_catalog.now(),
  deleted_at timestamptz null,
  unique (id, user_id)
);

create index if not exists chat_conversations_owner_activity_idx
  on private.chat_conversations (user_id, updated_at desc, id desc)
  where deleted_at is null;

create table if not exists private.chat_messages (
  id uuid primary key,
  conversation_id uuid not null,
  user_id uuid not null references auth.users(id) on delete cascade,
  turn_id uuid not null,
  role text not null check (role in ('user', 'assistant')),
  content text not null check (char_length(content) <= 24000),
  status text not null check (status in ('pending', 'streaming', 'completed', 'failed', 'cancelled')),
  provider text null check (provider is null or provider in ('openai', 'gemini')),
  model text null check (model is null or char_length(model) <= 120),
  input_tokens integer null check (input_tokens is null or input_tokens >= 0),
  output_tokens integer null check (output_tokens is null or output_tokens >= 0),
  created_at timestamptz not null default pg_catalog.now(),
  completed_at timestamptz null,
  foreign key (conversation_id, user_id)
    references private.chat_conversations(id, user_id) on delete cascade,
  unique (conversation_id, user_id, turn_id, role)
);

create index if not exists chat_messages_conversation_created_idx
  on private.chat_messages (conversation_id, created_at, id);

create table if not exists private.chat_provider_consents (
  id uuid primary key,
  user_id uuid not null references auth.users(id) on delete cascade,
  provider text not null check (provider in ('openai', 'gemini')),
  purpose text not null check (purpose = 'chat.message'),
  policy_version text not null check (char_length(policy_version) between 1 and 40),
  created_at timestamptz not null default pg_catalog.now(),
  revoked_at timestamptz null
);

create index if not exists chat_provider_consents_lookup_idx
  on private.chat_provider_consents (user_id, provider, purpose, created_at desc)
  where revoked_at is null;

create table if not exists private.chat_rate_limits (
  user_id uuid not null references auth.users(id) on delete cascade,
  window_started_at timestamptz not null,
  request_count integer not null check (request_count >= 0),
  primary key (user_id)
);

alter table private.chat_conversations enable row level security;
alter table private.chat_messages enable row level security;
alter table private.chat_provider_consents enable row level security;
alter table private.chat_rate_limits enable row level security;

revoke all on table private.chat_conversations from public, anon, authenticated, service_role;
revoke all on table private.chat_messages from public, anon, authenticated, service_role;
revoke all on table private.chat_provider_consents from public, anon, authenticated, service_role;
revoke all on table private.chat_rate_limits from public, anon, authenticated, service_role;

create or replace function public.create_chat_conversation(
  p_id uuid,
  p_user_id uuid,
  p_title text,
  p_provider text,
  p_language text
)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $function$
declare
  v_row private.chat_conversations;
begin
  if p_id is null or p_user_id is null
     or p_provider is null or p_provider not in ('openai', 'gemini')
     or p_language is null or p_language not in ('en', 'ha', 'ar', 'fr')
     or (p_title is not null and char_length(p_title) > 120) then
    raise exception 'Invalid chat conversation parameters';
  end if;

  insert into private.chat_conversations (id, user_id, title, provider, language)
  values (p_id, p_user_id, nullif(pg_catalog.btrim(p_title), ''), p_provider, p_language)
  returning * into v_row;

  return pg_catalog.jsonb_build_object(
    'id', v_row.id,
    'title', v_row.title,
    'provider', v_row.provider,
    'language', v_row.language,
    'created_at', v_row.created_at,
    'updated_at', v_row.updated_at
  );
end;
$function$;

create or replace function public.consume_chat_rate_limit(
  p_user_id uuid,
  p_limit integer default 20,
  p_window_seconds integer default 60
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $function$
declare
  v_now timestamptz := pg_catalog.now();
  v_allowed boolean := false;
begin
  if p_user_id is null or p_limit < 1 or p_limit > 100
     or p_window_seconds < 1 or p_window_seconds > 3600 then
    raise exception 'Invalid chat rate limit parameters';
  end if;

  insert into private.chat_rate_limits (user_id, window_started_at, request_count)
  values (p_user_id, v_now, 1)
  on conflict (user_id) do update
    set window_started_at = case
          when private.chat_rate_limits.window_started_at
               <= v_now - pg_catalog.make_interval(secs => p_window_seconds)
          then v_now else private.chat_rate_limits.window_started_at end,
        request_count = case
          when private.chat_rate_limits.window_started_at
               <= v_now - pg_catalog.make_interval(secs => p_window_seconds)
          then 1 else private.chat_rate_limits.request_count + 1 end
  returning request_count <= p_limit into v_allowed;

  return v_allowed;
end;
$function$;

revoke all on function public.create_chat_conversation(uuid, uuid, text, text, text)
  from public, anon, authenticated;
grant execute on function public.create_chat_conversation(uuid, uuid, text, text, text)
  to service_role;

revoke all on function public.consume_chat_rate_limit(uuid, integer, integer)
  from public, anon, authenticated;
grant execute on function public.consume_chat_rate_limit(uuid, integer, integer)
  to service_role;
