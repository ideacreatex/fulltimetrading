# Bull5 deployed and opening-auction cancellation fixed

## Actual Deployment

Experimental paper profile `maximum-stop12-costband2-whole-bull5-v1` is now
deployed in the operational checkout, not merely staged. Source commit:
`cba476d8`. Run: `hybrid-v4-bull5-2026-09-15`. Runtime hash:
`76b2c102b4de98e9351ad78d937d939e173cd734fd4acbd322bf824c2cf4f092`.

The unchanged installer `HYBRID_PAPER_MODE=candidate-v1 bin/install-hybrid-launchd`
verified the same existing `com.fulltimetrading.hybrid-v4-paper` LaunchAgent,
PID 69275. Actual activation was **2026-09-15 13:41:18 UTC**; commissioning was
13:41:21 UTC (09:41 New York). Code, manifest, proof and current signal hashes
match. The legacy exits-only daemon remained running. No new trading service,
API host/key change, live activation or manual broker mutation occurred.

The prior run `hybrid-v4-candidate-2026-09-15` is paused by the normal predecessor
handoff. Its original activation 01:51:22 UTC, $27,567.66 initial equity, four
canceled intents and zero fills remain stored. A new version has its own actual
activation; the previous history was neither deleted nor rebased.

## What The First Opening Revealed

The four accepted MSFT OPG orders (9+3+3+3 shares) did **not** execute. The daemon
requested their cancellation between 09:27:28 and 09:28:42 New York, before the
09:30 auction. Direct broker GETs confirm all four `canceled`, `filled_qty=0`.
Ledger cancellation requests match those timestamps. This was an execution bug
in our code, not missing buying power, a bearish signal or a broker rejection.

`CandidateEntryBatch::next()` confused the **new-order submission cutoff**
(`opg_submit_allowed=false` at 09:27) with expiry of an already accepted OPG.
It aborted the batch and misleadingly labelled cancellation
`partial_fill_protection` even when no shares had filled.

The existing fake-broker scenarios jumped from 09:20 directly to 09:30 and did
not exercise 09:27-09:30. Therefore the earlier 54 passing programs did not
establish the complete auction lifecycle. The independent forward audit caught
this gap; the historical results must not be presented as real paper profit.

## Fix And Verification

- Already submitted same-session OPG orders can wait through the submission
  cutoff and auction, bounded by the existing 09:32 execution deadline.
- No new OPG POST is permitted after 09:27. An unsubmitted remainder cannot
  revoke accepted auction orders or become a late market purchase.
- Partial fills still trigger cancel-confirm/protect sequencing. Ambiguous
  submissions remain lookup-only; loss of freshness/risk permission still aborts.
- Unfilled cleanup after the deadline is explicitly `entry_window_expired`,
  rather than a fictitious partial fill. No unlimited resting or price chasing.
- New regression reproduced the old failure at exactly 09:27, then passed
  **230 assertions across 14 restartable scenarios**, including both DST offsets,
  full/partial/no fills, unsent remainder, ambiguous POST and stale source.
- The complete staged suite passed **55/55 programs**, followed by 14
  post-admission corruption checks. Bull5's capital, minute, component-parity
  and economic evidence was revalidated for the new exact runtime hash.

Only after repeated broker confirmation of zero positions/open orders did the
deployment stop the existing tactical LaunchAgent, publish the exact admitted
sources, prepare the current verified September 14 signal, commit/push and run
the normal installer. Its two-minute stable-flat handoff and Telegram/heartbeat
checks passed. The missed September 15 opening was **not** retried manually or
converted to a late entry. The next purchase requires a fresh scheduled plan.

## Post-Install Evidence

Read-only audit at 13:41:50 UTC: active, account guard verified, equity/cash
$27,567.66, buying power $55,135.32, zero positions/open orders/intents/fills for
the new run, exact reconciliation within floating-point cash tolerance. Runtime
heartbeat, lock and launchd PID agree; executor exit is zero. Telegram outbox:
100 delivered, zero pending/failed-pending at 13:42 UTC. No native stop is due
until positive broker-confirmed ownership exists.

The status renderer initially recognized only the former profile and wrongly
described bull5's `validation_selected=false` as absent paper admission. Its
read-only profile recognition now includes bull5, still requiring matching run,
cycle profile, paper-only state and literal `paper_admission=true`. Presentation
tests pass 62 assertions; no trading gate or runtime hash changed. The correct
current reason for no entry is the missed execution window, not failed admission.

The offline verification runner now also accepts both historical basename test
keys and new path-style proof keys. Its read-only `--list-commands` enumerates
55 unique valid programs; listing does not claim to rerun verification.

Month gate for the new run: 0/31 calendar days, 1/20 observed market dates,
earliest review **2026-10-16 13:41:18 UTC**, other criteria still mandatory.
`validation_selected=false`, no independent holdout, and live remains disabled.
The next forward audit must verify that accepted OPG survives the cutoff and
that actual fills receive acknowledged native protection; no future fill or
profit is asserted here.

Machine-readable receipt: `HYBRID_V4_BULL5_DEPLOYMENT_2026-09-15.json`.
Earlier pre-open admission artifacts are archived unchanged under
`var/reports/bull5_admission_20260915/pre_open_release/`; the pre-open receipt is
historical, not the current deployment identity.

Broker contract: [Alpaca opening-auction order documentation](https://docs.alpaca.markets/us/docs/orders-at-alpaca).
