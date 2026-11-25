# Pixelforge Studio

PromptEdit take-home: a Laravel + Vue + Inertia app where users generate images through the Pixelforge API and pay exactly what each generation cost (+25%).

## What it does

- **Generate**: write a prompt, pick size/quality, add up to 4 reference image URLs. The price range (min to max) is shown live, before submitting.
- **History**: every generation with live status (sending, queued, processing, succeeded, failed), its estimated range, the actual charge and the image.
- **Webhooks**: signature-verified, deduplicated by event id, and correct when events arrive twice or out of order.
- **Balances** (stretch): credit balance, a fake "Buy credits" button, and generating is blocked when the user can't afford the *maximum* possible cost.
- **Admin report** (stretch): credit spend, provider cost and profit per UTC day and per user, with each day compared to Pixelforge's `GET /v1/usage`.

## Key decisions

| Topic | Decision |
|---|---|
| Money | Every amount is an integer number of **nanodollars** (1 USD = 10⁹). Pixelforge amounts have at most 9 decimals, so this is exact. No floats anywhere. The browser only ever gets preformatted strings. |
| Provider cost | `Σ tokens × rate` from the generation's own `usage` and `rates` (rates are fixed at creation, so we never use today's pricing for an old generation). |
| User charge | provider cost × 1.25, rounded **up** to the nanodollar (we never undercharge; in practice the published rates always give whole nanodollars). |
| Estimate | Output and image tokens are exact. Text tokens are `ceil(chars/4)` ±15% (Unicode characters, counted on the server). Reasoning tokens use the min/max for the quality. Shown as a min–max range. |
| Holds | On submit we hold the **maximum** possible cost (`available = balance − holds`). When it finishes we charge the real cost, or release the hold if it failed. The user row is locked, so double-clicks can't overspend. |
| Never charge twice | 1) `webhook_events.event_id` is unique; 2) the generation row is locked and `charged_at` is checked; 3) `ledger_entries.generation_id` is unique, so the database itself refuses a second charge. |
| Out of order | Statuses only move forward (`submitting → queued → processing → succeeded/failed`). A late `processing` after `succeeded` is ignored. |
| Webhook before POST returns | We send `metadata.generation_id` (our id), so the webhook finds the record even before we know Pixelforge's id. |
| Limits / overloads | Submission runs in a queued job with an `Idempotency-Key`. 429/503/network errors are retried after `max(Retry-After, 2^attempt)` seconds (capped at 60s), up to 8 attempts, then the generation is marked failed (free) with a clear message. |
| Lost webhooks | `generations:sync` runs every minute and polls generations that have been in progress for over a minute. |
| Uncertain failures | If we gave up because of network errors, Pixelforge might still have created the generation. In that case its later webhook is trusted over our guess, so we never miss a charge Pixelforge made us. |
| Report days | Grouped by the UTC day the generation **completed** (Pixelforge's `completed_at`), the same rule Pixelforge bills by, so the numbers can match `/v1/usage` exactly. |

## Code map

- `app/Support/Money.php`: nanodollar parsing and formatting
- `app/Services/Pricing.php`: provider cost, user charge, estimate
- `app/Services/Pixelforge/*`: HTTP client, error type, webhook signature
- `app/Services/GenerationUpdater.php`: the only place that changes status and charges
- `app/Jobs/SubmitGeneration.php`: submission with retries and backoff
- `app/Http/Controllers/PixelforgeWebhookController.php`: webhook endpoint (`POST /webhooks/pixelforge`)
- `app/Console/Commands/SyncGenerations.php`: polling fallback
- `app/Http/Controllers/Admin/ReportController.php`: operator report
- `resources/js/pages/Generations.vue`, `resources/js/pages/Admin/Report.vue`

## Run locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
composer run dev   # server + queue worker + vite
php artisan schedule:work   # in another terminal, for the polling fallback
```

Set `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET` and `ADMIN_EMAILS` in `.env`. For webhooks, expose the app with a tunnel (ngrok, Expose, Cloudflare Tunnel) and set the key's webhook URL to `https://<tunnel>/webhooks/pixelforge`.

## Tests

```bash
php artisan test
```

They cover the docs' worked examples (max cost $0.373875, usage $0.169080), signatures and replays, duplicate and out-of-order webhooks, failures being free, holds and blocking, retries and giving up, polling, and the report matching `/v1/usage`.

## Deploy (Laravel Cloud)

1. Push to GitHub and create the app on Laravel Cloud with a Postgres database.
2. Environment: `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` from a **separate** Pixelforge key for production, `ADMIN_EMAILS`, and `QUEUE_CONNECTION=database`.
3. Enable a **queue worker** and the **scheduler**.
4. Deploy command: `php artisan migrate --force`.
5. Set the production key's webhook URL to `https://<your-app>/webhooks/pixelforge`.
