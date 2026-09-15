# Daily Telegram commentary

The assistant sends a separate analytical message to the existing configured
Telegram chat. It does not append text to, suppress, or replace trading signals.
No broker order is created/cancelled. The active release files are not edited.

## Scheduling

The existing hourly research heartbeat checks eligibility before its research.
The daily post-close automation provides a duplicate-safe closing fallback.
Typically this produces an opening report around 09:45 and a closing report
around 16:45 New York time, subject to the app/host being available. It is not a
new launchd trading service. Broker calendar and clock are authoritative:

- Opening: 5 to 90 minutes after the official open; no lunchtime catch-up.
- Closing: at least 20 minutes after the official close, on the same date.
- Holidays/weekends: no report. Early closes and US/EU DST differences are handled.
- One message per active run, market date and phase; two scheduled jobs cannot
  duplicate a delivered message.

## Assistant Workflow

1. Run `php tools/paper_market_commentary.php context`.
2. If `context.due` is null, stop the commentary branch. If `existing_receipt`
   is delivered, do not send again. For sending/uncertain, investigate the
   independent outbox; do not delete rows or blindly retry.
3. Read the timestamped facts, actual orders/fills, current plan and dated market
   observations. Review current primary news sources when making current-news
   claims. Distinguish observation, interpretation and a scenario to watch.
   Historical bars are not live quotes. Label stale or missing inputs explicitly.
4. Write a JSON draft under `var/reports/market_commentary/` using the returned
   `context_path` and `context_sha256`. Do not edit the context snapshot.
5. Run `php tools/paper_market_commentary.php preview /absolute/draft.json`.
6. Review the preview, then run `php tools/paper_market_commentary.php send
   /absolute/draft.json`. Only `receipt.status=delivered` proves delivery.

Draft schema:

```json
{
  "schema": "paper-market-commentary-v1",
  "phase": "open",
  "session_date": "YYYY-MM-DD",
  "context_path": "var/reports/market_commentary/contexts/TIMESTAMP_PID.json",
  "context_sha256": "SHA256_FROM_CONTEXT_COMMAND",
  "market": "Interpretation of dated market observations, with uncertainty.",
  "algorithm": "What the deployed algorithm is doing and why, separately from research.",
  "watch": "Conditions/fills/risks to observe next, not a manual trading instruction.",
  "sources": [{"label": "Primary source title", "url": "https://example.com/page"}]
}
```

Use Russian. Keep each section short, at most 900 UTF-8 bytes and the whole
rendered message at most 3800 bytes. The context must be no more than 15 minutes
old and belong to the same active release and current eligible report window.
The tool verifies account guard, source hashes and signal recipe. Data warnings
remain visible to the author, rather than being replaced with invented values.

## Safety And Delivery

Operational SQLite is opened read-only. A separate database,
`var/db/paper_market_commentary.sqlite`, owns delivery receipts and message text.
It is not the trading notification outbox and cannot block entry admission.
The existing Telegram credentials are used without changes or logging.

Delivery is reserved atomically before the HTTP call. If the response is
ambiguous, the receipt becomes `uncertain` and automatic retry is disabled:
Telegram does not provide an idempotent send key. A crash with a `sending` row
also requires inspection. This deliberately prefers a visible missed report
over duplicate assistant commentary. It never claims exactly-once network
delivery. Unit tests use a fake sender and make no external calls.

## Signal Wording

The deployed trading message's `target: N shares` is a final position target,
not a take-profit. Compare it with the same sleeve's owned quantity and actual
broker orders. With zero holdings and an active BUY order it means an entry
awaiting execution. `Phase 1/3` means one of three staggered rebalance schedules,
not three profit-taking levels. Do not call a target or accepted order a fill.

The assistant block should explain this in ordinary language whenever relevant,
for example: "For this sleeve the plan is to buy 9 MSFT; 0 currently owned;
the opening-auction BUY is pending, not filled. Budget $4,594.61; reference
position value $4,548.69 at the completed-close reference price $505.41."
These numbers are an example from the September 14 close, not reusable defaults.

Verification: `php tests/paper_market_commentary.php`.
