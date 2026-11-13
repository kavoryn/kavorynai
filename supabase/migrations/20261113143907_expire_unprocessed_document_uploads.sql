alter table public.documents
  add column if not exists expires_at timestamptz not null default (pg_catalog.now() + interval '1 hour'),
  add column if not exists deleted_at timestamptz;

create index if not exists documents_expiry_cleanup_idx
  on public.documents (expires_at)
  where status in ('uploaded', 'failed') and deleted_at is null;

create or replace function public.register_uploaded_document(
  p_id uuid,
  p_user_id uuid,
  p_consent_token_hash text,
  p_action text,
  p_payload_sha256 text,
  p_output_language text,
  p_translate boolean,
  p_storage_key text,
  p_mime_type text,
  p_file_extension text,
  p_size_bytes bigint,
  p_idempotency_key text
)
returns table (
  document_id uuid,
  document_status text,
  registered_at timestamptz,
  stored_key text
)
language plpgsql
set search_path = ''
as $function$
declare
  v_existing public.documents%rowtype;
  v_consent_id uuid;
  v_now timestamptz := pg_catalog.now();
  v_inserted public.documents%rowtype;
begin
  if p_id is null or p_user_id is null
     or p_consent_token_hash is null or p_consent_token_hash !~ '^[a-f0-9]{64}$'
     or p_action is distinct from 'document.process'
     or p_payload_sha256 is null or p_payload_sha256 !~ '^[a-f0-9]{64}$'
     or p_output_language is null or p_output_language not in ('en', 'ha', 'ar', 'fr')
     or p_translate is null
     or p_storage_key is null or p_storage_key !~ '^[a-f0-9]{32}\.(pdf|jpg|png|webp)$'
     or p_mime_type is null or p_mime_type not in ('application/pdf', 'image/jpeg', 'image/png', 'image/webp')
     or p_file_extension is null or p_file_extension not in ('pdf', 'jpg', 'png', 'webp')
     or p_size_bytes is null or p_size_bytes < 1 or p_size_bytes > 20971520
     or p_idempotency_key is null or p_idempotency_key !~ '^[A-Za-z0-9_-]{16,128}$' then
    raise exception 'Invalid document registration parameters';
  end if;

  if (p_mime_type = 'application/pdf' and p_file_extension <> 'pdf')
     or (p_mime_type = 'image/jpeg' and p_file_extension <> 'jpg')
     or (p_mime_type = 'image/png' and p_file_extension <> 'png')
     or (p_mime_type = 'image/webp' and p_file_extension <> 'webp') then
    raise exception 'Document MIME and extension mismatch';
  end if;

  perform pg_catalog.pg_advisory_xact_lock(
    pg_catalog.hashtextextended(p_user_id::text || ':' || p_idempotency_key, 0)
  );

  select * into v_existing
    from public.documents d
   where d.user_id = p_user_id and d.idempotency_key = p_idempotency_key;

  if found then
    if v_existing.payload_sha256 <> p_payload_sha256
       or v_existing.output_language <> p_output_language
       or v_existing.translate <> p_translate then
      raise exception 'Idempotency key conflict';
    end if;
    return query select v_existing.id, v_existing.status, v_existing.created_at, v_existing.storage_key;
    return;
  end if;

  update public.processing_consents c
     set consumed_at = v_now
   where c.confirmation_token_hash = p_consent_token_hash
     and c.user_id = p_user_id
     and c.action = p_action
     and c.payload_sha256 = p_payload_sha256
     and c.output_language = p_output_language
     and c.translate = p_translate
     and c.consumed_at is null
     and c.revoked_at is null
     and c.expires_at > v_now
  returning c.id into v_consent_id;

  if v_consent_id is null then
    raise exception 'Consent is invalid, expired, mismatched, or already used';
  end if;

  insert into public.documents (
    id, user_id, consent_id, storage_key, mime_type, file_extension,
    size_bytes, payload_sha256, output_language, translate, idempotency_key,
    status, created_at, expires_at
  )
  values (
    p_id, p_user_id, v_consent_id, p_storage_key, p_mime_type, p_file_extension,
    p_size_bytes, pg_catalog.lower(p_payload_sha256), p_output_language, p_translate,
    p_idempotency_key, 'uploaded', v_now, v_now + interval '1 hour'
  )
  on conflict (user_id, idempotency_key) do nothing
  returning * into v_inserted;

  if v_inserted.id is null then
    select * into v_existing from public.documents d
     where d.user_id = p_user_id and d.idempotency_key = p_idempotency_key;
    if v_existing.id is null
       or v_existing.payload_sha256 <> p_payload_sha256
       or v_existing.output_language <> p_output_language
       or v_existing.translate <> p_translate then
      raise exception 'Idempotency key conflict';
    end if;
    return query select v_existing.id, v_existing.status, v_existing.created_at, v_existing.storage_key;
    return;
  end if;

  return query select v_inserted.id, v_inserted.status, v_inserted.created_at, v_inserted.storage_key;
end;
$function$;

revoke all on function public.register_uploaded_document(uuid, uuid, text, text, text, text, boolean, text, text, text, bigint, text) from public, anon, authenticated;
grant execute on function public.register_uploaded_document(uuid, uuid, text, text, text, text, boolean, text, text, text, bigint, text) to service_role;
