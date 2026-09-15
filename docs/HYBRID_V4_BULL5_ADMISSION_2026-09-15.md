# Bull5 staged paper admission, 2026-09-15

**Historical pre-open assessment, superseded at 09:41 New York.** The first
opening exposed an untested submission-cutoff cancellation bug. Bull5 with the
fix is now deployed; see `HYBRID_V4_BULL5_DEPLOYMENT_2026-09-15.md/.json` for the
new hash, 55-program regression, activation and preserved predecessor history.
The original pre-open proof/manifest is archived in
`var/reports/bull5_admission_20260915/pre_open_release/`.

## Status

The variant `bull_v110_ma50_boost105` now has a separately verified, source-bound
**experimental paper admission** in `var/staging/bull5-v1`. It is not deployed or
commissioned. `strict_validation_selected=false`, `live_approved=false`, and
`independent_holdout=false` remain explicit. No month-gate approval is implied.
The machine-readable receipt is `HYBRID_V4_BULL5_ADMISSION_2026-09-15.json`.

The operational profile remains `maximum-stop12-costband2-whole-v1`, run
`hybrid-v4-candidate-2026-09-15`, runtime hash
`709c3979f85a08c8e84eb9ee2f94598d2a0698ddb26e3b9e5d34c00c0ae341dd`.
No operational release file, API host/key, activation, ledger, order or position
was changed by this staging work. The staging configuration enables a future
paper release, but has no credentials, operational database or commissioning
record. Its hash is
`670ac65a387ac2d7a37603fa13c3d960c00c9f3fb5aedac15aa06cd34fe11a15`.

## Completed Checks

- Full isolated runtime regression: 54/54 programs, stripped credential
  environment and network disabled. All 50 programs from the active release
  proof are retained, with full dated snapshot, bull-map and fault-path checks.
- Real September 14 data passes the staged DataSnapshot -> signed artifact ->
  persistent 12-book ledger. It matches the independent research snapshot.
  Peak memory is 371,195,904 bytes, below 512 MiB. The latest bull condition is
  false, yielding the same 9+3+3+3 MSFT targets; this is not forward evidence of
  the boosted branch. Causal maps separately pass 17,518 assertions including
  positive-branch and prefix/future-mutation checks.
- The actual dated snapshot feeds 100 deterministic fake-broker fault scenarios,
  1,128 assertions: partial fills, rejection, ambiguous POST, delayed lookup,
  delayed cancel acknowledgement and fill/cancel races. Cash/ownership and
  acknowledged protective-stop quantities reconcile. These are not broker fills.
- All 64 unique bull5 stop/price/basis events on the recent paths exactly match
  existing frozen Alpaca SIP split-adjusted minute evidence. All touches and
  opening minutes reproduce; no new download or quote substitution. A one-minute
  delay proxy is worse for 34/64 events, worst about -300 bps. This is not NBBO
  election evidence or a guaranteed execution-price model.
- Capital sensitivity: 40 cases, 32 new replays and 8 hash-verified reuses;
  $25,000, $27,500, $27,567.66, $27,600 and $30,000; continuous 2021 and fresh
  2023 paths; 30/60 bps. Every curve/receipt/code/input hash is checked.
- Variant-specific admission verifies the unchanged original economic policy,
  capital/concentration/gross criteria, frozen research, eight bull5 component
  proofs including early history, source-bound snapshot, minute audit, regression,
  syntax and whitespace. No old candidate proof is relabelled as bull5 evidence.
- Fourteen additional post-admission assertions reject re-signed missing proof
  checks, wrong run/profile/hash, live approval, capital mismatch and verifier
  source mutation. Admission does not create a commissioning record or ledger.

The initial offline runs exposed missing copied PHP ini files/executable modes
and a legacy artifact test that contacted the real account. The builder now
preserves the runtime profile and modes. That test uses a local broker with no
HTTP implementation and rejects every submit/cancel. It also cannot load the
operational `.env`; both root and credential-free staging runs pass. The original
research-isolation test is unchanged; a separate staged map test handles the
intentionally different staged release. Early failed runs are retained under
`var/reports/bull5_admission_20260915/stage_attempt*.json`.

## Economic Interpretation

Incremental terminal money versus the **currently deployed stop12 variant** is
positive in all 20 capital/path/cost pairs: +1.61% to +34.06%. This is descriptive,
not a new mandatory all-pairs-dominance gate. Worst drawdown deterioration is
0.87 percentage points; maximum gross exposure is below 1.30 throughout.

At actual $27,567.66 capital, fresh-2023 improvement is +3.84% money at 30 bps
and +2.90% at 60 bps. The much larger continuous/60 result is circuit-path
sensitive: it falls from +34.06% at $27,567.66 to +2.79% at $25,000. Do not market
it as reliable incremental alpha, annual return, or expected paper profit.

The **original user-selected maximum**, before stop12/execution changes, is the
unchanged admission-policy comparator. At actual capital, continuous/30 gives
29.33% less terminal money but 5.58 pp lower drawdown; that passes the existing
risk-benefit alternative. Fresh-2023/30 gives 142.04% more money and 11.79 pp
lower drawdown. Both 60-bps comparisons improve money and drawdown. The same
policy also passes at $30,000. These comparisons must not be confused with the
smaller incremental benefit over the already deployed version.

Price history is Alpaca SIP with explicit raw/split handling, whole shares,
previous-close sizing and calendar financing. S5TW and VVIX remain external
inputs, not Alpaca series. Repeated adaptive use, a selected rather than
point-in-time basket, older SVXY regime changes, partial-fill latency and
stop-transition gaps limit inference. Historical admission is an experiment,
not independent validation or evidence of profitability on the demo account.

## Deployment Boundary

Read-only broker audit at 13:13 UTC: equity/cash $27,567.66, zero filled shares,
four existing BUY MSFT OPG orders, exact position/cash reconciliation and no
audit errors. `candidate_paper_cycle.php` requires no positions **and no open
orders** for isolated installation preflight (`flat_only_isolated_preflight`).
Thus the existing installer cannot safely replace this run now. Do not cancel
orders, liquidate positions, transfer ledger ownership or bypass the gate just
to deploy bull5.

When the account becomes naturally flat, recheck account/locks/exits, rebuild
and verify the source-bound package, refresh actual starting-capital evidence
if equity changed, prepare a current verified signal, then use the existing
paper LaunchAgent installation/commissioning path. Copying a manifest, changing
a hash or editing the active profile while orders exist is not deployment.
Any code/config/run-id change invalidates this exact package's approval.

Offline reproduction from the operational checkout (no broker mutations):

```sh
php tools/research_bull5_capital_20260915.php
php tools/verify_bull5_minute_reuse_20260915.php
php tools/build_bull5_stage.php
php tools/verify_bull5_stage_20260915.php
env -i PATH=/opt/homebrew/bin:/usr/bin:/bin HOME=/tmp php -d memory_limit=512M -d allow_url_fopen=0 -d disable_functions=curl_exec,fsockopen,stream_socket_client var/staging/bull5-v1/tools/verify_candidate_release.php /Users/admin/Documents/fulltimetrading/fulltimetrading
env -i PATH=/opt/homebrew/bin:/usr/bin:/bin HOME=/tmp php var/staging/bull5-v1/tests/staged_bull5_release.php
```

Do not run the root `tools/verify_candidate_release.php` to admit bull5: it
belongs to the active version. The staged override points at the bull-specific
verifier and refuses the operational checkout. Reproducing evidence changes
receipt timestamps/hashes; preserve the committed dated receipt as historical.

## Monthly Control And Commentary

Both existing Codex automations are active and were shown through the app's
automation cards. The closing job also ran September 14; it was not a missing
continuous 31-day process. The research heartbeat now checks each half hour;
the daily closing automation remains a fallback.

The month report at 13:15 UTC gives 0/31 calendar days, 2/20 observed market
dates, 0 exits and earliest review October 16 at 01:51:22 UTC, measured from
actual activation September 15 at 01:51:22 UTC. Two dates do not mean two
completed trading sessions. Real forward time cannot be replaced by a backtest.
Other gates still apply and even PASS cannot automatically enable live.

Separate opinion-message timing is now 09:00 before opening and 16:30 after
closing, New York time, following the broker calendar. This is useful operational
timing for opening-auction orders, not a tested statistical optimum. Official
clock, holidays, early closes and DST are tested. The first pre-open message
was delivered September 15 at 09:02:32 New York, Telegram message 883, through
the separate idempotent outbox. Broker fill/stop alerts remain event-driven.
See `PAPER_MARKET_COMMENTARY.md` for delivery limits and workflow.
