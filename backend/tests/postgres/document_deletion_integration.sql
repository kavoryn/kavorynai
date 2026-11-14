\set ON_ERROR_STOP on

-- Disposable PostgreSQL fixture. The test applies the real OCR queue, encrypted
-- result, result-read, and deletion migrations in repository order.
create extension if not exists pgcrypto;

do $$
begin
  if not exists (select 1 from pg_roles where rolname = 'anon') then create role anon nologin; end if;
  if not exists (select 1 from pg_roles where rolname = 'authenticated') then create role authenticated nologin; end if;
  if not exists (select 1 from pg_roles where rolname = 'service_role') then create role service_role nologin bypassrls; end if;
end
$$;

create schema if not exists auth;
create table auth.users (id uuid primary key);
create schema if not exists private;

create table public.documents (
  id uuid primary key,
  user_id uuid not null references auth.users(id),
  storage_key text not null unique,
  mime_type text not null,
  output_language text not null,
  translate boolean not null default false,
  status text not null,
  created_at timestamptz not null default now(),
  deleted_at timestamptz,
  expires_at timestamptz not null
);

create table private.ai_processing_consents (
  id uuid primary key,
  user_id uuid not null references auth.users(id),
  document_id uuid not null references public.documents(id) on delete cascade
);

-- Apply the actual production migrations, including the later reconciliation
-- that adds NOT NULL expires_at to private.ocr_results.
\i supabase/migrations/20261113151205_create_private_ocr_job_queue.sql
\i supabase/migrations/20261113151218_private_document_ocr_job_queue.sql
\i supabase/migrations/20261113151253_reconcile_existing_ocr_queue_and_add_completion.sql
\i supabase/migrations/20261113152043_encrypted_private_ocr_result_storage.sql
\i supabase/migrations/20261113152139_encrypted_ephemeral_document_ocr_results_and_worker_rpc.sql
\i supabase/migrations/20261113152207_reconcile_ocr_result_storage_with_existing_encrypted_table.sql
\i supabase/migrations/20261113152520_include_hmac_in_private_ocr_result_read_rpc.sql
\i supabase/migrations/20261113152751_only_return_completed_ocr_results.sql
\i supabase/migrations/20261113152835_add_ocr_job_lease_renewal_rpc.sql
\i supabase/migrations/20261114040000_add_owner_scoped_document_deletion.sql
\i supabase/migrations/20261114050000_fix_private_ocr_result_expiry_insert.sql

insert into auth.users(id) values
 ('10000000-0000-4000-8000-000000000001'),
 ('10000000-0000-4000-8000-000000000002');

-- All uploads start as uploaded so the real enqueue trigger creates their jobs.
insert into public.documents(id,user_id,storage_key,mime_type,output_language,translate,status,expires_at) values
 ('20000000-0000-4000-8000-000000000001','10000000-0000-4000-8000-000000000001','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf','application/pdf','ha',false,'uploaded',now()+interval '1 hour'),
 ('20000000-0000-4000-8000-000000000002','10000000-0000-4000-8000-000000000002','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.pdf','application/pdf','en',false,'uploaded',now()+interval '1 hour'),
 ('20000000-0000-4000-8000-000000000003','10000000-0000-4000-8000-000000000001','cccccccccccccccccccccccccccccccc.pdf','application/pdf','ha',false,'uploaded',now()+interval '1 hour'),
 ('20000000-0000-4000-8000-000000000004','10000000-0000-4000-8000-000000000001','dddddddddddddddddddddddddddddddd.pdf','application/pdf','ha',false,'uploaded',now()+interval '1 hour'),
 ('20000000-0000-4000-8000-000000000005','10000000-0000-4000-8000-000000000001','eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee.pdf','application/pdf','ha',false,'uploaded',now()+interval '1 hour');

update private.ocr_jobs
   set id = case document_id
       when '20000000-0000-4000-8000-000000000001' then '30000000-0000-4000-8000-000000000001'::uuid
       when '20000000-0000-4000-8000-000000000002' then '30000000-0000-4000-8000-000000000002'::uuid
       when '20000000-0000-4000-8000-000000000003' then '30000000-0000-4000-8000-000000000003'::uuid
       when '20000000-0000-4000-8000-000000000004' then '30000000-0000-4000-8000-000000000004'::uuid
       when '20000000-0000-4000-8000-000000000005' then '30000000-0000-4000-8000-000000000005'::uuid
       end,
       status = case when document_id = '20000000-0000-4000-8000-000000000002' then 'queued' else 'processing' end,
       locked_until = case when document_id = '20000000-0000-4000-8000-000000000002' then null else now()+interval '5 minutes' end,
       worker_id = case when document_id = '20000000-0000-4000-8000-000000000002' then null else
         case document_id
           when '20000000-0000-4000-8000-000000000001' then 'worker-owner-0001'
           when '20000000-0000-4000-8000-000000000003' then 'worker-race-0003'
           when '20000000-0000-4000-8000-000000000004' then 'worker-race-0004'
           when '20000000-0000-4000-8000-000000000005' then 'worker-expiry-0005'
         end
       end
 where document_id in (
   '20000000-0000-4000-8000-000000000001',
   '20000000-0000-4000-8000-000000000002',
   '20000000-0000-4000-8000-000000000003',
   '20000000-0000-4000-8000-000000000004',
   '20000000-0000-4000-8000-000000000005'
 );

insert into private.ai_processing_consents(id,user_id,document_id) values
 ('40000000-0000-4000-8000-000000000001','10000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001');

-- Exercise the production worker-facing RPC against the final NOT NULL schema.
do $$
declare stored boolean;
begin
  stored := public.store_document_ocr_result(
    '30000000-0000-4000-8000-000000000005',
    'worker-expiry-0005',
    'AAAAAAAAAAAAAAAAAAAAAA==',
    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
    '1',
    'ha',
    42,
    1,
    repeat('e',64),
    16
  );
  if not stored then raise exception 'OCR result RPC did not store a valid result'; end if;
  if not exists (
    select 1
      from private.ocr_results r
      join public.documents d on d.id = r.document_id
     where r.document_id = '20000000-0000-4000-8000-000000000005'
       and r.expires_at = d.expires_at
       and r.detected_language = 'ha'
       and r.character_count = 42
       and r.uncertain_span_count = 1
  ) then
    raise exception 'OCR result did not inherit document expiry and metadata';
  end if;
end
$$;

-- A different account must receive the same empty result as a nonexistent document.
do $$
declare affected integer;
begin
  select count(*) into affected
    from public.delete_document_for_user(
      '20000000-0000-4000-8000-000000000001',
      '10000000-0000-4000-8000-000000000002'
    );
  if affected <> 0 then raise exception 'Cross-account delete disclosed or changed a document'; end if;
  if (select deleted_at from public.documents where id='20000000-0000-4000-8000-000000000001') is not null then
    raise exception 'Cross-account delete changed the owner document';
  end if;
end
$$;

-- The owner can delete; active OCR work and sensitive child rows are removed.
do $$
declare affected integer;
begin
  select count(*) into affected
    from public.delete_document_for_user(
      '20000000-0000-4000-8000-000000000001',
      '10000000-0000-4000-8000-000000000001'
    );
  if affected <> 1 then raise exception 'Owner delete did not return the storage key'; end if;
  if not exists (select 1 from public.documents where id='20000000-0000-4000-8000-000000000001' and deleted_at is not null and status='deleted') then
    raise exception 'Document tombstone was not written'; end if;
  if not exists (select 1 from private.ocr_jobs where document_id='20000000-0000-4000-8000-000000000001' and status='cancelled' and worker_id is null and locked_until is null) then
    raise exception 'Active OCR job was not cancelled'; end if;
  if exists (select 1 from private.ai_processing_consents where document_id='20000000-0000-4000-8000-000000000001') then
    raise exception 'Document AI consent was not purged'; end if;
end
$$;

do $$
begin
  if has_function_privilege('anon','public.delete_document_for_user(uuid,uuid)','EXECUTE') then
    raise exception 'anon unexpectedly has execute permission';
  end if;
  if has_function_privilege('authenticated','public.delete_document_for_user(uuid,uuid)','EXECUTE') then
    raise exception 'authenticated unexpectedly has execute permission';
  end if;
  if not has_function_privilege('service_role','public.delete_document_for_user(uuid,uuid)','EXECUTE') then
    raise exception 'service_role is missing execute permission';
  end if;
end
$$;

select 'full OCR/deletion migration integration fixture ready' as result;
