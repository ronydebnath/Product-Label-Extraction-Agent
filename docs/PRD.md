# Label Extraction Agent — Product Requirements Document

| | |
|---|---|
| Status | Draft v1, agreed before implementation |
| Date | 2026-09-04 |
| Source | SupplyScope trial task brief ("Trial Task # 01 — Label Extraction Agent") |
| Companion | Technical design (data model, state machine, failure taxonomy, test matrix) in the Stage 0 design plan |

## 1. Summary

A small full-stack web application in which a signed-in user uploads one or more product label
images or PDFs. Each file is processed asynchronously by a separate worker process that calls an
LLM to extract structured product data (product name, brand, ingredients, allergens, net weight)
as JSON validated against a schema we define. The UI shows every upload with a live status
(queued, processing, completed, failed), a human-readable reason when something fails, and a
detail view of the extracted data.

The brief is explicit that this is not meant to be production-ready. It is an exercise in
engineering judgment: failure handling, idempotency, scalability reasoning, security of untrusted
input, testing of the unhappy paths, and clear documentation of trade-offs. Every requirement below
serves one of those goals or is a hard requirement of the brief.

## 2. Goals and non-goals

Goals

- G1. Satisfy every hard requirement of the brief (sections 1 to 5 and 7) with working, tested code.
- G2. Treat the LLM as a hostile, unreliable dependency and prove it: slow, down, timing out,
  rate-limited, or returning garbage must all produce a defined outcome the user can understand.
- G3. Treat every uploaded byte as hostile input.
- G4. Make the "separate worker" architecture visibly real: a distinct container, scalable to N,
  with no double-processing.
- G5. Make the code explainable line by line in a live review, and leave room for a small live change.
- G6. Ship within the 8 to 10 hour time-box by cutting scope deliberately and documenting the cuts.

Non-goals

- Production hardening beyond what the brief asks for (no multi-tenancy, no billing, no admin panel).
- A polished visual design. Usability and states matter; pixel work does not.
- Deploying to a hosting provider. Optional in the brief; parked until the app is complete.

## 3. Users

- **Uploader** (the end user): a supply-chain or product-data operator who has label images or
  spec-sheet PDFs and wants structured data without retyping it. Uploads a handful of files at a
  time, wants to see progress, wants to know why a file failed and whether to try again.
- **Reviewer** (the evaluating engineer): runs the stack from a clean machine with only Docker,
  reads the code and commit history, probes failure cases, and asks for a small change live.
- **Operator** (future): whoever answers "why is this upload stuck" in production. Served by
  logs, the Horizon dashboard, and a queue ping command.

## 4. User stories

- US1. As an uploader, I can register and log in, so my uploads are mine and not visible to others.
- US2. As an uploader, I can drop several images and PDFs at once and see each accepted file appear
  in my list immediately with the status "Queued".
- US3. As an uploader, when a file is rejected I see, per file, a plain-language reason, and the
  other files in the same batch are still accepted.
- US4. As an uploader, I can watch a file move from Queued to Processing to Completed without
  refreshing the page.
- US5. As an uploader, when extraction fails I see a reason I can act on ("The AI service was
  unavailable, please upload again") rather than an error code or stack trace.
- US6. As an uploader, I can open a completed file and see the product name, brand, ingredients,
  allergens and net weight, and any caveats the extraction noted.
- US7. As a reviewer, I can start everything with Docker Compose, scale the worker to three
  replicas, and observe that each job runs exactly once, in a worker container, never in web.
- US8. As a reviewer, I can run the test suite inside the container against real Postgres and Redis
  and see the unhappy paths covered.
- US9. As an operator, I can dispatch a ping job and see which container handled it, and I can
  find every log line for one upload by its id.

## 5. Functional requirements

Each requirement is numbered so tests and commits can reference it.

### 5.1 Authentication (decided in Stage 0)

- FR-1. Users register with name, email and password, and log in and out. Session-based auth using
  Laravel Fortify; no email verification, password reset, two-factor or social login.
- FR-2. Every upload belongs to the user who created it. Users only ever see, poll, or open their
  own uploads; another user's upload id returns 404, not 403, so ids are not confirmable.

### 5.2 Upload

- FR-3. The upload control accepts multiple files in one request, by picker and by drag-and-drop.
- FR-4. Accepted formats: JPEG, PNG, WebP, PDF. Determined by sniffing the bytes (MIME via
  libmagic plus our own magic-byte table), never by extension or the client-declared type.
- FR-5. Limits: 10 MB per file, 20 files per request, 10 pages per PDF, 25 megapixels per image.
  The same numbers appear in the UI copy, the validation rules, and the PHP/nginx request limits.
- FR-6. Structural validation beyond magic bytes: images must decode a valid header; PDFs must parse
  with `pdfinfo` and must not be encrypted. Failures are reported as "damaged or not a real
  image/PDF".
- FR-7. Validation is per file. A request with some invalid files accepts the valid ones and returns
  a per-file rejection reason for the rest. Rejected files are never stored and never queued.
- FR-8. Accepted files are stored outside the public web root under a uuid-based path. The
  original filename is kept for display only.
- FR-9. Each accepted file gets exactly one database row (status "queued") and exactly one queued
  job, dispatched only after the row is committed.
- FR-10. If the queue cannot be reached at dispatch time the row is recorded as failed with a
  "could not queue" reason and the response says so; the user is not left with a silently stuck row.

### 5.3 Queued processing

- FR-11. Jobs are pushed to Redis and executed by Laravel Horizon running in its own container.
  The web container never executes a job. Nothing happens synchronously in the request beyond
  validation and storage.
- FR-12. Any number of worker replicas may run at once. A job is processed by exactly one worker
  at a time; a redelivered or duplicated job finds the row already taken or already terminal and
  does nothing.
- FR-13. Processing is idempotent: running a job twice never produces a second extraction row,
  a second LLM charge, or a corrupted status.
- FR-14. Identical bytes uploaded again (same sha256, same model, same prompt version) reuse the
  existing extraction and complete without calling the LLM.
- FR-15. The worker survives a Redis restart; queued and reserved jobs survive it too (Redis
  persistence enabled).

### 5.4 Extraction

- FR-16. The worker sends the file to the LLM: images as image input, PDFs as native PDF input so
  every page is seen. All pages of a PDF go in one request.
- FR-17. The LLM is asked for JSON conforming to our schema (section 8) using strict structured
  output. The response is still validated server-side against the same schema; only a valid
  document is ever stored.
- FR-18. Timeouts are explicit: 5 s to connect, 60 s total per LLM call.
- FR-19. Transient failures (HTTP 429, 408, 5xx, timeouts, connection errors) are retried by the
  queue with exponential backoff plus jitter, honouring Retry-After, up to 5 attempts, then fail
  terminally with an "AI service unavailable" reason.
- FR-20. Permanent failures (HTTP 400/401/403/404/413/422, unparsable or schema-invalid output,
  refusal, truncated output) fail immediately with a specific reason. There is no repair-retry;
  the reasoning is documented in DECISIONS.md.
- FR-21. If the model reports the document is not a product label or spec sheet, the upload fails
  with "no product label found" rather than completing with empty fields.
- FR-22. Net weight refers to a single retail unit, never an outer carton. Allergens are reported
  as "contains" and "may contain". When the document's allergen statement is absent or incomplete
  the model may infer allergens from emphasised ingredients and must say so in a warning.
- FR-23. Token usage and latency are recorded per extraction.

### 5.5 Status, list and detail UI

- FR-24. The uploads page lists the current user's uploads, newest first, with filename, type,
  size, submitted time, status badge, and for failures the human-readable reason.
- FR-25. Status vocabulary shown to users: Queued, Processing, Completed, Failed. A queued upload
  waiting on a retry shows "Queued, retrying (n of 5)".
- FR-26. The list updates itself while any upload is not terminal, by polling every 2 seconds, and
  stops polling when all are terminal.
- FR-27. The detail page shows the extracted fields, warnings, the model used, and processing
  metadata (attempts, duration). Absent fields render as "Not found on document", never as an
  empty string or "null".
- FR-28. Required states: loading (initial fetch and upload in flight), empty (no uploads yet, with
  a prompt to upload), error (fetch failed, upload request failed, session expired), and per-file
  rejection feedback inline in the upload control.
- FR-29. Failure reasons shown to users come from a fixed table in code keyed by failure code.
  No exception message, HTTP body, or stack trace ever reaches the UI.

### 5.6 Operations

- FR-30. Every log line for an upload carries its id as a correlation id, from the upload request
  through each job attempt and the LLM call.
- FR-31. A `queue:ping` command dispatches a trivial job whose log line names the container that
  ran it.
- FR-32. A scheduled sweeper re-dispatches uploads stuck in "queued" and fails uploads whose
  processing lease expired past the attempt cap, so nothing stays "processing" forever.
- FR-33. The Horizon dashboard is available to signed-in users.

### 5.7 Documentation

- FR-34. README: what runs web, worker, queue, database and storage; exact commands to start the
  stack, run tests inside the container, and scale workers; what was cut and why.
- FR-35. DECISIONS.md, about one page: handling 50,000 simultaneous uploads, queue architecture
  rationale, LLM failure and retry strategy, idempotency and dedupe key, storage and image
  strategy, migrations placement, trade-offs.

## 6. Non-functional requirements

- NFR-1 Portability. `docker compose up` on a machine with only Docker brings up web, worker,
  Postgres and Redis (plus Vite in dev). One image serves web and worker. Non-root user, no secrets
  in any image layer, healthchecks gate startup, migrations run once per start in a one-shot service.
- NFR-2 Security. Sniffed MIME and magic bytes; size, count and page caps; storage outside the
  web root; uuid paths; per-user authorization; CSRF on state-changing requests; the OpenAI key
  only ever in `.env` or a runtime secret; LLM output treated as untrusted and validated.
- NFR-3 Reliability. Bounded retries with backoff and jitter; a distinct terminal failure path;
  compare-and-swap status transitions; database constraints for the invariants (failed implies a
  reason, processing implies a lease, one extraction per upload).
- NFR-4 Scalability posture. Job payloads carry only ids; the database is the source of truth;
  LLM concurrency is bounded by worker count times processes per worker; the 50k-upload scenario is
  analysed in DECISIONS.md (rate limiting, autoscaling workers, object storage, priority queues).
- NFR-5 Observability. Structured logs to stderr with correlation ids; Horizon metrics; a defined
  list of what to alert on (failure rate, queue wait, 401s from the LLM, stuck uploads).
- NFR-6 Testability. The LLM sits behind an interface with a scripted fake. Tests run inside the
  container against real Postgres and Redis. The mandated cases (unsupported type, malformed LLM
  response, job failure and retry) plus timeout, rate limit, retry exhaustion, duplicate job, and
  concurrent lease cases are covered.
- NFR-7 Code quality. Pint formatting, Larastan static analysis, TypeScript strict mode and ESLint
  as the frontend gate. Comments explain why, not what. Small, meaningful commits.

## 7. UX requirements

Pages: Login, Register, Uploads (list plus upload control), Upload detail.

Status badge semantics and copy:

| Status | Badge | Secondary text |
|---|---|---|
| queued | neutral | "Waiting for a worker", or "Retrying (2 of 5)" after a transient failure |
| processing | active, animated | "Extracting…" |
| completed | success | link to detail |
| failed | danger | the failure reason from the table below |

User-facing failure reasons (fixed table; the code keys are the contract with the backend):

| Code | Message |
|---|---|
| unsupported_type | Unsupported file type. Use JPEG, PNG, WebP or PDF. |
| file_empty | This file is empty. |
| file_too_large | File is larger than 10 MB. |
| too_many_files | Upload at most 20 files at a time. |
| corrupt_file | This file appears to be damaged or is not a real image or PDF. |
| too_many_pages | PDFs are limited to 10 pages. |
| image_too_large | Image dimensions are too large. |
| queue_unavailable | We could not queue this file. Try again in a minute. |
| llm_unavailable | The AI service was unavailable for too long. Please upload the file again. |
| llm_rejected_request | The AI service rejected the request. |
| llm_invalid_output | The AI returned data we could not understand. |
| no_label_found | We could not find a product label in this file. |
| file_missing | The uploaded file is no longer available. |
| unexpected | Something went wrong on our side. |

## 8. Data contract

Extracted data schema, version 1. Every key is required; fields that are not on the document are
`null`, never omitted and never empty strings.

```
document_type  "product_label" | "product_spec_sheet" | "other"
product_name   string | null
brand          string | null
ingredients    string[] | null
allergens      { contains: string[], may_contain: string[] } | null
net_weight     { value: number, unit: "g" | "kg" | "ml" | "l" | "oz" | "lb", raw: string } | null
warnings       string[]   (at most 5, each at most 200 characters)
```

Endpoints (all behind auth, JSON where noted):

- `POST /uploads` multipart, multiple files; 201 with accepted and rejected lists, 422 if none accepted.
- `GET /uploads` Inertia page with the initial list.
- `GET /uploads/{id}` Inertia detail page.
- `GET /api/uploads?ids=…` JSON status snapshot used for polling; returns status, attempts, failure message.
- `GET /horizon` dashboard, signed-in users only.

## 9. Constraints and assumptions

- Stack as specified by the brief's own stack: PHP 8.4, Laravel 13, PostgreSQL 17, Redis, Horizon,
  Laravel Actions, Pest, Pint, Larastan; React 19, TypeScript, Inertia 3, Tailwind 4, Radix,
  TanStack Query, Vite 8. All named versions exist and install cleanly; no substitutions.
- Single tenant. One shared application, users own their uploads. A tenant column is the first
  change a multi-tenant version would make.
- The OpenAI model is configured by environment variable and confirmed with one real call during
  implementation; the design does not depend on a specific model beyond vision and structured output.
- Sample inputs are three-page, image-only spec-sheet PDFs whose allergen section is deliberately
  incomplete, and one non-food product. The schema and prompt are designed around those.
- Time-box 8 to 10 hours. Scope cuts are listed in section 11 and repeated in the README.

## 10. Acceptance criteria

- AC-1. Clean machine, only Docker: copy `.env.example`, generate the app key, `docker compose up`;
  the app answers on port 8080 and the worker container logs show it picking up a `queue:ping` job.
- AC-2. `docker compose up --scale worker=3`, then upload 10 files: every upload completes or fails
  exactly once; no duplicate extraction rows; Horizon shows three supervisors.
- AC-3. Uploading a `.txt` renamed to `.jpg` is rejected with the unsupported-type message;
  the other files in the same batch are accepted.
- AC-4. With the LLM fake configured to return malformed JSON, the upload ends "Failed" with the
  invalid-output message and no extraction row exists.
- AC-5. With the fake returning HTTP 503 then success, the upload shows "Retrying (2 of 5)" and
  then completes; with 503 five times it ends "Failed" with the unavailable message.
- AC-6. Restarting Redis while the worker runs does not require restarting the worker; a job
  queued before the restart is processed after it.
- AC-7. `docker compose exec web php artisan test` passes against Postgres and Redis; the suite
  contains the mandated cases and no test contacts the real LLM.
- AC-8. README and DECISIONS.md exist, match the compose topology one-to-one, and document the cuts.

## 11. Out of scope, with reasons

| Item | Why it is cut | Where documented |
|---|---|---|
| Manual retry button on failed uploads | Re-upload achieves the same; a likely live-change request | README |
| Websocket status push | Polling needs no extra service at this scale; Reverb is the upgrade path | DECISIONS.md |
| Hosting deployment | Optional in the brief; decided later | README |
| Frontend unit tests | Time-box; TypeScript strict and ESLint are the gate | README |
| Virus scanning, image downscaling | Not required; the LLM never executes the bytes | README |
| Rate-limiting queue middleware | Documented as the 50k answer, not built | DECISIONS.md |
| Password reset, email verification, 2FA | Not required by the brief | README |
| Multi-tenancy, credits and metering | Company context, not task scope | DECISIONS.md |

## 12. Deliverables and milestones

1. Containers and schema: Dockerfile, compose files, entrypoint, migrations, models, Horizon config,
   Pint and PHPStan, queue ping proof.
2. Upload path: validation, persistence, dispatch, feature tests for accept and reject.
3. Extraction agent: LLM client interface with fake, schema validation, retry and timeout policy,
   idempotent job, failure mapping, sweeper.
4. Frontend: auth pages, uploads list with polling, detail page, all required states.
5. Tests: full matrix.
6. README, DECISIONS.md, review-call brief.

## 13. Risks and mitigations

- LLM output quality on spec sheets is uncertain: mitigated by strict structured output,
  server-side validation, the `document_type` gate, and warnings surfaced in the UI.
- Duplicate processing under retries or scaled workers: mitigated by compare-and-swap status
  transitions, a processing lease, and the unique extraction constraint; verified by tests and AC-2.
- Cost blow-up from hostile input: mitigated by size, count and page caps enforced before any
  LLM call, and by extraction reuse for identical bytes.
- Time-box overrun: mitigated by the scope table above and by building in the listed order so an
  incomplete later stage still leaves a coherent, tested core.

## 14. Open questions

- Which OpenAI model the provided key can use (confirmed during stage 3).
- Deployment target and timing (parked by decision on 2026-09-04).
