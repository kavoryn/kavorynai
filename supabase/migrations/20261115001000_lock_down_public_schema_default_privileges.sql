-- Restrict broad Supabase public-schema table grants.
--
-- Row-level policies continue to govern normal SELECT/INSERT/UPDATE/DELETE
-- access on user-facing tables. PostgreSQL TRUNCATE, REFERENCES and TRIGGER
-- privileges are not row-scoped, so they must not be inherited by API roles.
--
-- audit_events is append-only and has no user-facing RLS policy. Revoke direct
-- access from anon/authenticated and allow only server-side INSERTs.
--
-- Future public tables must receive explicit grants in their migrations.

begin;

revoke truncate, references, trigger
  on all tables in schema public
  from anon, authenticated, service_role;

do $$
begin
  if to_regclass('public.audit_events') is not null then
    execute 'revoke all on table public.audit_events from anon, authenticated, service_role';
    execute 'grant insert on table public.audit_events to service_role';
  end if;
end;
$$;

-- The Supabase-managed postgres role creates application migrations.
alter default privileges for role postgres in schema public
  revoke all on tables from anon, authenticated, service_role;

-- Some Supabase-managed objects can be created by supabase_admin. Guard for
-- environments where that platform role is not present (for example CI).
do $$
begin
  if exists (select 1 from pg_catalog.pg_roles where rolname = 'supabase_admin') then
    execute 'alter default privileges for role supabase_admin in schema public revoke all on tables from anon, authenticated, service_role';
  end if;
end;
$$;

commit;
