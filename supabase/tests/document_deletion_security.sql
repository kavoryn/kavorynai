-- Owner-scope, cleanup, idempotency and EXECUTE privilege checks.
\set ON_ERROR_STOP on

insert into public.documents (id, user_id, storage_key, status)
values
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
   '0123456789abcdef0123456789abcdef.pdf', 'uploaded'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a21', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22',
   '1123456789abcdef0123456789abcdef.png', 'uploaded');

insert into private.ocr_jobs (id, document_id, user_id, status, locked_until, worker_id)
values
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
   'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12', 'processing', now() + interval '60 seconds', 'worker-test-01'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a21', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a21',
   'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'queued', null, null);

insert into private.ocr_results (id, document_id, user_id)
values
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
   'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12'),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a21', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a21',
   'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22');

insert into private.ai_processing_consents (id, document_id, user_id)
values
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
   'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a21', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a21',
   'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22');

do $test$
begin
  if has_function_privilege('anon', 'public.delete_document_for_user(uuid,uuid)', 'EXECUTE') then
    raise exception 'anon must not execute the owner-scoped deletion function';
  end if;
  if has_function_privilege('authenticated', 'public.delete_document_for_user(uuid,uuid)', 'EXECUTE') then
    raise exception 'authenticated must not execute the service-only deletion function';
  end if;
  if not has_function_privilege('service_role', 'public.delete_document_for_user(uuid,uuid)', 'EXECUTE') then
    raise exception 'service_role must execute the deletion function';
  end if;
end;
$test$;

set role service_role;
do $test$
declare
  v_key text;
  v_status text;
  v_rows integer;
begin
  select stored_key, deletion_status
    into v_key, v_status
    from public.delete_document_for_user(
      'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
      'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12'
    );
  if v_key <> '0123456789abcdef0123456789abcdef.pdf' or v_status <> 'deleted' then
    raise exception 'Owned document deletion must return its storage key and deleted status';
  end if;

  select count(*) into v_rows
    from public.delete_document_for_user(
      'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a21',
      'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12'
    );
  if v_rows <> 0 then
    raise exception 'A user must not delete another account document';
  end if;

  select stored_key, deletion_status
    into v_key, v_status
    from public.delete_document_for_user(
      'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
      'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12'
    );
  if v_key <> '0123456789abcdef0123456789abcdef.pdf' or v_status <> 'deleted' then
    raise exception 'A repeat deletion must return the original key for storage cleanup retry';
  end if;
end;
$test$;
reset role;

do $test$
declare
  v_status text;
  v_deleted_at timestamptz;
  v_locked_until timestamptz;
  v_worker_id text;
  v_count integer;
begin
  select status, deleted_at into v_status, v_deleted_at
    from public.documents
   where id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
  if v_status <> 'deleted' or v_deleted_at is null then
    raise exception 'The owned document must become an inaccessible tombstone';
  end if;

  select status, locked_until, worker_id into v_status, v_locked_until, v_worker_id
    from private.ocr_jobs
   where document_id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
  if v_status <> 'cancelled' or v_locked_until is not null or v_worker_id is not null then
    raise exception 'Queued or leased OCR work must be cancelled and its lease cleared';
  end if;

  select count(*) into v_count from private.ocr_results
   where document_id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
  if v_count <> 0 then raise exception 'Encrypted OCR result must be purged'; end if;

  select count(*) into v_count from private.ai_processing_consents
   where document_id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
  if v_count <> 0 then raise exception 'Document-linked AI consent must be purged'; end if;

  select status into v_status from public.documents
   where id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a21';
  if v_status <> 'uploaded' then raise exception 'Another account document must remain unchanged'; end if;

  select count(*) into v_count from private.ocr_results
   where document_id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a21';
  if v_count <> 1 then raise exception 'Another account OCR result must remain unchanged'; end if;

  select count(*) into v_count from private.ai_processing_consents
   where document_id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a21';
  if v_count <> 1 then raise exception 'Another account AI consent must remain unchanged'; end if;
end;
$test$;

select 'owner-scoped document deletion SQL tests passed' as result;
