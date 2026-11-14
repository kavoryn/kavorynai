#!/usr/bin/env bash
set -euo pipefail

: "\${PGHOST:=127.0.0.1}"
: "\${PGPORT:=5432}"
: "\${PGUSER:=postgres}"
: "\${PGDATABASE:=kavoryn_test}"
: "\${PGPASSWORD:=postgres}"
export PGHOST PGPORT PGUSER PGDATABASE PGPASSWORD

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

psql -X -v ON_ERROR_STOP=1 -f backend/tests/postgres/document_deletion_integration.sql

wait_for_sleep() {
  local app="$1"
  local attempt found
  for attempt in $(seq 1 200); do
    found="$(psql -X -At -v app="$app" -c "select exists (select 1 from pg_stat_activity where application_name = :'app' and state = 'active' and query ilike '%pg_sleep%');")"
    if [ "$found" = "t" ]; then return 0; fi
    sleep 0.05
  done
  echo "Timed out waiting for PostgreSQL session '$app' to reach its synchronization point." >&2
  return 1
}

wait_for_lock() {
  local app="$1"
  local attempt found
  for attempt in $(seq 1 200); do
    found="$(psql -X -At -v app="$app" -c "select exists (select 1 from pg_stat_activity where application_name = :'app' and wait_event_type = 'Lock');")"
    if [ "$found" = "t" ]; then return 0; fi
    sleep 0.05
  done
  echo "Timed out waiting for PostgreSQL session '$app' to block on a row lock." >&2
  return 1
}

# Race A: worker locks the OCR job first. The harness observes the worker at
# its in-transaction barrier, starts DELETE, and confirms DELETE is blocked
# before allowing the worker transaction to commit.
PGAPPNAME=race_worker_first psql -X -v ON_ERROR_STOP=1 <<'SQL' &
begin;
select id from private.ocr_jobs
 where id='30000000-0000-4000-8000-000000000003'
 for update;
select pg_sleep(5);
select public.store_document_ocr_result(
 '30000000-0000-4000-8000-000000000003',
 'worker-race-0003',
 'AAAAAAAAAAAAAAAAAAAAAA==',
 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
 '1',
 'ha',
 40,
 0,
 repeat('a',64),
 16
);
commit;
SQL
worker_pid=$!
wait_for_sleep race_worker_first

PGAPPNAME=race_delete_waiting psql -X -v ON_ERROR_STOP=1 \
  -c "select * from public.delete_document_for_user('20000000-0000-4000-8000-000000000003','10000000-0000-4000-8000-000000000001');" \
  >"$tmp_dir/race-a-delete.log" &
delete_pid=$!
wait_for_lock race_delete_waiting
wait "$worker_pid"
wait "$delete_pid"
psql -X -v ON_ERROR_STOP=1 -c "do \$\$ begin if exists(select 1 from private.ocr_results where document_id='20000000-0000-4000-8000-000000000003') then raise exception 'Worker-first race left OCR result after delete'; end if; end \$\$;"

# Race B: DELETE locks/tombstones first. The harness waits until a worker RPC
# is observably blocked on that row before allowing the delete transaction to
# commit; the worker must then fail closed.
PGAPPNAME=race_delete_first psql -X -v ON_ERROR_STOP=1 <<'SQL' &
begin;
select * from public.delete_document_for_user(
 '20000000-0000-4000-8000-000000000004',
 '10000000-0000-4000-8000-000000000001'
);
select pg_sleep(5);
commit;
SQL
delete_pid=$!
wait_for_sleep race_delete_first

PGAPPNAME=race_worker_waiting psql -X -At -v ON_ERROR_STOP=1 \
  -c "select public.store_document_ocr_result('30000000-0000-4000-8000-000000000004','worker-race-0004','AAAAAAAAAAAAAAAAAAAAAA==','AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA','1','ha',40,0,repeat('b',64),16);" \
  >"$tmp_dir/race-b-worker.out" &
worker_pid=$!
wait_for_lock race_worker_waiting
wait "$delete_pid"
wait "$worker_pid"
store_result="$(tr -d '[:space:]' < "$tmp_dir/race-b-worker.out")"
test "$store_result" = "f"
psql -X -v ON_ERROR_STOP=1 -c "do \$\$ begin if exists(select 1 from private.ocr_results where document_id='20000000-0000-4000-8000-000000000004') then raise exception 'Delete-first race stored an OCR result'; end if; end \$\$;"

echo "PostgreSQL integration tests passed (full OCR migrations, result expiry, owner isolation, ACLs, purge, deterministic lock races)."
