# Weekly-open full CLI and regression verification

Later 08:40 update: the separate package now has exact-source experimental
paper admission and 60 passing programs. See `HYBRID_V4_WEEKLY_OPEN_ADMISSION_2026-09-20.md`.
The 03:18 receipt below remains dated evidence for the earlier runtime, not
the latest package. The repair is still NOT deployed.

Completed 2026-09-20T03:18:35Z in the existing isolated repair worktree,
branch `codex/bull5-weekly-open-repair-20260919`.
This supersedes the full-CLI and full-suite gaps in the earlier 02:37 targeted
report. It does NOT grant release admission, install the patch or resume paper.

## Full Submitted CLI

`tests/candidate_weekly_cli.php` passes 711 assertions over 85 independently
started CLI processes and five scenarios. It invokes the actual
`bin/trade tactical-paper-executor --candidate=true --submit=true --telegram=true`
inside a temporary copy with its own database, signal, locks and synthetic
manifest/commissioning. Only that disposable executor imports a frozen clock.
The notification, outbox admission, reconciliation, planning, persistence,
broker adapter and delivery-loop code are not duplicated or bypassed.

The subprocess receives a stripped environment. Paper broker, HTTP and Telegram
classes are network-free substitutes; HTTP transport functions and URL streams
are disabled. No operational credentials, database or launchd service are used.
The fixture checks its default database resolves inside its temporary root.
The fixture's synthetic admission is not published or counted as real admission.

| Scenario | Verified outcome |
| --- | --- |
| Full fills | Four accepted buys survive cutoff/open; all confirmed shares receive four acknowledged native stops, no unnecessary cancels. |
| Partial fill | Exactly two confirmed shares retained, unfinished buys canceled, exactly two shares protected. |
| Partial fill plus another expiry | Saved terminal-incomplete error pauses the run; cleanup still protects two shares and never resumes. |
| Genuine pending notification | The existing outbox gate still aborts; a subsequent cycle at 09:30:11 keeps canceling after delivery, before the 09:32 deadline. |
| No fill through deadline | Bounded 09:32 cleanup cancels all four orders; no late purchase, phantom position or unnecessary stop. |

Each scenario observes 09:27:00, 09:29:59 and three successive auction-time
cycles. Accepted orders never count as fills. The fake broker rejects opposing
open simple orders, duplicate client IDs and late/non-OPG buys.

With the old active scheduler substituted only in the disposable copy, the
test fails at Friday 09:30:05: `weekly_session=2026-09-17`,
`action=cancel`, `reason=entry_window_expired`. With the repaired scheduler,
the same queue/gate path waits. This closes the earlier gap where the component
test copied the executor's outbox rule instead of running it.

The CLI fixture starts from a provisioned flat ledger, not the actual held
two-share account. Existing-position cancel/protect behavior is additionally
covered by the separate 56-assertion component regression. The CLI's new-fill
stop is $432.96 (fixture fill $492 times 0.88), not the real account's $438.02
completed-close trailing stop. Neither fixture price is a broker fill forecast.

## Complete Regression Suite

`tools/verify_weekly_open_regressions.php` ran **59/59 programs**, no failures
or deferred programs: all 55 from the current reference admission plus four
additional CLI/session/integration/notification-health programs.

The data-bound programs were actually rerun, not credited from earlier output.
The runner verifies the independent expected snapshot's SHA256 through the
reference admission and September 15 execution receipt, checks/copies the eight
dated input files, and rebuilds the September 14 snapshot with the repaired hash.

- Actual Alpaca SIP raw/split plus dated S5TW/VVIX snapshot: 13 assertions;
  369,098,752 peak bytes, below the existing 512 MiB cap.
- Real-snapshot fake-broker fault matrix: 100 deterministic scenarios,
  1,128 assertions.
- Synthetic causal bull-map tests: 17,518 assertions.
- New CLI: 711 assertions; new session boundary: 78; component integration: 56.

These are software/data-binding regressions, not new trading hypotheses,
independent holdout, current-session signals or a new profit estimate. The
completed 500/192/144 historical studies were not repeated.

Reproduce from the isolated worktree:

```sh
php tools/verify_weekly_open_regressions.php /Users/admin/Documents/fulltimetrading/fulltimetrading --with-data-bound
php tests/candidate_weekly_cli.php
```

The baseline option `--baseline-scheduler=/absolute/old/scheduler.php` is a
negative control and is expected to fail, not a release command.

Machine-readable summary: `HYBRID_V4_WEEKLY_OPEN_CLI_2026-09-20.json`.
Full local receipt:
`var/reports/weekly_open_regressions/20260920_031759_9427.json`,
SHA256 `a5341fd3e4890cb9e414c9c9ae94536a286a4595404a099363f3abe2165ae1ad`.
It binds all test files, the fake-client helper, runner, copied inputs and
rebuilt signal. The temporary stage inventory is removed after verification.
Three new PHP files lint cleanly; `git diff --check` is clean.

## Remaining Boundary

The staged runtime is still
`20abb8ff6b3939d00d9255f64c99c034d9bbaecbe392ef64f7c00be3e6ebcacc`.
Only its scheduler differs from active
`76b2c102b4de98e9351ad78d937d939e173cd734fd4acbd322bf824c2cf4f092`;
no additional execution logic changed in this stage.

A full regression PASS does not create a content-bound release manifest,
complete the economic/history-evidence admission verifier for a new package,
perform fresh-current-signal preflight, or provide safe held-position handoff.
No installer or resume was attempted. The current installer requires flat
ownership/orders; do not liquidate manually or overwrite identity to satisfy it.

Read-only audit at 03:17:09 UTC still confirms paused run, 2 MSFT covered by
native stop 2 at $438.02, reconciliation OK, unchanged equity $27,571.22.
Both existing daemons are healthy, trading outbox 122 delivered and no pending.
Month gate remains 4/31 elapsed days and 4/20 market dates, blocked. Weekend
commentary has no due block. Active source/hash/activation, orders and live
permission remain unchanged.
