# Candidate interactions, 2026-09-15

Offline follow-up only. No runtime changes, broker submissions, gate changes or capital reset.

## Experiment

- 24 factorial cells: VVIX reentry 100/105/110, SVXY defensive cap 0.50/0.75, risk size 1.00/1.10, S5TW high touch/10 sessions versus high close/20 sessions. Both breadth modes retain the 1.25 multiplier.
- 18 combined hypotheses plus six existing single-factor/control recipes. Four start/end paths at 30/60 bps give 192 comparisons: 156 new replays and 36 hash-verified reused results. The prior 524 runs were not rerun.
- Initial equity $27,567.66. Alpaca raw integer sizing, split-only signals, dated S5TW/Cboe VVIX, fixed 6.25% financing; unchanged close-based 12% stop and 12 sleeves.
- Early paths: 2017-01-03 and 2019-01-02 through 2020-12-31. Recent paths: 2021-01-04 and 2023-01-03 through 2026-09-04. Earlier zero-history COIN excluded identically, without synthetic pre-IPO data.
- These overlapping samples and adaptively selected factors are NOT independent holdout validation. The basket is selected, not a point-in-time constituent/delist universe.

## Cross-Regime Findings

A higher-return tradeoff does exist: mix_v100_s75_r110_b20 raises terminal capital in the 2021-start path by 59.43% at 30 bps and 55.23% at 60 bps, while deepening maximum drawdown by 3.91 and 1.20 percentage points respectively. Its worst capital delta across the eight paths is -35.98%. This may be economically interesting, but it is not a uniform cross-regime improvement.

The all-eight labels below are descriptive robustness tests, NOT a new protective gate or a requirement that profit must always rise while drawdown falls. Money-only and drawdown-only benefits remain visible, as requested.

Terminal capital AND drawdown non-worse in all eight conditions, with at least one strict improvement: vvix_confirmation_105, vvix_confirmation_110.

Terminal capital non-worse (drawdown may worsen): vvix_confirmation_105, vvix_confirmation_110.

Drawdown non-worse (capital may fall): vvix_confirmation_105, vvix_confirmation_110.

CAGR is compounded annualized growth, not a guaranteed annual return. A positive DD delta means shallower drawdown. The minimum below is the worst of eight conditions, not an average that hides an unfavorable period.

| Recipe | Worst capital delta | Worst DD improvement, pp | Money/DD non-worse | Peak gross never higher |
|---|---:|---:|---|---|
| deployed | 0.00% | 0.00 | control | yes |
| s5tw_high_close_20_1.25 | -5.11% | -2.03 | no | no |
| risk_scale_1.1 | -1.60% | -3.29 | no | no |
| mix_v100_s50_r110_b20 | -3.55% | -3.26 | no | no |
| svxy_cap_0.75 | -37.25% | -2.62 | no | no |
| mix_v100_s75_r100_b20 | -37.45% | -4.54 | no | no |
| mix_v100_s75_r110_b10 | -32.24% | -5.68 | no | no |
| mix_v100_s75_r110_b20 | -35.98% | -5.62 | no | no |
| vvix_confirmation_105 | 0.00% | 0.00 | yes | yes |
| mix_v105_s50_r100_b20 | -5.11% | -2.03 | no | no |
| mix_v105_s50_r110_b10 | -1.60% | -3.29 | no | no |
| mix_v105_s50_r110_b20 | -3.55% | -3.26 | no | no |
| mix_v105_s75_r100_b10 | -22.48% | -2.49 | no | no |
| mix_v105_s75_r100_b20 | -22.25% | -4.54 | no | no |
| mix_v105_s75_r110_b10 | -16.43% | -5.68 | no | no |
| mix_v105_s75_r110_b20 | -20.47% | -5.62 | no | no |
| vvix_confirmation_110 | 0.00% | 0.00 | yes | yes |
| mix_v110_s50_r100_b20 | -5.11% | -2.49 | no | no |
| mix_v110_s50_r110_b10 | -1.60% | -3.29 | no | no |
| mix_v110_s50_r110_b20 | -3.55% | -3.26 | no | no |
| mix_v110_s75_r100_b10 | -17.10% | -2.69 | no | no |
| mix_v110_s75_r100_b20 | -16.72% | -4.54 | no | no |
| mix_v110_s75_r110_b10 | -10.32% | -5.68 | no | no |
| mix_v110_s75_r110_b20 | -14.53% | -5.62 | no | no |

## Single-Factor Crisis Check

| Recipe | Start | Cost, bps | CAGR | Maximum DD |
|---|---|---:|---:|---:|
| deployed | early2017 | 30 | 48.74% | -20.35% |
| deployed | early2017 | 60 | 14.60% | -26.61% |
| deployed | early2019 | 30 | 80.90% | -21.37% |
| deployed | early2019 | 60 | 25.61% | -27.26% |
| svxy_cap_0.75 | early2017 | 30 | 35.93% | -20.84% |
| svxy_cap_0.75 | early2017 | 60 | 19.32% | -28.89% |
| svxy_cap_0.75 | early2019 | 30 | 43.24% | -22.17% |
| svxy_cap_0.75 | early2019 | 60 | 26.73% | -27.55% |
| risk_scale_1.1 | early2017 | 30 | 51.22% | -21.67% |
| risk_scale_1.1 | early2017 | 60 | 17.12% | -29.90% |
| risk_scale_1.1 | early2019 | 30 | 79.45% | -23.54% |
| risk_scale_1.1 | early2019 | 60 | 28.53% | -26.58% |
| s5tw_high_close_20_1.25 | early2017 | 30 | 46.80% | -22.00% |
| s5tw_high_close_20_1.25 | early2017 | 60 | 34.16% | -25.64% |
| s5tw_high_close_20_1.25 | early2019 | 30 | 76.93% | -23.40% |
| s5tw_high_close_20_1.25 | early2019 | 60 | 24.40% | -27.38% |
| vvix_confirmation_110 | early2017 | 30 | 48.74% | -20.35% |
| vvix_confirmation_110 | early2017 | 60 | 22.48% | -26.61% |
| vvix_confirmation_110 | early2019 | 30 | 80.90% | -21.37% |
| vvix_confirmation_110 | early2019 | 60 | 42.98% | -27.26% |

SVXY is an indicator here, not a portfolio holding. Its daily target changed from -1x to -0.5x after February 27, 2018; the 2017 path crosses this product change whereas the 2019 path does not. Do not interpret the full SVXY history as a stationary exposure: [ProShares SVXY specification](https://www.proshares.com/our-etfs/strategic/svxy).

## Every Recent Result

| Recipe | Start | Cost, bps | Terminal capital | CAGR | Maximum DD |
|---|---|---:|---:|---:|---:|
| deployed | continuous | 30 | $1,028,889 | 89.46% | -23.06% |
| deployed | continuous | 60 | $523,894 | 68.17% | -24.81% |
| deployed | fresh2023 | 30 | $877,381 | 156.82% | -22.99% |
| deployed | fresh2023 | 60 | $580,167 | 129.43% | -23.90% |
| s5tw_high_close_20_1.25 | continuous | 30 | $1,015,801 | 89.03% | -22.95% |
| s5tw_high_close_20_1.25 | continuous | 60 | $528,169 | 68.42% | -24.60% |
| s5tw_high_close_20_1.25 | fresh2023 | 30 | $868,764 | 156.13% | -22.35% |
| s5tw_high_close_20_1.25 | fresh2023 | 60 | $615,491 | 133.16% | -22.84% |
| risk_scale_1.1 | continuous | 30 | $1,169,835 | 93.80% | -23.63% |
| risk_scale_1.1 | continuous | 60 | $591,304 | 71.81% | -25.00% |
| risk_scale_1.1 | fresh2023 | 30 | $968,239 | 163.81% | -23.36% |
| risk_scale_1.1 | fresh2023 | 60 | $659,383 | 137.58% | -24.97% |
| mix_v100_s50_r110_b20 | continuous | 30 | $1,174,114 | 93.92% | -23.66% |
| mix_v100_s50_r110_b20 | continuous | 60 | $579,958 | 71.22% | -25.12% |
| mix_v100_s50_r110_b20 | fresh2023 | 30 | $987,656 | 165.24% | -23.06% |
| mix_v100_s50_r110_b20 | fresh2023 | 60 | $670,547 | 138.67% | -25.09% |
| svxy_cap_0.75 | continuous | 30 | $1,362,202 | 99.08% | -25.68% |
| svxy_cap_0.75 | continuous | 60 | $680,725 | 76.13% | -26.52% |
| svxy_cap_0.75 | fresh2023 | 30 | $1,140,814 | 175.87% | -25.21% |
| svxy_cap_0.75 | fresh2023 | 60 | $770,711 | 147.90% | -26.44% |
| mix_v100_s75_r100_b20 | continuous | 30 | $1,355,107 | 98.89% | -25.95% |
| mix_v100_s75_r100_b20 | continuous | 60 | $682,566 | 76.22% | -26.68% |
| mix_v100_s75_r100_b20 | fresh2023 | 30 | $1,130,001 | 175.15% | -25.37% |
| mix_v100_s75_r100_b20 | fresh2023 | 60 | $777,642 | 148.51% | -26.75% |
| mix_v100_s75_r110_b10 | continuous | 30 | $1,620,634 | 105.28% | -26.68% |
| mix_v100_s75_r110_b10 | continuous | 60 | $802,215 | 81.31% | -28.03% |
| mix_v100_s75_r110_b10 | fresh2023 | 30 | $1,329,871 | 187.64% | -25.83% |
| mix_v100_s75_r110_b10 | fresh2023 | 60 | $894,279 | 158.15% | -27.83% |
| mix_v100_s75_r110_b20 | continuous | 30 | $1,640,366 | 105.72% | -26.97% |
| mix_v100_s75_r110_b20 | continuous | 60 | $813,259 | 81.75% | -26.01% |
| mix_v100_s75_r110_b20 | fresh2023 | 30 | $1,344,701 | 188.51% | -26.28% |
| mix_v100_s75_r110_b20 | fresh2023 | 60 | $910,476 | 159.42% | -27.98% |
| vvix_confirmation_105 | continuous | 30 | $1,029,258 | 89.47% | -23.03% |
| vvix_confirmation_105 | continuous | 60 | $556,433 | 69.97% | -24.57% |
| vvix_confirmation_105 | fresh2023 | 30 | $878,118 | 156.87% | -22.99% |
| vvix_confirmation_105 | fresh2023 | 60 | $580,258 | 129.44% | -23.90% |
| mix_v105_s50_r100_b20 | continuous | 30 | $1,016,471 | 89.05% | -22.92% |
| mix_v105_s50_r100_b20 | continuous | 60 | $592,190 | 71.85% | -24.38% |
| mix_v105_s50_r100_b20 | fresh2023 | 30 | $869,401 | 156.18% | -22.30% |
| mix_v105_s50_r100_b20 | fresh2023 | 60 | $615,667 | 133.18% | -22.78% |
| mix_v105_s50_r110_b10 | continuous | 30 | $1,170,560 | 93.82% | -23.60% |
| mix_v105_s50_r110_b10 | continuous | 60 | $609,995 | 72.75% | -25.33% |
| mix_v105_s50_r110_b10 | fresh2023 | 30 | $976,600 | 164.43% | -23.36% |
| mix_v105_s50_r110_b10 | fresh2023 | 60 | $660,044 | 137.64% | -24.97% |
| mix_v105_s50_r110_b20 | continuous | 30 | $1,174,655 | 93.94% | -23.63% |
| mix_v105_s50_r110_b20 | continuous | 60 | $623,398 | 73.42% | -25.38% |
| mix_v105_s50_r110_b20 | fresh2023 | 30 | $978,413 | 164.56% | -23.00% |
| mix_v105_s50_r110_b20 | fresh2023 | 60 | $671,390 | 138.75% | -25.09% |
| mix_v105_s75_r100_b10 | continuous | 30 | $1,373,696 | 99.37% | -25.29% |
| mix_v105_s75_r100_b10 | continuous | 60 | $704,302 | 77.19% | -26.67% |
| mix_v105_s75_r100_b10 | fresh2023 | 30 | $1,170,051 | 177.78% | -24.88% |
| mix_v105_s75_r100_b10 | fresh2023 | 60 | $743,522 | 145.49% | -26.39% |
| mix_v105_s75_r100_b20 | continuous | 30 | $1,363,341 | 99.11% | -25.96% |
| mix_v105_s75_r100_b20 | continuous | 60 | $745,447 | 78.98% | -26.97% |
| mix_v105_s75_r100_b20 | fresh2023 | 30 | $1,159,595 | 177.10% | -24.96% |
| mix_v105_s75_r100_b20 | fresh2023 | 60 | $787,411 | 149.35% | -26.66% |
| mix_v105_s75_r110_b10 | continuous | 30 | $1,591,156 | 104.61% | -26.73% |
| mix_v105_s75_r110_b10 | continuous | 60 | $845,680 | 83.01% | -26.19% |
| mix_v105_s75_r110_b10 | fresh2023 | 30 | $1,307,807 | 186.33% | -25.95% |
| mix_v105_s75_r110_b10 | fresh2023 | 60 | $878,824 | 156.93% | -27.80% |
| mix_v105_s75_r110_b20 | continuous | 30 | $1,614,654 | 105.14% | -26.95% |
| mix_v105_s75_r110_b20 | continuous | 60 | $843,681 | 82.93% | -26.23% |
| mix_v105_s75_r110_b20 | fresh2023 | 30 | $1,325,794 | 187.40% | -26.41% |
| mix_v105_s75_r110_b20 | fresh2023 | 60 | $895,061 | 158.22% | -27.96% |
| vvix_confirmation_110 | continuous | 30 | $1,031,699 | 89.55% | -23.03% |
| vvix_confirmation_110 | continuous | 60 | $532,829 | 68.68% | -24.03% |
| vvix_confirmation_110 | fresh2023 | 30 | $879,755 | 157.01% | -22.99% |
| vvix_confirmation_110 | fresh2023 | 60 | $581,405 | 129.57% | -23.90% |
| mix_v110_s50_r100_b20 | continuous | 30 | $1,017,708 | 89.09% | -22.81% |
| mix_v110_s50_r100_b20 | continuous | 60 | $525,965 | 68.29% | -27.30% |
| mix_v110_s50_r100_b20 | fresh2023 | 30 | $871,080 | 156.31% | -22.18% |
| mix_v110_s50_r100_b20 | fresh2023 | 60 | $614,160 | 133.02% | -22.61% |
| mix_v110_s50_r110_b10 | continuous | 30 | $1,172,341 | 93.87% | -23.46% |
| mix_v110_s50_r110_b10 | continuous | 60 | $569,457 | 70.67% | -24.96% |
| mix_v110_s50_r110_b10 | fresh2023 | 30 | $978,385 | 164.56% | -23.36% |
| mix_v110_s50_r110_b10 | fresh2023 | 60 | $661,815 | 137.82% | -24.92% |
| mix_v110_s50_r110_b20 | continuous | 30 | $1,176,553 | 93.99% | -23.49% |
| mix_v110_s50_r110_b20 | continuous | 60 | $574,294 | 70.92% | -26.10% |
| mix_v110_s50_r110_b20 | fresh2023 | 30 | $973,911 | 164.23% | -22.87% |
| mix_v110_s50_r110_b20 | fresh2023 | 60 | $673,074 | 138.91% | -25.05% |
| mix_v110_s75_r100_b10 | continuous | 30 | $1,354,826 | 98.89% | -25.75% |
| mix_v110_s75_r100_b10 | continuous | 60 | $671,245 | 75.70% | -26.21% |
| mix_v110_s75_r100_b10 | fresh2023 | 30 | $1,152,520 | 176.64% | -25.44% |
| mix_v110_s75_r100_b10 | fresh2023 | 60 | $740,218 | 145.19% | -26.41% |
| mix_v110_s75_r100_b20 | continuous | 30 | $1,388,381 | 99.75% | -25.52% |
| mix_v110_s75_r100_b20 | continuous | 60 | $701,408 | 77.06% | -26.57% |
| mix_v110_s75_r100_b20 | fresh2023 | 30 | $1,141,902 | 175.94% | -25.55% |
| mix_v110_s75_r100_b20 | fresh2023 | 60 | $790,355 | 149.61% | -26.62% |
| mix_v110_s75_r110_b10 | continuous | 30 | $1,595,262 | 104.71% | -26.75% |
| mix_v110_s75_r110_b10 | continuous | 60 | $759,167 | 79.56% | -27.43% |
| mix_v110_s75_r110_b10 | fresh2023 | 30 | $1,311,156 | 186.53% | -25.95% |
| mix_v110_s75_r110_b10 | fresh2023 | 60 | $881,903 | 157.18% | -27.80% |
| mix_v110_s75_r110_b20 | continuous | 30 | $1,618,357 | 105.23% | -26.94% |
| mix_v110_s75_r110_b20 | continuous | 60 | $766,352 | 79.85% | -27.80% |
| mix_v110_s75_r110_b20 | fresh2023 | 30 | $1,328,862 | 187.58% | -26.45% |
| mix_v110_s75_r110_b20 | fresh2023 | 60 | $897,160 | 158.38% | -27.97% |

## Evidence And Limits

- Adjacent-factor comparisons hold all other settings constant; VVIX neighbors are 100/105 and 105/110. Every edge and all eight deltas are retained in the JSON, not just the winning combinations.
- Every yearly return, drawdown and closed ticker/sleeve episode count is retained for all 192 comparisons. A trade is a completed ticker exposure, not a broker order or partial fill.
- Saved new-case stop events use split-adjusted historical price units. They are not directly comparable to nominal broker/minute prices without conversion. No new minute, NBBO, queue or partial-fill validation is claimed here.
- No automatic deployment. New releases require execution/data gates and a safe lifecycle, not a hash rewrite of the running paper strategy. Monthly activation and the current capital baseline remain unchanged.
- This batch narrows candidates; it does not justify an unlimited search for the largest in-sample number. Independent forward execution and sensitivity to nearby settings are the next useful evidence.

Verification: 6 relevant test programs passed; 1138862 curve/accounting/identity checks; 25263 historical stop records preserved. No operational database writes or orders.

```sh
php tests/candidate_interaction_study.php
php tests/candidate_interaction_assessment.php
php -d memory_limit=8G tools/research_candidate_interactions_20260915.php
php -d memory_limit=1G tools/summarize_candidate_interactions_20260915.php
```
