create or replace function public.cleanup_document_upload_rate_limits()
returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
    v_claims jsonb;
    v_deleted integer;
begin
    begin
        v_claims := current_setting('request.jwt.claims', true)::jsonb;
    exception when others then
        raise exception 'service role required' using errcode = '42501';
    end;

    if coalesce(v_claims ->> 'role', '') <> 'service_role' then
        raise exception 'service role required' using errcode = '42501';
    end if;

    delete from private.document_upload_rate_limits
    where bucket_start < clock_timestamp() - interval '2 days';

    get diagnostics v_deleted = row_count;
    return v_deleted;
end;
$$;

revoke all on function public.cleanup_document_upload_rate_limits() from public, anon, authenticated;
grant execute on function public.cleanup_document_upload_rate_limits() to service_role;
