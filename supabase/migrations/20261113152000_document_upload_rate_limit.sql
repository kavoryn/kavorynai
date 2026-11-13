create schema if not exists private;

create table if not exists private.document_upload_rate_limits (
    user_id uuid not null,
    window_seconds integer not null check (window_seconds in (60, 3600)),
    bucket_start timestamptz not null,
    hit_count integer not null check (hit_count >= 0),
    primary key (user_id, window_seconds, bucket_start)
);

alter table private.document_upload_rate_limits enable row level security;
revoke all on schema private from public, anon, authenticated;
revoke all on private.document_upload_rate_limits from public, anon, authenticated, service_role;

create or replace function public.consume_document_upload_rate_limit(p_user_id uuid)
returns table (allowed boolean, retry_after_seconds integer)
language plpgsql
security definer
set search_path = ''
as $$
declare
    v_claims jsonb;
    v_now timestamptz := clock_timestamp();
    v_minute_start timestamptz;
    v_hour_start timestamptz;
    v_minute_count integer := 0;
    v_hour_count integer := 0;
begin
    begin
        v_claims := current_setting('request.jwt.claims', true)::jsonb;
    exception when others then
        raise exception 'service role required' using errcode = '42501';
    end;

    if coalesce(v_claims ->> 'role', '') <> 'service_role' or p_user_id is null then
        raise exception 'service role required' using errcode = '42501';
    end if;

    v_minute_start := date_bin(interval '1 minute', v_now, timestamptz '2000-01-01 00:00:00+00');
    v_hour_start := date_bin(interval '1 hour', v_now, timestamptz '2000-01-01 00:00:00+00');

    perform pg_advisory_xact_lock(hashtextextended(p_user_id::text, 0));

    select hit_count into v_minute_count
    from private.document_upload_rate_limits
    where user_id = p_user_id and window_seconds = 60 and bucket_start = v_minute_start;

    select hit_count into v_hour_count
    from private.document_upload_rate_limits
    where user_id = p_user_id and window_seconds = 3600 and bucket_start = v_hour_start;

    v_minute_count := coalesce(v_minute_count, 0);
    v_hour_count := coalesce(v_hour_count, 0);

    if v_minute_count >= 5 then
        return query select false, greatest(1, ceil(extract(epoch from (v_minute_start + interval '1 minute' - v_now))::numeric)::integer);
        return;
    end if;

    if v_hour_count >= 30 then
        return query select false, greatest(1, ceil(extract(epoch from (v_hour_start + interval '1 hour' - v_now))::numeric)::integer);
        return;
    end if;

    insert into private.document_upload_rate_limits(user_id, window_seconds, bucket_start, hit_count)
    values (p_user_id, 60, v_minute_start, 1)
    on conflict (user_id, window_seconds, bucket_start)
    do update set hit_count = private.document_upload_rate_limits.hit_count + 1;

    insert into private.document_upload_rate_limits(user_id, window_seconds, bucket_start, hit_count)
    values (p_user_id, 3600, v_hour_start, 1)
    on conflict (user_id, window_seconds, bucket_start)
    do update set hit_count = private.document_upload_rate_limits.hit_count + 1;

    return query select true, 0;
end;
$$;

revoke all on function public.consume_document_upload_rate_limit(uuid) from public, anon, authenticated;
grant execute on function public.consume_document_upload_rate_limit(uuid) to service_role;
