# Label Extraction Agent

Upload product label images or PDFs; a queued agent calls an LLM and turns each one into
validated, structured product data (name, brand, ingredients, allergens, net weight).

Label Extraction Agent for SupplyScope. Product requirements: [docs/PRD.md](docs/PRD.md).
Technical decisions and trade-offs: [DECISIONS.md](DECISIONS.md).

## Topology

Everything is defined in [compose.yaml](compose.yaml); [compose.override.yaml](compose.override.yaml)
adds development conveniences and is merged automatically by plain `docker compose up`.

| Service | Image | Runs | Notes |
|---|---|---|---|
| `web` | this repo's Dockerfile, `runtime` target | nginx + php-fpm (supervisord) | HTTP only. Never executes a job. Port 8080. |
| `worker` | the **same image** | `php artisan horizon` | The only process that runs jobs. Scale with `--scale worker=N`. |
| `scheduler` | the same image | `php artisan schedule:work` | Fires due tasks (the stuck-upload sweeper). Exactly one instance; never scaled. |
| `migrate` | the same image | `php artisan migrate --force`, once | One-shot; web and worker wait for it to finish. |
| `postgres` | `postgres:17-alpine` | database | Healthcheck gates app startup. Named volume `pgdata`. |
| `redis` | `redis:8-alpine` | queue, cache, sessions | Append-only persistence so jobs survive a restart. Named volume `redisdata`. |
| `vite` | `node:22-alpine` (dev only) | Vite dev server with HMR | Port 5173. Production serves the bundle baked into the image. |

Uploaded files live on the `uploads` named volume, mounted into both web and worker at
`storage/app/private`. That only works because both run on one host; see DECISIONS.md for why
production points `UPLOADS_DISK` at object storage instead.

Web, worker and scheduler are one image, one entrypoint ([docker/entrypoint.sh](docker/entrypoint.sh)),
differing only by the argument (`web`, `horizon` or `scheduler`). The Dockerfile's stages and what
each buys are described at the top of [Dockerfile](Dockerfile).

## Run it

Requirements: Docker with Compose v2. Nothing else; PHP, Composer and Node run inside containers.

```sh
cp .env.example .env                                  # add OPENAI_API_KEY when you reach the extraction stage
docker compose run --rm --no-deps web php artisan key:generate
docker compose up
```

Then open http://localhost:8080. The Horizon dashboard is at http://localhost:8080/horizon.

Prove the queue is out-of-process:

```sh
docker compose exec web php artisan queue:ping      # dispatches from the web container
docker compose logs worker | grep queue.pong        # handled_by is the worker container's hostname
```

Scale the worker and watch jobs spread across replicas, each processed exactly once:

```sh
docker compose up -d --scale worker=3
```

Production-shaped run (no bind mounts, baked assets, cached config, opcache without file checks):

```sh
docker compose -f compose.yaml up --build
```

Changing `.env` needs `docker compose up -d` again (containers read it at creation), and Horizon
needs a restart after PHP changes in dev: `docker compose restart worker`.

## Tests and static analysis

All run inside the container against the real Postgres (database `app_test`) and Redis (db 9):

```sh
docker compose exec web php artisan test
docker compose exec web vendor/bin/pint --test
docker compose exec web vendor/bin/phpstan analyse
```

## Stack

PHP 8.4, Laravel 13.30, PostgreSQL 17, Redis 8, Laravel Horizon 5.48, Laravel Actions, Pest 5,
Pint, Larastan 3; React 19, TypeScript, Inertia 3, Tailwind 4, Radix UI, TanStack Query, Vite 8.
Every version named in the brief exists and installed cleanly; nothing was substituted.

## Scope cuts

Listed with reasons in [docs/PRD.md](docs/PRD.md#11-out-of-scope-with-reasons); the short version:
no manual retry button, no websocket push, no hosting deployment yet, no frontend unit tests
(TypeScript strict + ESLint instead), no virus scanning, and password reset / 2FA left out of auth.
