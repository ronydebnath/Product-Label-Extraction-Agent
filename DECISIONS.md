# Decisions

Kept to the what, why and how. Grows with each stage; trimmed to a page at the end.

## Queue architecture

Redis + Laravel Horizon, one supervisor, jobs carrying only an upload id. Redis because it is
already the session and cache store, Horizon because it gives per-supervisor concurrency limits,
metrics and a dashboard for free. The worker is a separate container from the same image; the
web process never runs a job. `docker compose up --scale worker=3` proves it: Redis pops each job
for exactly one worker, and the job itself re-checks the row's status with a compare-and-swap so a
redelivery finds nothing to do.

Timing ladder, which must hold or a job can run twice: LLM HTTP timeout 60 s, job timeout 90 s,
processing lease stale at 120 s, `REDIS_QUEUE_RETRY_AFTER` 150 s.

## Scheduler

A fourth container running `php artisan schedule:work` off the same image. The stuck-upload
sweeper (re-dispatch rows left `queued`, fail rows whose lease expired at the attempt cap) has to
be fired by something, and the two obvious homes are both wrong: `web` would fire it once per
replica, and `worker` would fire it once per `--scale worker=N`. Timers are not work, so they do
not belong in a process type that scales. One scheduler, never scaled, with `onOneServer` locks on
the tasks themselves so that a scaling mistake costs nothing.

The scheduler is deliberately not given the uploads volume. It moves rows and re-dispatches job
ids; it never opens a file.

## Migrations

A one-shot `migrate` compose service that web and worker wait on with
`service_completed_successfully`. Not the entrypoint: three web replicas starting at once would
each run the same DDL, two would fail on "relation already exists" and crash-loop. Laravel's
`migrate --isolated` exists but needs the cache store, which is the thing we are booting.
In production this becomes a release step (a job that runs before the new version takes traffic).

## Image strategy

One multi-stage Dockerfile, one image for web and worker. The `base` stage installs PHP
extensions and nginx; `vendor` runs composer on that same PHP so platform checks are real;
`assets` builds Vite in a node stage that never reaches the runtime; `runtime` is base plus
vendor plus the bundle, non-root; `dev` layers dev dependencies on top and is only selected by
the compose override. Config caching happens in the entrypoint at container start, not at build,
because the values come from the environment and baking them would put secrets in a layer.

## Storage

Uploads go to a Laravel disk chosen by `UPLOADS_DISK`. Locally that is the `uploads` named
volume shared by web and worker. In production it must be object storage (any S3-compatible
bucket): container filesystems are ephemeral and workers do not share a disk with web. Files are
stored under a uuid path outside the web root; the client's filename is display-only.

## Idempotency and dedupe

Status transitions are single `UPDATE ... WHERE status = expected` statements with an
affected-rows check, never read-then-save. A worker takes a row by moving it from queued to
processing and stamping a lease; a stale lease may be taken over. The `extractions.upload_id`
unique constraint makes double-writes impossible at the database level.

Dedupe key: sha256 of the bytes, plus model and prompt version. Filename and size are
user-controlled; the hash is the only identity the client cannot lie about. Identical bytes
reuse the existing extraction and never reach the LLM twice.

## Trade-offs so far

- Postgres CHECK constraints instead of enum types: adding a status is one constraint swap.
- Auth via Fortify rather than the official starter kit: the 2026 kit brings Wayfinder (runs PHP
  inside the Vite build), vite-plus, passkeys and two-factor, all of which would have to be
  explained and none of which the task needs.
- poppler's `pdfinfo` in the image (about 30 MB) to validate PDFs structurally and count pages
  before any money is spent, instead of trusting magic bytes.
- Per-file rejection at upload time, nothing persisted for rejects: no LLM cost, no garbage rows.
