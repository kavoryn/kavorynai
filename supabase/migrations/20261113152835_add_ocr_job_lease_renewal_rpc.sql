create or replace function private.renew_ocr_job_lease(
  p_job_id uuid,
  p_worker_id text,
  p_lease_seconds integer default 600
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
begin
  if p_job_id is null
     or p_worker_id is null
     or p_worker_id !~ '^[A-Za-z0-9._:-]{8,128}$'
     or p_lease_seconds is null
     or p_lease_seconds < 30
     or p_lease_seconds > 600 then
    raise exception 'Invalid OCR lease renewal parameters';
  end if;

  update private.ocr_jobs
     set locked_until = pg_catalog.now() + pg_catalog.make_interval(secs => p_lease_seconds),
         updated_at = pg_catalog.now()
   where id = p_job_id
     and status = 'processing'
     and worker_id = p_worker_id
     and locked_until > pg_catalog.now();

  return found;
end;
$$;

create or replace function public.renew_ocr_job_lease(
  p_job_id uuid,
  p_worker_id text,
  p_lease_seconds integer default 600
)
returns boolean
language sql
security definer
set search_path = ''
as $$
  select private.renew_ocr_job_lease(p_job_id, p_worker_id, p_lease_seconds);
$$;

revoke all on function private.renew_ocr_job_lease(uuid,text,integer) from public, anon, authenticated;
grant execute on function private.renew_ocr_job_lease(uuid,text,integer) to service_role;
revoke all on function public.renew_ocr_job_lease(uuid,text,integer) from public, anon, authenticated;
grant execute on function public.renew_ocr_job_lease(uuid,text,integer) to service_role;
