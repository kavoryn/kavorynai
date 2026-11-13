create or replace function public.get_document_ocr_result(p_document_id uuid, p_user_id uuid)
returns table (
  ciphertext_base64 text,
  nonce_base64 text,
  encryption_key_version text,
  result_sha256 text,
  detected_language text,
  character_count integer,
  uncertain_span_count integer,
  expires_at timestamptz
)
language sql
security definer
set search_path = ''
as $$
  select r.ciphertext_base64, r.nonce_base64, r.encryption_version::text,
         r.result_sha256, r.detected_language, r.character_count,
         r.uncertain_span_count, r.expires_at
    from private.ocr_results r
    join public.documents d on d.id = r.document_id
   where r.document_id = p_document_id
     and r.user_id = p_user_id
     and d.user_id = p_user_id
     and d.status = 'succeeded'
     and d.deleted_at is null
     and r.expires_at > pg_catalog.now()
     and d.expires_at > pg_catalog.now();
$$;

revoke all on function public.get_document_ocr_result(uuid,uuid) from public, anon, authenticated;
grant execute on function public.get_document_ocr_result(uuid,uuid) to service_role;
