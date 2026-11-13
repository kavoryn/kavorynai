-- Private OCR queue used by PHP background workers only.
create table if not exists private.ocr_jobs (
  id uuid primary key default gen_random_uuid(),
  document_id uuid not null unique references public.documents(id) on delete cascade,
  user_id uuid not null references auth.users(id) on delete cascade,
  status text not null default 'queued'
    check (status in ('queued','processing','succeeded','failed','cancelled')),
  attempt_count smallint not null default 0 check (attempt_count >= 0 and attempt_count <= 3),
  available_at timestamptz not null default now(),
  locked_until timestamptz,
  worker_id text,
  last_error_code text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  completed_at timestamptz,
  constraint ocr_jobs_check check (
    (status = 'processing' and locked_until is not null and worker_id is not null)
    or (status <> 'processing' and locked_until is null and worker_id is null)
  )
);

create index if not exists ocr_jobs_claim_idx
  on private.ocr_jobs (available_at, created_at)
  where status in ('queued','processing');
create index if not exists ocr_jobs_user_created_idx
  on private.ocr_jobs (user_id, created_at desc);

alter table private.ocr_jobs enable row level security;
revoke all on private.ocr_jobs from public, anon, authenticated;
grant all on private.ocr_jobs to service_role;

create or replace function private.enqueue_ocr_job_for_uploaded_document()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  if new.status = 'uploaded' then
    insert into private.ocr_jobs (document_id, user_id)
    values (new.id, new.user_id)
    on conflict (document_id) do nothing;
  end if;
  return new;
end;
$$;

revoke all on function private.enqueue_ocr_job_for_uploaded_document() from public, anon, authenticated;

drop trigger if exists documents_enqueue_ocr_job on public.documents;
create trigger documents_enqueue_ocr_job
after insert on public.documents
for each row execute function private.enqueue_ocr_job_for_uploaded_document();

create or replace function private.claim_next_ocr_job(p_worker_id text, p_lease_seconds integer default 120)
returns table (
  job_id uuid,
  document_id uuid,
  user_id uuid,
  storage_key text,
  mime_type text,
  output_language text,
  translate boolean,
  attempt_count smallint,
  lease_expires_at timestamptz
)
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_job private.ocr_jobs%rowtype;
begin
  if p_worker_id is null or p_worker_id !~ '^[A-Za-z0-9._:-]{8,128}$'
     or p_lease_seconds is null or p_lease_seconds < 30 or p_lease_seconds > 600 then
    raise exception 'Invalid OCR worker claim parameters';
  end if;

  update private.ocr_jobs
     set status = 'failed',
         locked_until = null,
         worker_id = null,
         last_error_code = 'attempts_exhausted',
         updated_at = pg_catalog.now()
   where status = 'processing'
     and locked_until <= pg_catalog.now()
     and attempt_count >= 3;

  select j.* into v_job
    from private.ocr_jobs j
    join public.documents d on d.id = j.document_id
   where d.status = 'uploaded'
     and d.deleted_at is null
     and d.expires_at > pg_catalog.now()
     and j.attempt_count < 3
     and (
       (j.status = 'queued' and j.available_at <= pg_catalog.now())
       or (j.status = 'processing' and j.locked_until <= pg_catalog.now())
     )
   order by j.available_at, j.created_at
   for update of j skip locked
   limit 1;

  if v_job.id is null then
    return;
  end if;

  update private.ocr_jobs
     set status = 'processing',
         attempt_count = attempt_count + 1,
         worker_id = p_worker_id,
         locked_until = pg_catalog.now() + pg_catalog.make_interval(secs => p_lease_seconds),
         updated_at = pg_catalog.now()
   where id = v_job.id
   returning * into v_job;

  return query
    select v_job.id, d.id, d.user_id, d.storage_key, d.mime_type,
           d.output_language, d.translate, v_job.attempt_count, v_job.locked_until
      from public.documents d
     where d.id = v_job.document_id;
end;
$$;

create or replace function private.fail_ocr_job(
  p_job_id uuid,
  p_worker_id text,
  p_error_code text,
  p_retry_delay_seconds integer default 30
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_status text;
begin
  if p_job_id is null or p_worker_id is null
     or p_worker_id !~ '^[A-Za-z0-9._:-]{8,128}$'
     or p_error_code is null or p_error_code !~ '^[a-z0-9_]{2,64}$'
     or p_retry_delay_seconds is null or p_retry_delay_seconds < 0 or p_retry_delay_seconds > 3600 then
    raise exception 'Invalid OCR job failure parameters';
  end if;

  update private.ocr_jobs
     set status = case when attempt_count >= 3 then 'failed' else 'queued' end,
         available_at = pg_catalog.now() + pg_catalog.make_interval(secs => p_retry_delay_seconds),
         locked_until = null,
         worker_id = null,
         last_error_code = p_error_code,
         updated_at = pg_catalog.now()
   where id = p_job_id and status = 'processing' and worker_id = p_worker_id
     and locked_until > pg_catalog.now()
  returning status into v_status;

  return v_status is not null;
end;
$$;

revoke all on function private.claim_next_ocr_job(text, integer) from public, anon, authenticated;
revoke all on function private.fail_ocr_job(uuid, text, text, integer) from public, anon, authenticated;
grant execute on function private.claim_next_ocr_job(text, integer) to service_role;
grant execute on function private.fail_ocr_job(uuid, text, text, integer) to service_role;
