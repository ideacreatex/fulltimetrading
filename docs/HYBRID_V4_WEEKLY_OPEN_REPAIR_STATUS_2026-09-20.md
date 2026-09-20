# Weekly-open repair: isolated and verified, not deployed

Operational check completed September 20, 2026, 02:35:18 UTC. Targeted repair
verification completed at 02:37 UTC. Heartbeat triggers without tool execution
are not counted as completed operational checks.

## Completed Repair

Source and tests are committed separately at
`f165b15042432a8456a3145da02fba1cd46407fd`, branch
`codex/bull5-weekly-open-repair-20260919`, worktree
`var/staging/weekly-open-repair-20260919`.
The full report and machine-readable targeted receipt are in that branch at
`docs/HYBRID_V4_WEEKLY_OPEN_REPAIR_2026-09-20.md` and `.json`.

The scheduler now recognizes the end of the week from the validated completed
artifact's intended next session, not the broker clock's moving `next_open`.
This prevents a false Thursday weekly report at Friday open and the associated
spurious outbox-induced abort of already accepted OPG orders.

Twelve targeted programs passed, including 78 new session-boundary assertions
and 56 queue/ledger/planner integration assertions. Both new tests failed
against the unchanged operational scheduler and passed against the repair.
Real pending notifications still block admission; terminal expiry still pauses;
cleanup restores protection and cannot automatically resume or chase entries.
The existing 230-assertion auction-boundary test also passed.

Only one manifest-bound source differs in the isolated worktree:
`src/Trading/TacticalPortfolioNotificationSchedule.php`.
Active hash remains `76b2c102b4de98e9351ad78d937d939e173cd734fd4acbd322bf824c2cf4f092`.
Staged hash is `20abb8ff6b3939d00d9255f64c99c034d9bbaecbe392ef64f7c00be3e6ebcacc`.
No operational source, strategy, manifest, commissioning or ledger changed.

## Remaining Release Work

This is not the complete 55-program release suite or a new admission proof.
The full CLI preflight contract passes, but the submitted Friday-auction CLI
path is not covered end-to-end by this new component integration; its outbox
readiness rule is reproduced in the fixture. Close that verification gap and
complete exact-source admission before treating this as a releasable package.

Deployment is separately blocked by held shares and an open native paper stop.
The current installer requires a stable flat account; it is not a supported
in-place upgrade or paused-run recovery procedure. Do not merge runtime files
into this operational checkout, replace the hash, erase the pause, liquidate
positions manually, or weaken gates to make installation possible.

## Fresh Paper Evidence

Compared with September 19 at 21:32 UTC, no new fills, orders, equity change or
uncovered shares. Run remains paused with `candidate_terminal_incomplete:79796b9b9712`.
Two MSFT, average $492, remain covered by acknowledged GTC SELL STOP 2 at $438.02;
order `9f121ce3-334a-47e0-8284-6e6f46db4cb1`. Ownership and cash reconciliation
pass. Equity $27,571.22, cash $26,583.66, buying power $54,154.88, unrealized $3.56.
Stop acknowledgment is not a guaranteed fill price or extended-hours protection.

Both existing LaunchAgents/PHP/lock PIDs agree (69275 tactical, 1477 legacy),
heartbeats fresh. Paper host/account guards and commissioned hash pass. Exporter
exit 2 represents the paused trading cycle, not service death; no restart made.
Trading outbox moved 121 -> 122 delivered, zero pending/failed: the additional
row is the daily repeat of the existing pause error at 00:00:22 UTC, not a new
failure. The latest signal is September 18 for September 21; buying is blocked.
Separate commentary context has `due=null` on the weekend, so nothing was sent.

Month report: 4/31 elapsed days, 4/20 market dates, 5 stored snapshot dates;
return +0.0129%, maximum drawdown -0.0614%, zero completed exit episodes.
Unresolved pause/expiry, error rate, insufficient observation/exits/weeks and
weekly concentration keep the gate blocked. Earliest review stays October 16
at 13:41:18 UTC. No history or capital reset; automatic live stays disabled.

Retained local evidence:

- `var/reports/candidate_forward_20260915/20260920_023518_88830.json`, SHA256
  `9db835237b9ebb140f72f608a78704d09d214b0b90e6a7a2155381f254eb6851`.
- `var/reports/heartbeat_20260920_0232/operational/latest_paper_status.json`, SHA256
  `30fd2a56b21a2afe464a8ac663f4fc602883342bec596f8bda72fa8dfb2d6e4c`.
