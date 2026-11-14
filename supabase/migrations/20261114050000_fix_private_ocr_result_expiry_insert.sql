-- Ensure encrypted OCR results always inherit the owning document's expiry.
-- The later reconciliation migration made expires_at NOT NULL on private.ocr_results,
-- so the worker insert must populate it from the locked document row.

create or replace function private.store_ocr_result(
  p_job_id uuid,
  p_worker_id text,
  p_provider text,
  p_nonce_base64 text,
  p_ciphertext_base64 text,
  p_result_sha256 text,
  p_plaintext_bytes integer
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_document_id uuid;
  v_user_id uuid;
  v_expires_at timestamptz;
begin
  if p_job_id is null
     or p_worker_id is null
     or p_worker_id !~ '^[A-Za-z0-9._:-]{8,128}$'
     or p_provider is distinct from 'google_cloud_vision'
     or p_nonce_base64 is null
     or p_ciphertext_base64 is null
     or p_result_sha256 is null
     or p_result_sha256 !~ '^[a-f0-9]{64}$'
     or p_plaintext_bytes is null
     or p_plaintext_bytes < 2
     or p_plaintext_bytes > 5242880 then
    raise exception 'Invalid OCR result parameters';
  end if;

  if length(decode(p_nonce_base64, 'base64')) <> 24
     or length(decode(p_ciphertext_base64, 'base64')) < 16 then
    raise exception 'Invalid encrypted OCR result payload';
  end if;

  select d.id, d.user_id, d.expires_at
    into v_document_id, v_user_id, v_expires_at
    from private.ocr_jobs j
    join public.documents d on d.id = j.document_id
   where j.id = p_job_id
     and j.status = 'processing'
     and j.worker_id = p_worker_id
     and j.locked_until > pg_catalog.now()
     and d.deleted_at is null
     and d.expires_at > pg_catalog.now()
   for update of j;

  if v_document_id is null or v_expires_at is null then
    return false;
  end if;

  insert into private.ocr_results (
    job_id, document_id, user_id, provider, encryption_version,
    nonce_base64, ciphertext_base64, result_sha256, plaintext_bytes,
    expires_at
  ) values (
    p_job_id, v_document_id, v_user_id, p_provider, 1,
    p_nonce_base64, p_ciphertext_base64, p_result_sha256, p_plaintext_bytes,
    v_expires_at
  )
  on conflict (job_id) do update
    set nonce_base64 = excluded.nonce_base64,
        ciphertext_base64 = excluded.ciphertext_base64,
        result_sha256 = excluded.result_sha256,
        plaintext_bytes = excluded.plaintext_bytes,
        provider = excluded.provider,
        encryption_version = excluded.encryption_version,
        expires_at = excluded.expires_at,
        updated_at = pg_catalog.now()
    where private.ocr_results.document_id = excluded.document_id
      and private.ocr_results.user_id = excluded.user_id;

  return found;
end;
$$;

revoke all on function private.store_ocr_result(uuid,text,text,text,text,text,integer)
  from public, anon, authenticated;
grant execute on function private.store_ocr_result(uuid,text,text,text,text,text,integer)
  to service_role;
