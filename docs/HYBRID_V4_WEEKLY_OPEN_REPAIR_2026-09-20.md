# Isolated weekly-open scheduler repair

Verified September 20, 2026, 02:37 UTC. This is a targeted software repair,
NOT a release-admission proof, deployment, automatic resume or performance claim.

## Scope

Branch: `codex/bull5-weekly-open-repair-20260919`.
Base: `ea99a4a2ae69b648853a160e777fa9344c8614bb`.
Worktree: `var/staging/weekly-open-repair-20260919`.
No operational credentials were copied and no service was started here.

The September 18 incident is documented in
`HYBRID_V4_WEEKLY_OPEN_INCIDENT_2026-09-19.md`. During Friday's opening,
`clock.next_open` moves to Monday. Comparing Thursday's completed artifact
against that moving field invented a Thursday weekly report. Its pending
outbox row then closed entry admission and durably aborted accepted OPG orders.

Only one manifest-bound source file changes:
`src/Trading/TacticalPortfolioNotificationSchedule.php`.
The week boundary now uses the validated artifact's `intended_session`.
Both executor callers already supply this official-calendar-bound next session.
Missing, malformed, normalized or non-increasing dates fail closed; a next
session later than the broker's next opening also fails closed. Fresh clock,
account scope, decision identity, actual-close eligibility, durable keys and
catch-up behavior remain required. No weekday-only holiday calendar is invented.

- Active runtime: `76b2c102b4de98e9351ad78d937d939e173cd734fd4acbd322bf824c2cf4f092`.
- Isolated runtime: `20abb8ff6b3939d00d9255f64c99c034d9bbaecbe392ef64f7c00be3e6ebcacc`.
- Active scheduler SHA256: `a12f32cb9c77982892e4c7abb09a0f534f694d5ae10ca722c30fb1125051cac5`.
- Repaired scheduler SHA256: `8248761d8d355fb5c27b4eb80986ee90984307d9bbb488ab5db630318cd65a5a`.

## Verification

Twelve targeted test programs passed on the repaired worktree:

| Program under tests/ | Reported assertions |
| --- | ---: |
| tactical_weekly_session_boundary.php | 78 |
| candidate_weekly_open_integration.php | 56 |
| tactical_portfolio_notification_schedule.php | Not counted by program |
| candidate_cycle_contract.php | 10 |
| candidate_opg_auction_boundary.php | 230 |
| candidate_entry_batch.php | 86 |
| candidate_execution_plan.php | 32 |
| tactical_portfolio_weekly_summary.php | Not counted by program |
| candidate_release_gate.php | 37 |
| tactical_notification_health_guard.php | Not counted by program |
| tactical_notification_policy.php | Not counted by program |
| tactical_run_identity_gate.php | Not counted by program |

The first two tests fail with exit 255 when preloading the unchanged active
scheduler, and pass with the repaired scheduler. Failures are respectively
"No Thursday weekly at Friday 09:30:00" and "Repaired scheduler must not invent
a Thursday weekly report at Friday open." Preloading reads only the PHP class;
it does not access the active database or broker.

Boundary coverage includes Friday open, stale Thursday artifacts after Friday
close and on Monday, valid Friday close and Monday catch-up, Friday holiday,
Thanksgiving short session, DST, ISO year boundary, missing/invalid next-session
dates and stale clocks. Scheduling leaves its inputs unchanged.

The integration test uses the real scheduler, notification ledger, execution
window and batch planner with temporary SQLite and a fake broker. Three
successive opening observations keep accepted orders waiting without a
spurious outbox row or sticky abort. A negative control inserts a genuine
pending notification: admission still closes, delivery cannot undo the batch
abort, synthetic zero-fill expiry still pauses, and cleanup restores the
original two shares' native stop. No late entry or automatic resume occurs.

Run the regression directly with
`php tests/candidate_weekly_open_integration.php`, or the reproduction helper
with `php tools/reproduce_candidate_weekly_open_20260919.php --expect-repaired`.
The helper's default mode deliberately expects the old defect and is not a
passing test for repaired sources. The operational original remains unchanged.

All four changed/new PHP files lint successfully; `git diff --check` is clean.
The adjacent JSON records test outputs and source hashes, not admission flags.

## Limits And Deployment Boundary

This is NOT the complete 55-program release suite. The CLI contract test covers
isolated preflight and identity/corruption rejection; it does not exercise the
complete submitted CLI at a Friday auction. The new integration composes real
components but reproduces the executor's outbox-readiness rule in its fixture.
Full submitted-CLI auction coverage remains a release-verification gap.

The existing pending-outbox gate, terminal-incomplete pause, cancellation reason
label, position sizing, signal recipe, protective gap mechanics and all broker
order methods are unchanged. This patch does not explain why Alpaca expired
one order and cannot prove that the canceled orders would have filled.

At the operational read-only audit completed 02:35:18 UTC, the old release
remained installed and commissioned but paused. Two MSFT were owned and covered
by acknowledged GTC SELL STOP 2 at $438.02; independent reconciliation passed.
Equity $27,571.22, cash $26,583.66; no new fills versus September 19 at 21:32.
Both PHP/LaunchAgent/lock PIDs matched (69275 and 1477), with fresh heartbeats.
Exporter exit 2 reflects the paused cycle, not a dead daemon.
Trading outbox: 122 delivered, zero pending/failed; the extra row is the daily
repeat of the existing pause error, not a new trading failure.
Commentary context is a weekend with `due=null`; no analytical message sent.

Month gate: 4/31 elapsed days, 4/20 market dates, return +0.0129%, maximum
drawdown -0.0614%, zero completed exits. Paused run, unresolved error/expiry,
error rate and insufficient duration/exits/weeks keep the gate blocked.
Earliest review remains 2026-10-16T13:41:18Z; live is disabled.

No release proof, manifest, commissioning, operational runtime, activation,
capital, broker order or ledger history was changed. Do not cherry-pick runtime
files into the operational checkout while positions/orders remain open. Any
deployment requires exact-source full admission and the supported safe handoff;
the current flat-account installer is not an in-place repair/resume mechanism.
Never force liquidation or erase the saved pause to make deployment possible.
