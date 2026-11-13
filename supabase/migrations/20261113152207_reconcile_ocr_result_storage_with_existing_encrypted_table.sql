-- Keep one canonical encrypted result table: private.ocr_results.
-- The worker-facing RPCs are exposed only to service_role; the private tables
-- and queue remain outside the Data API.

alter table private.ocr_results
  add column if not exists detected_language text not null default 'und',
  add column if not exists character_count integer not null default 0,
  add column if not exists uncertain_span_count integer not null default 0,
  add column if not exists expires_at timestamptz;

update private.ocr_results r
   set expires_at = d.expires_at
  from public.documents d
 where d.id = r.document_id
   and r.expires_at is null;

alter table private.ocr_results alter column expires_at set not null;

do $$
begin
  if not exists (select 1 from pg_constraint where conname = 'ocr_results_detected_language_check') then
    alter table private.ocr_results add constraint ocr_results_detected_language_check
      check (detected_language in ('en','ha','ar','fr','und'));
  end if;
  if not exists (select 1 from pg_constraint where conname = 'ocr_results_character_count_check') then
    alter table private.ocr_results add constraint ocr_results_character_count_check
      check (character_count between 0 and 2000000);
  end if;
  if not exists (select 1 from pg_constraint where conname = 'ocr_results_uncertain_span_count_check') then
    alter table private.ocr_results add constraint ocr_results_uncertain_span_count_check
      check (uncertain_span_count between 0 and 200000);
  end if;
end;
$$;

create index if not exists ocr_results_expiry_idx on private.ocr_results(expires_at);

drop table if exists private.document_ocr_results;
drop function if exists public.store_document_ocr_result(uuid,text,text,text,text,text,integer,integer);
drop function if exists public.get_document_ocr_result(uuid,uuid);
drop function if exists public.purge_expired_document_ocr_results();

create or replace function public.store_document_ocr_result(
  p_job_id uuid,
  p_worker_id text,
  p_ciphertext_base64 text,
  p_nonce_base64 text,
  p_encryption_key_version text,
  p_detected_language text,
  p_character_count integer,
  p_uncertain_span_count integer,
  p_result_sha256 text,
  p_plaintext_bytes integer
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_stored boolean;
  v_document_id uuid;
begin
  if p_encryption_key_version is distinct from '1'
     or p_detected_language not in ('en','ha','ar','fr','und')
     or p_character_count is null or p_character_count < 0 or p_character_count > 2000000
     or p_uncertain_span_count is null or p_uncertain_span_count < 0 or p_uncertain_span_count > 200000 then
    raise exception 'Invalid OCR result metadata';
  end if;

  v_stored := private.store_ocr_result(
    p_job_id, p_worker_id, 'google_cloud_vision', p_nonce_base64,
    p_ciphertext_base64, p_result_sha256, p_plaintext_bytes
  );
  if not v_stored then return false; end if;

  select document_id into v_document_id
    from private.ocr_results
   where job_id = p_job_id;

  update private.ocr_results r
     set detected_language = p_detected_language,
         character_count = p_character_count,
         uncertain_span_count = p_uncertain_span_count,
         expires_at = d.expires_at
    from public.documents d
   where r.job_id = p_job_id
     and d.id = r.document_id
     and d.id = v_document_id
     and d.deleted_at is null
     and d.expires_at > pg_catalog.now();

  return found;
end;
$$;

create or replace function public.get_document_ocr_result(p_document_id uuid, p_user_id uuid)
returns table (
  ciphertext_base64 text,
  nonce_base64 text,
  encryption_key_version text,
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
         r.detected_language, r.character_count, r.uncertain_span_count, r.expires_at
    from private.ocr_results r
    join public.documents d on d.id = r.document_id
   where r.document_id = p_document_id
     and r.user_id = p_user_id
     and d.user_id = p_user_id
     and d.deleted_at is null
     and r.expires_at > pg_catalog.now()
     and d.expires_at > pg_catalog.now();
$$;

create or replace function public.purge_expired_document_ocr_results()
returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare v_deleted integer;
begin
  delete from private.ocr_results r
   where r.expires_at <= pg_catalog.now()
      or exists (
        select 1 from public.documents d
         where d.id = r.document_id
           and (d.deleted_at is not null or d.expires_at <= pg_catalog.now())
      );
  get diagnostics v_deleted = row_count;
  return v_deleted;
end;
$$;

create or replace function public.claim_next_ocr_job(p_worker_id text, p_lease_seconds integer default 120)
returns table (
  job_id uuid, document_id uuid, user_id uuid, storage_key text, mime_type text,
  output_language text, translate boolean, attempt_count smallint, lease_expires_at timestamptz
)
language plpgsql
security definer
set search_path = ''
as $$
begin
  return query select * from private.claim_next_ocr_job(p_worker_id, p_lease_seconds);
end;
$$;

create or replace function public.fail_ocr_job(
  p_job_id uuid, p_worker_id text, p_error_code text, p_retry_delay_seconds integer default 30
)
returns boolean
language sql
security definer
set search_path = ''
as $$ select private.fail_ocr_job(p_job_id, p_worker_id, p_error_code, p_retry_delay_seconds); $$;

create or replace function public.complete_ocr_job(p_job_id uuid, p_worker_id text)
returns boolean
language sql
security definer
set search_path = ''
as $$ select private.complete_ocr_job(p_job_id, p_worker_id); $$;

revoke all on function public.claim_next_ocr_job(text,integer) from public, anon, authenticated;
revoke all on function public.fail_ocr_job(uuid,text,text,integer) from public, anon, authenticated;
revoke all on function public.complete_ocr_job(uuid,text) from public, anon, authenticated;
revoke all on function public.store_document_ocr_result(uuid,text,text,text,text,text,integer,integer,text,integer) from public, anon, authenticated;
revoke all on function public.get_document_ocr_result(uuid,uuid) from public, anon, authenticated;
revoke all on function public.purge_expired_document_ocr_results() from public, anon, authenticated;

grant execute on function public.claim_next_ocr_job(text,integer) to service_role;
grant execute on function public.fail_ocr_job(uuid,text,text,integer) to service_role;
grant execute on function public.complete_ocr_job(uuid,text) to service_role;
grant execute on function public.store_document_ocr_result(uuid,text,text,text,text,text,integer,integer,text,integer) to service_role;
grant execute on function public.get_document_ocr_result(uuid,uuid) to service_role;
grant execute on function public.purge_expired_document_ocr_results() to service_role;
