-- Atomic, owner-scoped idempotency claims for chat turns.
-- A client_turn_id is stable across retries; provider work is claimed once.

create table if not exists private.chat_turn_requests (
  user_id uuid not null references auth.users(id) on delete cascade,
  conversation_id uuid not null,
  client_turn_id uuid not null,
  turn_id uuid not null unique,
  request_hash text not null check (request_hash ~ '^[0-9a-f]{32}$'),
  provider text not null check (provider in ('openai','gemini')),
  status text not null check (status in ('processing','completed','failed','cancelled')),
  assistant_message_id uuid null,
  created_at timestamptz not null default pg_catalog.now(),
  updated_at timestamptz not null default pg_catalog.now(),
  primary key (user_id, conversation_id, client_turn_id),
  foreign key (conversation_id,user_id)
    references private.chat_conversations(id,user_id) on delete cascade
);

alter table private.chat_turn_requests enable row level security;
revoke all on table private.chat_turn_requests from public,anon,authenticated,service_role;

create or replace function public.claim_chat_turn(
  p_user_id uuid,
  p_conversation_id uuid,
  p_client_turn_id uuid,
  p_turn_id uuid,
  p_user_message_id uuid,
  p_content text,
  p_provider text
) returns jsonb
language plpgsql
security definer
set search_path = ''
as $function$
declare
  v_conversation private.chat_conversations;
  v_claim private.chat_turn_requests;
  v_assistant_content text;
begin
  if p_user_id is null or p_conversation_id is null or p_client_turn_id is null
     or p_turn_id is null or p_user_message_id is null
     or p_content is null or pg_catalog.btrim(p_content) = ''
     or pg_catalog.char_length(p_content) > 24000
     or p_provider is null or p_provider not in ('openai','gemini') then
    raise exception 'Invalid chat turn claim parameters';
  end if;

  -- Serializes claims per conversation, so a stable client ID is claimed once.
  select * into v_conversation
  from private.chat_conversations
  where id=p_conversation_id and user_id=p_user_id and deleted_at is null
  for update;

  if not found or v_conversation.provider <> p_provider then
    raise exception 'Chat conversation not found';
  end if;
  if not public.has_chat_provider_consent(p_user_id,p_provider) then
    raise exception 'Active provider consent required';
  end if;

  -- Check an existing client key before Gemini's single-turn restriction so
  -- completed Gemini retries can replay without invoking the provider again.
  select * into v_claim
  from private.chat_turn_requests
  where user_id=p_user_id and conversation_id=p_conversation_id
    and client_turn_id=p_client_turn_id
  for update;

  if found then
    if v_claim.request_hash <> pg_catalog.md5(p_content)
       or v_claim.provider <> p_provider then
      return pg_catalog.jsonb_build_object('outcome','conflict');
    end if;

    if v_claim.status='completed' then
      select m.content into v_assistant_content
      from private.chat_messages m
      where m.id=v_claim.assistant_message_id
        and m.user_id=p_user_id and m.conversation_id=p_conversation_id;
      return pg_catalog.jsonb_build_object(
        'outcome','completed','turn_id',v_claim.turn_id,
        'assistant_message_id',v_claim.assistant_message_id,
        'assistant_content',v_assistant_content
      );
    end if;

    return pg_catalog.jsonb_build_object(
      'outcome',v_claim.status,'turn_id',v_claim.turn_id
    );
  end if;

  -- A conversation may have only one active generation at a time, even when
  -- two different client turn IDs arrive concurrently.
  select * into v_claim
  from private.chat_turn_requests
  where user_id=p_user_id and conversation_id=p_conversation_id
    and status='processing'
  order by created_at desc
  limit 1
  for update;
  if found then
    return pg_catalog.jsonb_build_object(
      'outcome','processing','turn_id',v_claim.turn_id
    );
  end if;

  if p_provider='gemini' and exists (
    select 1
    from private.chat_messages u
    join private.chat_messages a
      on a.conversation_id=u.conversation_id
     and a.user_id=u.user_id
     and a.turn_id=u.turn_id
     and a.role='assistant'
     and a.status='completed'
    where u.conversation_id=p_conversation_id
      and u.user_id=p_user_id
      and u.role='user'
      and u.status='completed'
  ) then
    return pg_catalog.jsonb_build_object('outcome','multi_turn_not_supported');
  end if;

  insert into private.chat_turn_requests(
    user_id,conversation_id,client_turn_id,turn_id,request_hash,provider,status
  ) values (
    p_user_id,p_conversation_id,p_client_turn_id,p_turn_id,
    pg_catalog.md5(p_content),p_provider,'processing'
  ) returning * into v_claim;

  insert into private.chat_messages(
    id,conversation_id,user_id,turn_id,role,content,status,provider,completed_at
  ) values (
    p_user_message_id,p_conversation_id,p_user_id,p_turn_id,
    'user',p_content,'completed',p_provider,pg_catalog.now()
  );

  update private.chat_conversations
  set updated_at=pg_catalog.now()
  where id=p_conversation_id and user_id=p_user_id;

  return pg_catalog.jsonb_build_object(
    'outcome','claimed','turn_id',p_turn_id
  );
end;
$function$;

create or replace function public.complete_chat_turn(
  p_user_id uuid,
  p_conversation_id uuid,
  p_client_turn_id uuid,
  p_assistant_message_id uuid,
  p_content text,
  p_provider text,
  p_model text default null
) returns jsonb
language plpgsql
security definer
set search_path = ''
as $function$
declare
  v_claim private.chat_turn_requests;
  v_message private.chat_messages;
begin
  if p_user_id is null or p_conversation_id is null or p_client_turn_id is null
     or p_assistant_message_id is null or p_content is null
     or pg_catalog.btrim(p_content) = '' or pg_catalog.char_length(p_content)>24000
     or p_provider is null or p_provider not in ('openai','gemini')
     or (p_model is not null and pg_catalog.char_length(p_model)>120) then
    raise exception 'Invalid chat turn completion parameters';
  end if;

  select * into v_claim
  from private.chat_turn_requests
  where user_id=p_user_id and conversation_id=p_conversation_id
    and client_turn_id=p_client_turn_id
  for update;

  if not found or v_claim.provider<>p_provider then
    raise exception 'Chat turn claim not found';
  end if;

  if v_claim.status='completed' then
    select * into v_message from private.chat_messages
    where id=v_claim.assistant_message_id and user_id=p_user_id;
    return pg_catalog.jsonb_build_object(
      'id',v_message.id,'turn_id',v_message.turn_id,
      'content',v_message.content,'status',v_message.status
    );
  end if;

  if v_claim.status<>'processing' then
    raise exception 'Chat turn is not processing';
  end if;

  insert into private.chat_messages(
    id,conversation_id,user_id,turn_id,role,content,status,provider,model,completed_at
  ) values (
    p_assistant_message_id,p_conversation_id,p_user_id,v_claim.turn_id,
    'assistant',p_content,'completed',p_provider,p_model,pg_catalog.now()
  ) returning * into v_message;

  update private.chat_turn_requests
  set status='completed',assistant_message_id=p_assistant_message_id,
      updated_at=pg_catalog.now()
  where user_id=p_user_id and conversation_id=p_conversation_id
    and client_turn_id=p_client_turn_id;

  update private.chat_conversations
  set updated_at=pg_catalog.now()
  where id=p_conversation_id and user_id=p_user_id;

  return pg_catalog.jsonb_build_object(
    'id',v_message.id,'turn_id',v_message.turn_id,
    'content',v_message.content,'status',v_message.status
  );
end;
$function$;

create or replace function public.fail_chat_turn(
  p_user_id uuid,
  p_conversation_id uuid,
  p_client_turn_id uuid,
  p_status text
) returns boolean
language plpgsql
security definer
set search_path = ''
as $function$
declare v_count integer;
begin
  if p_user_id is null or p_conversation_id is null or p_client_turn_id is null
     or p_status is null or p_status not in ('failed','cancelled') then
    raise exception 'Invalid chat terminal status';
  end if;
  update private.chat_turn_requests
  set status=p_status,updated_at=pg_catalog.now()
  where user_id=p_user_id and conversation_id=p_conversation_id
    and client_turn_id=p_client_turn_id and status='processing';
  get diagnostics v_count=row_count;
  return v_count=1;
end;
$function$;

revoke all on function public.claim_chat_turn(uuid,uuid,uuid,uuid,uuid,text,text)
  from public,anon,authenticated;
revoke all on function public.complete_chat_turn(uuid,uuid,uuid,uuid,text,text,text)
  from public,anon,authenticated;
revoke all on function public.fail_chat_turn(uuid,uuid,uuid,text)
  from public,anon,authenticated;
grant execute on function public.claim_chat_turn(uuid,uuid,uuid,uuid,uuid,text,text)
  to service_role;
grant execute on function public.complete_chat_turn(uuid,uuid,uuid,uuid,text,text,text)
  to service_role;
grant execute on function public.fail_chat_turn(uuid,uuid,uuid,text)
  to service_role;
