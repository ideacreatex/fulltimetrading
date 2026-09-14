# Algorithm research: sources and interpretation

Research date: 2026-09-08. Scope: the existing US-stock hybrid-v4 model, not
penny stocks. Publication dates below are not dates of the underlying market
sample. A recent paper is a hypothesis source, not evidence of our profitability.

## What is current

### Cost-aware trading and turnover control

Zarattini, Pagani and Wilcox revisit stock trend following using 1991-2024
history. Their central implementation issue is trading cost; a turnover-control
algorithm materially changes the feasibility of the strategy. This motivates
testing a no-trade band, but does not establish a suitable band for hybrid-v4.
Source: [Does Trend-Following Still Work on Stocks?](https://papers.ssrn.com/sol3/papers.cfm?abstract_id=5084316).

Abbade and Costa, submitted 2026-03-30 and revised 2026-04-04, compare five RL
algorithms under fixed and nonlinear impact models. Their reported rankings
depend strongly on the execution-cost model. This is a preprint, not a
universally validated production method. Our 20/30/40 bps stresses are not a
replication of their nonlinear-impact experiments.
Source: [Realistic Market Impact Modeling for Reinforcement Learning Trading Environments](https://arxiv.org/abs/2603.29086).

### Risk-adaptive allocation, not just more accurate predictions

Schwarz's international study, published in Journal of Empirical Finance 80
(2025), reports mixed evidence after trading costs. Downside-risk scaling is
promising in some factors and markets, not uniformly. The research predates its
2025 journal publication; downside volatility itself is not a new invention.
Source: [On the performance of volatility-managed equity factors](https://papers.ssrn.com/sol3/papers.cfm?abstract_id=3951115).

Kashif and Slepaczuk's 2026-05-17 preprint combines continuous portfolio weights,
transaction costs, turnover penalties, diversification and walk-forward tests.
Its abstract explicitly does not find significant excess returns over buy and
hold across every market. This motivates separate regime/risk/turnover tests,
not an assumption that replacing rules with RL will improve the account.
Source: [Deep Reinforcement Learning Framework for Diversified Portfolio Management Across Global Equity Markets](https://arxiv.org/abs/2605.17307).

### Foundation models and covariates

Amazon released Chronos-2 on 2025-10-20 with multivariate and covariate-informed
forecasting. Its benchmark gains measure forecast quality, not net stock-trading
P/L. The present experiment does not download, train or claim to backtest Chronos.
Source: [Introducing Chronos-2](https://www.amazon.science/blog/introducing-chronos-2-from-univariate-to-universal-forecasting).

Google's 2026 TimesX work emphasizes richer context, data quality and leakage
control. It reports that some methods successful on earlier benchmarks do not
generalize to its benchmark. We do not have a complete point-in-time historical
news/fundamental dataset here, so news and modern LLM scores are not retroactively
inserted into the 2021 backtest.
Source: [Rethinking Context-Enriched Time-Series Forecasting Evaluation](https://research.google/pubs/rethinking-context-enriched-time-series-forecasting-evaluation/).

### Leakage and multiple comparisons

Zhang and Stadie's preprint submitted 2026-08-04 studies contamination measurement
in LLM backtests. A simple before/after training-cutoff comparison is not enough
in their framework. Our response is to keep this round price-only, compare
physically truncated history with the full-run prefix, freeze candidates before
evaluation and distinguish a training-selected candidate from a hindsight winner.
Source: [Temporal Leakage in LLM Backtesting](https://arxiv.org/abs/2608.02985).

These measures do not remove the pre-existing hand-selected-universe bias or
make previously reviewed 2024-2026 data an untouched out-of-sample test. A
centered block-bootstrap maximum-statistic diagnostic adjusts only for this
declared family of candidates, not all earlier research decisions.

## Implemented experiment

The grid in `src/Research/AlgorithmTrendResearch.php` contains 156 new parameter
variants in 13 economic families, plus the unchanged baseline and previous
full-period winner. Each family has two windows or modes, three strengths and
two scopes (all sleeves or only the dynamic sleeve). These are correlated
variants, not 156 independent scientific discoveries.

| Family | Mechanism |
|---|---|
| Relative strength | Add the stock's trailing return minus SPY's return to its score. |
| Acceleration | Add short-horizon return minus half the two-horizon return. |
| Trend efficiency | Discount a score when the price path is noisy relative to its net move. |
| Volume confirmation | Scale scores by capped completed-bar volume relative to prior volume. |
| Smoothed scores | Blend current scores with a causal exponential average. |
| Breadth risk | Halve size when too few eligible universe stocks are above their SMA. |
| Multi-horizon trend votes | Reduce size when fewer than three of four horizons are positive. |
| Downside sizing | Blend ordinary volatility with scaled downside semideviation. |
| Tail sizing | Reduce size using the mean of the worst 20% of recent returns. |
| Overnight-gap budget | Reduce size when historical RMS overnight gaps exceed a budget. |
| Stock drawdown budget | Reduce size as the selected stock falls from its trailing high. |
| Volatility-of-volatility | Reduce size when short-term volatility is unstable. |
| Resize bands | Skip small same-stock resizes, never exits, switches or overweight reductions. |

The existing market filter, pause, trailing exits, margin interest, maximum gross
and qualification criteria remain in the replay. No operational profile, paper
run, API host/key, LaunchAgent or broker order is changed by these tools.

All signals use completed bars; simulated execution is on a subsequent opening.
This does not prove that the modeled opening fills would be available. Feature
engineering has source-inspired economic motivation, but it is not an exact
replication of the cited trading systems or modern ML models.
