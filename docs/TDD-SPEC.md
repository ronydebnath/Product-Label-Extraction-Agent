# Spec-driven TDD playbook

How every behaviour in this repo gets built: requirement, then spec, then a failing test, then
the smallest implementation that passes, then refactor with the gates green. The test suite is
the executable specification; the PRD and design docs are its source.

## 1. The loop

1. Pick the next item from the backlog (section 5). It names a PRD requirement (FR-n or AC-n).
2. Write the spec line in the test file as the test description: `it('rejects a text file
   renamed to .jpg (FR-4)')`. Given/when/then in the body, one behaviour per test.
3. Run it inside the container and watch it fail for the right reason.
4. Implement the minimum. Business logic goes in an Action class; queue mechanics in the Job;
   HTTP in a controller Action; user-facing text in the `FailureCode` enum.
5. Run `php artisan test`, `pint --test`, `phpstan analyse`. Refactor while green.
6. Check the behaviour once in the running Docker stack when it has a visible effect.
7. Update docs touched by the change. Announce the commit point.

Never write the implementation first and back-fill tests. Never skip the failing run.

## 2. Test infrastructure (what exists and what to rely on)

- Pest 5 with `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')`.
  Feature tests boot the app and hit real Postgres (`app_test`, created by the postgres init
  script) and real Redis (database index 9). No sqlite anywhere.
- `phpunit.xml` sets `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=array`,
  `UPLOADS_DISK=local`. Tests that need the real Redis queue set `config(['queue.default' =>
  'redis'])` explicitly and flush db 9 in `beforeEach`.
- Fakes and helpers to create as they are needed:
  - `Tests\Support\FakeLlmClient` implementing `App\Llm\LlmClient`, scripted:
    `returns(array $json)`, `returnsRaw(string $text)`, `fails(Throwable $e)`,
    `sequence(array $outcomes)`, `calls(): int`, `lastRequest()`. Bound in `tests/Pest.php`
    `beforeEach` via `app()->instance(LlmClient::class, $fake)` so no test can reach OpenAI.
  - `Http::fake()` only in `OpenAiResponsesClient` tests, to map status codes to exceptions.
  - `Queue::fake()` in upload tests to assert dispatch without running the job.
  - `$job->withFakeQueueInteractions()` then `assertReleased()`, `assertNotReleased()`,
    `assertFailed()`, `assertDeleted()` for retry behaviour, with the job's `handle()` called
    directly.
  - `Storage::fake('local')` for upload persistence assertions; the `uploads` config keeps
    `disk = local` in tests.
  - Factories: `UserFactory` (skeleton), `UploadFactory` with states `queued()`,
    `processing(staleLease: bool)`, `completed()`, `failed(FailureCode)`, and
    `ExtractionFactory`.
  - `Tests\Support\PdfFixture::pages(int $n): string` builds a minimal valid PDF with n empty
    pages in memory (Pages tree with `/Count n`), so page-cap tests need no binary fixtures.
  - `tests/Fixtures/`: tiny committed files: `1x1.png`, `1x1.jpg`, `1x1.webp`, `one-page.pdf`,
    `not-an-image.txt`. For the pixel cap, hand-craft a PNG whose IHDR declares 6000x6000; the
    header is all `getimagesize` reads.
- Determinism: freeze time with `$this->travelTo(...)` or `Carbon::setTestNow()`. Backoff
  jitter goes through an injectable `RetryBackoff` policy so job tests bind a fixed delay and the
  policy has its own unit test with `mt_srand()`.
- Nothing sleeps, nothing touches the network, nothing depends on test order.

## 3. Naming and layout

```
tests/Feature/Auth/RegistrationTest.php         tests/Feature/Uploads/StoreUploadsTest.php
tests/Feature/Auth/LoginTest.php                tests/Feature/Uploads/ListAndShowTest.php
tests/Feature/Extraction/ProcessUploadJobTest.php
tests/Feature/Extraction/ExtractLabelDataTest.php
tests/Feature/Extraction/OpenAiClientTest.php
tests/Feature/Extraction/RetryBackoffTest.php
tests/Feature/Ops/RequeueStaleUploadsTest.php
tests/Support/FakeLlmClient.php  tests/Support/PdfFixture.php  tests/Fixtures/*
```

Descriptions read as spec lines and end with the requirement id in parentheses. Group with
`describe()` per requirement area when a file grows.

## 4. Definition of done, per stage

- All backlog rows for the stage are green, plus the gates (Pest, Pint, PHPStan; `tsc --noEmit`
  and ESLint once the frontend exists).
- The behaviour was exercised once in the Docker stack (upload a sample PDF, watch the worker).
- README and DECISIONS.md reflect any new command, limit or decision.
- SESSION-CONTINUITY.md status board and next action updated.
- Commit point announced; Rony commits.

## 5. Backlog: spec to test matrix

Status: `[ ]` todo, `[x]` done. Mandated by the brief: (M). Write them in the listed order.

### Stage 1 (done)

- [x] T1.1 Health endpoint answers 200 without database or queue (NFR-1).
- [x] T1.2 Manual: ping dispatched from web is handled by the worker container (AC-1).
- [x] T1.3 Manual: three worker replicas process each ping exactly once (AC-2).
- [x] T1.4 Manual: worker survives a Redis restart (AC-6, FR-15).

### Stage 2: auth and upload path

Auth (FR-1, FR-2)
- [ ] T2.1 Registers a user with name, email, password and starts a session.
- [ ] T2.2 Rejects registration with a duplicate email or a short password.
- [ ] T2.3 Logs in with valid credentials; rejects invalid ones; throttles after repeated failures.
- [ ] T2.4 Logs out and invalidates the session.
- [ ] T2.5 Guests are redirected from `/uploads` and get 401 JSON from `/api/uploads`.

Upload rejection (FR-4 to FR-7)
- [ ] T2.6 (M) `.txt` renamed to `.jpg` is rejected as `unsupported_type`; nothing stored, nothing dispatched.
- [ ] T2.7 Zero-byte file rejected as `file_empty`.
- [ ] T2.8 File over 10 MB rejected as `file_too_large` before any sniffing.
- [ ] T2.9 Twenty-one files rejected as `too_many_files` with no file processed.
- [ ] T2.10 PDF magic bytes with junk body rejected as `corrupt_file`; PNG header with junk body likewise.
- [ ] T2.11 Eleven-page PDF rejected as `too_many_pages`; ten pages accepted.
- [ ] T2.12 PNG declaring 6000x6000 rejected as `image_too_large`.
- [ ] T2.13 SVG and HEIC rejected even when the client declares `image/png`.

Upload acceptance (FR-3, FR-8, FR-9, FR-10)
- [ ] T2.14 JPEG, PNG, WebP and PDF are accepted: 201, one `queued` row each with sniffed mime,
      kind, size, sha256, page count for the PDF; file exists on the disk under a uuid path; the
      original name (including one containing `../`) is stored as display text only.
- [ ] T2.15 `ProcessUploadJob` is dispatched once per accepted file with the upload id, after commit.
- [ ] T2.16 Mixed batch of two valid and one invalid file: 201, both lists populated, only two rows.
- [ ] T2.17 All files invalid: 422 with per-file reasons.
- [ ] T2.18 Queue connection throwing at dispatch: row is `failed` with `queue_unavailable` and
      the response reports it.
- [ ] T2.19 Rows belong to the authenticated user; another user's upload id returns 404 on show.

### Stage 3: extraction agent

LLM client (FR-18, FR-19, FR-20)
- [ ] T3.1 429 with Retry-After maps to `LlmTransientException` carrying the delay; 500/502/503/504/408 map to transient; connection timeout maps to transient.
- [ ] T3.2 400/401/403/404/413/422 map to `LlmPermanentException` with `llm_rejected_request`.
- [ ] T3.3 Request shape: PDF sent as `input_file`, image as `input_image` data URL, strict
      `json_schema` present, model from config, upload id in metadata, key never logged.
- [ ] T3.4 Parses `output_text`; `incomplete` status and refusal map to `llm_invalid_output`.

Retry policy (FR-19)
- [ ] T3.5 Delays for attempts 1 to 4 fall in [7.5, 12.5], [22.5, 37.5], [67.5, 112.5], [202.5, 337.5] seconds; Retry-After larger than the computed delay wins.

Extraction action (FR-14, FR-16, FR-17, FR-21, FR-22)
- [ ] T3.6 Valid response yields a `LabelData` DTO; tokens and duration captured.
- [ ] T3.7 (M) Unparsable JSON text fails with `llm_invalid_output`.
- [ ] T3.8 (M) Valid JSON with a missing key, an unknown unit, or extra properties fails with `llm_invalid_output`.
- [ ] T3.9 `document_type = other` fails with `no_label_found`.
- [ ] T3.10 Identical content hash with the same model and prompt version reuses the extraction; the fake records zero calls.
- [ ] T3.11 Missing file on disk fails with `file_missing` and makes zero calls.

Job (FR-11 to FR-13, FR-19, FR-20, FR-23)
- [ ] T3.12 Happy path: `queued` to `completed`, one extraction row, `completed_at` set, attempts 1.
- [ ] T3.13 (M) Transient failure: row back to `queued`, attempts 1, `last_error` set, `assertReleased` with the policy's delay.
- [ ] T3.14 (M) Fifth transient failure: `assertFailed`, row `failed` with `llm_unavailable`.
- [ ] T3.15 Permanent failure: `assertFailed`, `assertNotReleased`, row `failed` with the specific code.
- [ ] T3.16 Job run against a `completed` or `failed` upload: zero calls, no new row, no change.
- [ ] T3.17 Upload already `processing` with a fresh lease: second run exits without a call; with a stale lease: takes over and increments attempts.
- [ ] T3.18 `failed()` hook receiving `MaxAttemptsExceededException` leaves the row terminal with a code, never stuck in `processing`.
- [ ] T3.19 Any other Throwable: row `failed` with `unexpected`, exception reported.
- [ ] T3.20 End to end on the real Redis queue: dispatch, `queue:work --once`, upload `completed`.

Sweeper (FR-32)
- [ ] T3.21 `queued` rows older than five minutes are re-dispatched; `processing` rows past the lease with attempts at the cap are failed; fresh rows are untouched.

### Stage 4: frontend (no unit tests by decision; Inertia assertions only)

- [ ] T4.1 `/uploads` renders the `Uploads/Index` page with the user's uploads only, newest first (FR-24).
- [ ] T4.2 `/uploads/{id}` renders `Uploads/Show` with extraction data for completed uploads and the failure message for failed ones; 404 for other users' ids and non-uuid ids (FR-27, FR-2).
- [ ] T4.3 `/api/uploads?ids=` returns status, attempts and message for the caller's uploads, never `last_error` (FR-26, FR-29).
- [ ] Manual: loading, empty, error and rejection states in the browser (FR-28); polling stops when all terminal.

### Stage 5: remaining matrix

- [ ] Every row above green; add any case discovered during Stage 4.
- [ ] `tsc --noEmit` and ESLint clean.

## 6. Anti-patterns we refuse

- Happy-path-only files. Every area starts with its rejection or failure tests.
- Mocking Eloquent or the database; the real schema and its constraints are part of the spec.
- Asserting on log strings or private state instead of observable outcomes (row status, dispatch, response).
- `sleep()` in tests, real network calls, or reading the OpenAI key from anywhere.
- Tests that pass because `Queue::fake()` or `sync` hid the behaviour under test; the retry
  tests call `handle()` directly with fake queue interactions for exactly this reason.
