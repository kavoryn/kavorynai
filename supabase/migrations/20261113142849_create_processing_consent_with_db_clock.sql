create or replace function public.create_processing_consent(
  p_id uuid,
  p_user_id uuid,
  p_action text,
  p_payload_sha256 text,
  p_output_language text,
  p_translate boolean,
  p_token_hash text,
  p_ttl_seconds integer
)
returns table (
  consent_id uuid,
  consented_at timestamptz,
  expires_at timestamptz
)
language plpgsql
set search_path = ''
as $function$
declare
  v_now timestamptz := pg_catalog.now();
begin
  if p_ttl_seconds < 60 or p_ttl_seconds > 600
     or p_action <> 'document.process'
     or p_payload_sha256 !~ '^[a-f0-9]{64}$'
     or p_token_hash !~ '^[a-f0-9]{64}$'
     or p_output_language not in ('en', 'ha', 'ar', 'fr') then
    raise exception 'Invalid consent grant parameters';
  end if;

  return query
  insert into public.processing_consents (
    id,
    user_id,
    feature,
    provider_name,
    purpose,
    consented_at,
    confirmation_token_hash,
    expires_at,
    action,
    payload_sha256,
    output_language,
    translate,
    consumed_at
  )
  values (
    p_id,
    p_user_id,
    'document',
    null,
    'User-authorized document OCR and explanation',
    v_now,
    p_token_hash,
    v_now + pg_catalog.make_interval(secs => p_ttl_seconds),
    p_action,
    pg_catalog.lower(p_payload_sha256),
    p_output_language,
    p_translate,
    null
  )
  returning id, processing_consents.consented_at, processing_consents.expires_at;
end;
$function$;

revoke all on function public.create_processing_consent(uuid, uuid, text, text, text, boolean, text, integer) from public, anon, authenticated;
grant execute on function public.create_processing_consent(uuid, uuid, text, text, text, boolean, text, integer) to service_role;
