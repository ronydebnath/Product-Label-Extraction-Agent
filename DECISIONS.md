# Decisions

The four questions the project demands, answered with what this build actually does. Fuller reasoning
sits in the code comments next to the decisions themselves, and the Stage 0 plan is in
[docs/design.md](docs/design.md).

## 50,000 uploads arriving at once

Measured here: one 3-page spec sheet takes **3.4–5.3 s** and about **3,200 input tokens** on
`gpt-5.4-mini`. Concurrency is `maxProcesses` (5) x worker replicas, so today's `--scale worker=3`
gives 15 in flight. 50,000 documents at that rate is **~4.6 hours**; finishing in 30 minutes needs
roughly 140 concurrent calls.

That is the processing side. **Arriving** is a separate problem with earlier failure modes. The web
tier is stateless, so it scales by adding replicas behind a load balancer — but each replica's
php-fpm pool bounds its concurrency, and every in-flight request holds a Postgres connection, so
50,000 arrivals become 50,000 connection attempts long before they become a throughput question.
The fixes are ordinary: more web replicas rather than a bigger pool, pgbouncer in front of Postgres
so connections are pooled rather than multiplied, and presigned uploads so the ~15 GB of bytes never
touches PHP at all. The existing caps (10 MB and 20 files per request, 210 MB body limit in nginx)
already bound what any single request can cost.

Past the front door the bottleneck is not PHP: validation and storage are milliseconds, the payload
is a 36-byte id, and 50,000 of those in Redis is a few megabytes. What breaks, in order, is **the
provider's rate limit**, then **spend** (~160M input tokens), then **object storage throughput**. So:

- **Cap concurrent LLM calls with `Redis::throttle()` queue middleware.** This is the one piece
  that must exist. Without it, scaling workers converts a throughput problem into 50,000 requests
  hitting a 429 and burning all five attempts each — strictly worse than being slow.
  **Documented, not built**: it is ten lines, but it cannot be honestly demonstrated without a
  rate limit to hit.
- **Separate queues for bulk and interactive work**, so one importer's batch does not put a single
  user's upload behind 50,000 others.
- **Scale `worker` horizontally** and raise `maxProcesses` to whatever the throttle allows.
- **Backpressure at the edge**: a per-user rate limit on `POST /uploads`, so queue depth is a
  decision rather than an accident.
- **Presigned direct-to-object-storage uploads**, so the bytes never pass through PHP.

Two things already help: identical bytes reuse an existing extraction without reaching the model,
and the database only does indexed inserts and primary-key reads, so Postgres is not what falls
over. What does not change is honesty to the user: with a queue this deep an upload sits in
`Queued` for hours, and the status vocabulary says exactly that rather than pretending to progress.

## Why this queue architecture

Redis and Horizon, in a **separate container** from the same image, differing only by command. The
web process never runs a job.

Redis because it is already the cache and session store, and because popping a job is O(1) — a
database-backed queue would have workers polling one table under exactly the load that matters.
Horizon for supervisors, autoscaling, failed-job retention and a dashboard. It all sits behind
Laravel's queue abstraction, so moving to SQS later is config, not a rewrite.

**The job payload is an upload id and nothing else.** The row is the source of truth, so a job that
waits through a deploy cannot act on a stale copy of the record.

Correctness under replicas comes from the database, not the queue. Every status change is one
conditional `UPDATE ... WHERE status = expected` with an affected-rows check: two workers handed the
same job both run the claim and exactly one wins. Claiming stamps a lease, and an expired lease can
be taken over — that is how work from a worker killed mid-job is recovered rather than stranded.
`extractions.upload_id` is unique, so a double write is impossible even if every guard above failed.

The timings are a ladder and the order matters:
**HTTP 60s < job timeout 90s < lease 120s < queue retry_after 150s.** Each step leaves room for the
one before it to finish and record what happened, so a job is never redelivered while its first run
is still talking to the model.

## LLM failures and retries

The model sits behind an `LlmClient` interface; nothing else names OpenAI, and every test binds a
fake, so no test can spend money. Failures split in two, and that split *is* the strategy:

- **Transient** — 429, 408, 5xx, connection failure or timeout. The row returns to `queued` and the
  job releases itself with exponential backoff plus jitter (~10s, 30s, 90s, 270s, each ±25%), up to
  5 attempts, then fails as `llm_unavailable`. Jitter matters: without it every job queued during
  one outage returns at the same instant and causes the next one. A `Retry-After` header wins when
  it asks for longer, is ignored when it asks for less, and is capped at five minutes.
- **Permanent** — 400/401/403/404/413/422, unparsable output, schema violation, refusal, truncation,
  or a document the model says is not a label. These fail immediately. Retrying spends money to
  arrive at the same place.

The client never retries internally: that belongs to the queue, which knows the attempt number and
survives a restart. **There is no repair-retry** on malformed JSON — it doubles the worst case's
cost and latency, has no bounded success rate, and makes the failure path the least-tested code in
the system. Strict `json_schema` makes malformed output rare, and the response is validated again
server-side against the same schema before anything is stored.

Two backstops catch the rest: a `failed()` hook for when the queue gives up without `handle()`
finishing, and a scheduled sweeper for jobs Redis lost or workers died holding. Internal detail goes
to `last_error` and the logs; users only ever see a fixed sentence from `FailureCode`.

## Trade-offs

- **Validation is header-deep for images.** `getimagesize` reads the header and stops, because
  decoding is what the 25-megapixel cap exists to avoid. A valid header over a corrupt body reaches
  the model, which fails it. Cheaper there than carrying GD in the image.
- **`pdfinfo` in the image** (~30 MB of poppler) to prove a PDF parses and count pages before
  spending anything, instead of trusting four magic bytes.
- **Per-file rejection, nothing stored for rejects.** One bad file in twenty does not cost the user
  the other nineteen. The file-count cap refuses the request whole, because a silent partial
  success is worse than a clear refusal.
- **Polling every 2s, not websockets.** A broadcaster and a connection per tab is real
  infrastructure for a page most people leave within a minute. The poll asks only about rows that
  can still change and stops when everything is terminal.
- **Fortify, not the starter kit**, which brings Wayfinder, passkeys and two-factor — none needed,
  all of which I would have to explain.
- **One image for web, worker and scheduler**, differing only by an entrypoint argument, so what is
  tested is what ships. **Migrations run in a one-shot service**, not an entrypoint: three replicas
  starting together would race the same DDL and two would crash-loop.
- **Deliberately not built**: retry button, websockets, virus scanning, cloud deployment, frontend
  unit tests (TypeScript strict and ESLint instead), password reset and 2FA. Reasons in
  [docs/PRD.md](docs/PRD.md#11-out-of-scope-with-reasons).
