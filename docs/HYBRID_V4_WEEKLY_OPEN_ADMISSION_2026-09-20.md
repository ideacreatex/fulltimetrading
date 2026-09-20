# Weekly-open repair: separate experimental paper admission

Admission completed September 20, 2026, 08:40:58 UTC; dated current-signal
rebuild completed at 08:43:31 UTC. This closes the exact-source admission and
package-identity gaps recorded in the earlier 03:18 report. NOT deployed.

## Package And Scope

- Proposed run: `hybrid-v4-bull5-weekly-2026-09-20`.
- Predecessor: `hybrid-v4-bull5-2026-09-15`; no ledger or activation was changed.
- Unchanged profile/recipe: `maximum-stop12-costband2-whole-bull5-v1` /
  `bull_v110_ma50_boost105`.
- Runtime: `59a61cb8b98f1325fcda2b76e7947468a11b9bab6ee1142f279f7b9f0f0214f1`.
- Manifest: `var/releases/candidate-bull5-weekly-v1/manifest.json` in isolation.
- `paper_admission=true`; `strict_validation_selected=false`, `live_approved=false`,
  `commissioned=false`. No daemon or installer was started in staging.

Only three manifest-bound files differ from the operational release: the
verified scheduler repair, candidate configuration (run/predecessor/manifest
identity only), and the admission builder. Trading rules, sizing, stops, circuit,
accounting, account guards and installer are byte-identical. The builder retains
its original criteria and adds an explicit isolated weekly-repair mode.
`WeeklyOpenReleaseEvidence` verifies the allowed source delta and independent
parent/test/helper/runner/input hashes; its own hash is bound to both receipts.

## Actual Verification

- 60/60 programs passed for this exact runtime, with no deferred programs.
  New configuration/verifier identity justified this run, not another search
  for profitable parameters or a repeat of the completed historical studies.
- The new evidence-contract test passed 67 assertions: missing fields, changed
  tests/helpers/data, substituted programs, excess memory, wrong identity,
  live permission and re-signed unreviewed runtime changes are rejected.
- Submitted CLI: 711 assertions, 85 restarted processes, five fake-broker
  scenarios. Pending messages and terminal expiry remain fail-closed. Simulated
  execution is not a new real broker fill or proof of an actual handoff.
- 14 post-admission corruption/commissioning checks passed on disposable
  copies of the generated release. No commission or ledger was created.
- 72 PHP/shell lint invocations and both worktree whitespace checks passed.
- Independent September 14 full snapshot, the real-snapshot 100-case fault
  matrix and causal maps passed again for the new package. Snapshot peak:
  369,098,752 bytes, below the existing 512 MiB cap.

The builder rechecked 218 reference-file hashes and executed the existing
assessment logic on eight economic comparisons and eight parity receipts.
The 40 capital cases, minute evidence for 64 stop events, research protocols
and inputs retain their original hashes. These historical simulations and
minute downloads were reused, NOT rerun or renamed as independent evidence.
No benefit threshold was weakened. Minute touches do not prove execution;
history remains adaptively selected. No new profit improvement is claimed.

## September 18 Signal

`tools/verify_weekly_open_current_signal.php` copied eight hash-checked input
files and rebuilt the full September 18 signal for September 21. All fields
except run/hash/content checksum/generated timestamp match the source-bound
operational artifact. The rebuild passed 21 assertions; peak 371,195,904 bytes.
Inputs: Alpaca SIP raw/split prices and the original S5TW/Cboe VVIX, not Yahoo.

This is dated data/decision parity, NOT an independent current broker/calendar
preflight. It made zero broker requests and did not publish into the operational
signal path. Freshness must be checked again at actual handoff.

## Remaining Handoff Conditions

Read-only audit at 08:45:01 UTC confirmed the installed predecessor is paused,
with two MSFT covered by the acknowledged stop at $438.02 and reconciliation OK.
The unchanged installer requires a stable flat account. This package does not
implement held-position migration or clear the saved terminal error. Do not
copy runtime files over the active release, cancel protection, manually
liquidate, erase history, force a hash change or automatically resume the run.

Admission retains the originally reviewed exact starting capital $27,567.66.
Observed equity is $27,571.22, so it is NOT currently eligible for that exact
capital gate either. Review actual capital at a permitted future flat handoff;
do not reset equity or enlarge the tolerance. Fresh official calendar/account/
signal preflight, clean/pushed source, the 120-second stable-flat check and
LaunchAgent commissioning remain mandatory. No successor activation occurred;
the active run's month history and October 16 review threshold are unchanged.
Even a month PASS cannot enable live.

## Reproduction And Receipts

Run ONLY in `var/staging/weekly-open-repair-20260919`, without credentials or a
trading database; the operational reference root is read-only.

```sh
php tools/verify_weekly_open_regressions.php /Users/admin/Documents/fulltimetrading/fulltimetrading --with-data-bound
php tools/verify_staged_bull5_release.php /Users/admin/Documents/fulltimetrading/fulltimetrading --weekly-open-repair var/reports/weekly_open_regressions/20260920_083956_45223.json
php tests/staged_bull5_release.php
php -d memory_limit=512M tools/verify_weekly_open_current_signal.php /Users/admin/Documents/fulltimetrading/fulltimetrading
```

Use the newly emitted receipt path if intentionally rebuilding. The admission
and dated-signal commands were actually run with stripped credentials and
disabled HTTP transports; the test runner enforces this for its child processes.
Machine-readable evidence: `HYBRID_V4_WEEKLY_OPEN_ADMISSION_2026-09-20.json`.

- Regression receipt: `751cd8bf58d8eebdb1a7bb80749f600e8b809c6909993afd03f6064d27fcea4c`.
- Manifest: `bde644f335811e66dea5720c7735d41214de6b40a26186a0416b20a76d912183`.
- Admission proof: `086e852440c121cd3a9effeb775e1402bcfe34a46fb460b5df73f1072acf7916`.
- Dated-signal receipt: `1fa464aef1d399013c9ec8716aeb3a00041f6e4e16b3e97a14e00295239432c5`.
