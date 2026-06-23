# RevReply

RevReply Take home

The implemented is intentionally small but complete:

1. Connect one Gmail account with OAuth.
2. Manually sync recent inbox threads.
3. Persist threads and messages locally.
4. Classify each thread with deterministic heuristics.
5. Generate a stub draft reply.
6. Review and edit drafts in a Next.js dashboard.

The goal was not to finish a production system in one pass. The goal was to make sound architectural decisions, implement the highest-signal path end to end, and document how the system would evolve for scale.

## The Why

I deliberately optimized for a credible end-to-end slice instead of broad infrastructure work.

Implemented now:

1. Gmail OAuth.
2. Manual sync for one connected Gmail account.
3. Route -> controller -> service backend structure.
4. Local persistence for accounts, threads, messages, and drafts.
5. Heuristic classification stub.
6. Draft generation stub.
7. Dashboard for review and editing.

skipped for this version:

1. Gmail Pub/Sub watch setup.
2. Automatic token refresh flow.
3. Auto-send of replies.
4. Real LLM integration.
5. Full MIME and attachment parsing.
6. Real-time dashboard updates.
7. Multi-tenant worker scaling.

These were skipped because they would consume most of the time while adding less signal than a working vertical piece.

### 1. Manual Sync Now, Webhooks Later

This working version uses manual sync.

Why:

1. It proves the core business workflow quickly.
2. It avoids spending days on Gmail watch and Pub/Sub setup for a take-home.
3. It still keeps the production path obvious.

If this were extended, I would replace manual sync with Gmail watch notifications feeding a queue-backed history sync pipeline.

### 2. Draft-First Instead of Auto-Send

The system generates reviewable drafts instead of sending automatically.

The Why:

1. Classification can be wrong.
2. Draft tone can be wrong.
3. Thread context may be incomplete.
4. Draft review demonstrates a safer operational posture.

For a real product, auto-send should be a per-account policy behind confidence thresholds, audit logging, and opt-in controls.

### 3. Thin Controller, Service-Led Logic

The backend uses one `GmailController` for the take-home, but keeps logic in services. (also its the trend! route > controller > service :) 

Why:

1. One controller makes the codebase easier to scan during review.
2. Service boundaries keep future refactors easy.

Current service split:

1. `GmailService` handles OAuth, sync, reads, and draft updates.
2. `ThreadClassifierService` handles deterministic classification.
3. `DraftGenerationService` handles deterministic draft generation.

### 4. Store Refresh Tokens Even If Refresh Is Deferred

This version requests offline access and stores refresh tokens when Google returns them.

Why:

1. Access tokens expire quickly.
2. Manual sync becomes fragile if reconnect is required every hour.
3. Persisting the token now keeps the account model realistic, even if automated refresh is not implemented yet.

### 5. Persist Gmail Data Locally

Threads, messages, and drafts are stored in MySQL rather than rendering directly from Gmail responses.

Why:

1. The dashboard needs stable local reads.
2. Classification and draft outputs need to be persisted.
3. Replay, retry, and later queue-based processing depend on local.

## Current Backend Flow

### OAuth

1. `GET /api/integrations/gmail/connect` creates a short-lived OAuth `state` and redirects to Google.
2. `GET /api/integrations/gmail/callback` validates the `state`, exchanges the code for tokens, fetches the Gmail profile, and upserts the account.

### Manual Sync

1. `POST /api/integrations/gmail/accounts/{gmailAccount}/sync` checks that the account has a non-expired access token.
2. It fetches recent inbox threads from Gmail.
3. It fetches full thread payloads.
4. It normalizes messages and persists them.
5. It classifies the thread with heuristics.
6. It generates or updates one stub draft tied to the latest message.

### Review

1. `GET /api/integrations/gmail/accounts` returns connection status for the dashboard.
2. `GET /api/threads` returns stored local threads.
3. `GET /api/threads/{gmailThread}` returns a thread with messages and drafts.
4. `PATCH /api/drafts/{replyDraft}` updates draft content and review status.

## Route Surface

Backend routes:

1. `GET /api/integrations/gmail/connect`
2. `GET /api/integrations/gmail/callback`
3. `GET /api/integrations/gmail/accounts`
4. `POST /api/integrations/gmail/accounts/{gmailAccount}/sync`
5. `GET /api/threads`
6. `GET /api/threads/{gmailThread}`
7. `PATCH /api/drafts/{replyDraft}`

## Data Model

### `gmail_accounts`

Purpose: stores one connected Gmail identity and its sync state.

Important fields:

1. `google_email`
2. `access_token`
3. `refresh_token`
4. `token_expires_at`
5. `gmail_history_id`
6. `sync_status`
7. `last_synced_at`
8. `last_error_code`
9. `last_error_message`

### `gmail_threads`

Purpose: local thread record used by the dashboard and downstream reply pipeline.

Important fields:

1. `gmail_thread_id`
2. `subject`
3. `snippet`
4. `participants`
5. `latest_message_at`
6. `classification`
7. `classification_confidence`
8. `classification_reason`
9. `status`

### `gmail_messages`

Purpose: local copy of normalized Gmail messages within each thread.

Important fields:

1. `gmail_message_id`
2. `sender_email`
3. `sender_name`
4. `recipients`
5. `cc`
6. `subject`
7. `snippet`
8. `body_text`
9. `gmail_received_at`
10. `is_unread`
11. `raw_payload`

### `reply_drafts`

Purpose: stores generated or edited reply content for reviewer approval.

Important fields:

1. `draft_subject`
2. `draft_body`
3. `status`
4. `generation_source`
5. `approved_at`
6. `sent_at`

## Classification and Drafting

There is no live LLM integration in this version.

Instead:

1. `ThreadClassifierService` uses simple keyword heuristics.
2. `DraftGenerationService` maps classification labels to canned but editable replies.

This is intentional.

Why:

1. The task values architecture and decision more than provider wiring.
2. A stub keeps the rest of the system testable.
3. It makes future LLM integration a clean replacement rather than an intertwined dependency.

If I extended this, I would keep the exact same service boundaries and swap the implementation to:

1. Normalize thread content.
2. Call an LLM provider with prompt versioning.
3. Persist label, confidence, rationale, and generated draft.
4. Add evaluation and safety review around reply quality.

## Failure Handling

Current version:

1. OAuth `state` is cached and validated to avoid callback forgery.
2. Sync rejects accounts with missing or expired access tokens.
3. Sync failures update `sync_status`, `last_error_code`, and `last_error_message`.
4. Draft edits are validated server-side.

Production version should add:

1. Automatic refresh token exchange on token expiry.
2. Retry and backoff around Gmail API failures.
3. Queue-backed job isolation for sync, classification, and drafting.
4. Idempotency around webhook/history processing.
5. Better surfaced operational metrics.

## Scale and Multi-Account Plan

This version is intentionally single-account and manual-first, but the design direction is straightforward.

### Ingestion

I would use Gmail `watch` plus Pub/Sub push notifications, not polling. (good part!!)

Why:

1. Polling burns quota.
2. Polling introduces unnecessary latency.
3. Webhook-driven history sync is the normal scalable Gmail integration pattern. (what i currently implemented at MEDVi)

### Queue Design

I would split production work into separate jobs:

1. `SyncHistoryJob`
2. `ClassifyThreadJob`
3. `GenerateDraftJob`
4. `RefreshTokenJob`
5. `RenewWatchJob`

Why:

1. Each step has different retry behavior.
2. Gmail and LLM calls should be isolated.
3. One failing account should not block others.

### Rate Limits

For production, I would add:

1. Per-account throttling for Gmail API calls.
2. Provider-level throttling for LLM calls.
3. Leave `5xx` responses.

### Idempotency

For production, I would rely on Gmail identifiers and unique indexes to make replay safe.

Important ids:

1. `gmail_thread_id`
2. `gmail_message_id`
3. `history_id`
4. Gmail draft id if draft creation is delegated to Gmail later

## Frontend Design

The Next.js dashboard is server-rendered for initial data and uses a small client boundary for interaction.

Why:

1. Server Components are a clean fit for initial dashboard reads.
2. Client logic is only needed for manual sync, thread switching, and draft edits.
3. This keeps the UI simple and performant.

Dashboard features:

1. Connect Gmail button.
2. Manual sync button.
3. Account status panel.
4. Local thread queue.
5. Classification badge and rationale.
6. Message context view.
7. Draft editor.
8. Approve state update.

## Local Development

### Backend

From `api/`:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Required environment values:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=api
DB_USERNAME=root
DB_PASSWORD=

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/api/integrations/gmail/callback
GOOGLE_FRONTEND_REDIRECT_URL=http://localhost:3000
```

### Frontend

From `web/`:

```bash
npm install
cp .env.example .env.local
npm run dev
```

Frontend environment values:

```env
API_URL=http://127.0.0.1:8000
NEXT_PUBLIC_API_URL=http://127.0.0.1:8000
```

### Google OAuth Setup

1. Create a Google Cloud project.
2. Enable the Gmail API.
3. Create OAuth client credentials.
4. Add the backend callback URL as an authorized redirect URI.
5. Use the generated client id and client secret in the Laravel `.env`.

## Testing

Focused backend test coverage exists for the implemented slice.

Run:

```bash
cd api
php artisan test tests/Feature/GmailSyncTest.php
```

What it covers:

1. Manual Gmail sync with `Http::fake()`.
2. Thread and message persistence.
3. Heuristic classification.
4. Stub draft generation.
5. Draft editing through the API.
6. Accounts endpoint for dashboard status.

Frontend validation run:

```bash
cd web
npx eslint app/page.tsx app/dashboard-client.tsx app/layout.tsx
```

## What I Would Build Next

If I had more time, I would prioritize the following in order:

1. Replace manual sync with Gmail watch plus Pub/Sub history processing.
2. Implement refresh-token based access token renewal.
3. Move sync, classification, and drafting to queued jobs.
4. Add a real Gmail draft save operation so approved drafts live in Gmail itself.
5. Replace heuristic classification and canned replies with an LLM-backed pipeline.
6. Add better admin and operational visibility for failed syncs.

## What I Deliberately Skipped and Why

### Gmail Pub/Sub Setup

Skipped because it is operationally expensive for a short assessment and does not add as much signal as a working end-to-end slice.

### Auto-Send

Skipped because it is riskier than draft-first and harder to justify without confidence controls.

### Full MIME Parsing

Skipped because the review workflow only needs enough message text to classify and draft.

### Real LLM Integration

Skipped because provider wiring and prompt iteration would consume time better spent on architecture and system boundaries.

### Real-Time Updates

Skipped because manual refresh and manual sync are sufficient for the current review workflow.

## Deploy Story

For a production deployment, I would separate concerns like this:

1. Laravel API on an app service or container platform.
2. Queue workers running separately from the API.
3. MySQL for durable state.
4. Redis for queueing, cache, and short-lived state.
5. Next.js deployed separately as the dashboard frontend.
6. Pub/Sub or equivalent cloud event delivery for Gmail notifications.

## Least Certain Decision

The decision I would want to revisit first is where to draw the line between synchronous sync orchestration and queued work for a small installation.

For the take-home, synchronous manual sync is the right trade.

For a real system, I would almost certainly move sync work behind jobs immediately once more than one or two accounts are connected.