# Deployment

Written host-agnostically on purpose. Nothing in this application knows where it runs: the same
image serves every role, storage is a Laravel disk, and the queue is a connection string. No target
has been provisioned yet, so **treat this as the plan, not a transcript** — the compose topology it
describes has been run and verified, the cloud specifics have not.

## What has to run

Five processes and three stores. The compose file is the reference implementation of exactly this.

| Role | Command | Count | Notes |
|---|---|---|---|
| Web | `entrypoint web` | 2+ | nginx + php-fpm. Stateless. Behind a load balancer. Never runs a job. |
| Worker | `entrypoint horizon` | 1+ | The only process that runs jobs. Scale this for throughput. |
| Scheduler | `entrypoint scheduler` | **exactly 1** | Fires the sweeper. Never scale it; see below. |
| Migrations | `php artisan migrate --force` | once per release | A release step, not a startup step. |
| Postgres | managed service | 1 | 17. Put pgbouncer in front once web replicas multiply. |
| Redis | managed service | 1 | Persistence **on**; queued and reserved jobs must survive a restart. |
| Object storage | S3-compatible bucket | — | Private. Never a container volume. |

Web, worker and scheduler are **one image**, selected by the argument to `docker/entrypoint.sh`.
Whatever is tested is what ships.

### Why the scheduler is exactly one

`schedule:work` fires every due task on the minute, so a second instance runs the sweeper twice
concurrently. The tasks also take a Redis lock (`onOneServer`), so a mistaken second instance
degrades to a no-op rather than double work — verified: two containers, two minutes, two runs, the
instances alternating as lock winner. Do not rely on the lock; run one.

## Build and ship

```sh
docker build --target runtime -t registry.example.com/label-extraction:$(git rev-parse --short HEAD) .
docker push registry.example.com/label-extraction:<sha>
```

The `runtime` target is the production stage: composer without dev dependencies, the Vite bundle
built in a Node stage that never reaches the final image, running as a non-root `app` user. Do not
deploy the `dev` target — it carries Composer, dev dependencies, and opcache timestamp checks.

Tag by commit sha, not `latest`. Dev and production images are already tagged separately here for
exactly this reason: sharing a tag once meant a production build silently replaced the dev image.

**No secrets are baked into any layer.** Config, route, view and event caches are built by the
entrypoint at container start, when the real environment exists — not at build time.

## Release order

Migrations must not run from the web entrypoint. Three replicas starting together race the same DDL,
two fail on "relation already exists", and they crash-loop. Run them once, before the new version
takes traffic:

1. Build and push the image.
2. Run `php artisan migrate --force` as a one-off task on the new image. Wait for success.
3. Roll web, then worker, then scheduler.
4. `php artisan horizon:terminate` so workers restart on the new code after finishing their
   current job. Horizon does not hot-reload.

Migrations must be backward-compatible for the length of the roll: old and new code run
simultaneously. Add columns nullable, backfill separately, drop in a later release.

## Configuration

Everything comes from the environment; `.env.example` is the full list.

Required in production:

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=                 # php artisan key:generate --show, then store as a secret
APP_URL=https://…        # must be right; sessions and absolute URLs depend on it

DB_CONNECTION=pgsql
DB_HOST= DB_PORT= DB_DATABASE= DB_USERNAME= DB_PASSWORD=

REDIS_HOST= REDIS_PORT= REDIS_PASSWORD=
REDIS_QUEUE_RETRY_AFTER=150   # part of the timing ladder; see DECISIONS.md before changing

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis          # required the moment there is more than one web replica

UPLOADS_DISK=s3
AWS_ACCESS_KEY_ID= AWS_SECRET_ACCESS_KEY= AWS_DEFAULT_REGION= AWS_BUCKET=
AWS_ENDPOINT=                 # any S3-compatible provider
AWS_USE_PATH_STYLE_ENDPOINT=  # true for MinIO and most non-AWS providers

OPENAI_API_KEY=               # secret
OPENAI_MODEL=gpt-5.4-mini

LOG_CHANNEL=stderr            # the platform collects stdout/stderr; do not write log files
```

`SESSION_DRIVER=redis` is not optional with multiple web replicas — the default file driver would
scatter sessions across containers and users would appear randomly logged out.

### Storage must be object storage

`UPLOADS_DISK=local` writes to the container filesystem, which is ephemeral and not shared. The
worker is a different container from the web process and will not find the file; the upload fails
as `file_missing`. Point it at a private bucket. The local named volume works only because
everything runs on one host in development.

## Health and readiness

`/up` answers without touching Postgres or Redis — it reports that the process is alive, not that
its dependencies are. Use it as the liveness probe and the load balancer check.

Worker and scheduler have no HTTP endpoint. Monitor them by process supervision plus:

```sh
php artisan horizon:status
php artisan queue:failed
```

## Scaling

Scale `worker` for throughput; concurrency is `maxProcesses` (5 in production) x replicas. Scale
`web` for request volume. Never scale `scheduler`.

Before scaling workers hard, add the `Redis::throttle()` middleware described in DECISIONS.md.
Without it, more workers turn a throughput problem into a wall of 429s that burns every retry.

## What to alert on

| Signal | Why it matters |
|---|---|
| `failure_code = llm_rejected_request` appearing at all | usually a bad or revoked API key: **every** job will fail |
| `failure_code = file_missing` | storage misconfiguration; the row and the bucket disagree |
| `failure_code = queue_unavailable` | Redis was unreachable at dispatch |
| Rising `llm_unavailable` | the provider is degraded, or the throttle is missing |
| Rows in `processing` older than the lease | workers are dying; check memory limits |
| Horizon failed-job count climbing | anything above |
| Queue wait time | the number a user actually feels |

Every log line carries the upload id as a correlation id, so one grep follows a file from request to
completion.

## A concrete example: Fly.io

The candidate host, not yet provisioned. One app, three process groups from the same image:

```toml
[processes]
  web = "web"
  worker = "horizon"
  scheduler = "scheduler"

[http_service]
  internal_port = 8080
  processes = ["web"]
  [http_service.checks]
    path = "/up"

[[vm]]
  processes = ["scheduler"]
  # count = 1, always. See above.
```

Managed Postgres and Redis (Upstash for Redis, with persistence enabled), Tigris or S3 for the
bucket, `fly secrets set` for `APP_KEY`, `OPENAI_API_KEY` and the database and storage credentials.
Migrations go in `[deploy] release_command = "php artisan migrate --force"`, which Fly runs once
before shifting traffic — the release-step requirement above, for free.

Any platform that can run three process groups from one image and inject secrets works the same way:
ECS services, Kubernetes Deployments plus a Job for migrations, or a plain VM running
`docker compose -f compose.yaml up -d`.

## Before the first production deploy

- [ ] `APP_DEBUG=false` and a generated `APP_KEY` held as a secret
- [ ] `UPLOADS_DISK=s3` against a **private** bucket
- [ ] `SESSION_DRIVER=redis` if more than one web replica
- [ ] Redis persistence enabled and `maxmemory-policy noeviction` — a queue must fail loudly under
      memory pressure, never silently drop jobs
- [ ] Migrations wired as a release step, not an entrypoint
- [ ] Exactly one scheduler
- [ ] The Horizon dashboard gated: `HorizonServiceProvider::gate()` currently admits **any**
      signed-in user, which is fine for a demo and not for production
- [ ] Backups on Postgres, and a restore actually tested
