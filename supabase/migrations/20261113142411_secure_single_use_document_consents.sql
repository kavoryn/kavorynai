create extension if not exists pgcrypto;

alter table public.processing_consents
  add column if not exists action text not null default 'document.process',
  add column if not exists payload_sha256 text,
  add column if not exists output_language text,
  add column if not exists translate boolean not null default false,
  add column if not exists consumed_at timestamptz;

alter table public.processing_consents
  alter column payload_sha256 set not null,
  alter column output_language set not null;

alter table public.processing_consents
  add constraint processing_consents_action_check
    check (action = 'document.process'),
  add constraint processing_consents_payload_sha256_check
    check (payload_sha256 ~ '^[a-f0-9]{64}$'),
  add constraint processing_consents_output_language_check
    check (output_language in ('en', 'ha', 'ar', 'fr')),
  add constraint processing_consents_token_hash_check
    check (confirmation_token_hash ~ '^[a-f0-9]{64}$'),
  add constraint processing_consents_expiry_check
    check (expires_at > consented_at);

create unique index if not exists processing_consents_token_hash_uidx
  on public.processing_consents (confirmation_token_hash);

drop policy if exists consent_select_own on public.processing_consents;
alter table public.processing_consents enable row level security;

revoke all on table public.processing_consents from public, anon, authenticated;
grant all on table public.processing_consents to service_role;

create or replace function public.consume_processing_consent(
  p_token_hash text,
  p_user_id uuid,
  p_action text,
  p_payload_sha256 text,
  p_output_language text,
  p_translate boolean
)
returns boolean
language sql
set search_path = ''
as $function$
  with consumed as (
    update public.processing_consents
       set consumed_at = pg_catalog.now()
     where confirmation_token_hash = p_token_hash
       and user_id = p_user_id
       and action = p_action
       and payload_sha256 = p_payload_sha256
       and output_language = p_output_language
       and translate = p_translate
       and consumed_at is null
       and revoked_at is null
       and expires_at > pg_catalog.now()
     returning id
  )
  select exists (select 1 from consumed);
$function$;

revoke all on function public.consume_processing_consent(text, uuid, text, text, text, boolean) from public, anon, authenticated;
grant execute on function public.consume_processing_consent(text, uuid, text, text, text, boolean) to service_role;
