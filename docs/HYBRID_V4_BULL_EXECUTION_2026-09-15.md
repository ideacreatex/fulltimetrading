# Bull5 execution and data binding, 2026-09-15

Three declared SMA50 bull-risk +5% variants, with VVIX reentry thresholds 100/105/110. These are execution checks of existing hypotheses, not new return discoveries. The active paper release and pending orders were not changed.

## Completed

- 24/24 close/whole-share/prefix checks: four historical paths, 30/60 bps, twelve sleeves. Frozen source hashes, metrics and entire equity curves match the committed bull-risk study.
- 934661 replay assertions, 277848 sleeve-session observations, 95909 quantity comparisons. Opening-gap stops preempted 207 sizing comparisons; these are not counted as quantity matches.
- 4275 recorded historical stops passed receipt/price checks. These split-adjusted daily-model events are not native broker fill proofs.
- Three isolated September 14 snapshot artifacts were independently rebuilt twice from Alpaca SIP raw/split and validated external indicator files. They bind exact recipe, profile, runtime reference, source files and full dated scale/confirmation maps. Self-checksummed tampering is rejected against trusted recomputation.
- All three latest snapshots have bullish=false and equal deployed close contexts. Thus this date exercises the non-boosted branch only; positive bull behavior is covered by the historical component and causal-map tests, not by claiming the latest snapshot exercised it.
- Snapshot schema is deliberately incompatible with the active signal contract, including after re-signing in its checksum format. No order/entry permission is granted. Peak construction memory: 374.52 MiB, under the 512 MiB test limit.

## Why The Large 60 Bps Gain Occurs

The continuous 2021-01-04 to 2026-09-04 comparison starts at $27,567.66. VVIX110 bull5 ends at $702,339.37 versus $523,894.43: +34.06% terminal money, not +34% annual alpha.

At the August 17, 2021 close, the deployed control reaches -18.26% risk-epoch drawdown and schedules liquidation; bull5 is at -16.55% and does not trigger. The first differing restriction applies August 18. The control releases only after the May 27, 2022 close, following 197 cash-restricted sessions. Across the complete replay, prior-close force_cash applies on 217 control sessions versus 26 for VVIX110 bull5.

| Year | Control return | Bull5 VVIX110 return | Relative capital factor |
|---|---:|---:|---:|
| 2021 | -6.77% | 34.70% | 1.444798 |
| 2022 | -5.06% | -16.52% | 0.879292 |
| 2023 | 201.35% | 220.57% | 1.063765 |
| 2024 | 52.75% | 49.34% | 0.977693 |
| 2025 | 119.20% | 118.86% | 0.998427 |
| 2026 | 112.77% | 116.23% | 1.016245 |

2026 is partial through September 4. Annual factors multiply to 1.34061240. The 2021 advantage is partly surrendered in 2022. Grouped daily log-return differences also reconcile exactly; they use the PREVIOUS close restriction to avoid same-day attribution lookahead.

This establishes strong path/circuit sensitivity, not an independent causal split between sizing, rounding, holding selection, costs and confirmation. Those remain coupled. Whole-share rounding was reproduced, not removed in a counterfactual. Do not extrapolate the 34.06% as stable expected improvement. The separately initialized 2023 path was only +2.90% at 60 bps and +3.84% at 30 bps in the parent study.

## Remaining Admission Work

This is NOT a new release PASS and does not replace maximum-stop12-costband2-whole-v1. A future staged release still needs its full command/snapshot contract, persisted ledger and partial-fill/stop failure matrix, variant-specific minute execution evidence in a consistent price-adjustment basis, and capital sensitivity. No active strategy replacement while its orders/positions exist. The monthly gate and activation remain unchanged; no automatic live transition.

All history is repeatedly/adaptively used, not an independent holdout. The selected stock basket is not a point-in-time universe. S5TW is external Investing.com data and VVIX is Cboe data, not Alpaca; tradable instrument bars are Alpaca SIP. The 2018 SVXY mandate change and imperfect auction/stop modeling remain limitations.

## Verification

14 relevant regression programs passed, plus 4610 receipt/hash/stop assertions. Snapshot unit test: 55 assertions; snapshot filesystem integration: 37 assertions; accounting unit test: 12 assertions.

```sh
php -d memory_limit=8G tools/verify_candidate_bull_parity_20260915.php
php -d memory_limit=512M tools/verify_candidate_bull_snapshot_20260915.php
php -d memory_limit=1G tools/summarize_candidate_bull_execution_20260915.php
```

Per-case proofs: var/reports/candidate_bull_parity_20260915. Isolated snapshots: var/reports/candidate_bull_snapshot_20260915. The attribution control was rerun once specifically to record its previously unsaved circuit history. No second parameter sweep was performed.
