# Technical design (Stage 0 plan, approved 2026-09-04)

Companion to docs/PRD.md. Written before any code; kept as the reference for the data model,
state machine, action/job decomposition, failure taxonomy and test matrix. Where the code and this
document disagree, the code plus DECISIONS.md win and this file should be updated.

## What the inputs told us

- The four sample PDFs are 3-page, image-only "Product Specification Sheets" (Chrome "Save as PDF" of
  rendered pages; zero fonts, zero text streams). Text extraction is useless; the model must see pages.
- Data is spread across pages: name / brand / pack size on page 1, ingredient declaration on page 2,
  allergen section on pages 2–3 reads "VITAL NOT COMPLETED". Allergens must be inferred from the bolded
  ingredients (Fish, Wheat, Milk). Every page must go to the model in one request.
- One sample is a household surface spray ("NOT FOR HUMAN CONSUMPTION"): ingredients and allergens are
  legitimately absent. The schema must distinguish "not on the document" from "empty".
- Net weight is ambiguous on the fish sheet: "NET Weight / Pack: 800 g" vs "800g x 4 bags / carton".
- Every named version exists and supports Laravel 13: laravel/framework 13.30, inertia-laravel 3.3 +
  @inertiajs/react 3.7, React 19.2, Vite 8.2, Tailwind 4.3, Horizon 5.48, laravel-actions 2.12,
  Pest 5.1 (requires PHP 8.4), Larastan 3.11, Pint 1.30. No substitutions. Docker 29.7 / Compose 5.4 local.
- The brief contains the OpenAI key in plain text. It goes into `.env` only.
- OpenAI's Responses API accepts PDFs natively (`input_file`, base64 data URI, up to 100 pages / 32 MB
  per request) and rasterises pages itself. We do not need Ghostscript/Imagick in the worker.

## Decisions confirmed (2026-09-04)

Confirmed by Rony: rejected files are rejected synchronously per file and never persisted; duplicate
bytes get a new row that reuses the existing extraction (sha256 + model + prompt_version); net weight is
the single retail unit with a raw string; schema extras (document_type, contains/may_contain, warnings)
are in; caps are 10 MB / 20 files / 10 pages / 25 MP; model is env-configured and verified with one real
call in Stage 3; plain Laravel Job + Actions. Sweeper built, FE unit tests skipped, no retry button, no
websockets, no cloud deploy, no virus scanning, RateLimited middleware documented not built.
Resolved after this was written: user authentication IS in scope (Fortify, session auth, uploads
scoped per user); hosting deployment is parked until the app is complete.
Every decision here is to be carried into DECISIONS.md in the repo.

## Open questions (as originally asked, each with my recommendation)

1. Auth / tenancy. Nothing in the brief. Recommend: add  auth, one global list, documented as out of scope,
   with a note that `tenant_id` on `uploads` is the first column a multi-tenant version adds.
2. Rejected files. Reject synchronously per file (HTTP 422 entries in the response, nothing persisted),
   or persist them as `failed` rows so they appear in the list? Recommend synchronous per-file rejection:
   no garbage rows, no LLM cost, instant feedback. A mixed batch accepts the good files.
3. Duplicate content (same bytes uploaded twice). (a) New row, reuse the existing extraction, no LLM
   call. (b) Reject as duplicate. (c) Always re-extract. Recommend (a): the user still sees their upload,
   and we never pay twice for identical bytes. Key = sha256 + model + prompt_version.
4. Net weight semantics. Single retail unit (800 g), not the outer carton. Recommend retail unit, plus a
   `raw` string so the reviewer sees exactly what the model read.
5. Schema extras beyond the five required fields. `document_type` (product_label | product_spec_sheet |
   other; "other" becomes a terminal "no label found" failure), allergens split into `contains` /
   `may_contain`, and a bounded `warnings[]` for things like "allergen statement not completed; inferred
   from bold ingredients". Recommend all three, each driven by the sample files. No confidence scores.
6. Caps. 10 MB per file, 20 files per request, 10 pages per PDF, 25 megapixels per image.
7. Model. Which model does the key have access to? Recommend a mini-tier vision model behind
   `OPENAI_MODEL`, verified with one real call in Stage 3.
8. Job class. Plain Laravel Job for queue mechanics (tries / timeout / release / failed hook, the
   documented API) with Actions for the business logic, or the `AsJob` action decorator for the job too?
   Recommend plain Job + Actions: fewer layers to explain live.

Scope I am assuming, object if you disagree: build the stuck-upload sweeper command (about 40 lines);
skip frontend unit tests in favour of `tsc --noEmit` + ESLint; no retry button, no websockets, no cloud
deploy, no virus scanning, no image downscaling; `RateLimited` queue middleware documented as the 50k
answer, not built.

## Key decisions

- Dedupe key: sha256 of the bytes. Filename and size are user-controlled; the hash is the only identity
  the client cannot lie about. Stored on `uploads`, denormalised onto `extractions` with model and
  prompt_version so a prompt change invalidates reuse automatically.
- Retries belong to the queue, not the HTTP client. The HTTP client makes zero in-process retries: an
  in-process sleep holds a worker slot and eats the job timeout. Transient failures release the job with
  backoff (worker freed).
- Backoff: 5 attempts, delay = max(Retry-After, 10 s x 3^(n-1) with +-25% jitter): about 10, 30, 90, 270 s.
  Worst case about 7 minutes before terminal failure. Jitter de-synchronises the herd after an outage.
- Timing ladder (must hold or jobs double-process): HTTP total 60 s < job timeout 90 s < lease stale 120 s
  < Horizon retry_after 150 s.
- No repair-retry on malformed output. With strict structured outputs the shape cannot be wrong; the
  remaining malformed cases (refusal, truncation, empty) are not fixed by asking again. A systematic prompt
  or schema bug should fail loudly, not be papered over at 2x cost. Server-side schema validation stays,
  because the API contract is not ours to trust. This is the most likely "small change" in the review.
- Failure text lives in code, not in the DB. `failure_code` is an enum; `FailureCode::message()` is the
  only place user-facing text exists. Nothing from an exception message is persisted where the UI reads it.
- Migrations run in a one-shot `migrate` compose service; web and worker depend on it with
  `service_completed_successfully`. Three replicas migrating in an entrypoint race the same DDL
  (Laravel's migrator has no cross-process lock unless `--isolated`, which itself needs Redis/DB cache):
  two lose with "relation already exists" and crash-loop.
- `web` runs nginx + php-fpm under supervisord in one container (Cloud Run's one-container model);
  `worker` runs `php artisan horizon`. Same image, same entrypoint, different argument.
- Uploads on a private disk backed by a named volume, path `{Y}/{m}/{uuid}.{ext}`. Never the original name.
- Redis with `--appendonly yes` on a named volume so reserved jobs survive a restart.
- PDF validation via poppler `pdfinfo` in a subprocess (timeout 5 s): proves the file parses, rejects
  encrypted PDFs, gives the page count. Magic bytes alone are trivially spoofed.

## Data model

Table `uploads` (uuid v7 primary key: time-ordered, unguessable in URLs, doubles as the correlation id):

| column | type | notes |
| --- | --- | --- |
| id | uuid PK | v7; correlation id in every log line and in OpenAI request metadata |
| original_name | varchar(255) | display only, never used for paths |
| mime_type | varchar(64) | sniffed, never client-supplied |
| kind | varchar(8) | check in (image, pdf); drives the LLM input type |
| size_bytes | bigint | check > 0 |
| page_count | smallint null | PDFs only |
| content_hash | char(64) | sha256 hex, indexed |
| storage_path | varchar(255) | relative to the private uploads disk |
| status | varchar(16) | check in (queued, processing, completed, failed) |
| attempts | smallint | default 0; LLM attempts made, our count |
| processing_started_at | timestamptz null | lease start; stale after 120 s |
| last_error | varchar(255) null | sanitised internal note for ops, never shown in UI |
| failure_code | varchar(32) null | enum value |
| completed_at, failed_at | timestamptz null | |
| created_at, updated_at | timestamptz | created_at is queued_at |

Constraints: `CHECK ((status = 'failed') = (failure_code IS NOT NULL))`,
`CHECK (status <> 'processing' OR processing_started_at IS NOT NULL)`, `CHECK (size_bytes > 0)`.
Indexes: `(status, created_at)` for the list and the sweeper; `(content_hash)` for reuse.
Status is a varchar + CHECK, not a Postgres enum: adding a value is one constraint swap, PG enums cannot
be reordered or shrunk.

Table `extractions`:

| column | type | notes |
| --- | --- | --- |
| id | uuid PK | |
| upload_id | uuid unique FK -> uploads, cascade | uniqueness is the DB-enforced "written at most once" |
| content_hash | char(64) | denormalised for reuse lookup |
| model | varchar(64) | |
| prompt_version | smallint | bump invalidates reuse |
| data | jsonb | schema-valid LabelData only |
| input_tokens, output_tokens | int null | cost per file |
| duration_ms | int null | LLM latency |
| created_at | timestamptz | |

Index: unique `(upload_id)`; `(content_hash, model, prompt_version)`.
Why a second table: the uploads row is hot and small (polled every 2 s); the extraction is written once
and read on the detail view; the unique FK is the double-write guard; versioned re-extraction later is
"drop the unique". Laravel's `failed_jobs` table stays for `queue:retry`.

## LabelData schema (v1)

```
document_type: "product_label" | "product_spec_sheet" | "other"
product_name:  string | null
brand:         string | null
ingredients:   string[] | null        (null = not on document; [] never returned)
allergens:     { contains: string[], may_contain: string[] } | null
net_weight:    { value: number, unit: "g"|"kg"|"ml"|"l"|"oz"|"lb", raw: string } | null
warnings:      string[]               (max 5, max 200 chars each)
```
Strict JSON schema: `additionalProperties: false`, every key required (nullable where absent), string
length caps, array length caps. One PHP array is the single source of truth: it is sent to OpenAI as
`text.format` json_schema strict and used server-side by opis/json-schema.

## State machine

```
 web request                 worker CAS                       single tx
 ----------> queued ---------------------------> processing ----------------> completed
               ^                                    |
               |  transient LLM error (released)    |  non-retryable
               +------------------------------------+-----------------> failed
               |                                                          ^
               +--- retries exhausted / sweeper --------------------------+
```

| from -> to | actor | guard (SQL WHERE) | side effects |
| --- | --- | --- | --- |
| none -> queued | web, in tx | | file on disk, row insert, dispatch afterCommit |
| queued -> processing | worker | status='queued' OR (status='processing' AND processing_started_at < now()-120s) | processing_started_at=now, attempts+1; 0 rows affected means another worker holds it: exit silently |
| processing -> completed | worker, in tx | status='processing' | insert extraction, completed_at |
| processing -> queued | worker | status='processing' | last_error, release with backoff |
| processing -> failed | worker | status='processing' | failure_code |
| queued/processing -> failed | failed() hook or sweeper | status in (queued, processing) | failure_code |
| completed/failed -> anything | nobody | terminal | a re-delivered job sees terminal and exits |

Every transition is one `UPDATE ... WHERE status = expected` with an affected-rows check. Never
read-then-save a status. Retryable errors go back to `queued` (not "processing") so the UI is truthful
("queued, retry 2 of 5") and only a live attempt ever holds a lease. Invariants: completed implies an
extraction row exists; failed implies failure_code; processing implies a lease timestamp.

## Action / job decomposition

Web (Laravel Actions):
- `StoreUploads` (asController, POST /uploads): per file `ValidateUploadedFile` then `CreateUpload`;
  responds 201 with `{accepted[], rejected[]}` if at least one accepted, 422 if none.
- `ValidateUploadedFile`: size bounds; MIME via finfo AND our own magic-bytes table; images must pass
  `getimagesize` and the dimension cap; PDFs must pass `pdfinfo` (subprocess, 5 s timeout), not encrypted,
  pages within cap. Returns a `ValidatedFile` DTO or throws `InvalidUploadException(FailureCode)`.
- `CreateUpload`: sha256, store on private disk, insert row inside a transaction, dispatch
  `ProcessUploadJob` afterCommit. Dispatch failure marks the row failed(queue_unavailable).
- `ListUploads` (Inertia page), `ShowUpload` (Inertia detail), `UploadStatuses` (JSON, polled).

Worker:
- `ProcessUploadJob` (plain Job, payload = upload id, tries 5, timeout 90): acquire lease (CAS);
  `ExtractLabelData`; `markCompleted`. Catch transient: back to queued, delay = max(Retry-After,
  jittered exponential); on last attempt `fail($e)` else `release($delay)`. Catch permanent: markFailed +
  `fail($e)`. Catch Throwable: markFailed(unexpected). `failed()` hook makes the row terminal for the
  cases that bypass handle() (MaxAttemptsExceeded, timeout kill).
- `ExtractLabelData` (Action): reuse lookup by (content_hash, model, prompt_version); load bytes
  (missing -> permanent file_missing); build `LlmRequest` (image as data-URL `input_image`, PDF as
  `input_file`, strict json_schema); call `LlmClient`; validate with the schema; `document_type=other`
  -> permanent no_label_found; return `LabelData`.
- `LlmClient` interface; `OpenAiResponsesClient` (Laravel Http, connectTimeout 5 s, timeout 60 s, no
  in-process retries, maps HTTP to `LlmTransientException(retryAfter?)` for 408/429/5xx/connection errors
  and `LlmPermanentException(code)` for 400/401/403/404/413/422, parse failures, refusal, incomplete);
  `FakeLlmClient` (scripted outcomes, call counter) bound in tests.
- `LabelDataSchema` + `PROMPT_VERSION` constant; `FailureCode` string enum with `message()`.
- `RequeueStaleUploads` command (scheduled every minute): re-dispatch `queued` rows older than 5 min;
  fail `processing` rows whose lease is 10+ minutes stale and attempts >= 5, else re-dispatch.
- Horizon: one supervisor, `maxProcesses` 5 per container, `balance` auto; `--scale worker=3` gives 15
  concurrent LLM calls at most.

Frontend (Stage 4): pages `Uploads/Index` (dropzone + list) and `Uploads/Show`; components
`UploadDropzone`, `UploadList`, `UploadRow`, `StatusBadge`, `ExtractionView`, `EmptyState`, `ErrorState`;
hooks `useUploadStatuses(ids)` (TanStack Query, refetchInterval 2 s while any upload is non-terminal)
and `useUploadFiles()` mutation.

## Failure taxonomy

Upload time, HTTP 422 per file, nothing stored:

| situation | failure_code | user sees |
| --- | --- | --- |
| sniffed MIME not JPEG/PNG/WebP/PDF (txt, docx, svg, heic, animated gif) | unsupported_type | Unsupported file type. Use JPEG, PNG, WebP or PDF. |
| 0 bytes | file_empty | This file is empty. |
| over 10 MB | file_too_large | File is larger than 10 MB. |
| over 20 files | too_many_files | Upload at most 20 files at a time. |
| magic bytes / getimagesize / pdfinfo disagree or fail, encrypted PDF | corrupt_file | This file appears to be damaged or is not a real image or PDF. |
| PDF over 10 pages | too_many_pages | PDFs are limited to 10 pages. |
| image over 25 MP | image_too_large | Image dimensions are too large. |
| Redis down at dispatch (row persisted as failed) | queue_unavailable | We could not queue this file. Try again in a minute. Alert. |

Job time, transient: release with backoff, terminal `llm_unavailable` after 5 attempts:

| situation | handling |
| --- | --- |
| 429 | honour Retry-After; alert on rate spike (throughput problem, not a bug) |
| 500 / 502 / 503 / 504 / 408, connect or read timeout, DNS or connection refused | backoff |
| DB connection lost mid-job | backoff (job throws, Laravel retries) |
| worker killed mid-job (OOM, deploy, SIGKILL) | lease expires; redelivered job takes over; counts as an attempt |
| Redis restart | worker reconnects (verified in Stage 1); AOF keeps reserved jobs; sweeper covers loss |

While queued for retry the UI says "Queued, retrying (2 of 5)". After exhaustion: "The AI service was
unavailable for too long. Please upload the file again."

Job time, permanent, fails immediately:

| situation | failure_code | user sees | ops |
| --- | --- | --- | --- |
| 401 / 403 | llm_rejected_request | The AI service rejected the request. | page: every job will fail |
| 400 / 413 / 422 / 404 (unknown model) | llm_rejected_request | same | alert |
| JSON parse error, schema violation, incomplete (truncated), refusal | llm_invalid_output | The AI returned data we could not understand. | log 16 KB excerpt, metric |
| document_type = other | no_label_found | We could not find a product label in this file. | none |
| file missing on disk | file_missing | The uploaded file is no longer available. | alert: volume problem |
| any other Throwable | unexpected | Something went wrong on our side. | Sentry |

## Test matrix

Pest feature tests in the container against real Postgres and Redis; the LLM is always the fake.
Mandated cases marked (M).

Upload path:
1. Accepts JPEG, PNG, WebP, PDF fixtures: 201, rows queued, files on the private disk, job pushed with the upload id.
2. (M) `.txt` renamed to `.jpg`: 422 unsupported_type, nothing stored, nothing dispatched.
3. PDF magic bytes with junk body: corrupt_file. Encrypted PDF: corrupt_file.
4. 11-page PDF rejected, 10-page accepted.
5. Over 10 MB rejected; 0-byte rejected.
6. 21 files rejected; mixed batch of 2 good + 1 bad: 201, both lists, only good rows exist.
7. SVG and HEIC rejected even with an `image/*` client MIME.
8. Stored path is uuid-based; an original name containing `../` is stored as display text only.
9. Queue connection throwing at dispatch: row failed(queue_unavailable), response reports it.

Job path (fake LLM, `withFakeQueueInteractions`):
10. Valid response: completed, extraction row, tokens and duration recorded.
11. (M) Malformed JSON text: failed llm_invalid_output, no extraction row, assertFailed, not released.
12. (M) Valid JSON, wrong shape or bad unit enum: same terminal path.
13. Refusal / incomplete response: llm_invalid_output.
14. (M) Transient 503: status back to queued, attempts 1, last_error set, assertReleased with delay in the jittered range.
15. 429 with Retry-After 30: released with delay of at least 30.
16. Timeout (ConnectionException): released.
17. (M) Retry exhaustion: 5th transient failure: assertFailed, status failed, llm_unavailable.
18. Permanent 400 / 401: failed llm_rejected_request, assertNotReleased.
19. document_type other: no_label_found.
20. File missing on disk: file_missing, zero LLM calls.
21. Idempotency: job run against a completed upload: zero LLM calls, no second extraction row, no state change. Same for failed.
22. Concurrency: upload already processing with a fresh lease: second run exits without an LLM call; with a stale lease: takes over, attempts incremented.
23. Content-hash reuse: second upload with identical bytes completes with zero LLM calls and a copied extraction.
24. `failed()` hook with MaxAttemptsExceededException: row terminal, failure_code set, never stuck in processing.
25. Real queue: dispatch to real Redis, `queue:work --once` inside the test, upload completed.

LLM client (Http::fake):
26. 429 maps to transient with retryAfter; 500 transient; 400 permanent; timeout transient.
27. Request shape: PDF as input_file, image as input_image data URL, strict json_schema present, key from config and never logged.
28. Parses output_text; handles incomplete status.

API / UI / ops:
29. Status endpoint returns statuses and the human message, never last_error; 404 for unknown and for non-uuid ids.
30. Sweeper: stale queued row re-dispatched; stale processing row past the attempt cap failed.

Static gates: Pint, PHPStan (Larastan) level 6, `tsc --noEmit`, ESLint.