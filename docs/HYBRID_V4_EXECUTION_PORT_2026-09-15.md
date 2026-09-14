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
- `CandidateProtection`: protect confirmed owned shares, update stops only from
  completed closes, preserve protection on a resized remainder and handle cancellation races.
- `CandidateCircuit`: JSON-persistent replay of the frozen portfolio circuit; repeated
  observations or process restarts cannot shorten its pause or reset its peak.

- `CandidateCloseEngine` and `CandidateSleeveState`: atomically freeze all twelve
  actual-book decisions, per-sleeve risk state, portfolio circuit and stop checkpoints.
  Replay parity passed 57,453 assertions from 2021 and 37,259 from a fresh 2023 start.
- `CandidateSignalArtifact`: compact incumbent-dependent close decisions with exact
  runtime/recipe/source hashes. Full snapshot preparation uses about 358 MiB under
  the service's 512 MiB limit; historical feature arrays are not retained twelve times.
- `CandidateEntryBatch`: persistent account-wide cancel/entry/protect coordination,
  with no duplicate POST after restart and no opposing active simple orders.
- `CandidateSession`: official Alpaca market calendar, fresh broker clock, early
  closes and a 20-minute completed-daily-bar delay; no weekday-only substitution.
- New executor/daemon and explicit `--candidate=true` dispatch; installer mode
  `HYBRID_PAPER_MODE=candidate-v1` uses the same existing LaunchAgent and preserves
  clean/pushed-repository, isolated preflight, account, Telegram and rollback gates.
- Status/month reports select actual activation, not a staged file. A healthy
  acknowledged native stop is counted as standing protection, not a stuck entry.

The existing 38-program regression suite passed again at 2026-09-14T22:33Z.
All 22 frozen research hashes and the old deployed runtime identity still match.
New unit programs use isolated SQLite databases and a fake broker. No broker
orders were submitted by testing.

The new split-only whole-share paths produced 64 distinct historical stop events.
Fresh Alpaca SIP minute data covered 54 symbol-days over 53 dates: all 64 touched
their stop during regular hours, with no missing opening minute. A one-minute
delay proxy was worse in 34 cases, worst about -300 bps versus the daily modeled
fill. This is a touch/latency sensitivity, not proof of NBBO election or fill price.

## Broker execution constraint

Alpaca rejects an ordinary market buy while an opposite stop sell is open, even
if another internal sleeve owns that stop. It also rejects a stop while an
opposite partially filled market buy remains open. The initial permissive fake
broker missed this; the tests now explicitly enforce the documented rule.

The executor freezes a bounded batch before removing any owned protection,
confirms stop cancellations, submits same-side entries, and waits for or cancels
remaining buys before restoring native protection. Existing positions' stops
are not removed for OPG additions before 09:15 New York time. A data/risk failure
aborts new entries and restores protection. Canceled partial entries are not
chased. These operational shortfalls and non-atomic stop gaps are not encoded in
the daily return curves and must be measured in forward paper trading.

Rules: [Alpaca user protection](https://docs.alpaca.markets/us/docs/user-protection),
[Alpaca order semantics](https://docs.alpaca.markets/us/docs/orders-at-alpaca).

## Remaining admission work

Versioned release evidence, full command-level preflight, fresh external inputs,
existing-LaunchAgent handoff and post-handoff checks remain mandatory. The new
configuration is staged and disabled, not deployed. At 2026-09-14T22:24:48Z Cboe
VVIX included September 14, but S5TW still ended September 11. Current-session
activation must not use that older snapshot. Passing component tests or a
retrospective economic preference review alone is not permission to deploy.
