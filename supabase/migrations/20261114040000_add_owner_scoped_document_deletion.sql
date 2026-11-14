-- Owner-scoped document deletion.
-- The document row remains as a minimal tombstone so retries and upload idempotency
-- cannot resurrect a deleted upload. The private file is removed by the API after
-- this transaction commits; repeat DELETE calls safely retry physical file cleanup.

create or replace function public.delete_document_for_user(
  p_document_id uuid,
  p_user_id uuid
)
returns table (
  stored_key text,
  deletion_status text
)
language plpgsql
security definer
set search_path = ''
as $function$
declare
  v_document public.documents%rowtype;
begin
  if p_document_id is null or p_user_id is null then
    raise exception 'Document deletion identity is required';
  end if;

  select d.*
    into v_document
    from public.documents d
   where d.id = p_document_id
     and d.user_id = p_user_id
   for update;

  -- Do not disclose whether another user's document exists.
  if v_document.id is null then
    return;
  end if;

  if v_document.deleted_at is null then
    update public.documents
       set status = 'deleted',
           deleted_at = pg_catalog.now()
     where id = p_document_id
       and user_id = p_user_id;

    -- Stop queued or leased OCR work. A worker already processing cannot save
    -- a result because the result-storage RPC checks documents.deleted_at.
    update private.ocr_jobs
       set status = 'cancelled',
           locked_until = null,
           worker_id = null,
           updated_at = pg_catalog.now()
     where document_id = p_document_id
       and user_id = p_user_id
       and status in ('queued', 'processing');

    -- Remove encrypted OCR output and provider-consent records tied to this
    -- document. The document row itself is retained as a tombstone only.
    delete from private.ocr_results
     where document_id = p_document_id
       and user_id = p_user_id;

    delete from private.ai_processing_consents
     where document_id = p_document_id
       and user_id = p_user_id;
  end if;

  -- Return the private key even for a previously deleted document so a retry
  -- can finish physical cleanup after a transient filesystem failure.
  return query select v_document.storage_key, 'deleted'::text;
end;
$function$;

revoke all on function public.delete_document_for_user(uuid, uuid)
  from public, anon, authenticated;
grant execute on function public.delete_document_for_user(uuid, uuid)
  to service_role;
