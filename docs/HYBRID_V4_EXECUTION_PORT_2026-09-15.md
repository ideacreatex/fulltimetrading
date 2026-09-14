# Candidate execution port, 2026-09-15

This is an implementation milestone, not evidence of deployment or permission to trade.
The active four-sleeve paper runtime and its stored identities are unchanged.

## Frozen replay

`tools/fetch_candidate_execution_data.php` captured Alpaca SIP raw and split-only
daily histories through the completed 2026-09-14 session: 57,708 bars per dataset.
Capture manifests and checksums are in `var/reports/candidate_execution_data_20260914`.
The SIP request end is capped at the delayed-data entitlement boundary; no fallback
provider, API host or credentials were changed.

`tools/replay_candidate_execution.php` completed 18 cases, ending 2026-09-04:
two exact fractional regressions against the frozen all-adjusted candidate and
16 split-only execution comparisons. The latter cross two start dates, 30/60 bps,
the selected maximum/candidate, and fractional/whole-share sizing.
Full protocols, annual metrics, trade ledgers and curves are in
`var/reports/candidate_execution_20260915`.

Whole-share orders are sized from prior-close nominal prices and NAV, with a cost
reserve. Quantity is fixed before the opening price is known. An opening gap is
measured, not retrospectively resized away. Split-only returns exclude simulated
dividend reinvestment. The historical stop fill model is still a daily-price
simulation, not proof of executable NBBO fills or a maximum loss guarantee.

| Start / cost | Maximum CAGR | Candidate CAGR | Maximum drawdown | Candidate drawdown |
| --- | ---: | ---: | ---: | ---: |
| 2021-01-04 / 30 bps | 104.267% | 89.196% | -28.656% | -23.136% |
| 2021-01-04 / 60 bps | 66.398% | 74.165% | -29.026% | -24.504% |
| 2023-01-03 / 30 bps | 106.651% | 157.361% | -34.164% | -23.143% |
| 2023-01-03 / 60 bps | 86.317% | 129.461% | -24.639% | -23.954% |

All rows use whole-share sizing, independent static capital and $30,000 initial
equity. The 2021/30-bps maximum has a 1.406 intraday gross bound; the candidate's
four corresponding bounds are below 1.30. The 60-bps cost-band strength is halved
to preserve the original switching hurdle, rather than retuning the strategy.
These are execution sensitivities of an adaptively selected strategy, not a new
independent holdout or a forecast of paper/live returns.

## Implemented components

- `WholeShareSizing`: prior-close quantities, actual-open execution and cash/cost conservation.
- Versioned `PaperExecutionRotation*` replay forks leave the frozen parent engines unchanged.
- `CandidateOrder`: deterministic, immutable market/stop order identities and whole quantities.
- `CandidateLedger`: twelve independent books, transactional cumulative fills and cost basis,
  sleeve-owned sell reservations, append-only events, compare-and-swap checkpoints and a
  31-day review date based on actual activation. Old four-sleeve identity rules remain intact.
- `CandidateOrderReconciler`: persist-before-POST, lookup-only ambiguous recovery and
  cancel-confirm-before-reuse of reserved shares.
- `CandidateProtection`: protect confirmed partial fills, add protection for later fills,
  update the stop only from completed closes and handle fills during cancellation.
- `CandidateCircuit`: JSON-persistent replay of the frozen portfolio circuit; repeated
  observations or process restarts cannot shorten its pause or reset its peak.

Verification: `whole_share_sizing.php` 3,176 assertions;
`candidate_paper_ledger.php` 41 assertions; `candidate_circuit.php` 466 assertions.
Tests use temporary SQLite databases and a fake broker. No broker orders are sent.

## Remaining admission work

The signal/executor adapter must preserve the existing per-sleeve drawdown and
close-trailing exits, in addition to the new 12% native stops and shared circuit.
Close decision parity, account-level risk/reconciliation, fresh external inputs,
versioned admission evidence, existing-LaunchAgent handoff and post-handoff checks
must be completed before changing the deployed profile. Passing these component
tests alone is not a paper release approval. Native stop cancellation/recreation
is not atomic and requires monitoring; a stop cannot guarantee the exit price.

Broker order semantics: [Alpaca order documentation](https://docs.alpaca.markets/us/docs/orders-at-alpaca).
