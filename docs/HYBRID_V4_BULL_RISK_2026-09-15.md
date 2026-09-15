# Conditional bull-risk study, 2026-09-15

Offline research only. No deployment authority, operational database changes, manual orders, gate edits, capital reset or activation change.

## Protocol

- Twelve predeclared sizing hypotheses: SMA50/SMA200 confirmation, 1.05/1.10 risk boost, VVIX reentry 100/105/110. Six cached anchor/constant-risk controls. Four paths at 30/60 bps: 144 comparisons, 96 new and 48 reused. Two additional helper-parity reruns are verification, not new hypotheses.
- Bull confirmation uses completed SPY and QQQ closes above their own average, rising averages over ten sessions and positive five-session returns; SVXY must be above SMA200. Outside confirmation exposure is identical to the own-VVIX anchor. Stops and circuit confirmation are unchanged.
- Alpaca raw whole-share sizing and split-adjusted signals; dated S5TW/Cboe VVIX; 6.25% annual calendar-day financing; initial equity $27,567.66; 12 sleeves and close-based 12% stop. No Yahoo substitution.
- Early paths start 2017-01-03/2019-01-02 and end 2020-12-31; recent paths start 2021-01-04/2023-01-03 and end 2026-09-04. No pre-IPO synthesis: only symbols with zero history before end are omitted.
- This is adaptively chosen research on overlapping, already examined periods, NOT independent holdout. The selected basket is not a point-in-time constituent/delist universe.

## Conditional Sizing Results

Highest terminal capital among the twelve new rules on the 2021-start, 30-bps path: bull_v110_ma50_boost110. This ranking is descriptive, not release selection. Negative early-regime results and cost sensitivity are retained below.

Relative capital changes compare final dollars, not annual growth. A positive drawdown delta means a shallower drawdown. Worst-of-eight measures are descriptive, NOT a new mandatory gate: useful money-only and risk-only tradeoffs remain eligible for further investigation.

| Rule | 2021/30 capital vs anchor | 2021/30 DD improvement, pp | Worst capital vs anchor | Worst DD improvement vs anchor, pp |
|---|---:|---:|---:|---:|
| bull_v110_ma50_boost110 | 7.26% | -0.07 | -2.31% | -1.62 |
| bull_v105_ma50_boost110 | 7.37% | -0.13 | -2.31% | -1.62 |
| bull_v100_ma50_boost110 | 7.24% | -0.16 | -2.31% | -1.62 |
| bull_v110_ma200_boost110 | 4.50% | -0.71 | -2.87% | -2.11 |
| bull_v105_ma200_boost110 | 4.62% | -0.83 | -2.87% | -2.11 |
| bull_v100_ma200_boost110 | 4.63% | -0.84 | -2.87% | -2.11 |
| bull_v110_ma200_boost105 | 3.72% | -0.29 | -3.34% | -2.03 |
| bull_v105_ma200_boost105 | 3.84% | -0.41 | -3.34% | -2.03 |
| bull_v110_ma50_boost105 | 3.50% | -0.13 | -0.12% | -0.13 |
| bull_v100_ma200_boost105 | 3.78% | -0.42 | -3.34% | -2.03 |
| bull_v105_ma50_boost105 | 3.58% | -0.25 | 0.01% | -0.25 |
| bull_v100_ma50_boost105 | 3.54% | -0.26 | 0.08% | -0.26 |

## Leader Versus Controls

| Recipe | Path | Cost, bps | Terminal capital | CAGR | Maximum DD |
|---|---|---:|---:|---:|---:|
| deployed | early2017 | 30 | $134481.29 | 48.74% | -20.35% |
| deployed | early2017 | 60 | $47501.29 | 14.60% | -26.61% |
| deployed | early2019 | 30 | $89997.58 | 80.90% | -21.37% |
| deployed | early2019 | 60 | $43454.10 | 25.61% | -27.26% |
| deployed | continuous | 30 | $1028888.81 | 89.46% | -23.06% |
| deployed | continuous | 60 | $523894.43 | 68.17% | -24.81% |
| deployed | fresh2023 | 30 | $877381.02 | 156.82% | -22.99% |
| deployed | fresh2023 | 60 | $580166.53 | 129.43% | -23.90% |
| vvix_confirmation_110 | early2017 | 30 | $134481.29 | 48.74% | -20.35% |
| vvix_confirmation_110 | early2017 | 60 | $61927.75 | 22.48% | -26.61% |
| vvix_confirmation_110 | early2019 | 30 | $89997.58 | 80.90% | -21.37% |
| vvix_confirmation_110 | early2019 | 60 | $56277.33 | 42.98% | -27.26% |
| vvix_confirmation_110 | continuous | 30 | $1031698.60 | 89.55% | -23.03% |
| vvix_confirmation_110 | continuous | 60 | $532829.05 | 68.68% | -24.03% |
| vvix_confirmation_110 | fresh2023 | 30 | $879754.69 | 157.01% | -22.99% |
| vvix_confirmation_110 | fresh2023 | 60 | $581405.09 | 129.57% | -23.90% |
| mix_v110_s50_r110_b10 | early2017 | 30 | $143665.34 | 51.22% | -21.67% |
| mix_v110_s50_r110_b10 | early2017 | 60 | $69036.34 | 25.86% | -29.90% |
| mix_v110_s50_r110_b10 | early2019 | 30 | $88560.76 | 79.45% | -23.54% |
| mix_v110_s50_r110_b10 | early2019 | 60 | $59948.79 | 47.58% | -26.58% |
| mix_v110_s50_r110_b10 | continuous | 30 | $1172341.33 | 93.87% | -23.46% |
| mix_v110_s50_r110_b10 | continuous | 60 | $569457.43 | 70.67% | -24.96% |
| mix_v110_s50_r110_b10 | fresh2023 | 30 | $978385.22 | 164.56% | -23.36% |
| mix_v110_s50_r110_b10 | fresh2023 | 60 | $661814.55 | 137.82% | -24.92% |
| bull_v110_ma50_boost110 | early2017 | 30 | $131368.86 | 47.87% | -21.97% |
| bull_v110_ma50_boost110 | early2017 | 60 | $62091.58 | 22.56% | -26.58% |
| bull_v110_ma50_boost110 | early2019 | 30 | $90518.01 | 81.43% | -21.37% |
| bull_v110_ma50_boost110 | early2019 | 60 | $56193.99 | 42.88% | -27.27% |
| bull_v110_ma50_boost110 | continuous | 30 | $1106620.47 | 91.91% | -23.11% |
| bull_v110_ma50_boost110 | continuous | 60 | $658498.37 | 75.10% | -24.98% |
| bull_v110_ma50_boost110 | fresh2023 | 30 | $927541.03 | 160.74% | -22.79% |
| bull_v110_ma50_boost110 | fresh2023 | 60 | $609192.98 | 132.51% | -23.34% |

## Yearly Results

Continuous 2021 start and 30 bps, not separate annual resets. Trades are completed ticker exposure episodes, not orders or partial fills. The JSON retains every year for every one of the 144 comparisons.

| Recipe | Year | Return | Maximum DD | Closed ticker episodes | Closed sleeve episodes |
|---|---:|---:|---:|---:|---:|
| deployed | 2021 | 39.96% | -18.17% | 32 | 208 |
| deployed | 2022 | -16.72% | -17.63% | 8 | 38 |
| deployed | 2023 | 237.44% | -23.03% | 17 | 159 |
| deployed | 2024 | 74.07% | -23.06% | 33 | 208 |
| deployed | 2025 | 135.20% | -15.48% | 30 | 183 |
| deployed | 2026 | 131.78% | -19.33% | 21 | 190 |
| vvix_confirmation_110 | 2021 | 39.96% | -18.17% | 32 | 208 |
| vvix_confirmation_110 | 2022 | -16.72% | -17.63% | 8 | 38 |
| vvix_confirmation_110 | 2023 | 237.44% | -23.03% | 17 | 159 |
| vvix_confirmation_110 | 2024 | 74.39% | -22.90% | 33 | 208 |
| vvix_confirmation_110 | 2025 | 135.35% | -15.42% | 30 | 183 |
| vvix_confirmation_110 | 2026 | 131.83% | -19.32% | 21 | 190 |
| bull_v110_ma50_boost110 | 2021 | 43.52% | -17.30% | 32 | 207 |
| bull_v110_ma50_boost110 | 2022 | -17.55% | -18.47% | 8 | 41 |
| bull_v110_ma50_boost110 | 2023 | 241.95% | -23.11% | 17 | 161 |
| bull_v110_ma50_boost110 | 2024 | 75.54% | -23.04% | 34 | 210 |
| bull_v110_ma50_boost110 | 2025 | 134.31% | -15.29% | 30 | 183 |
| bull_v110_ma50_boost110 | 2026 | 141.18% | -19.28% | 21 | 190 |

## Evidence And Limits

- Every comparison against deployed, the same-VVIX anchor and constant risk is in the JSON. Neighbor settings change one factor only. Conditional boosts are checked independently of circuit reentry; a bull market never bypasses its pause.
- Eligibility means the sizing rule was available on that session, not a claim that every eligible session increased an actual position. Integer shares, held assets and circuit state can prevent an exposure change.
- Prefix tests verify that future bars cannot change past regime flags. Two exact historical helper parity checks compare complete curves, metrics, annual results and trade ledgers with frozen controls.
- Historical stops use split-adjusted prices. They do not prove broker nominal price/quantity parity; date-specific raw/split conversion and isolated execution tests remain necessary for any new release.
- No new minute, NBBO, queue, partial-fill, live-market or independent forward profitability evidence is claimed. SVXY history spans its 2018 exposure change in the 2017 path, but not in the 2019 path; see the prior interactions report.
- All 144 result/curve hashes, all annual returns and counts, all simulated new stop events, both parity proofs and frozen input/source hashes are retained. Current paper positions and orders are not part of this backtest.

Verification: 8 relevant programs passed, 1023872 accounting/date/hash checks, 17098 new-case stop events preserved. Active release unchanged; zero broker orders.

```sh
php tests/candidate_bull_risk_study.php
php tests/candidate_bull_risk_assessment.php
php -d memory_limit=8G tools/research_candidate_bull_20260915.php
php -d memory_limit=1G tools/summarize_candidate_bull_20260915.php
```
