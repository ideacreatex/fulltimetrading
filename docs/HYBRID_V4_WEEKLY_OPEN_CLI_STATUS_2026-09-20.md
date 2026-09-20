# Weekly-open repair: full CLI gap closed

Verified September 20, 2026, 03:18:35 UTC. Isolated commit
`66d89e847eb6c050012b85cdead1f893462a16f6`, branch
`codex/bull5-weekly-open-repair-20260919`, builds on scheduler fix `f165b150`.
No operational runtime or broker mutation occurred.

## Completed

- Full submitted CLI: 711 assertions, 85 process restarts, five scenarios.
  It runs the actual command, outbox, gates, reconciler and planner against
  network-free broker/Telegram clients and a temporary database. Only the
  disposable executor's clock is instrumented; no trading decision is stubbed.
- The old scheduler reproduces a Thursday weekly report and BUY cancellation
  at Friday 09:30:05. The repaired scheduler keeps accepted OPG waiting.
- Full fills and partial fills receive exactly the confirmed-share stop
  coverage. Genuine pending notifications and the 09:32 deadline still abort;
  another order's terminal expiry still pauses while cleanup protects fills.
  Delivery before 09:32 does not undo an already persisted batch abort.
- All 59 programs passed: the prior 55-program suite plus four additions.
  The independent September 14 snapshot was rebuilt from hash-verified Alpaca
  SIP raw/split and dated S5TW/VVIX inputs, not replaced by synthetic prices.
  Its 100-scenario fault matrix and 17,518 map assertions were actually rerun.
  Peak snapshot memory was 369,098,752 bytes, below the existing 512 MiB cap.

This is regression/data-binding verification, not new strategy hypotheses,
independent historical holdout, current-session data or a return improvement.
Synthetic CLI fixtures are distinguished from the separate Alpaca input check.
Existing-held-position behavior remains covered by the earlier component test;
the new full CLI fixture begins from a provisioned flat ledger.

Full reports in the isolated branch/worktree:
`docs/HYBRID_V4_WEEKLY_OPEN_CLI_2026-09-20.md` and `.json`.
Reproducible runner: `tools/verify_weekly_open_regressions.php` with the
operational reference path and `--with-data-bound`, run ONLY in isolation.
It publishes test receipts, not release manifests or commissioning records.
Full local receipt: `var/reports/weekly_open_regressions/20260920_031759_9427.json`
under the isolated worktree, SHA256
`a5341fd3e4890cb9e414c9c9ae94536a286a4595404a099363f3abe2165ae1ad`.

## Still Not Deployed

The previous full-CLI/full-suite verification gaps are closed. Exact-source
release admission, package identity/handoff and fresh-current-signal preflight
remain separate work; no new release manifest was issued. Existing installation
requires a stable flat account, not an in-place replacement over held shares.
Do not clear the saved pause, change identity, bypass gates or force liquidation.

Active hash stays `76b2c102b4de98e9351ad78d937d939e173cd734fd4acbd322bf824c2cf4f092`.
Isolated hash stays `20abb8ff6b3939d00d9255f64c99c034d9bbaecbe392ef64f7c00be3e6ebcacc`.
Only the scheduler differs in manifest-bound runtime files.

At 03:17:09 UTC, read-only audit still confirmed the paused run, 2 MSFT with
native GTC stop 2 at $438.02, no uncovered shares, matching ownership/cash,
equity $27,571.22 and cash $26,583.66. Both LaunchAgents/PHP/lock PIDs remain
69275/1477 with fresh heartbeats. Exporter exit 2 reflects the paused cycle;
restarting a healthy service would not repair its saved error.
Trading outbox: 122 delivered, no pending; weekend commentary `due=null`.
Month gate remains 4/31 elapsed days and 4/20 market dates; earliest review
October 16 at 13:41:18 UTC. No history reset or live permission.

Audit evidence: `var/reports/candidate_forward_20260915/20260920_031709_9032.json`,
SHA256 `cee11edb285558392bc644956ca170c3b0cd779daa75a9c9dc66cc1258888508`.
