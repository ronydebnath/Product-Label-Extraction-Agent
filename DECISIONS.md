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
the tasks themselves so that a scaling mistake costs nothing. Verified rather than assumed: two
scheduler containers running a once-a-minute task produced two runs in two minutes, with the two
instances alternating as lock winner.

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

## Model choice

`gpt-5.4-mini`, behind `OPENAI_MODEL` so it can be changed without a deploy. Chosen by running the
same real spec sheet through the candidates rather than by reputation:

| | gpt-5-mini | gpt-5.4-mini |
| --- | --- | --- |
| latency, 3-page PDF | 16.1 s | 3.4 s |
| output tokens | 1170 (832 reasoning) | 186 (0 reasoning) |
| allergen split | wrong: copied `contains` into `may_contain` | correct: `may_contain` empty |

Both read all three pages, both returned schema-valid JSON, and both caught the document's
`VITAL NOT COMPLETED` allergen statement and inferred allergens from the ingredient list with a
warning saying so. The newer mini is five times faster for a sixth of the output tokens and got the
allergen structure right, so the cheaper model is also the better one here.

PDFs go to the Responses API as `input_file` with a base64 data URI. That is what makes the
page-count cap meaningful: OpenAI rasterises the document itself, so every page is seen in one
request and the worker image needs no Ghostscript, Imagick or GD.

Structured output is a strict `json_schema`, and the response is validated again server-side
against the same schema. The API accepts `maxItems` and `maxLength`, but accepting a keyword is not
the same as enforcing it, and a model is an untrusted dependency either way: the caps on `warnings`
are enforced by us.

## Upload validation

Checks run cheapest first: PHP's own upload result, then size, then sniffed type, then structure.
Nothing reads a file's contents until the size cap has passed, so an oversized upload is refused
without ever being parsed. Type comes from `finfo` over the bytes; the extension and the client's
declared mime type are attacker-controlled and are never consulted. PDFs must satisfy `pdfinfo`,
which reads the cross-reference table rather than trusting four magic bytes, and encrypted PDFs are
refused because they parse but will not render.

Images are validated by header only (`getimagesize`), not decoded. Decoding is what the dimension
cap exists to avoid: a 25-megapixel image costs far more memory to decode than to reject, and GD is
deliberately not in the image. The cost is that a file with a valid header and a corrupt body gets
as far as the model, which then fails it. That is the right place to pay it.

Rejection is per file (FR-7): one bad file in a batch of twenty does not cost the user the other
nineteen, and each rejection names the file they recognise. The file-count cap is the exception and
refuses the request whole, because accepting the first twenty of twenty-one is a silent partial
success the user cannot see.

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
  explained and none of which the task needs. Fortify itself is trimmed the same way: registration
  and login only. Password reset, email verification, profile updates, two-factor and passkeys are
  removed from `features`, and the migrations and published actions that served them are deleted
  rather than left dormant.
- Tests configure themselves with `<server force="true">` in `phpunit.xml`, not `<env>`. compose
  hands the container the whole of .env as real environment variables; PHP exposes those in
  `$_SERVER`, which Laravel's `env()` consults before `$_ENV`. With `<env>` the suite claimed
  `app_test` and actually ran against the development database on the real queue. Worth writing
  down because "the tests are isolated" was an assumption, and it was wrong until it was checked.
- poppler's `pdfinfo` in the image (about 30 MB) to validate PDFs structurally and count pages
  before any money is spent, instead of trusting magic bytes.
- Per-file rejection at upload time, nothing persisted for rejects: no LLM cost, no garbage rows.
