# Hybrid-v4: research and paper readiness, 2026-09-14

## Actual outcome

The existing paper runtime was recovered. The researched trading candidate was **not deployed** and is **not qualified for order submission**. No live activation, credential/host changes, manual orders, liquidation, strategy edits, stored identity edits, or protective-gate bypass occurred.

The deployed strategy remains `causal-stock-rotation-hybrid-v4`, four sleeves, run `hybrid-v4-paper-2026-09-07`. Neither the selected historical maximum nor the twelve-sleeve research candidate is the deployed strategy.

Machine-readable evidence: [paper_candidate_20260914.json](research_results/paper_candidate_20260914.json).

## Repairs applied to paper

The tactical LaunchAgent PID was alive, but its child executor repeatedly exited 255. The exact error was a PHP 128 MiB memory exhaustion in `TacticalPortfolioWeeklySummary.php:64`. Its snapshot loader materializes full records and the weekly summary copies/sorts them. A read-only reproduction with 28,704 real snapshots reached 128 MiB before the rest of the executor was considered.

Applied a finite **512 MiB** PHP limit scoped to this LaunchAgent and inherited by its PHP children through `PHP_INI_SCAN_DIR`. The leading empty scan-directory entry preserves the default PHP configuration/extensions. The installer now emits the same profile. The inherited limit and SQLite extension are covered by a subprocess test. This is a resource mitigation, not a streaming rewrite of the frozen runtime. [PHP configuration reference](https://www.php.net/manual/en/configuration.file.php).

Only the existing tactical LaunchAgent was booted out and bootstrapped once, after confirming the old PID and held lock disappeared. PID changed from 47708 to 33152. Legacy PID 1477 was untouched. The first restored executor completed at 20:06:59 UTC, exited **2 rather than 255**, wrote a fresh cycle/snapshot and delivered its scheduled Telegram catch-up. Exit 2 is the existing signal qualification block, not successful trading authorization.

The exporter also had a clock-ordering bug: it captured `now` before slow broker reads, then compared a newer heartbeat with that old time. Moving observation time after the reads removes a false future/stale classification. Genuine stale/future timestamps, failed executor checks and all trading gates are unchanged and regression-tested.

At 20:09:49 UTC:

- Paper account guard verified; equity/cash **$27,567.66**, positions/open orders/intents/fill audits **0**.
- Both trading LaunchAgents and status-export are registered; trading PIDs match locks and fresh heartbeats.
- Telegram **94 delivered**, up from 93; no pending/failed outbox. The extra message was the daemon's scheduled notification, not a synthetic manual trade alert.
- Runtime hash and strategy hash match the existing run. Activation remains **2026-09-07T20:00:49Z**.
- Trading authorization remains `validation_selected=false`, `signal_plan_blocked:9b9de15ec1ae`, `blocked_signal_or_plan`; all four targets HOLD. Health is not reported as green.
- Direct month report: **7 days, 5/20 market dates**, 0 return/drawdown, 0 exits; earliest calendar review **2026-10-08T20:00:49Z**. This is a future manual live-review condition, not permission to switch to live.

Evidence is in `var/reports/paper_candidate_20260914/`: `recovery_before.json`, `identity_after_recovery.json`, `operational_final/`, and `operational_final_verification.json`.

## Candidate replay and gate

All main comparisons use Alpaca SIP, `adjustment=all`, next-session-open decisions, 30 bps one-way transaction costs, hypothetical $30,000 capital, and end **2026-09-04**. January 2023 is a genuinely fresh start, not a slice of the continuing 2021 portfolio. Returns are simulations, not broker P/L.

| Research configuration | CAGR since 2021 | Maximum DD | CAGR from fresh 2023 | Maximum DD |
|---|---:|---:|---:|---:|
| User-selected previous maximum | 87.90% | -30.67% | 121.48% | -29.89% |
| Existing research candidate: stop12, three phases, cost band 2 | 87.83% | -23.95% | 142.88% | -23.59% |
| Candidate plus 5% volatility-scaled QQQ | 86.25% | -23.81% | 139.79% | -23.27% |

The candidate was found in the earlier September 10 work; this run reproduced its curves exactly, not discovered it anew. Its full-period CAGR does not exceed the selected original maximum. Its improvement is lower historical drawdown and a better fresh-2023 result.

The historical gate was recomputed, not merely read from an old flag:

| Failed requirement | Actual | Required |
|---|---:|---:|
| Train 2021-2023 CAGR | 67.89% | 100% |
| Validation 2024-2025 CAGR | 81.32% | 100% |
| Train CAGR excluding five best days | 32.21% | 40% |
| Validation CAGR excluding five best days | 39.31% | 40% |

These are **internal project criteria**, not Alpaca's universal requirements for a demo account. They were not lowered to force a PASS. A research result failing these checks cannot honestly become `validation_selected=true` under the existing qualification policy.

Additional deployment gaps remain: twelve independent sleeves versus the executor/repository's four-sleeve contract; close-updated standing stops and shared portfolio circuit; fractional historical versus whole-share operational sizing; SIP/all research versus operational frozen SIP/split plus recent IEX. Minute touch confirmation alone is not native broker stop/reconciliation parity. An Alpaca trailing stop follows an intraday high-water mark and must not silently replace a close-updated research stop. [Alpaca order semantics](https://docs.alpaca.markets/us/docs/orders-at-alpaca).

## Stop execution sensitivity

Eight complete path-dependent candidate replays were run. Extra slippage applies to intraday standing-stop fills; gap-open handling stays as in the frozen engine. This is not a full delay model for every gap/auction execution.

| Intraday stop assumption | CAGR since 2021 | DD | Fresh-2023 CAGR | DD |
|---|---:|---:|---:|---:|
| Frozen baseline | 87.83% | -23.95% | 142.88% | -23.59% |
| Additional 0.5% slippage | 85.09% | -23.15% | 137.25% | -23.49% |
| Additional 1% slippage | 73.42% | -25.51% | 128.14% | -23.68% |
| Fill at observed daily low, adverse diagnostic | 59.61% | -25.60% | 116.35% | -23.83% |

Daily-low execution is an ex-post stress assumption, never an executable trading rule. Changes in costs can change later controller states and holdings, so DD need not move monotonically.

For the two baseline starts, 567 sleeve-level events collapse to **67 unique symbol/date/stop/fill events**, covering 55 symbol-days and 53 dates. All 67 had a regular-session minute low touching the stop and an opening minute. 39 symbol-days reused frozen Alpaca minute evidence; 16 were fetched. The next contiguous minute's open was worse than the daily fill in 34/67 cases; worst **-300.04 bps**, 5th percentile **-133.08 bps**. These are diagnostics, not executable fill guarantees or NBBO-election proof. Alpaca paper itself omits several live execution frictions. [Paper simulation limitations](https://docs.alpaca.markets/us/docs/paper-trading).

## New standard algorithms

Implemented an isolated, cash-funded, long-only ETF research engine and fixed **108 configurations** before looking at their results:

- 24 absolute/time-series momentum variants: 63/126/252 sessions, four economic baskets, equal/inverse-volatility weights.
- 12 dual-momentum variants: relative ranking plus absolute hurdle versus SHY, one/two holdings.
- 24 Donchian variants: 20/55/100-session entry ranges and 10/20-session exits.
- 36 Wilder RSI(2) mean-reversion variants: SPY/QQQ/IWM, entry 5/10/20, exit 50/70, five/ten-session holds, SMA200 trend filter.
- 12 volatility-scaled SPY/QQQ variants: 20/60-session volatility and 10/20/30% annualized targets, capped at 100% investment.

The baskets use SPY, QQQ, IWM, EFA, EEM, GLD, IEF, TLT, HYG, XLK, XLF, XLP and XLU; SHY supplies the dual-momentum hurdle. No Yahoo prices, shorts, options or borrowed satellite capital. Scheduled strategies rebalance on fixed session intervals, not calendar-month ends. Close signals trade at the following open. Exact post-cost target sizing pays costs from capital; no hidden cash borrowing. Satellites receive static initial allocations of 5/10/20%; they are not free daily-rebalanced portfolios and are not governed by the stock strategy's circuit.

Completed **648 standalone replays**: continuous 2021, fresh 2023, and earlier 2017-2020, each at 30/60 bps. Also 108 actual-history truncated-prefix replays and **2,592 mixed comparisons** against both original maximum and candidate. The 60-bps candidate control keeps its original switching hurdle fixed, using the proven band-1-at-60 versus band-2-at-30 equivalence.

Result: **zero** additions increased CAGR versus their own anchor on both starts and both cost levels; zero passed even the necessary train/validation CAGR checks. Full qualification was not claimed for these mixed portfolios. Lower-risk mixes exist, but reduce CAGR rather than add profit. No new independent holdout or statistically confirmed new alpha was established. Failed ideas were retained, not hidden.

## Alpaca data anomaly

A fixed large-wick diagnostic found one flagged ETF bar: **SPY, 2026-02-02**, daily low **68.64**, open **685.90**, close **691.70**. A fresh Alpaca daily request returned the same anomalous low. All 390 regular-session Alpaca minute bars instead had a minimum of **685.75** and a maximum of **693.21**.

The frozen files were not changed. A separately labelled sensitivity replay replaced only that bar's extrema with the complete RTH minute range, keeping daily open/close/volume. This is not a canonical vendor correction or an approved production data source.

Another **432 standalone replays and 2,592 mixed comparisons** reproduced zero profit-improving additions. None of the 108 continuous-run CAGRs changed; **37 drawdown results changed**. For example, volatility-scaled SPY 20/20 had raw DD -90.11%, versus -19.71% in this diagnostic. Raw ETF DD comparisons are therefore not trustworthy where that wick is encountered. The raw and diagnostic series are retained separately.

## Internet findings

- July 21, 2026: Sepp and Lucic analyze trend systems, volatility normalization and trading-cost effects. This motivates testing transparent trend/volatility baselines, not importing a promised return. The ETF tests here are not a replication of the paper's futures results. [Paper](https://arxiv.org/abs/2607.19497).
- July 1, 2026: the CVaR work distinguishes abrupt jump protection from slower trend-based drawdown protection. Its stylized simulations do not prove that adding puts will improve this stock strategy. No option backtest was invented without historical option-chain and execution data. [Paper](https://arxiv.org/abs/2607.00883).
- Vibe-Trading changes through September 14 emphasize correctness: A-share data accidentally routed through a crypto engine, missing run evidence, and lost structured diagnostics were repaired. These are useful engineering lessons, not transferable alpha. No foreign agent, skill or broker connector was installed. [Market routing fix](https://github.com/HKUDS/Vibe-Trading/pull/1431), [run evidence fix](https://github.com/HKUDS/Vibe-Trading/pull/1413), [diagnostics fix](https://github.com/HKUDS/Vibe-Trading/pull/1424).
- Classical trend and strategy examples were reviewed as baselines, not assumed profitable locally. [AQR evidence](https://www.aqr.com/Insights/Research/Journal-Article/A-Century-of-Evidence-on-Trend-Following-Investing), [QuantConnect library](https://www.quantconnect.com/docs/v2/writing-algorithms/strategy-library).

## Verification and reproducibility

**35/35 test programs passed**, including cash/cost conservation, all 108 configuration prefixes and future-shock invariance, missing-data rejection, existing account/identity/order/month guards, LaunchAgent rollback, child PHP configuration inheritance and exporter time-ordering. `git diff --check` passed. The parent candidate's 22 frozen research code hashes and both complete baseline curves still match. The execution runtime hash independently matches the stored active run.

Commands, from the operational repository:

```sh
php -d memory_limit=4G tools/research_standard_etfs_20260914.php
php -d memory_limit=4G tools/audit_paper_candidate_20260914.php
php -d memory_limit=2G tools/audit_candidate_minutes_20260914.php
php -d memory_limit=2G tools/audit_etf_data_20260914.php
php -d memory_limit=4G tools/audit_standard_etf_wicks_20260914.php
php -d memory_limit=4G tools/verify_paper_candidate_20260914.php tests
php tools/verify_paper_candidate_20260914.php operational operational_final
php tools/summarize_paper_candidate_20260914.php
```

The operational verification reads a previously exported isolated snapshot; it does not fetch a new one automatically. Data/code manifests guard historical resumes. Development retries are not counted as extra hypotheses. The first development output with incorrectly named summary threshold keys is quarantined in `standard_etfs_20260914_invalid_gate_summary_attempt`; the corrected run fails closed on PHP warnings and is the only primary result used here.

Remaining outcome: the request to deploy an improved qualified strategy has **not** been completed. Recovery and infrastructure fixes are deployed; the candidate must not be represented as trading on demo merely because its research files exist.
