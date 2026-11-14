\set ON_ERROR_STOP on

-- Isolated PostgreSQL fixture. This is a disposable CI database, never Supabase staging.
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
  status text not null,
  deleted_at timestamptz,
  expires_at timestamptz not null
);

create table private.ocr_jobs (
  id uuid primary key default gen_random_uuid(),
  document_id uuid not null unique references public.documents(id) on delete cascade,
  user_id uuid not null references auth.users(id),
  status text not null,
  locked_until timestamptz,
  worker_id text,
  updated_at timestamptz not null default now()
);

create table private.ai_processing_consents (
  id uuid primary key,
  user_id uuid not null references auth.users(id),
  document_id uuid not null references public.documents(id) on delete cascade
);

\i supabase/migrations/20261113152043_encrypted_private_ocr_result_storage.sql
\i supabase/migrations/20261114040000_add_owner_scoped_document_deletion.sql

insert into auth.users(id) values
 ('10000000-0000-4000-8000-000000000001'),
 ('10000000-0000-4000-8000-000000000002');

insert into public.documents(id,user_id,storage_key,status,expires_at) values
 ('20000000-0000-4000-8000-000000000001','10000000-0000-4000-8000-000000000001','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf','processing',now()+interval '1 hour'),
 ('20000000-0000-4000-8000-000000000002','10000000-0000-4000-8000-000000000002','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.pdf','uploaded',now()+interval '1 hour');

insert into private.ocr_jobs(id,document_id,user_id,status,locked_until,worker_id) values
 ('30000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001','10000000-0000-4000-8000-000000000001','processing',now()+interval '5 minutes','worker-owner-0001'),
 ('30000000-0000-4000-8000-000000000002','20000000-0000-4000-8000-000000000002','10000000-0000-4000-8000-000000000002','queued',null,null);

insert into private.ai_processing_consents(id,user_id,document_id) values
 ('40000000-0000-4000-8000-000000000001','10000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001');

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
    raise exception 'Document tombstone was not written';
  end if;
  if not exists (select 1 from private.ocr_jobs where document_id='20000000-0000-4000-8000-000000000001' and status='cancelled' and worker_id is null and locked_until is null) then
    raise exception 'Active OCR job was not cancelled';
  end if;
  if exists (select 1 from private.ai_processing_consents where document_id='20000000-0000-4000-8000-000000000001') then
    raise exception 'Document AI consent was not purged';
  end if;
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

-- Two fresh documents are used by the shell harness for opposing lock-order races.
insert into public.documents(id,user_id,storage_key,status,expires_at) values
 ('20000000-0000-4000-8000-000000000003','10000000-0000-4000-8000-000000000001','cccccccccccccccccccccccccccccccc.pdf','processing',now()+interval '1 hour'),
 ('20000000-0000-4000-8000-000000000004','10000000-0000-4000-8000-000000000001','dddddddddddddddddddddddddddddddd.pdf','processing',now()+interval '1 hour');

insert into private.ocr_jobs(id,document_id,user_id,status,locked_until,worker_id) values
 ('30000000-0000-4000-8000-000000000003','20000000-0000-4000-8000-000000000003','10000000-0000-4000-8000-000000000001','processing',now()+interval '5 minutes','worker-race-0003'),
 ('30000000-0000-4000-8000-000000000004','20000000-0000-4000-8000-000000000004','10000000-0000-4000-8000-000000000001','processing',now()+interval '5 minutes','worker-race-0004');

select 'postgres deletion integration fixture ready' as result;
