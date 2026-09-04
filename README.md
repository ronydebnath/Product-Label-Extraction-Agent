# Label Extraction Agent

Upload product label images or PDFs; a queued agent sends each one to an LLM and turns it into
validated, structured product data — name, brand, ingredients, allergens, net weight.

Built for the SupplyScope trial task. The LLM is treated throughout as an untrusted, unreliable
dependency: slow, absent, rate-limited, or answering with nonsense.

| | |
|---|---|
| **Run it** | [Quick start](#quick-start) — Docker and nothing else |
| **How it works** | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — processes, data model, state machine, boundaries |
| **Commands** | [docs/USAGE.md](docs/USAGE.md) — every command, and debugging a stuck upload |
| **Ship it** | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — topology, release order, what to alert on |
| **Why** | [DECISIONS.md](DECISIONS.md) — 50k uploads, queue architecture, LLM failures, trade-offs |
| **Requirements** | [docs/PRD.md](docs/PRD.md) |

## Quick start

Requirements: **Docker with Compose v2, and nothing else.** PHP, Composer, Node and npm all run
inside containers — you do not need any of them installed.

```sh
git clone <this repo> && cd "Label Extraction Agent app"
cp .env.example .env                                                # required: see below
docker compose run --rm --no-deps web php artisan key:generate      # writes APP_KEY into .env
docker compose up
```

Then open http://localhost:8080 and register an account.

**This is the development stack.** A bare `docker compose` merges
[compose.override.yaml](compose.override.yaml), which bind-mounts the checkout over the image and
publishes Postgres, Redis and an unauthenticated Adminer. On a server, run `compose.yaml` alone:
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md#a-concrete-example-one-vm) walks a single VM end to end.

### What happens on that first run, and how long it takes

**`cp .env.example .env` is not optional.** Compose reads `.env` to configure the database
container, so without it nothing starts at all — you get
`required variable DB_PASSWORD is missing a value`. The example file has working local defaults, so
copying it is enough; the only value you may want to change is `OPENAI_API_KEY`.

**Dependencies install themselves. There is no `composer install` or `npm install` step.**

| | How it arrives |
|---|---|
| `vendor/` | The image ships it. In dev the bind mount hides the image's copy, so [`docker/entrypoint.sh`](docker/entrypoint.sh) runs `composer install` on start when `vendor/autoload.php` is missing. |
| `node_modules/` | The `vite` service runs `npm install` before starting the dev server, into a named volume that never touches your host. |
| `public/build` | Built inside the image for production runs; in dev the Vite dev server serves assets and no build is needed. |
| Database schema | The one-shot `migrate` service runs `migrate --force` before `web` and `worker` start. |

**The first run takes several minutes** — it builds the PHP image, installs Composer and npm
dependencies, and compiles assets. Later runs start in seconds. `docker compose up` without `-d`
lets you watch it; the app is ready when `web` reports healthy.

### Verify it worked

```sh
docker compose ps                                    # web/postgres/redis healthy, migrate Exited (0)
curl -s -o /dev/null -w "%{http_code}\n" localhost:8080/login   # 200
docker compose exec web php artisan queue:ping       # then look for queue.pong in the worker log
```

`migrate` showing `Exited (0)` is success — it is a one-shot task, not a crashed service.

### Without an OpenAI key

Everything works except extraction. Uploads are validated, stored and queued; the worker then gets a
`401` from OpenAI and each upload finishes as **Failed — "The AI service rejected the request."**
That is the correct handling of a permanent failure, and it is a reasonable way to see the failure
path. Put a real key in `OPENAI_API_KEY` and re-create the containers to get extraction:

```sh
docker compose up -d --force-recreate      # a plain `restart` keeps the OLD environment
```

### If something goes wrong

| Symptom | Cause |
|---|---|
| `required variable DB_PASSWORD is missing` | no `.env` — run `cp .env.example .env` |
| `APP_KEY is empty` and the container exits | run the `key:generate` step above |
| Ports 8080, 5173, 5432, 6379 or 8081 already in use | set `WEB_PORT`, `POSTGRES_HOST_PORT`, `REDIS_HOST_PORT` or `ADMINER_PORT` in `.env` |
| Blank page in the browser | the `vite` service is not running; check `docker compose logs vite` |
| A `.env` change appears to be ignored | `restart` reuses the old environment — use `up -d --force-recreate` |
| `Failed to open stream: .../vendor/autoload.php` | the dev bind mount is hiding the image's `vendor/`, and this checkout has none. On a server use `-f compose.yaml`; locally make sure `APP_ENV=local` so the entrypoint can install |
| Nothing answers on the host's IP | compose publishes `${WEB_PORT:-8080}`, so it is `:8080` unless you set `WEB_PORT=80`. Then check `ufw` and any cloud firewall |

More in [docs/USAGE.md](docs/USAGE.md).

## Topology

[compose.yaml](compose.yaml) is the production-shaped topology;
[compose.override.yaml](compose.override.yaml) adds development conveniences and is merged
automatically by a plain `docker compose up`.

| Service | Image | Runs | Notes |
|---|---|---|---|
| `web` | this repo's Dockerfile, `runtime` target | nginx + php-fpm under supervisord | HTTP only. **Never executes a job.** Port 8080. |
| `worker` | the **same image** | `php artisan horizon` | The only process that runs jobs. `--scale worker=N`. |
| `scheduler` | the same image | `php artisan schedule:work` | Fires the stuck-upload sweeper. Exactly one; never scaled. |
| `migrate` | the same image | `php artisan migrate --force`, once | One-shot. Everything else waits for it to succeed. |
| `postgres` | `postgres:17-alpine` | uploads + extractions | Healthcheck gates app startup. Volume `pgdata`. |
| `redis` | `redis:8-alpine` | queue, cache, sessions | Append-only, `noeviction`: a queue must fail loudly, never drop jobs. Volume `redisdata`. |
| `vite` | `node:22-alpine` *(dev only)* | Vite dev server with HMR | Port 5173. Production bakes the bundle into the image. |
| `adminer` | `adminer:5` *(dev only)* | database console | Port 8081. Absent from `compose.yaml`: an unauthenticated door into Postgres. |

Web, worker and scheduler are **one image and one entrypoint**
([docker/entrypoint.sh](docker/entrypoint.sh)), differing only by the argument (`web`, `horizon`,
`scheduler`). Development builds the `dev` target under its own tag, so building the production
image never replaces the one the dev stack is running.

Uploaded files live on the `uploads` named volume, mounted into web and worker at
`storage/app/private` — outside the web root. That works only because both run on one host; in
production `UPLOADS_DISK` points at object storage.

## Prove the interesting parts

The queue is genuinely out-of-process — dispatched from `web`, executed in `worker`:

```sh
docker compose exec web php artisan queue:ping
docker compose logs worker | grep queue.pong        # handled_by is the worker's hostname
```

Three workers, no double processing:

```sh
docker compose up -d --scale worker=3
```

Production-shaped run — no bind mounts, baked assets, cached config. This is also the command a
server runs; [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md#a-concrete-example-one-vm) has the rest of it:

```sh
docker compose -f compose.yaml up --build
```

## How a file is handled

1. **Validated by its bytes.** Sniffed MIME and structure, never the extension. PDFs must parse
   under `pdfinfo`; images must present a readable header. Caps: 10 MB per file, 20 files per
   request, 10 pages per PDF, 25 megapixels per image — all from
   [config/uploads.php](config/uploads.php), which is also what the UI quotes.
2. **Stored under a generated uuid** on a private disk. The client's filename is display-only.
3. **One row, one job**, dispatched after the row is committed and carrying only its id.
4. **Claimed by one worker** with a compare-and-swap plus a lease, so a redelivered or duplicated
   job finds the row taken and does nothing.
5. **Sent to the model** as native PDF or image input, asking for strict JSON Schema output, which
   is then validated again server-side against that same schema.
6. **Polled by the page** every 2s until nothing is moving, then not at all.

Validation is per file: one bad file in a batch of twenty does not cost you the other nineteen, and
each rejection names the file and the reason.

## Failure handling

Transient failures (429, 408, 5xx, timeouts) are retried by the queue with exponential backoff and
jitter — ~10s, 30s, 90s, 270s, ±25% — up to 5 attempts, honouring `Retry-After`. Permanent ones
(rejected request, unparsable or schema-invalid output, refusal, not a label) fail immediately.
There is no repair-retry; the reasoning is in [DECISIONS.md](DECISIONS.md).

Users only ever see a fixed sentence keyed by failure code. Exception text, HTTP bodies and stack
traces stay in `last_error` and the logs, and every log line carries the upload id as a correlation
id.

## Tests and static analysis

Against real Postgres (`app_test`) and real Redis (db 9) — no sqlite anywhere.

```sh
docker compose exec web php artisan test            # 124 tests, 403 assertions
docker compose exec web vendor/bin/pint --test
docker compose exec web vendor/bin/phpstan analyse  # Larastan level 6
docker compose exec vite npm run types              # tsc --noEmit
docker compose exec vite npm run lint               # eslint
```

The suite covers the unhappy paths deliberately: unsupported and disguised file types, corrupt
files, oversized and over-long documents, malformed and schema-invalid LLM output, transient
failures with retry and exhaustion, permanent failures, redelivery, stale leases, and a lost queue.

## Stack

PHP 8.4, Laravel 13.30, PostgreSQL 17, Redis 8, Horizon 5.48, Laravel Actions, Pest 5, Pint,
Larastan 3; React 19, TypeScript 6, Inertia 3, Tailwind 4, Radix UI, TanStack Query, Vite 8.

## Scope cuts

Deliberate, with reasons in [docs/PRD.md](docs/PRD.md#11-out-of-scope-with-reasons): no manual retry
button, no websocket push, no virus scanning, no frontend unit tests (TypeScript strict and ESLint
instead), no password reset or 2FA, and no managed cloud hosting —
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) walks a single VM end to end, but the multi-replica,
managed-service topology is the plan rather than a transcript.

The one piece of the 50,000-upload answer that is described rather than built is the rate-limiting queue middleware. It is ten lines, and it cannot be honestly demonstrated without a real rate limit to hit.
