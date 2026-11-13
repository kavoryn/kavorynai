-- Encrypted OCR output, private to the backend worker.
create table if not exists private.ocr_results (
  job_id uuid primary key references private.ocr_jobs(id) on delete cascade,
  document_id uuid not null unique references public.documents(id) on delete cascade,
  user_id uuid not null references auth.users(id) on delete cascade,
  provider text not null check (provider = 'google_cloud_vision'),
  encryption_version smallint not null check (encryption_version = 1),
  nonce_base64 text not null check (length(decode(nonce_base64, 'base64')) = 24),
  ciphertext_base64 text not null check (length(decode(ciphertext_base64, 'base64')) >= 16),
  result_sha256 text not null check (result_sha256 ~ '^[a-f0-9]{64}$'),
  plaintext_bytes integer not null check (plaintext_bytes between 2 and 5242880),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table private.ocr_results enable row level security;
revoke all on private.ocr_results from public, anon, authenticated;
grant all on private.ocr_results to service_role;

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

  select d.id, d.user_id
    into v_document_id, v_user_id
    from private.ocr_jobs j
    join public.documents d on d.id = j.document_id
   where j.id = p_job_id
     and j.status = 'processing'
     and j.worker_id = p_worker_id
     and j.locked_until > pg_catalog.now()
     and d.deleted_at is null
     and d.expires_at > pg_catalog.now()
   for update of j;

  if v_document_id is null then
    return false;
  end if;

  insert into private.ocr_results (
    job_id, document_id, user_id, provider, encryption_version,
    nonce_base64, ciphertext_base64, result_sha256, plaintext_bytes
  ) values (
    p_job_id, v_document_id, v_user_id, p_provider, 1,
    p_nonce_base64, p_ciphertext_base64, p_result_sha256, p_plaintext_bytes
  )
  on conflict (job_id) do update
    set nonce_base64 = excluded.nonce_base64,
        ciphertext_base64 = excluded.ciphertext_base64,
        result_sha256 = excluded.result_sha256,
        plaintext_bytes = excluded.plaintext_bytes,
        provider = excluded.provider,
        encryption_version = excluded.encryption_version,
        updated_at = pg_catalog.now()
    where private.ocr_results.document_id = excluded.document_id
      and private.ocr_results.user_id = excluded.user_id;

  return found;
end;
$$;

revoke all on function private.store_ocr_result(uuid,text,text,text,text,text,integer) from public, anon, authenticated;
grant execute on function private.store_ocr_result(uuid,text,text,text,text,text,integer) to service_role;
