# Low-token paper monitoring

## Separation of responsibilities

The existing paper LaunchAgents execute PHP monitoring and Telegram notifications
without an LLM. Keep their lifecycle, intervals, account guard and gates unchanged.
The Codex heartbeat is scheduled analysis, NOT an always-on watchdog. Fewer model
wakeups mean slower assistant diagnosis/recovery between windows. A live host,
network, broker and working daemons remain prerequisites.

## Compact inspection

Run `php tools/paper_monitor_digest.php` and
`launchctl list | rg 'fulltimetrading|^PID'`. The digest reads existing local JSON
and both SQLite outboxes in read-only mode; it never calls a broker, submits an
order, restarts a service, writes a database or grants trading permission.

- `inspection_issues` describes missing/stale/unsafe inspection inputs.
- `runtime_errors` and `run.status` are independent: healthy processes may be paused.
- A status older than 20 minutes or heartbeat older than 180 seconds is flagged.
- Missing inputs are not treated as zero positions or successful delivery.
- Audit freshness requires the same run, success and age no greater than 24 hours.
- An old successful audit is not proof of current stop coverage after a change.

Compare run, quantities, orders/stops, errors and outboxes with the latest verified
snapshot. New risk, orders/fills, persistent new errors, incomplete inputs or an
expired audit require `php tools/audit_candidate_forward.php`. Inspect only its
summary, not all historical order lookups. Recheck a moving snapshot once. Do not
repeat investigation of unchanged known errors. No research/backtests/web without
a concrete new requirement. Preserve full evidence under `var/reports`.

## Analysis windows

Heartbeat target: weekdays at 15:00, 16:00, 19:30, 20:30, 22:30 and 23:30 in
Asia/Nicosia. Paired hours cover the US/EU DST mismatch. The first pair covers
09:00 New York; the second covers 13:30 early-close commentary; the last covers
16:30 regular-close commentary. Only the authoritative Alpaca calendar/context
may decide eligibility. Extra paired slots exit quietly when not due/delivered.
If the host timezone changes, review the schedule. Unexpected exchange closure
times may be covered only by the daily fallback, not these fixed windows.

Run `php tools/paper_market_commentary.php context` first and save full output
outside the conversation. Inspect due/receipt/warnings only. When eligible,
follow `docs/PAPER_MARKET_COMMENTARY.md`: fresh context, dated facts, no invented
news, preview then send, delivery receipt required. No late pre-open catchup.
Close analytics requires a fresh month report. Daily full audit remains at 23:50
local, including weekends, with medium reasoning instead of ultra.

## Non-negotiable boundaries

Paper only. No live, manual orders/cancels/liquidation, host/key changes, gate
bypass, capital/history/activation reset or automatic unpause. No manifest-bound
edits while positions/orders exist. Recover only a proven dead/stale service,
after diagnosis, through its existing paper LaunchAgent. No healthy-daemon restart.
Do not run root `tools/verify_candidate_release.php`. Before release work, read
`docs/HYBRID_V4_WEEKLY_OPEN_ADMISSION_STATUS_2026-09-20.md` and relevant linked docs.
Admission is not commissioning. The month gate requires 31 days from actual
activation, 20 market dates and ALL criteria; PASS only requests human review.

## Verification

`php tests/paper_monitor_digest.php` tests staleness, missing inputs, guard failure,
PID/lock mismatch, process failure, wrong/expired audit and visible paused state.
The digest and tests are outside `CandidateRelease::files()`. Verify that the
runtime hash is unchanged before/after editing. Commit only these monitoring files.
