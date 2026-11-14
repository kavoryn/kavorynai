#!/usr/bin/env bash
set -euo pipefail

: "${PGHOST:=127.0.0.1}"
: "${PGPORT:=5432}"
: "${PGUSER:=postgres}"
: "${PGDATABASE:=kavoryn_test}"
: "${PGPASSWORD:=postgres}"
export PGHOST PGPORT PGUSER PGDATABASE PGPASSWORD

psql -v ON_ERROR_STOP=1 -f backend/tests/postgres/document_deletion_integration.sql

# Race A: the worker obtains the OCR row lock, stores a valid encrypted result,
# and commits. DELETE must wait, then tombstone the document and purge that result.
psql -v ON_ERROR_STOP=1 <<'SQL' &
begin;
select id from private.ocr_jobs
 where id='30000000-0000-4000-8000-000000000003'
 for update;
select pg_sleep(1);
select private.store_ocr_result(
 '30000000-0000-4000-8000-000000000003',
 'worker-race-0003',
 'google_cloud_vision',
 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
 'AAAAAAAAAAAAAAAAAAAAAA==',
 repeat('a',64),
 16
);
commit;
SQL
worker_pid=$!
sleep 0.2
psql -v ON_ERROR_STOP=1 -c "select * from public.delete_document_for_user('20000000-0000-4000-8000-000000000003','10000000-0000-4000-8000-000000000001');"
wait "$worker_pid"
psql -v ON_ERROR_STOP=1 -c "do \$\$ begin if exists(select 1 from private.ocr_results where document_id='20000000-0000-4000-8000-000000000003') then raise exception 'Worker-first race left OCR result after delete'; end if; end \$\$;"

# Race B: DELETE locks and tombstones first. A worker waiting on the same OCR
# row must fail closed after the delete commits and must not persist a result.
psql -v ON_ERROR_STOP=1 <<'SQL' &
begin;
select * from public.delete_document_for_user(
 '20000000-0000-4000-8000-000000000004',
 '10000000-0000-4000-8000-000000000001'
);
select pg_sleep(1);
commit;
SQL
delete_pid=$!
sleep 0.2
store_result=$(psql -v ON_ERROR_STOP=1 -At -c "select private.store_ocr_result('30000000-0000-4000-8000-000000000004','worker-race-0004','google_cloud_vision','AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA','AAAAAAAAAAAAAAAAAAAAAA==',repeat('b',64),16);")
wait "$delete_pid"
test "$store_result" = "f"
psql -v ON_ERROR_STOP=1 -c "do \$\$ begin if exists(select 1 from private.ocr_results where document_id='20000000-0000-4000-8000-000000000004') then raise exception 'Delete-first race stored an OCR result'; end if; end \$\$;"

echo "PostgreSQL document deletion integration tests passed (owner isolation, ACLs, purge, both OCR lock races)."
