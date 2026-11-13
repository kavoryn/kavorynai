-- Distinguish explicit consent for document explanation from document Q&A.
alter table private.ai_processing_consents
  drop constraint if exists ai_processing_consents_purpose_check;

alter table private.ai_processing_consents
  add constraint ai_processing_consents_purpose_check
  check (purpose in (
    'Explain OCR text using the selected external AI provider',
    'Answer a question using the selected external AI provider'
  ));

create or replace function public.record_ai_processing_consent(
  p_id uuid,
  p_user_id uuid,
  p_document_id uuid,
  p_provider_name text,
  p_output_language text,
  p_purpose text
)
returns table (
  consent_id uuid,
  document_sha256 text,
  consented_at timestamptz
)
language plpgsql
security definer
set search_path = ''
as $function$
declare
  v_document_sha256 text;
  v_consented_at timestamptz := pg_catalog.now();
begin
  if p_id is null or p_user_id is null or p_document_id is null
     or p_provider_name is null or p_provider_name not in ('openai', 'gemini')
     or p_output_language is null or p_output_language not in ('en', 'ha', 'ar', 'fr')
     or p_purpose is null or p_purpose not in (
       'Explain OCR text using the selected external AI provider',
       'Answer a question using the selected external AI provider'
     ) then
    raise exception 'Invalid AI processing consent parameters';
  end if;

  select d.payload_sha256
    into v_document_sha256
    from public.documents d
   where d.id = p_document_id
     and d.user_id = p_user_id
     and d.status = 'succeeded';

  if v_document_sha256 is null then
    raise exception 'Completed document not found for this user';
  end if;

  return query
  insert into private.ai_processing_consents (
    id, user_id, document_id, document_sha256, provider_name,
    output_language, purpose, consented_at
  )
  values (
    p_id, p_user_id, p_document_id, v_document_sha256, p_provider_name,
    p_output_language, p_purpose, v_consented_at
  )
  returning id, private.ai_processing_consents.document_sha256,
            private.ai_processing_consents.consented_at;
end;
$function$;

revoke all on function public.record_ai_processing_consent(uuid, uuid, uuid, text, text)
  from public, anon, authenticated;
revoke all on function public.record_ai_processing_consent(uuid, uuid, uuid, text, text, text)
  from public, anon, authenticated;
grant execute on function public.record_ai_processing_consent(uuid, uuid, uuid, text, text, text)
  to service_role;
