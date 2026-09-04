# Usage

Every command runs inside a container. The host needs Docker and nothing else — no PHP, no
Composer, no Node. Commands are given in full; there are no aliases to learn.

> The `composer setup` and `composer dev` scripts inherited from the Laravel skeleton assume PHP and
> Node on the host and are **not** the workflow here. Ignore them.

## Running the stack

```sh
docker compose up -d                  # dev stack, override merged automatically
docker compose up -d --build          # after changing the Dockerfile or anything in docker/
docker compose ps                     # what is running; `migrate` showing Exited is correct
docker compose logs -f worker         # follow job output
docker compose down                   # stop, keeping the named volumes
docker compose down -v                # stop and delete the database, Redis and uploaded files
```

Dev brings up `web`, `worker`, `scheduler`, `migrate`, `postgres`, `redis`, `vite` and `adminer`.
`docker compose -f compose.yaml up` runs the production-shaped topology instead: no bind mounts, no
Vite, no Adminer, assets baked into the image.

| What | Where |
|---|---|
| Application | http://localhost:8080 |
| Horizon dashboard | http://localhost:8080/horizon |
| Adminer (dev only) | http://localhost:8081 — server `postgres`, credentials from `.env` |
| Vite dev server | http://localhost:5173 (assets only, not a page) |

### The two commands that catch people out

```sh
docker compose up -d --force-recreate web    # after changing .env; `restart` keeps the OLD env
docker compose restart worker                # after changing PHP; Horizon does not hot-reload
```

A container reads `.env` when it is **created**. `docker compose restart` reuses the existing
container and its original environment, so a changed value appears to be ignored.

## First run

```sh
cp .env.example .env
docker compose run --rm --no-deps web php artisan key:generate
# put a real OPENAI_API_KEY in .env, then:
docker compose up -d
```

Migrations run automatically in the one-shot `migrate` service; `web` and `worker` wait for it.

## Working on the app

```sh
docker compose exec web composer require vendor/package
docker compose exec web php artisan make:action Uploads/Thing   # any artisan generator
docker compose exec vite npm install some-package
docker compose exec web bash                                    # a shell in the app container
```

Never run `composer` from the `composer:2` image directly: it runs a different PHP version and will
resolve dependencies against the wrong platform.

## Quality gates

All five must pass before a commit point.

```sh
docker compose exec web php artisan test              # Pest, real Postgres + Redis
docker compose exec web vendor/bin/pint               # fix formatting
docker compose exec web vendor/bin/pint --test        # check only
docker compose exec web vendor/bin/phpstan analyse    # Larastan, level 6
docker compose exec vite npm run types                # tsc --noEmit
docker compose exec vite npm run lint                 # eslint
```

Narrow the test run while working:

```sh
docker compose exec web php artisan test tests/Feature/Uploads
docker compose exec web php artisan test --filter="rejects a text file"
```

Tests use the `app_test` database and Redis db 9, both isolated from the dev data. That isolation
depends on `<server force="true">` entries in `phpunit.xml`; see docs/SESSION-CONTINUITY.md if you
ever add a variable there.

## Queue and worker

```sh
docker compose exec web php artisan queue:ping        # dispatch a trivial job
docker compose logs worker | grep queue.pong          # handled_by names the worker container
docker compose up -d --scale worker=3                 # more workers; jobs still run exactly once
docker compose exec web php artisan horizon:status
docker compose exec web php artisan horizon:supervisors
docker compose exec web php artisan horizon:terminate # graceful restart, used on deploy
docker compose exec web php artisan queue:failed      # failed jobs
docker compose exec web php artisan queue:retry all
```

`queue:ping` is the proof that jobs leave the web process: it is dispatched from `web` and the log
line naming the handling host appears in `worker`.

## Uploads and extraction

```sh
docker compose exec web php artisan uploads:sweep     # recover lost jobs by hand
docker compose exec web php artisan schedule:list     # the scheduler runs the sweep every minute
docker compose exec web php artisan schedule:run      # fire due tasks once, without waiting
```

`uploads:sweep` re-dispatches uploads stuck in `queued`, takes over rows whose worker died, and
fails rows whose lease expired with no attempts left. It is safe to run at any time and safe to run
twice — a re-dispatched job that races a live one loses the claim and exits.

## Database

```sh
docker compose exec web php artisan migrate:status
docker compose exec web php artisan migrate           # dev; the migrate service does this on up
docker compose exec web php artisan migrate:fresh     # DESTRUCTIVE: drops every table
docker compose exec postgres psql -U app -d app       # a psql prompt
docker compose exec postgres psql -U app -d app -c "select status, count(*) from uploads group by 1"
```

Or use Adminer at http://localhost:8081 if you would rather click.

## Debugging a stuck upload

Every log line for an upload carries its id as the correlation id, from the request that created it
to the job that finished it.

```sh
docker compose logs worker | grep <upload-id>
docker compose exec postgres psql -U app -d app -c \
  "select id, status, attempts, failure_code, processing_started_at, last_error from uploads where id = '<id>'"
```

Read it like this:

| What you see | What it means |
|---|---|
| `queued`, `attempts` 0, minutes old | the job was lost — run `uploads:sweep` |
| `queued`, `attempts` 1–4 | waiting out its retry backoff; normal |
| `processing`, lease older than 120s | the worker died; the sweeper will take it over |
| `failed` with `failure_code` | terminal; `last_error` has the technical detail |
| `completed` but no extraction row | should be impossible — `extractions.upload_id` is unique |

## Frontend

```sh
docker compose logs -f vite           # HMR and build errors
docker compose exec vite npm run build
docker compose restart vite           # after changing vite.config.ts
```

The dev server must be running for pages to render in dev; the blade layout points at it through
`public/hot`. In production the bundle is baked into the image and there is no Vite at all.
