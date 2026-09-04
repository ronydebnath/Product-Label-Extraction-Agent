# Session continuity plan

Read this first at the start of every AI development session. It says what this project is, how
we work, where things are, what is done, and exactly what to do next. Update it at the end of
every session (checklist at the bottom).

Reading order for a fresh session: this file, then [PRD.md](PRD.md) (what we are building),
[design.md](design.md) (data model, state machine, failure taxonomy, test matrix),
[../DECISIONS.md](../DECISIONS.md) (why), [TDD-SPEC.md](TDD-SPEC.md) (how we build and test).
Then run `git log --oneline` and `git status` to see the real state before trusting the status
board below.

## 1. What this is

A Label Extraction Agent for SupplyScope, built by Rony. Users sign in, upload product label
images and PDFs, a Horizon worker calls OpenAI to extract product name, brand, ingredients,
allergens and net weight as schema-validated JSON, and a React/Inertia UI shows status, failure
reasons and the extracted data. The brief is `../Trial Task.md` in the parent folder.

The main focus of this project is failure handling, idempotency,
security of untrusted input, scalability reasoning, unhappy-path tests, and clear trade-offs.


## 2. How we work (non-negotiable)

- Work in stages and stop at the end of each stage for Rony's approval. Stages: 0 plan (done),
  1 containers and schema (done), 2 upload path with auth, 3 extraction agent, 4 frontend,
  5 full test matrix, 6 README/DECISIONS/project overview brief.
- Rony makes every git commit. Never run `git commit` or `git push`. When a coherent chunk is
  finished and green, announce a commit point with a suggested message and the file list.
- Ask when a requirement is genuinely ambiguous; do not guess silently. Push back on
  over-engineering and on AI-shaped solutions where a deterministic one is simpler.
- Spec first, test first: see TDD-SPEC.md. Every behaviour has a PRD requirement id and a test.
- Comments explain why, never what. No comment that restates the line below it.
- Prefer fewer moving parts. Every dependency or tool is something should be explainable.
- The OpenAI key goes in `.env` only. `.env.example` is committed, `.env` is git-ignored.
- Before announcing a commit point: `php artisan test`, `pint --test`, `phpstan analyse`
  all green inside the container, and the change checked in the running Docker stack.
- Documentation is part of every stage: update DECISIONS.md when a decision is made, README when
  a command or topology changes, and this file at the end of the session.

## 3. Environment facts

- Host PHP is 8.3 and the project needs 8.4, so every PHP, Composer and npm command runs inside
  Docker. Docker 29 with Compose v2 is installed. Nothing else is required.
- The repo lives at `/Users/rony/Downloads/Trial Task Dev/Label Extraction Agent app` (spaces in
  the path; always quote it). The brief, four sample PDFs and the original Stage 0 plan are in the
  parent folder.
- Sample inputs are three-page, image-only spec-sheet PDFs (no text layer). Allergen sections are
  deliberately incomplete; one sample is a non-food product. The prompt and schema are designed
  around them. Copies live in `tests/UAT files/` for manual testing through the UI (excluded from
  the Docker image; automated tests use tiny generated fixtures instead).
- A working `.env` exists locally with an APP_KEY. `OPENAI_API_KEY` is not set yet (Stage 3).
- All stack versions in the brief exist and are installed: Laravel 13.30, Horizon 5.48, Pest 5.1,
  Larastan 3.11, Pint 1.30; npm lockfile is the skeleton's (React/Inertia not yet added).
- The `composer:2` image runs PHP 8.5; resolving dependencies with it would lie about the
  platform. Use the app's own container instead (`docker compose exec web composer ...`).

## 4. Command cheat sheet

```sh
docker compose up -d                       # dev stack (override merged): web, worker, scheduler, migrate, postgres, redis, vite
docker compose up -d --build               # after Dockerfile or docker/ changes
docker compose restart worker              # after PHP changes; Horizon does not hot-reload
docker compose up -d --scale worker=3      # prove single processing across replicas
docker compose exec web php artisan test   # Pest against Postgres app_test and Redis db 9
docker compose exec web vendor/bin/pint --test
docker compose exec web vendor/bin/phpstan analyse --memory-limit=512M
docker compose exec web composer require vendor/package
docker compose exec vite npm install some-package
docker compose exec web php artisan queue:ping && docker compose logs worker | grep queue.pong
docker compose logs -f worker              # job output; web logs are nginx + php-fpm
docker compose -f compose.yaml up --build  # production-shaped run without the dev override
```

Changing `.env` needs `docker compose up -d` again; containers read it at creation.

## 5. Repository map

Custom code so far (everything else is the untouched Laravel 13.10 skeleton):

| Path | What |
|---|---|
| `Dockerfile`, `.dockerignore` | Multi-stage image (base, vendor, assets, runtime, dev); stage notes at the top |
| `compose.yaml`, `compose.override.yaml` | Prod-shaped topology plus dev override |
| `docker/entrypoint.sh` | Role by argument: `web`, `horizon`, or any command; caches config in prod only |
| `docker/nginx`, `docker/php`, `docker/supervisor`, `docker/postgres` | Non-root nginx, php-fpm pool (replaces stock www.conf), supervisord, test-db init |
| `config/uploads.php` | Disk choice, caps, accepted MIME types (single source for the limits) |
| `config/horizon.php` | Supervisor timeout 90 s, maxProcesses 5 in production |
| `app/Providers/HorizonServiceProvider.php` | Dashboard gate: any signed-in user |
| `database/migrations/2026_09_04_*` | `uploads` and `extractions` with CHECK constraints |
| `app/Models/Upload.php`, `Extraction.php`, `User.php` | Models; `app/Enums/UploadStatus.php` |
| `app/Jobs/PingQueue.php`, `app/Console/Commands/QueuePing.php` | Out-of-process proof and ops diagnostic |
| `tests/Pest.php`, `phpunit.xml`, `tests/Feature/HealthCheckTest.php` | Test bootstrap against real Postgres/Redis |
| `phpstan.neon` (level 6), `pint.json` | Static gates |
| `docs/` | PRD, design, this plan, TDD spec |
| `tests/UAT files/` | The four sample spec-sheet PDFs for manual checks |
| `README.md`, `DECISIONS.md` | Reviewer-facing docs, grown per stage |

## 6. Decisions in force (details in DECISIONS.md and design.md)

- Auth: Fortify, session-based, registration and login only. Uploads scoped by `user_id`;
  another user's id returns 404. Not the official starter kit (Wayfinder runs PHP inside the Vite
  build and the kit carries tooling the task does not need).
- Rejected files: per-file 422 in the upload response, nothing persisted. Mixed batches accept
  the good files.
- Caps: 10 MB per file, 20 files per request, 10 pages per PDF, 25 megapixels per image.
- Validation order: empty, too large, sniffed MIME plus magic bytes, structure (`getimagesize`
  or `pdfinfo`), page and pixel caps. Cheap checks first, hostile bytes never reach the LLM.
- Dedupe: sha256 + model + prompt_version reuse an existing extraction without an LLM call.
- Queue: plain Laravel Job for mechanics, Actions (lorisleiva) for business logic. Payload is the
  upload id only. Compare-and-swap status transitions; processing lease stale at 120 s.
- Timing ladder: HTTP 60 s < job timeout 90 s < lease 120 s < retry_after 150 s.
- Retries: 5 attempts, delay = max(Retry-After, 10 s x 3^(n-1) with +-25% jitter). Transient =
  429/408/5xx/timeouts/connection errors. Permanent = 4xx client errors, schema-invalid output,
  refusal, truncation, document_type "other", file missing. No repair-retry (reasoning in
  DECISIONS.md; it is the most likely live-change request).
- Failure text lives in a `FailureCode` enum in code; only the code is stored.
- Storage: `UPLOADS_DISK` picks a Laravel disk; local volume in dev, S3-compatible in production.
- Model: env-configured (`OPENAI_MODEL`), mini-tier vision model, verified with one real call
  in Stage 3. Deployment: parked (Fly.io was the candidate; nothing written).
- Scope cuts: no retry button, no websockets, no frontend unit tests (tsc + ESLint instead),
  no virus scanning, `RateLimited` middleware documented not built.

## 7. Status board

| Stage | Status | Evidence |
|---|---|---|
| 0 Plan | done | docs/design.md, decisions confirmed by Rony 2026-09-04 |
| 1 Containers and schema | done, verified | migrate service ran 5 migrations; ping from web handled by worker container; worker survived `docker compose restart redis` (logs one connection error, then continues); 3 replicas processed 7 pings exactly once; tests, Pint, PHPStan green |
| 2 Upload path with auth | next | see section 8 |
| 3 Extraction agent | todo | LlmClient interface + fake, schema, retry policy, job, sweeper |
| 4 Frontend | todo | React + Inertia scaffold, auth pages, list with polling, detail, states |
| 5 Test matrix | todo | remaining rows of TDD-SPEC.md section 5 |
| 6 Docs and review brief | todo | trim DECISIONS.md to a page, README final, review-call brief |

## 8. Next action (Stage 2)

Backend only; pages come in Stage 4. Work test-first in this order (details in TDD-SPEC.md):

1. `composer require laravel/fortify lorisleiva/laravel-actions` inside the container. Run
   `php artisan fortify:install`, keep only `Features::registration()`, drop the two-factor
   migration it publishes, register views later (Stage 4). Write the auth feature tests
   (register, login, logout, guests redirected) before wiring routes.
2. `app/Enums/FailureCode.php` with `message()` for every code in PRD section 7.
3. `tests/Fixtures/` plus a `PdfFixture` helper that builds an N-page PDF in memory.
4. `POST /uploads` through `StoreUploads` (Action as controller) calling `ValidateUploadedFile`
   and `CreateUpload`. Write the rejection tests first (unsupported type, empty, too large, too
   many files, corrupt, too many pages, image too large), then the accept test, then the mixed
   batch, then the "dispatch after commit" and "queue unavailable" tests.
5. `ProcessUploadJob` stub that only acquires the lease (so dispatch tests have a class to assert
   on); the real body arrives in Stage 3.
6. Update README (upload limits), DECISIONS.md (validation order, why per-file rejection), and
   this file. Announce the commit point.

Decided 2026-09-04: the sweeper is fired by a `scheduler` compose service running
`php artisan schedule:work` off the same image. The container exists already and idles until
Stage 3 registers a task in `routes/console.php`; register it with `onOneServer()`.

## 9. Gotchas already paid for

- nginx creates only the leaf temp directory; its parent must exist. Paths now live under the
  app-owned `/var/lib/nginx/tmp` and `/run/nginx`.
- The image's stock php-fpm `www.conf` carries user/group directives that print notices under
  a non-root master; `docker/php/fpm-pool.conf` replaces it (copied to `www.conf`).
- Horizon 5.48 inlines its dashboard assets; `horizon:publish` and the `laravel-assets` tag are
  no-ops and were removed.
- `docker compose ps` always shows `migrate` as Exited; that is the one-shot service finishing.
- After a Redis restart the worker logs one `Name does not resolve` error and then recovers.
- Pint's `fully_qualified_strict_types` rule wants `Carbon` imported in model docblocks.
- `composer create-project` refuses a non-empty directory; the repo was scaffolded in a scratch
  directory and copied in around the existing `.git`.
- With `QUEUE_CONNECTION=sync` in tests, a forgotten `Queue::fake()` runs the job inline.

## 10. End-of-session checklist

1. Run the three gates and the stack; fix or note anything red.
2. Update the status board (section 7) and the next action (section 8) so they are literally true.
3. Add any new gotcha to section 9 and any new decision to section 6 and DECISIONS.md.
4. List uncommitted work and announce the commit point(s) with messages.
5. Note open questions for Rony at the top of section 8.
