# Weekly-open repair: full CLI gap closed

08:40 UTC update: separate exact-source paper admission and package identity
are now complete in isolated commit `4d3244bd`, with 60 passing programs.
See `HYBRID_V4_WEEKLY_OPEN_ADMISSION_STATUS_2026-09-20.md` for the current state.
No deployment or resume occurred; the earlier receipts below remain dated.

Verified September 20, 2026, 03:18:35 UTC. Isolated commit
`66d89e847eb6c050012b85cdead1f893462a16f6`, branch
`codex/bull5-weekly-open-repair-20260919`, builds on scheduler fix `f165b150`.
No operational runtime or broker mutation occurred.

## 08:07 UTC Operational Follow-Up

The completed 59-program receipt, all test/helper/runner hashes, dated input
hashes, rebuilt signal and both runtime identities were checked again at
08:07:50 UTC and still match. The programs were NOT rerun. Repair commit
`66d89e84` is pushed; operational documentation commit `09ded553` is now pushed.

That documentation commit had previously been left local. The scheduled
exporter's status-only Git guard correctly refused to push it and returned
exit 1. After the explicit documentation push, the normal status export
successfully committed/pushed snapshot `81993935`. It returned exit 2 for the
existing paused trading cycle, not a publication failure. No guard was relaxed,
service restarted or paused run resumed. launchd's last-exit field still refers
to its own scheduled invocation until that job runs again.

Read-only broker audit completed at 08:04:56 UTC: unchanged 2 MSFT, fully covered
by the same acknowledged stop at $438.02; reconciliation passes. Equity
$27,571.22, cash $26,583.66, unrealized P/L +$3.56. Both trading PHP processes,
PID/locks and heartbeats are healthy. Exact paper URL and account guard pass.
Trading outbox remains 122 delivered, none pending/failed. Commentary has four
delivered receipts, latest September 16 close, no uncertain/sending records;
September 20 is a weekend with `due=null`, so no catch-up was sent.

Month report remains 4/31 elapsed days and 4/20 market dates, with six stored
snapshot dates (weekend observations do not add market dates). Gate blocked;
earliest review remains October 16 at 13:41:18 UTC. No new orders or fills.
Audit: `var/reports/candidate_forward_20260915/20260920_080456_29538.json`, SHA256
`766c0a05d9d1268346abed673c12b7fb5b1d4d2187a50207b659e20b1ee5fdb7`.
Status: `var/reports/heartbeat_20260920_0804/operational/latest_paper_status.json`,
SHA256 `8ab57cbc7178ef002c5d53e08a1b9686c8bd3f3f91df1ad0e2b15c23b381a3bf`.

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

The previous full-CLI/full-suite verification gaps are closed. The subsequent
08:40 verification also completed exact-source admission and separate package
identity. Safe handoff and fresh-current-broker preflight remain. Existing installation
requires a stable flat account, not an in-place replacement over held shares.
Do not clear the saved pause, change identity, bypass gates or force liquidation.

Active hash stays `76b2c102b4de98e9351ad78d937d939e173cd734fd4acbd322bf824c2cf4f092`.
The 03:18 isolated hash was `20abb8ff6b3939d00d9255f64c99c034d9bbaecbe392ef64f7c00be3e6ebcacc`,
with only the scheduler changed. The newly admitted package has its own hash
and identity, recorded in the later admission-status document.

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
