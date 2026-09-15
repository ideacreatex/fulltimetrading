# Deployed candidate sensitivity study, 2026-09-15

Status: offline completed research. No strategy switch, no orders, no live approval.

## Design

- 124 hypotheses plus the deployed control; 500 complete cases, four per recipe.
- Initial equity $27,567.66. Starts 2021-01-04 and 2023-01-03; both end 2026-09-04.
- Alpaca raw prices for prior-close integer sizing, split-adjusted bars for signals. Frozen S5TW and Cboe VVIX supplements; no Yahoo substitution.
- Costs 30 and 60 basis points (0.30% and 0.60% of traded notional); economic switching hurdle held constant.
- Calendar-day margin interest is modeled at the frozen 6.25% annual rate. The fixed stock basket is not a point-in-time membership/delisting universe; selection/survivorship bias remains possible.
- Both samples have already informed research. This is NOT independent holdout validation. More trials increase overfitting risk.
- Continuous 2021 and fresh 2023 runs are different portfolio paths, not slices of one equity curve.
- All input/source hashes, every recipe, every result and yearly trade counts are retained in the adjacent JSON; raw curves and ledgers are under var/reports/deployed_candidate_study_20260915.

## Results

2 non-publication recipes have non-worse terminal capital AND drawdown in all four conditions with at least one strict improvement. This is a descriptive historical comparison, not an automatic deployment gate.

12 recipes reduce drawdown by at least one percentage point somewhere without worsening it in any condition; some sacrifice capital. 4 recipes produce unchanged capital/drawdown, so they are not improvements.

CAGR is annualized compounded growth, not yearly guaranteed income. DD is maximum drawdown. Dollar amounts include the starting capital. Display selection includes money/DD dominators, defensive alternatives, and the largest 2021/30-bps CAGR; see all trials below.

### 30 bps

| Recipe | 2021 terminal | 2021 CAGR | 2021 DD | 2023 terminal | 2023 CAGR | 2023 DD |
|---|---:|---:|---:|---:|---:|---:|
| deployed | $1,028,889 | 89.46% | -23.06% | $877,381 | 156.82% | -22.99% |
| vvix_confirmation_110 | $1,031,699 | 89.55% | -23.03% | $879,755 | 157.01% | -22.99% |
| vvix_confirmation_105 | $1,029,258 | 89.47% | -23.03% | $878,118 | 156.87% | -22.99% |
| s5tw_high_close_20_1.25 | $1,015,801 | 89.03% | -22.95% | $868,764 | 156.13% | -22.35% |
| dynamic_weight_0.35 | $1,109,612 | 92.00% | -21.97% | $864,408 | 155.78% | -21.43% |
| svxy_cap_0.75 | $1,362,202 | 99.08% | -25.68% | $1,140,814 | 175.87% | -25.21% |
| risk_scale_1.1 | $1,169,835 | 93.80% | -23.63% | $968,239 | 163.81% | -23.36% |

### 60 bps

| Recipe | 2021 terminal | 2021 CAGR | 2021 DD | 2023 terminal | 2023 CAGR | 2023 DD |
|---|---:|---:|---:|---:|---:|---:|
| deployed | $523,894 | 68.17% | -24.81% | $580,167 | 129.43% | -23.90% |
| vvix_confirmation_110 | $532,829 | 68.68% | -24.03% | $581,405 | 129.57% | -23.90% |
| vvix_confirmation_105 | $556,433 | 69.97% | -24.57% | $580,258 | 129.44% | -23.90% |
| s5tw_high_close_20_1.25 | $528,169 | 68.42% | -24.60% | $615,491 | 133.16% | -22.84% |
| dynamic_weight_0.35 | $653,270 | 74.86% | -23.02% | $600,717 | 131.62% | -21.73% |
| svxy_cap_0.75 | $680,725 | 76.13% | -26.52% | $770,711 | 147.90% | -26.44% |
| risk_scale_1.1 | $591,304 | 71.81% | -25.00% | $659,383 | 137.58% | -24.97% |

## Earlier Market Regimes

24 additional cases: the six predeclared follow-up recipes, starts 2017-01-03 and 2019-01-02, end 2020-12-31, at both costs. Includes the 2018 decline and the COVID shock. No historical prices were invented before IPO: symbols with zero available bars (COIN) were excluded identically from every sleeve/case. This is still a selected stock basket, not a survivorship-free holdout.

Money/DD non-worse than control across all eight checked start/cost conditions: vvix_confirmation_105, vvix_confirmation_110. Overlapping periods do not provide independent statistical confirmations.

| Recipe | Cost, bps | 2017 CAGR | 2017 DD | 2019 CAGR | 2019 DD |
|---|---:|---:|---:|---:|---:|
| deployed | 30 | 48.74% | -20.35% | 80.90% | -21.37% |
| deployed | 60 | 14.60% | -26.61% | 25.61% | -27.26% |
| circuit_0.15_pause_10 | 30 | 27.40% | -21.56% | 36.91% | -25.04% |
| circuit_0.15_pause_10 | 60 | 17.46% | -22.79% | 28.94% | -24.24% |
| dynamic_weight_0.35 | 30 | 46.85% | -19.79% | 74.97% | -23.16% |
| dynamic_weight_0.35 | 60 | 32.99% | -25.79% | 26.04% | -27.28% |
| dynamic_weight_0.4 | 30 | 47.62% | -19.96% | 77.72% | -21.69% |
| dynamic_weight_0.4 | 60 | 14.87% | -25.97% | 26.12% | -27.02% |
| vvix_confirmation_105 | 30 | 48.74% | -20.35% | 80.90% | -21.37% |
| vvix_confirmation_105 | 60 | 19.55% | -26.61% | 37.43% | -27.26% |
| vvix_confirmation_110 | 30 | 48.74% | -20.35% | 80.90% | -21.37% |
| vvix_confirmation_110 | 60 | 22.48% | -26.61% | 42.98% | -27.26% |

## Yearly Comparison

2021-start, 30 bps. A trade is one completed aggregate ticker exposure, counted in its exit year; resizing and partial exits are NOT additional trades. No forced year-end close. 2026 ends September 4.

| Recipe | Year | Return | DD | Closed ticker episodes | Closed sleeve episodes |
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
| vvix_confirmation_105 | 2021 | 39.96% | -18.17% | 32 | 208 |
| vvix_confirmation_105 | 2022 | -16.72% | -17.63% | 8 | 38 |
| vvix_confirmation_105 | 2023 | 237.44% | -23.03% | 17 | 159 |
| vvix_confirmation_105 | 2024 | 74.16% | -23.02% | 33 | 208 |
| vvix_confirmation_105 | 2025 | 135.15% | -15.49% | 30 | 183 |
| vvix_confirmation_105 | 2026 | 131.78% | -19.33% | 21 | 190 |
| s5tw_high_close_20_1.25 | 2021 | 39.38% | -18.27% | 31 | 208 |
| s5tw_high_close_20_1.25 | 2022 | -16.93% | -17.84% | 8 | 38 |
| s5tw_high_close_20_1.25 | 2023 | 255.23% | -20.47% | 18 | 155 |
| s5tw_high_close_20_1.25 | 2024 | 65.87% | -22.95% | 34 | 206 |
| s5tw_high_close_20_1.25 | 2025 | 135.07% | -15.46% | 30 | 183 |
| s5tw_high_close_20_1.25 | 2026 | 129.78% | -19.35% | 21 | 190 |
| dynamic_weight_0.35 | 2021 | 48.60% | -17.05% | 32 | 209 |
| dynamic_weight_0.35 | 2022 | -16.93% | -17.83% | 8 | 41 |
| dynamic_weight_0.35 | 2023 | 257.61% | -19.85% | 17 | 158 |
| dynamic_weight_0.35 | 2024 | 71.02% | -21.97% | 33 | 206 |
| dynamic_weight_0.35 | 2025 | 136.59% | -14.72% | 30 | 183 |
| dynamic_weight_0.35 | 2026 | 125.33% | -19.01% | 21 | 190 |
| svxy_cap_0.75 | 2021 | 39.75% | -18.17% | 32 | 208 |
| svxy_cap_0.75 | 2022 | -17.04% | -17.95% | 8 | 38 |
| svxy_cap_0.75 | 2023 | 242.81% | -23.30% | 17 | 162 |
| svxy_cap_0.75 | 2024 | 102.15% | -24.48% | 33 | 208 |
| svxy_cap_0.75 | 2025 | 157.85% | -16.94% | 27 | 182 |
| svxy_cap_0.75 | 2026 | 138.54% | -19.40% | 21 | 190 |
| risk_scale_1.1 | 2021 | 43.10% | -17.64% | 32 | 209 |
| risk_scale_1.1 | 2022 | -17.05% | -17.97% | 8 | 38 |
| risk_scale_1.1 | 2023 | 252.47% | -22.95% | 17 | 155 |
| risk_scale_1.1 | 2024 | 72.45% | -23.63% | 34 | 204 |
| risk_scale_1.1 | 2025 | 139.04% | -14.57% | 30 | 189 |
| risk_scale_1.1 | 2026 | 146.05% | -19.80% | 20 | 188 |

## Every Trial

Deltas are relative to deployed control. Minimum means the worst of the four conditions. Gross exposure increases are shown separately: extra leverage is not free alpha.

| Recipe | Family | Min CAGR delta, pp | Min DD improvement, pp | Money/DD dominates | Gross never higher |
|---|---|---:|---:|---|---|
| deployed | control | 0.000 | 0.000 | no | yes |
| stop_0.08_pause_1 | position_stop | -42.899 | -3.636 | no | yes |
| stop_0.08_pause_3 | position_stop | -69.645 | -4.393 | no | yes |
| stop_0.08_pause_5 | position_stop | -64.326 | -6.926 | no | yes |
| stop_0.1_pause_1 | position_stop | -31.065 | -2.456 | no | yes |
| stop_0.1_pause_3 | position_stop | -42.432 | -9.780 | no | yes |
| stop_0.1_pause_5 | position_stop | -61.992 | -8.919 | no | yes |
| stop_0.12_pause_3 | position_stop | -19.225 | -0.734 | no | no |
| stop_0.12_pause_5 | position_stop | -17.393 | -2.657 | no | yes |
| stop_0.14_pause_1 | position_stop | -9.260 | -1.511 | no | no |
| stop_0.14_pause_3 | position_stop | -25.559 | -1.434 | no | yes |
| stop_0.14_pause_5 | position_stop | -33.458 | -12.133 | no | yes |
| stop_0.16_pause_1 | position_stop | -13.411 | -1.045 | no | no |
| stop_0.16_pause_3 | position_stop | -30.380 | -7.295 | no | no |
| stop_0.16_pause_5 | position_stop | -30.212 | -11.156 | no | yes |
| circuit_0.12_pause_3 | portfolio_circuit | -20.164 | 1.547 | no | no |
| circuit_0.12_pause_5 | portfolio_circuit | -16.209 | 1.600 | no | no |
| circuit_0.12_pause_10 | portfolio_circuit | -13.067 | 1.856 | no | no |
| circuit_0.15_pause_3 | portfolio_circuit | -6.645 | 0.036 | no | no |
| circuit_0.15_pause_5 | portfolio_circuit | -6.108 | 0.036 | no | no |
| circuit_0.15_pause_10 | portfolio_circuit | -5.338 | 0.036 | no | no |
| circuit_0.18_pause_3 | portfolio_circuit | 0.000 | 0.000 | no | yes |
| circuit_0.18_pause_10 | portfolio_circuit | 0.000 | 0.000 | no | yes |
| circuit_0.21_pause_3 | portfolio_circuit | -0.127 | -0.724 | no | yes |
| circuit_0.21_pause_5 | portfolio_circuit | -0.127 | -0.724 | no | yes |
| circuit_0.21_pause_10 | portfolio_circuit | -0.640 | -0.724 | no | no |
| dynamic_weight_0.35 | allocation | -1.041 | 1.086 | no | no |
| dynamic_weight_0.4 | allocation | -2.311 | 0.479 | no | no |
| dynamic_weight_0.45 | allocation | -1.203 | 0.269 | no | no |
| dynamic_weight_0.55 | allocation | 0.830 | -0.419 | no | no |
| dynamic_weight_0.6 | allocation | -5.578 | -1.835 | no | no |
| dynamic_weight_0.65 | allocation | 2.262 | -0.945 | no | no |
| switch_hurdle_0.5 | turnover | -7.746 | -0.981 | no | no |
| switch_hurdle_1 | turnover | -4.813 | -0.641 | no | yes |
| switch_hurdle_1.5 | turnover | -4.479 | -0.673 | no | no |
| switch_hurdle_2.5 | turnover | -1.852 | -0.327 | no | no |
| switch_hurdle_3 | turnover | -5.027 | -1.094 | no | yes |
| switch_hurdle_3.5 | turnover | -8.945 | -1.094 | no | no |
| switch_hurdle_4 | turnover | -10.709 | -1.965 | no | no |
| cadence_3_phases_1 | cadence | -5.782 | -1.510 | no | no |
| cadence_3_phases_2 | cadence | -9.186 | -2.141 | no | no |
| cadence_5_phases_3 | cadence | -21.191 | -0.544 | no | no |
| cadence_5_phases_5 | cadence | -16.365 | -0.570 | no | yes |
| cadence_10_phases_3 | cadence | -59.641 | -2.509 | no | no |
| risk_scale_0.6 | risk_size | -57.443 | 2.564 | no | yes |
| risk_scale_0.75 | risk_size | -21.595 | -0.376 | no | yes |
| risk_scale_0.9 | risk_size | -2.671 | -1.365 | no | yes |
| risk_scale_1.1 | risk_size | 3.632 | -1.067 | no | no |
| vvix_cap_90_0.5 | vvix_risk | -44.606 | 2.250 | no | no |
| vvix_cap_90_0.75 | vvix_risk | -11.135 | -0.934 | no | no |
| vvix_cap_100_0.5 | vvix_risk | -11.956 | 0.447 | no | yes |
| vvix_cap_100_0.75 | vvix_risk | -3.772 | -1.379 | no | no |
| vvix_cap_110_0.5 | vvix_risk | -4.450 | -1.424 | no | yes |
| vvix_cap_110_0.75 | vvix_risk | -2.076 | -2.127 | no | no |
| vvix_cap_120_0.5 | vvix_risk | -2.368 | -1.759 | no | yes |
| vvix_cap_120_0.75 | vvix_risk | -0.727 | -2.253 | no | no |
| vvix_confirmation_85 | vvix_reentry | -13.360 | -3.470 | no | yes |
| vvix_confirmation_90 | vvix_reentry | -3.087 | 0.000 | no | yes |
| vvix_confirmation_95 | vvix_reentry | -0.521 | -0.351 | no | yes |
| vvix_confirmation_105 | vvix_reentry | 0.010 | 0.000 | yes | yes |
| vvix_confirmation_110 | vvix_reentry | 0.091 | 0.000 | yes | yes |
| vvix_confirmation_120 | vvix_reentry | 0.091 | -0.615 | no | yes |
| svxy_trend_100 | svxy_risk | -4.137 | -2.786 | no | no |
| svxy_trend_150 | svxy_risk | -13.492 | -2.822 | no | yes |
| svxy_trend_250 | svxy_risk | -0.516 | -1.737 | no | yes |
| svxy_cap_0.25 | svxy_risk | -7.927 | -0.588 | no | no |
| svxy_cap_0.75 | svxy_risk | 7.957 | -2.620 | no | yes |
| s5tw_high_touch_5_1.1 | s5tw_high | -2.274 | -0.008 | no | no |
| s5tw_high_touch_5_1.25 | s5tw_high | -2.555 | -0.133 | no | yes |
| s5tw_high_touch_10_1.1 | s5tw_high | -1.259 | -0.124 | no | yes |
| s5tw_high_touch_20_1.1 | s5tw_high | -2.071 | -0.665 | no | yes |
| s5tw_high_touch_20_1.25 | s5tw_high | 1.139 | -0.494 | no | no |
| s5tw_high_close_5_1.1 | s5tw_high | -3.951 | -0.191 | no | yes |
| s5tw_high_close_5_1.25 | s5tw_high | -2.985 | -0.130 | no | yes |
| s5tw_high_close_10_1.1 | s5tw_high | -3.499 | -0.120 | no | no |
| s5tw_high_close_10_1.25 | s5tw_high | -1.380 | -0.147 | no | no |
| s5tw_high_close_20_1.1 | s5tw_high | -2.819 | -0.272 | no | no |
| s5tw_high_close_20_1.25 | s5tw_high | -0.690 | 0.105 | no | no |
| s5tw_high_hold2_5_1.1 | s5tw_high | -3.340 | -0.046 | no | no |
| s5tw_high_hold2_5_1.25 | s5tw_high | -4.404 | 0.022 | no | yes |
| s5tw_high_hold2_10_1.1 | s5tw_high | -2.911 | -0.060 | no | no |
| s5tw_high_hold2_10_1.25 | s5tw_high | -2.326 | -0.274 | no | no |
| s5tw_high_hold2_20_1.1 | s5tw_high | -2.446 | -0.357 | no | no |
| s5tw_high_hold2_20_1.25 | s5tw_high | 0.089 | -0.394 | no | no |
| s5tw_high_level_75 | s5tw_high | 0.691 | -0.304 | no | yes |
| s5tw_high_level_85 | s5tw_high | -1.354 | -0.013 | no | yes |
| s5tw_low_touch_3_0.5 | s5tw_low | -0.124 | -0.153 | no | no |
| s5tw_low_touch_3_1.25 | s5tw_low | -3.429 | -0.001 | no | yes |
| s5tw_low_touch_5_0.5 | s5tw_low | -0.212 | -0.157 | no | no |
| s5tw_low_touch_5_1.25 | s5tw_low | -3.417 | 0.000 | no | yes |
| s5tw_low_touch_10_0.5 | s5tw_low | 0.796 | -1.566 | no | yes |
| s5tw_low_touch_10_1.25 | s5tw_low | -3.466 | -0.023 | no | no |
| s5tw_low_reclaim11_3_0.5 | s5tw_low | -4.273 | -0.156 | no | yes |
| s5tw_low_reclaim11_3_1.25 | s5tw_low | -0.582 | -0.011 | no | yes |
| s5tw_low_reclaim11_5_0.5 | s5tw_low | -0.974 | -0.092 | no | no |
| s5tw_low_reclaim11_5_1.25 | s5tw_low | -0.734 | -0.153 | no | yes |
| s5tw_low_reclaim11_10_0.5 | s5tw_low | -0.941 | -0.326 | no | no |
| s5tw_low_reclaim11_10_1.25 | s5tw_low | -1.939 | -0.218 | no | no |
| s5tw_low_reclaim20_3_0.5 | s5tw_low | -4.306 | -0.176 | no | yes |
| s5tw_low_reclaim20_3_1.25 | s5tw_low | -0.735 | -0.154 | no | yes |
| s5tw_low_reclaim20_5_0.5 | s5tw_low | -0.723 | -0.109 | no | yes |
| s5tw_low_reclaim20_5_1.25 | s5tw_low | -0.734 | -0.015 | no | no |
| s5tw_low_reclaim20_10_0.5 | s5tw_low | -2.751 | -0.315 | no | yes |
| s5tw_low_reclaim20_10_1.25 | s5tw_low | -2.192 | -0.202 | no | no |
| downside_strength_0.1 | downside_size | -0.567 | -2.027 | no | no |
| downside_strength_0.25 | downside_size | -0.399 | -2.232 | no | yes |
| downside_strength_0.75 | downside_size | -0.607 | -0.322 | no | no |
| asset_sma_20 | asset_trend | -79.948 | -8.273 | no | no |
| asset_sma_100 | asset_trend | -11.850 | -1.548 | no | no |
| asset_sma_150 | asset_trend | -12.865 | -2.021 | no | no |
| vol_shock_1.5_0.5 | volatility_shock | -10.169 | -2.719 | no | no |
| vol_shock_1.5_0.75 | volatility_shock | -5.531 | -1.586 | no | no |
| vol_shock_2_0.5 | volatility_shock | 0.000 | 0.000 | no | yes |
| vol_shock_2_0.75 | volatility_shock | 0.000 | 0.000 | no | yes |
| rank_buffer_0.05 | rank_stability | -3.546 | -2.727 | no | no |
| rank_buffer_0.1 | rank_stability | -16.453 | -0.492 | no | no |
| rank_buffer_0.2 | rank_stability | -26.829 | -1.609 | no | no |
| rank_basket_2 | rank_diversification | -39.973 | -0.510 | no | yes |
| rank_basket_3 | rank_diversification | -61.342 | 0.333 | no | yes |
| publication_lag_s5tw_1 | publication_sensitivity | -1.023 | -0.605 | no | no |
| publication_lag_s5tw_2 | publication_sensitivity | -0.885 | -0.485 | no | no |
| publication_lag_svxy_1 | publication_sensitivity | 0.334 | -0.138 | no | yes |
| publication_lag_svxy_2 | publication_sensitivity | -0.219 | -0.816 | no | no |
| publication_lag_vvix_1 | publication_sensitivity | 0.000 | 0.000 | yes | yes |
| publication_lag_vvix_2 | publication_sensitivity | -0.003 | -0.001 | no | yes |

## Execution Limits And Next Evidence

- Daily-bar tests do not establish NBBO fills, queue priority or partial-fill timing. Alpaca paper itself omits market impact, latency slippage, fees and dividends, and randomly simulates partial fills: [Alpaca paper specification](https://docs.alpaca.markets/us/docs/paper-trading).
- OPG acceptance is not a fill. Native stop registration is not a guaranteed stop price or extended-hours protection: [Alpaca order specification](https://docs.alpaca.markets/us/docs/orders-at-alpaca).
- tools/audit_candidate_forward.php independently checks read-only broker observations, intent identities, fill/cash conservation and acknowledged close-based stop coverage. It never repairs, submits or cancels orders.
- Six publication-lag experiments are robustness diagnostics, not permission to feed stale indicators into the active runtime.
- A favorable recipe still requires execution parity, cost/minute checks, parameter-neighborhood review and future independent paper observations. This study does not modify its gate or replace the running release.
- Hourly continuation is attached to the existing task. It must not duplicate a running study, repeat unchanged results, or mutate the active manifest.

## Reproduction

```sh
php tests/deployed_candidate_study.php
php tests/deployed_candidate_comparison.php
php tests/paper_forward_execution_audit.php
php -d memory_limit=8G tools/research_deployed_candidate_20260915.php
php -d memory_limit=8G tools/stress_deployed_candidate_early_20260915.php
php -d memory_limit=1G tools/summarize_deployed_candidate_20260915.php
php tools/audit_candidate_forward.php
```

The 8G limit belongs only to offline research. The active paper daemon memory limit and release hash are unchanged.
Verification: 53 regression programs passed; 2939480 accounting/date/evidence assertions; 500 cases; no operational orders or database writes by research.
