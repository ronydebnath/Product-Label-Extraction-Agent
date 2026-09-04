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

## Treating the model as an untrusted dependency

Every call goes through an `LlmClient` interface. Nothing outside `OpenAiResponsesClient` names
OpenAI, and every test binds a fake, so no test can spend money or fail because a third party is
having a bad afternoon.

Failures are split in two, and the split is the whole retry strategy:

- **Transient** (429, 408, 5xx, connection failure or timeout) means the request was fine and the
  service was not. The job returns the row to `queued` and releases itself with exponential
  backoff and jitter: roughly 10s, 30s, 90s, 270s, each varied by +/-25%. Jitter matters because
  every job queued during one outage would otherwise come back at the same instant and cause the
  next one. A `Retry-After` header wins when it asks for longer than we would have waited, and is
  ignored when it asks for less, and is capped at five minutes so a hostile or mistaken header
  cannot strand an upload.
- **Permanent** (400/401/403/404/413/422, unparsable output, schema violation, refusal, truncation,
  a document the model says is not a label) fails immediately. Retrying spends money to arrive at
  the same place.

The client never retries internally. Retrying belongs to the queue, which knows the attempt number,
survives a process restart, and records what happened on the row. A client that hid its own retries
would make the attempt count a lie and multiply the real timeout by however many it hid.

**No repair-retry on malformed output.** Sending the broken JSON back and asking for a fix doubles
the cost and the latency of the worst case, has no bounded success rate, and makes the failure path
the least-tested code in the system. Strict `json_schema` already makes malformed output rare; when
it happens the honest answer is a clear failure and a logged excerpt, not another guess.

## Concurrency

Every status change is one conditional `UPDATE ... WHERE status = expected` with an affected-rows
check, never read-then-save. Two workers handed the same job both run the claim; the database
serialises them and exactly one sees a row affected. The other is told no and stops.

A claim stamps a lease. A row already in `processing` becomes claimable once its lease expires,
which is how work from a worker killed mid-job (OOM, deploy, SIGKILL) gets picked up instead of
being stranded. The attempt is counted at claim time, not on success, so a worker that dies
mid-call still burns an attempt and a poisonous file cannot loop forever.

The timing ladder holds this together, and the order matters:
`HTTP timeout 60s < job timeout 90s < lease 120s < queue retry_after 150s`. Each step leaves room
for the one before it to finish and record what happened, so a job is never redelivered while its
first run is still talking to the model.

Three things can still leave a row stranded, so there is a `failed()` hook for when the queue gives
up without `handle()` finishing, and a sweeper for jobs Redis lost. The sweeper's staleness
threshold (10 minutes) is deliberately longer than the maximum backoff (5 minutes), or it would
mistake a row patiently waiting out its retry for a lost one.

## Idempotency and dedupe

Status transitions are single `UPDATE ... WHERE status = expected` statements with an
affected-rows check, never read-then-save. A worker takes a row by moving it from queued to
processing and stamping a lease; a stale lease may be taken over. The `extractions.upload_id`
unique constraint makes double-writes impossible at the database level.

Dedupe key: sha256 of the bytes, plus model and prompt version. `PROMPT_VERSION` must be bumped
whenever the prompt or schema changes: the same bytes asked a different question are a different
answer, and reusing the old one would be wrong. A reused extraction records zero tokens and zero
duration, because that is what it cost; copying the original's counts would inflate every usage
total by every duplicate anyone ever uploaded. Filename and size are
user-controlled; the hash is the only identity the client cannot lie about. Identical bytes
reuse the existing extraction and never reach the LLM twice.

## Frontend

Inertia rather than a separate SPA with its own API and token auth: the app is four screens behind
a session, and Inertia lets the same Laravel routes and the same authorisation serve typed React
pages without a second contract to keep in step.

Status updates are polled every two seconds, not pushed. Websockets would need a broadcaster and a
persistent connection per open tab, for a page most people leave within a minute of uploading. The
poll asks only about rows that are still moving and disables itself entirely once everything is
terminal, so an idle tab makes no requests at all. If this became a page people leave open all day,
that is the point to reconsider.

Everything the browser sees goes through one `UploadResource`, so there is exactly one place to
check that `last_error` never leaves the server. Failure text on screen is always the mapped
sentence from `FailureCode::message()`, never an exception, and the caps quoted in the dropzone are
sent from `config/uploads.php` rather than hard-coded, so the UI cannot promise a limit the
validator does not enforce.

Absent fields render as "Not found on document" rather than as blanks. A null in this data means
the document did not say, which is information a reviewer needs, and an empty cell looks like a
bug in the extractor.

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
