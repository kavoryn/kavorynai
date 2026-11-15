-- Disposable PostgreSQL fixture for the owner-scoped document deletion migration.
-- This file is used only by GitHub Actions' ephemeral PostgreSQL service.
create table public.documents (
  id uuid primary key,
  user_id uuid not null,
  storage_key text not null unique,
  status text not null check (status in ('uploaded','queued','processing','succeeded','failed','deleted')),
  deleted_at timestamptz
);

create table private.ocr_jobs (
  id uuid primary key,
  document_id uuid not null,
  user_id uuid not null,
  status text not null check (status in ('queued','processing','succeeded','failed','cancelled')),
  locked_until timestamptz,
  worker_id text,
  updated_at timestamptz not null default now(),
  constraint ocr_jobs_lease_check check (
    (status = 'processing' and locked_until is not null and worker_id is not null)
    or (status <> 'processing' and locked_until is null and worker_id is null)
  )
);

create table private.ocr_results (
  id uuid primary key,
  document_id uuid not null,
  user_id uuid not null
);

create table private.ai_processing_consents (
  id uuid primary key,
  document_id uuid not null,
  user_id uuid not null
);
