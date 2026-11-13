-- Reconcile the existing private.ocr_jobs queue and add a lease-checked completion RPC.
-- The worker queue itself is created by the earlier create_private_ocr_job_queue migration.

drop trigger if exists documents_enqueue_ocr_job on public.documents;
drop function if exists private.enqueue_document_ocr_job();
drop function if exists private.claim_document_ocr_job(integer);
drop function if exists private.complete_document_ocr_job(uuid, uuid);
drop function if exists private.fail_document_ocr_job(uuid, uuid, text, integer);
drop table if exists private.document_ocr_jobs;

create or replace function private.complete_ocr_job(p_job_id uuid, p_worker_id text)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_document_id uuid;
begin
  if p_job_id is null or p_worker_id is null
     or p_worker_id !~ '^[A-Za-z0-9._:-]{8,128}$' then
    raise exception 'Invalid OCR completion parameters';
  end if;

  update private.ocr_jobs
     set status = 'succeeded',
         locked_until = null,
         worker_id = null,
         last_error_code = null,
         completed_at = pg_catalog.now(),
         updated_at = pg_catalog.now()
   where id = p_job_id
     and status = 'processing'
     and worker_id = p_worker_id
     and locked_until > pg_catalog.now()
  returning document_id into v_document_id;

  if v_document_id is null then
    return false;
  end if;

  update public.documents
     set status = 'succeeded'
   where id = v_document_id and deleted_at is null;

  return true;
end;
$$;

revoke all on function private.complete_ocr_job(uuid, text) from public, anon, authenticated;
grant execute on function private.complete_ocr_job(uuid, text) to service_role;

create trigger documents_enqueue_ocr_job
after insert on public.documents
for each row execute function private.enqueue_ocr_job_for_uploaded_document();
