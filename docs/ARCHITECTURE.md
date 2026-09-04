# Architecture

How the pieces fit together and which of them is allowed to know about which. For *why* any of it
is shaped this way, see [DECISIONS.md](../DECISIONS.md).

## Processes

Three long-running roles plus a one-shot, all built from **one image** and selected by the argument
to [`docker/entrypoint.sh`](../docker/entrypoint.sh). Whatever is tested is what ships.

```
                    ┌──────────┐
   browser ────────▶│   web    │  nginx + php-fpm      validates, stores, dispatches
                    └────┬─────┘  never runs a job
                         │ push job id
                    ┌────▼─────┐
                    │  redis   │  queue · cache · sessions   (appendonly, noeviction)
                    └────┬─────┘
                         │ pop
                    ┌────▼─────┐        ┌───────────┐
                    │  worker  │───────▶│  OpenAI   │  the only outbound call
                    │ (horizon)│◀───────└───────────┘
                    └────┬─────┘  scale for throughput
                         │
   ┌───────────┐    ┌────▼─────┐    ┌──────────────┐
   │ scheduler │───▶│ postgres │    │ object store │  uploaded bytes
   │  exactly 1│    └──────────┘    └──────────────┘  (named volume in dev)
   └───────────┘     source of truth
     uploads:sweep
```

`web` and `worker` share the uploads disk and the database and communicate through **nothing else**.
There is no in-process work, no shared memory, and no direct call between them: the only channel is
a row in Postgres plus a job id in Redis.

## Code layout, and the boundaries that matter

```
app/
  Actions/Uploads/      StoreUploads · ValidateUploadedFile · CreateUpload
                        ListUploads · ShowUpload · UploadStatuses
  Actions/Extraction/   ExtractLabelData
  Jobs/                 ProcessUploadJob            queue mechanics only
  Llm/                  LlmClient (interface) · OpenAiResponsesClient
                        LabelDataSchema · LabelDataValidator · RetryBackoff
                        LlmRequest · LlmResponse · Llm{Transient,Permanent}Exception
  Models/               Upload · Extraction · User
  Enums/                UploadStatus · FailureCode
  Http/Resources/       UploadResource              the only shape that leaves the server
  Console/Commands/     RequeueStaleUploads · QueuePing
resources/js/
  Pages/                Uploads/{Index,Show} · Auth/{Login,Register}
  Components/           UploadDropzone · UploadList · StatusBadge · ExtractionView · States
  lib/                  useUploadStatuses (polling) · format
```

Four boundaries are load-bearing:

- **Business logic lives in Actions; queue mechanics live in the Job.** `ProcessUploadJob` decides
  who owns a row, whether a failure is worth retrying, and how the row reaches a terminal state.
  It contains no knowledge of labels, schemas or HTTP. `ExtractLabelData` contains no knowledge of
  queues, which is why it is testable without one.
- **Only `OpenAiResponsesClient` names OpenAI.** Everything else depends on the `LlmClient`
  interface, so tests bind a fake and no test can spend money or fail because a third party is down.
- **Only `UploadResource` shapes outbound data.** One place to guarantee `last_error` never leaves
  the server, used by the Inertia pages and the JSON poller alike.
- **Only `FailureCode` produces user-facing failure text.** An exception message cannot reach a
  browser without going through a code that does not exist for it.

## Data model

```
users ──1:N──▶ uploads ──1:1──▶ extractions
```

**`uploads`** — uuid v7 primary key, which doubles as the correlation id in every log line.

| Column | Notes |
|---|---|
| `user_id` | FK, cascade delete. Every query is scoped through it. |
| `original_name` | Display only. Never used to build a path. |
| `mime_type`, `kind` | Sniffed from the bytes, not the extension. `kind` is `image` or `pdf`. |
| `size_bytes`, `page_count` | `page_count` null for images. |
| `content_hash` | sha256 of the bytes. Half of the dedupe key; indexed. |
| `storage_path` | `uploads/{Y}/{m}/{uuid}.{ext}`, outside the web root. |
| `status` | `queued` · `processing` · `completed` · `failed` |
| `attempts` | Incremented at **claim** time, not on success. |
| `processing_started_at` | The lease. Null unless processing. |
| `last_error` | Operators only. Capped at 500 chars. Never serialised outward. |
| `failure_code` | The `FailureCode` the UI renders from. |

Indexes: `(user_id, created_at)` for the list, `(status, created_at)` for the sweeper,
`content_hash` for reuse.

**Five CHECK constraints make illegal states unrepresentable**, rather than relying on application
code to be correct:

```sql
status IN ('queued','processing','completed','failed')
kind   IN ('image','pdf')
size_bytes > 0
(status = 'failed') = (failure_code IS NOT NULL)   -- failed iff there is a reason
status <> 'processing' OR processing_started_at IS NOT NULL   -- processing iff leased
```

**`extractions`** — `upload_id` is **unique**. That single constraint is the last line of defence
against a double write: even if every application guard failed, the second insert is rejected by
the database. `data` is `jsonb`, validated against `LabelDataSchema` before it is ever written.
`(content_hash, model, prompt_version)` is indexed, because those three together are the dedupe key.

## The request path

`POST /uploads` → [`StoreUploads`](../app/Actions/Uploads/StoreUploads.php)

1. Reject the whole request if it carries more than 20 files. Accepting the first 20 of 21 is a
   silent partial success.
2. Per file, [`ValidateUploadedFile`](../app/Actions/Uploads/ValidateUploadedFile.php) runs
   cheapest-first: PHP's upload result → size → sniffed MIME → structure (`pdfinfo` for PDFs,
   `getimagesize` for images). Nothing reads contents until the size cap has passed. Failure throws
   `InvalidUploadException` carrying a `FailureCode`; the loop continues with the next file.
3. [`CreateUpload`](../app/Actions/Uploads/CreateUpload.php) hashes, stores, inserts the row, then
   dispatches. **In that order** — a worker that picks the job up in the same millisecond must find
   the row. If dispatch throws, the row is marked `queue_unavailable` rather than left stranded.
4. 201 with `{accepted, rejected}`, or 422 when nothing was accepted. Same shape either way.

## The job path

`ProcessUploadJob(uploadId)` — the payload is an id and nothing else, so a job that waits through a
deploy cannot act on a stale copy of the record.

```
  row deleted ──────────────────────────────────▶ return, log
  status terminal ──────────────────────────────▶ return, log
  claim fails (someone else holds a live lease) ▶ return, log
        │ claim succeeds (CAS + lease, attempts += 1)
        ▼
  ExtractLabelData
        ├── ok ─────────────────▶ markCompleted()
        ├── LlmTransient ──┬── attempts < 5 ──▶ returnToQueue() + release(backoff)
        │                  └── attempts = 5 ──▶ markFailed(llm_unavailable) + fail()
        ├── LlmPermanent ───────▶ markFailed(code) + fail()
        └── Throwable ──────────▶ report() + markFailed(unexpected) + fail()
```

Plus `failed()`, the hook for when the queue gives up without `handle()` finishing — a killed
worker, a job timeout, attempts exhausted inside the queue. Without it a row could sit in
`processing` forever with nothing coming back for it.

## State machine

```
              ┌──────── release(backoff) ────────┐
              │                                  │
  queued ──claim──▶ processing ──────────────────┘
    │                   │
    │                   ├── extract ok ─────▶ completed   (terminal)
    │                   └── failure ────────▶ failed      (terminal)
    │
    └── dispatch threw ────────────────────▶ failed (queue_unavailable)
```

Every transition is **one conditional `UPDATE ... WHERE status = expected`** with an affected-rows
check — never read-then-save. Two workers handed the same job both run the claim; the database
serialises them and exactly one sees a row affected.

A row in `processing` whose lease is older than 120s is claimable again. That is how work from a
worker killed mid-job is recovered rather than stranded, and why attempts are counted at claim time:
a file that kills workers still exhausts its attempts instead of looping forever.

The timings are a ladder, and the order is the invariant:

```
LLM HTTP 60s  <  job timeout 90s  <  lease 120s  <  queue retry_after 150s
```

Each step leaves room for the previous one to finish and record what happened. Invert any pair and
a job can be redelivered while its first run is still talking to the model.

## Extraction

[`ExtractLabelData`](../app/Actions/Extraction/ExtractLabelData.php):

1. **Reuse check** on `(content_hash, model, prompt_version)`. A hit copies the data and records
   zero tokens and zero duration, because that is what it cost.
2. Load the bytes. Missing file → permanent `file_missing`; no LLM call is made.
3. Build an `LlmRequest`: PDFs as `input_file`, images as `input_image`, both as base64 data URIs,
   with `LabelDataSchema::schema()` as a strict `json_schema`.
4. `LabelDataValidator` parses the reply and validates it against **that same schema**. Unparsable
   or non-conforming → permanent `llm_invalid_output`, with a capped excerpt logged.
5. `document_type = other` → permanent `no_label_found`. A row of nulls is indistinguishable
   downstream from a real extraction of a blank label.

`PROMPT_VERSION` is part of the dedupe key: change the prompt or the schema and old results stop
matching, because the same bytes asked a different question are a different answer.

## Recovery

Three layers, because the queue is not trustworthy on its own:

| Layer | Catches |
|---|---|
| `ProcessUploadJob` catch blocks | anything `handle()` can see |
| `failed()` hook | the queue giving up without `handle()` finishing |
| `uploads:sweep`, every minute | jobs Redis lost, workers that died holding a lease |

The sweeper's staleness threshold (10 min) is deliberately longer than the maximum backoff (5 min),
or it would mistake a row patiently waiting out its retry for a lost one. Everything it does is safe
to run twice: a re-dispatched job that races a live one loses the claim and exits.

## Frontend

Inertia, so the same Laravel routes and the same authorisation serve typed React pages — no second
API and no token auth to keep in step. `UploadResource` is mirrored by the `Upload` interface in
`resources/js/types.ts`.

`useUploadStatuses` polls `/api/uploads?ids=…` every 2s, but **only for rows that can still change**.
When everything is terminal the id list is empty, the query is disabled, and an idle tab makes no
requests at all. The list is derived during render from three sources — the server's props, files
accepted in this tab, and the latest poll — with no effects synchronising copies of the same state.

## Configuration surface

| File | Owns |
|---|---|
| [`config/uploads.php`](../config/uploads.php) | caps, accepted types, disk, path prefix |
| [`config/llm.php`](../config/llm.php) | model, endpoints, timeouts, attempts, backoff, lease, sweeper |
| [`config/horizon.php`](../config/horizon.php) | supervisor, `maxProcesses` per container |
| `.env` | secrets and per-environment values only |

The caps quoted in the UI, enforced by the validator, and mirrored in `php.ini` all read from
`config/uploads.php`, so the interface cannot promise a limit the server does not enforce.
