#!/usr/bin/env bash
set -euo pipefail

: "${PGHOST:=127.0.0.1}"
: "${PGPORT:=5432}"
: "${PGUSER:=postgres}"
: "${PGDATABASE:=postgres}"
export PGHOST PGPORT PGUSER PGDATABASE

psql -v ON_ERROR_STOP=1 <<'SQL'
insert into auth.users (id, email, email_confirmed_at)
values (
  '22222222-2222-4222-8222-222222222222',
  'checkout-race-test@example.com',
  pg_catalog.now()
)
on conflict (id) do update
  set email=excluded.email, email_confirmed_at=excluded.email_confirmed_at;

create or replace function private.test_pause_checkout_insert()
returns trigger
language plpgsql
as $$
begin
  if new.user_id = '22222222-2222-4222-8222-222222222222'
     and new.status = 'pending' then
    perform pg_catalog.pg_sleep(3);
  end if;
  return new;
end;
$$;

drop trigger if exists test_pause_checkout_insert on private.user_subscriptions;
create trigger test_pause_checkout_insert
before insert on private.user_subscriptions
for each row execute function private.test_pause_checkout_insert();
SQL

first_log="$(mktemp)"
second_log="$(mktemp)"
cleanup() {
  psql -v ON_ERROR_STOP=1 -c "drop trigger if exists test_pause_checkout_insert on private.user_subscriptions; drop function if exists private.test_pause_checkout_insert();" >/dev/null 2>&1 || true
  rm -f "$first_log" "$second_log"
}
trap cleanup EXIT

PGAPPNAME=kavoryn-checkout-race-first psql -v ON_ERROR_STOP=1 >"$first_log" 2>&1 <<'SQL' &
select public.create_my_billing_checkout_intent(
  '22222222-2222-4222-8222-222222222222',
  'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
  'kavoryn-race-first-0001',
  '21212121-2121-4121-8121-212121212121',
  '23232323-2323-4323-8323-232323232323'
);
SQL
first_pid=$!

# Wait until the first request is inside the function (and sleeping in the
# test trigger while holding its per-user transaction lock).
locked=0
for attempt in $(seq 1 60); do
  active="$(psql -At -c "select count(*) from pg_stat_activity where application_name='kavoryn-checkout-race-first' and state='active' and query like '%create_my_billing_checkout_intent%'")"
  if [[ "$active" -gt 0 ]]; then
    locked=1
    break
  fi
  sleep 0.1
done
if [[ "$locked" -ne 1 ]]; then
  cat "$first_log" >&2
  echo "First checkout did not reach the concurrency test window." >&2
  wait "$first_pid" || true
  exit 1
fi

set +e
PGAPPNAME=kavoryn-checkout-race-second psql -v ON_ERROR_STOP=1 >"$second_log" 2>&1 <<'SQL'
select public.create_my_billing_checkout_intent(
  '22222222-2222-4222-8222-222222222222',
  'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
  'kavoryn-race-second-0002',
  '24242424-2424-4424-8424-242424242424',
  '25252525-2525-4525-8525-252525252525'
);
SQL
second_status=$?
set -e

wait "$first_pid"

if [[ "$second_status" -eq 0 ]]; then
  cat "$second_log" >&2
  echo "Concurrent second checkout unexpectedly succeeded." >&2
  exit 1
fi
if ! grep -Fq "checkout already pending" "$second_log"; then
  cat "$second_log" >&2
  echo "Second checkout failed for an unexpected reason." >&2
  exit 1
fi

pending_count="$(psql -At -c "select count(*) from private.user_subscriptions where user_id='22222222-2222-4222-8222-222222222222' and status='pending'")"
transaction_count="$(psql -At -c "select count(*) from private.billing_transactions where user_id='22222222-2222-4222-8222-222222222222' and reference in ('kavoryn-race-first-0001','kavoryn-race-second-0002')")"
[[ "$pending_count" == "1" ]]
[[ "$transaction_count" == "1" ]]

echo "Concurrent checkout integration test passed: first succeeded; second returned checkout already pending."
