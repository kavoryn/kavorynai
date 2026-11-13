-- Atomic per-account document Q&A rate limit (10 questions per rolling minute window).
create table if not exists private.ai_question_rate_limits (
  user_id uuid primary key references auth.users(id) on delete cascade,
  window_started_at timestamptz not null,
  request_count integer not null check (request_count >= 0)
);

alter table private.ai_question_rate_limits enable row level security;
revoke all on table private.ai_question_rate_limits from public, anon, authenticated, service_role;

create or replace function public.consume_ai_question_rate_limit(
  p_user_id uuid,
  p_limit integer default 10,
  p_window_seconds integer default 60
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $function$
declare
  v_now timestamptz := pg_catalog.now();
  v_allowed boolean := false;
begin
  if p_user_id is null or p_limit < 1 or p_limit > 100
     or p_window_seconds < 1 or p_window_seconds > 3600 then
    raise exception 'Invalid rate limit parameters';
  end if;

  insert into private.ai_question_rate_limits(user_id, window_started_at, request_count)
  values (p_user_id, v_now, 1)
  on conflict (user_id) do update
    set window_started_at = case
          when private.ai_question_rate_limits.window_started_at
               <= v_now - pg_catalog.make_interval(secs => p_window_seconds)
          then v_now else private.ai_question_rate_limits.window_started_at end,
        request_count = case
          when private.ai_question_rate_limits.window_started_at
               <= v_now - pg_catalog.make_interval(secs => p_window_seconds)
          then 1 else private.ai_question_rate_limits.request_count + 1 end
  returning request_count <= p_limit into v_allowed;

  return v_allowed;
end;
$function$;

revoke all on function public.consume_ai_question_rate_limit(uuid, integer, integer)
  from public, anon, authenticated;
grant execute on function public.consume_ai_question_rate_limit(uuid, integer, integer)
  to service_role;
