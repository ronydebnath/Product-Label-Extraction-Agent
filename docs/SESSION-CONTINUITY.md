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
docker compose up -d                       # dev stack (override merged): web, worker, scheduler, migrate, postgres, redis, vite, adminer
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
open http://localhost:8081                 # Adminer, dev only: server "postgres", creds from .env
docker compose -f compose.yaml up --build  # production-shaped run without the dev override
```

Changing `.env` needs `docker compose up -d` again; containers read it at creation.

## 5. Repository map

Custom code so far (everything else is the untouched Laravel 13.10 skeleton):

| Path | What |
|---|---|
| `Dockerfile`, `.dockerignore` | Multi-stage image (base, vendor, assets, runtime, dev); stage notes at the top |
| `compose.yaml`, `compose.override.yaml` | Prod-shaped topology plus dev override |
| `docs/ARCHITECTURE.md` | Processes, module boundaries, schema, state machine, invariants |
| `docs/USAGE.md` | Every command, and how to debug a stuck upload |
| `docs/DEPLOYMENT.md` | Deployment plan: topology, release order, config, alerting |
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
| 1b Scheduler | done, verified | `scheduler` service runs `schedule:work`; minute loop fires (2 ticks in 2 minutes); `--scale scheduler=2` still fires each task once, instances alternating as lock winner |
| 2a Auth | done | Fortify trimmed to registration + login; 7 tests green; register exercised in the browser, lands on /uploads |
| 2b Upload path | done, verified | 18 upload tests green; a real 3-page UAT spec sheet uploaded through the running stack was accepted with page_count 3, stored under a uuid path visible from the worker container, and its job drained from Redis; a text file named .jpg was rejected in the same request |
| 3 Extraction agent | done, verified | 107 tests green; three real UAT spec sheets extracted end to end through the live API in the running stack, all completed on attempt 1; the falafel sheet's page-3 allergen table was correctly flagged as conflicting with its ingredient list |
| 4 Frontend | done, verified | Inertia 3.7 + React 19.2 + TS 6 + Tailwind 4 + TanStack Query; 124 PHP tests, tsc and ESLint clean, production image builds; login, list, detail and the polling snapshot all exercised against the running stack |
| 5 Test matrix | done | every row in TDD-SPEC.md section 5 is ticked; 124 tests, 403 assertions |
| 6 Docs | done | DECISIONS.md rewritten around the brief's four questions (1,066 words); README corrected

## 8. Next action

All six stages are complete and every backlog row in TDD-SPEC.md is green. What remains is Rony's
call, not more building:

1. Deployment is still parked (Fly.io was the candidate). Nothing Fly-specific has been written,
   and the app is deliberately host-agnostic: `UPLOADS_DISK` points at object storage, migrations
   are a release step, and the scheduler is already its own process.
2. If anything else is added, it starts as a new row in TDD-SPEC.md section 5 with a failing test.

The most defensible remaining gap is the rate-limiting queue middleware for the 50k scenario:
documented in DECISIONS.md, deliberately not built.

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
- `phpunit.xml` must use `<server>` with `force="true"`, never `<env>`. compose passes .env into
  the container as real environment variables, PHP puts those in `$_SERVER`, and Laravel's `env()`
  reads `$_SERVER` before `$_ENV`. With `<env>` the suite silently ran as `APP_ENV=local` against
  the development database and the real Redis queue. If you add a variable there use `<server>`,
  and re-check with `dump(app()->environment(), config('database.connections.pgsql.database'))`.
- Fortify's login throttle returns 429 from middleware, not a validation error on the session.
- The dev and production images MUST NOT share a tag. `docker compose -f compose.yaml build` builds
  the `runtime` target, and with a shared tag it silently replaces the dev image; the stack then
  runs with `opcache.validate_timestamps=Off` and serves stale bytecode for every file you edit.
  The override now tags dev as `label-extraction/app:dev`. Symptom: edits appear to do nothing.
- `@viteReactRefresh` must appear in the Blade layout before `@vite(...)`, or `@vitejs/plugin-react`
  throws before React mounts and the page is blank. Production builds do not use refresh, so every
  automated check passes while the browser shows nothing.
- Chaining `->beforeEach()` twice on `pest()` REPLACES the first closure instead of adding to it.
  Everything shared belongs in one closure in `tests/Pest.php`; getting this wrong silently
  unbound the fake LLM client and 23 tests started resolving the real OpenAI client.
- `JsonResource` wraps output in `data` by default, which breaks Inertia prop assertions.
  `JsonResource::withoutWrapping()` in `AppServiceProvider::boot()` keeps one shape everywhere.
- Inertia 3 puts the page object in `<script data-page="app" type="application/json">`, not in a
  `data-page` attribute on a div. Parsing the old shape finds nothing.
- Feature tests need `withoutVite()` or every page render looks for a manifest that only exists
  after `npm run build`.
- The `@/*` alias needs to be declared twice: `paths` in tsconfig.json for the type checker and
  `resolve.alias` in vite.config.ts for the bundler. And without `baseUrl` (deprecated in TS 6)
  the paths entries need a leading `./`.
- Horizon does not hot-reload PHP. After changing worker code: `docker compose restart worker`.
  After changing `.env`: `docker compose up -d --force-recreate worker`, because a restart keeps
  the old environment. Getting this wrong once left the worker running the previous OPENAI_MODEL
  while `.env` and every test said otherwise.
- `TimeoutExceededException` extends `MaxAttemptsExceededException`, so checking for both in a
  `failed()` hook is redundant and PHPStan will say so.
- A helper function defined in one Pest test file is not visible in another. Shared helpers
  (`validDocument`, `sampleFile`, `uploadedBytes`, `fakeLlm`) live in `tests/Pest.php`.
- `docker compose restart` does NOT re-read `.env`; the container keeps the environment it was
  created with. Only `up -d` (or `--force-recreate`) picks up a changed value. This cost an hour
  of chasing a "dead" OpenAI key that was live on the host and stale in the container. To compare
  a secret across the boundary without printing it:
  `docker compose exec -T web sh -c 'printf "%s" "$OPENAI_API_KEY" | sha256sum'` against the same
  hash of the value in `.env`. Note that OpenAI's 401 masks the middle of the key and echoes only
  the prefix and last four characters, so a wrong key can look identical to the right one.
- `UploadedFile::fake()` reports a mime type guessed from the filename, so it cannot test sniffing.
  Use the `uploadedBytes()` / `sampleFile()` helpers in `tests/Pest.php`, which build a real
  `UploadedFile` over real bytes with `test: true`.
- Pest already defines a global `fixture()`; naming a helper that collides fails at parse time.
- `LOG_CHANNEL=stderr`, so there is no `storage/logs/laravel.log`. Read application output with
  `docker compose logs <service>`, not by grepping a file.
- `onOneServer()` on a closure throws `LogicException` unless `name()` is called first. Scheduling
  the sweeper as `Schedule::command('uploads:sweep')` avoids this; a closure needs the name.
- The entrypoint lives in the image, not the bind mount, so adding a role to it needs
  `docker compose up -d --build`, not a restart.

## 10. End-of-session checklist

1. Run the three gates and the stack; fix or note anything red.
2. Update the status board (section 7) and the next action (section 8) so they are literally true.
3. Add any new gotcha to section 9 and any new decision to section 6 and DECISIONS.md.
4. List uncommitted work and announce the commit point(s) with messages.
5. Note open questions for Rony at the top of section 8.
