# NOTES

## 1. Classifier failure

After up to three attempts (parse JSON + whitelist + catch timeout), we create an `open` reply task with `sentiment = null` and still claim the event. We do **not** suppress the client or stop enrollments.

False-positive `unsubscribe` from a flaky LLM is worse than a manager reading an unclassified reply. The schema already allows nullable sentiment. Claiming the event avoids infinite at-least-once reprocessing of the same LLM call.

**Client / campaign:** the client is resolved by `tenant_id + lowercased sender email`. A campaign enrollment is not required to create a Reply Center task: if the client exists, the reply is actionable regardless of the client's current enrollment state. Enrollment data is used when processing a confirmed `unsubscribe`; we set `suppressed_at` and stop any **active** enrollments for that `tenant_id + client_id` (no-op if none). Unknown sender (bounce / self-loop) → claim, no task and no cross-tenant writes.

`Auto-Submitted` / other headers are intentionally **not** used as a shortcut around `SentimentClassifier` — the assignment requires classification through that contract.

## 2. Idempotency

Unique indexes on `processed_events (tenant_id, event_id)` and `reply_tasks (tenant_id, event_id)`. Ownership is taken with `INSERT … ON CONFLICT DO NOTHING` (`insertOrIgnore`) and checking affected rows — no exception-driven control flow inside a Postgres transaction (which would abort the TX).

Side effects (task create, unsubscribe) run in the same transaction only after a successful claim. Concurrent workers: one wins the insert, the other gets `affected = 0` and exits.

Verified with a sequential double `dispatchSync` (one task / one processed row). No two-connection race test: `RefreshDatabase` hides uncommitted rows from a second PDO. That does **not** mean missing concurrency protection — correctness under two workers is the unique constraint + atomic `INSERT … ON CONFLICT DO NOTHING`, not application timing.

**Fixture run:** all 12 events from `tests/Fixtures/inbound_events.json` were processed via `ProcessInboundReplyJob` (covered by `test_fixture_suite_produces_expected_counts`). Result: 11 unique `event_id`s claimed; 9 reply tasks (no task for bounce `evt_01HZ8A0007` / loop `evt_01HZ8A0009`); duplicate `evt_01HZ8A0001` → one task; unsubscribe suppresses Rita and stops her active enrollments; HTML-only body (`0008`) is classified from stripped HTML.

## 3. Reused / rewritten / avoided

**Kept:** models, `FakeFlakyClassifier` (as the LLM stand-in), `DemoSeeder` base clients, `SendCampaignStepJob`, queue/Mail stubs, fixture JSON.

**Added/fixed:** corrected the original create migrations: `clients` unique `(tenant_id, email)`, `processed_events` / `reply_tasks` unique `(tenant_id, event_id)`. Also `IdempotencyGuard::claim`, `SentimentLabelResolver`, full `ProcessInboundReplyJob`, tenant-43 seed row, feature tests, `nunomaduro/collision` (gives `artisan test` so `make test` matches the README), these NOTES.

**Avoided:** NATS/HTTP/UI/OpenAI, global Eloquent tenant scope (jobs have no request context — every query uses explicit `tenant_id`), FKs, Mailgun suppression sync, parsing `In-Reply-To` into non-existent `reply_tasks` campaign columns.

Email is lowercased only on lookup; no citext/mutator — known simplification within the time budget.

## 4. Agent vs hand

**Agent wrote:** implementation plan; schema unique constraints in the existing create migrations (not a new `100400` alter); `IdempotencyGuard::claim`; `SentimentLabelResolver`; `ProcessInboundReplyJob`; DemoSeeder tenant-43 row; `ProcessInboundReplyJobTest`; first draft of NOTES; `nunomaduro/collision` so `artisan test` exists (README assumed it; skeleton `composer.json` did not). Briefly used `./vendor/bin/phpunit` in Makefile until Collision was in place, then restored `php artisan test`.

**Hand:** restored the `artisan` CLI entrypoint from a fresh Laravel project (it was missing / broken in the working copy); opened `inbound_events.json` and matched senders to DemoSeeder before asserts; insisted on `insertOrIgnore` instead of catch-inside-TX; rejected a fake two-PDO race under `RefreshDatabase`; required resolver to return `null` (never throw) and `retryDelayMs=0` in testing; verified `phpunit.xml` is `pgsql`; ran Docker Postgres + `make test`.

**Reworked after agent:** claim design (exception → `insertOrIgnore`); unique constraints edited into create migrations (not a separate alter); removed enrollment-as-gate; fixed false “global tenant scope” docblock on `Client`; NOTES wording; dropped optional in-suite “real race” into §5.

## 5. Skipped on purpose

- Real multi-connection race test (`RefreshDatabase` incompatibility; see §2).
- Email case-normalization on write / citext.
- Header-based auto-reply short-circuit.
- Global tenant scope, foreign keys, outbound Mailgun suppression list.
- Rewriting `FakeFlakyClassifier` or outbound campaign code.
