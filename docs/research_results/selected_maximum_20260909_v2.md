# Selected Maximum Rebase: 2026-09-09

Primary: Alpaca SIP adjustment=all, 2023-01-03 through 2026-09-04, fresh $30,000, 30 bps per traded notional.
Research baseline selected by the user: `balanced__global_confirmed_f6156ed86a11`. Operational deployment is unchanged.

## Comparable Results

| Configuration | CAGR | Total return | Max drawdown | Closed trades | Terminal equity |
|---|---:|---:|---:|---:|---:|
| Current operational algorithm (historical model) | 96.59% | 1094.05% | -39.54% | 78 | $358,215.92 |
| Previous balanced | 96.05% | 1082.02% | -37.08% | 94 | $354,605.22 |
| Selected previous maximum | 121.48% | 1748.99% | -29.89% | 98 | $554,698.00 |
| Selected maximum + stop12 only | 139.86% | 2377.12% | -29.51% | 106 | $743,136.64 |
| Previous stop12 wrapper | 107.65% | 1359.44% | -23.66% | 90 | $437,831.86 |
| `maximum_stop12__algorithm__efficiency_score_60_0p5_all` (hindsight) | 174.70% | 3974.22% | -22.56% | 96 | $1,222,266.82 |
| `maximum_stop12__breadth_replace__vvix_inverse_120_1.25` (hindsight) | 171.93% | 3825.66% | -37.17% | 103 | $1,177,696.87 |
| `maximum_stop12__algorithm__smoothed_score_5_0p5_all` (hindsight) | 171.32% | 3793.16% | -20.62% | 101 | $1,167,947.06 |
| Maximum: reentry VVIX below 90 | 122.16% | 1770.03% | -24.52% | 96 | $561,010.42 |

## Annual Returns and Closed Trades

| Configuration | 2023 | 2024 | 2025 | 2026 through Sep 4 |
|---|---:|---:|---:|---:|
| Current operational algorithm (historical model) | 176.23% / 14 | 56.06% / 21 | 60.65% / 25 | 72.42% / 18 |
| Previous balanced | 211.92% / 23 | 36.45% / 22 | 56.50% / 31 | 77.46% / 18 |
| Selected previous maximum | 211.92% / 23 | 69.77% / 26 | 91.38% / 31 | 82.44% / 18 |
| Selected maximum + stop12 only | 238.80% / 19 | 93.44% / 33 | 102.19% / 34 | 86.93% / 20 |
| Previous stop12 wrapper | 220.01% / 18 | 52.30% / 29 | 113.27% / 28 | 40.41% / 15 |
| `maximum_stop12__algorithm__efficiency_score_60_0p5_all` | 285.20% / 21 | 122.69% / 28 | 134.67% / 27 | 102.39% / 20 |
| `maximum_stop12__breadth_replace__vvix_inverse_120_1.25` | 284.56% / 24 | 157.06% / 33 | 80.67% / 26 | 119.80% / 20 |
| `maximum_stop12__algorithm__smoothed_score_5_0p5_all` | 291.88% / 18 | 153.68% / 29 | 120.02% / 32 | 77.99% / 22 |
| Maximum: reentry VVIX below 90 | 211.92% / 23 | 69.77% / 26 | 93.61% / 29 | 82.40% / 18 |

A trade is a completed aggregate ticker exposure, assigned to its exit year. Partial resizes are not additional trades; open end positions are not force-closed.

## Robustness

| Configuration | 60bps CAGR / DD | Continuous 2021 CAGR / DD | Expanded 68 CAGR / DD | Earlier 2017-2020 CAGR / DD | Original gate |
|---|---:|---:|---:|---:|---|
| Current operational algorithm (historical model) | 78.43% / -40.91% | 74.52% / -38.86% | -0.43% / -63.24% | 19.53% / -42.05% | train_cagr, validation_cagr, validation_drawdown, full_drawdown, negative_years, validation_ex_top5_days_cagr, holdout_ex_top5_days_cagr |
| Previous balanced | 79.65% / -35.99% | 86.50% / -30.88% | 29.89% / -37.55% | 29.54% / -31.82% | validation_cagr, validation_ex_top5_days_cagr, holdout_ex_top5_days_cagr |
| Selected previous maximum | 98.84% / -30.75% | 87.90% / -30.67% | 32.64% / -32.59% | 16.56% / -31.43% | validation_cagr, validation_ex_top5_days_cagr |
| Selected maximum + stop12 only | 91.54% / -33.30% | 73.27% / -27.84% | 22.25% / -53.13% | 15.95% / -31.44% | train_cagr, validation_cagr, validation_ex_top5_days_cagr, holdout_ex_top5_days_cagr |
| Previous stop12 wrapper | 85.16% / -29.44% | 69.80% / -26.10% | 19.31% / -43.66% | 38.76% / -28.40% | train_cagr, validation_cagr, validation_ex_top5_days_cagr, holdout_ex_top5_days_cagr |
| `maximum_stop12__algorithm__efficiency_score_60_0p5_all` | 129.49% / -31.18% | 51.63% / -23.99% | 37.16% / -45.45% | 22.33% / -25.58% | train_cagr, validation_cagr, holdout_cagr, holdout_top1_day_share, train_ex_top5_days_cagr, validation_ex_top5_days_cagr, holdout_ex_top5_days_cagr |
| `maximum_stop12__breadth_replace__vvix_inverse_120_1.25` | 132.17% / -39.87% | 85.18% / -35.07% | 16.44% / -56.83% | 2.69% / -50.49% | train_cagr, validation_cagr, validation_drawdown, full_drawdown, full_gross_bound, negative_years, validation_ex_top5_days_cagr |
| `maximum_stop12__algorithm__smoothed_score_5_0p5_all` | 126.83% / -26.38% | 43.73% / -28.57% | 3.80% / -44.43% | 21.14% / -28.72% | train_cagr, validation_cagr, train_ex_top5_days_cagr, validation_ex_top5_days_cagr, holdout_ex_top5_days_cagr |
| Maximum: reentry VVIX below 90 | 100.75% / -24.99% | 86.89% / -30.67% | 30.53% / -37.49% | 16.74% / -29.32% | validation_cagr, validation_ex_top5_days_cagr |

## Coverage and Verification

- 2197 declared recipes: 1999 unique effective configurations, 192 duplicates, 6 rejected above the unchanged gross limit.
- 219 ETF event definitions, 438 cost replays, 584 unique SVXY static mixes at 2.5/5/10/20% initial capital, 1168 mix evaluations.
- 426 audit replays; 49 actual truncated-input prefixes; four exact reproductions of the previous comparison.
- 27/27 tests passed. Original operational identity unchanged; zero orders submitted.
- Centered 20-session block-bootstrap max-mean excess test: 2582 cases, 420 later sessions, p=0.9461. This does not correct all prior searches.
- Minute event audit: 73 distinct stop events; 73 RTH touches; 38/73 next-minute proxies worse than the daily fill. Not a full minute replay.

## Limits

- All historical periods have been observed before; parameter search is exploratory, not new untouched evidence.
- Primary 2023 restart is not a slice of the continuous 2021 equity path. Qualification uses the original 2021/2024/2026 splits only on audited cases.
- ETF satellite is an independent static allocation outside the main portfolio stop; no live integration exists.
- Daily adjusted data is not a cash/share corporate-action ledger; fixed-universe survivorship remains unresolved.
- Split/spinoff sensitivity changes equity bars; the SIP-all SVXY/market confirmation history and S5TW/VVIX snapshots remain fixed.
- Portfolio circuit exits at the next open after an observed breach. A standing stop is not a portfolio drawdown guarantee.
- Native broker stop lifecycle and persisted portfolio circuit are not implemented in the operational executor.
- S5TW/VVIX are frozen research data, not monitored live production feeds.
- Increased fees, conservative day-low stop fills and minute event checks are diagnostics, not a full execution simulator.

## Indicator Groups

Below are the family leaders selected by 2023-2024 Calmar, not the highest full-period hindsight returns. Some full-period leaders also won their training groups.

| Family | Selected configuration | CAGR 2023 onward | Max drawdown |
|---|---|---:|---:|
| s5tw | `maximum_stop12__breadth_replace__s5tw_high_touch_11_80_20_0.5` | 131.41% | -30.55% |
| s5tw_sensitivity | `maximum__breadth_replace__s5tw_sensitivity_high_fade80_11_75_10_0.5` | 98.88% | -39.78% |
| svxy | `maximum_stop12__breadth__svxy_return_20_0.75` | 139.84% | -40.69% |
| vvix | `maximum__breadth_replace__vvix_sma_ratio_1.05_0.5_10` | 95.09% | -30.59% |
| refinement | `maximum__refinement__svxy200_0.75_high80_10_1.25_vvix120_0.75` | 99.97% | -33.02% |
| confirmation_sensitivity | `maximum__confirmation__vvix90_svxy20_trend20` | 122.16% | -24.52% |

The train-selected SVXY satellite mix has CAGR 120.13% and drawdown -29.74% versus 121.48% and -29.89% for its unmodified main anchor. Each of 584 SVXY mixes was also evaluated at 60 bps. Buying SVXY directly did not increase full-period CAGR versus its own main anchor in this grid.

The expanded-universe basket audit exceeded a 2 GiB PHP worker cap; only that research worker was resumed with 6 GiB. Frozen code, data, recipes and trading limits were unchanged; all 426 audit jobs completed.

A stop does not guarantee its execution price: [Alpaca order documentation](https://docs.alpaca.markets/us/docs/orders-at-alpaca). Data endpoint: [Alpaca historical bars](https://docs.alpaca.markets/us/reference/stockbars).

Full machine-readable metrics: [selected_maximum_20260909_v2.json](selected_maximum_20260909_v2.json). Frozen recipes, hashes, curves and every replay are in `var/reports/selected_maximum_20260909/`.
