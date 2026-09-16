# Bull5 first paper fill: partial entry and acknowledged protection

## Observed Outcome

This is a dated broker observation, not a backtest or a new release admission.
The active run remains `hybrid-v4-bull5-2026-09-15`, profile
`maximum-stop12-costband2-whole-bull5-v1`, runtime hash
`76b2c102b4de98e9351ad78d937d939e173cd734fd4acbd322bf824c2cf4f092`.
Activation remains 2026-09-15T13:41:18Z. No strategy, identity, capital, gate,
LaunchAgent or broker order was modified by this audit.

On September 16, all four overnight MSFT OPG orders survived the 09:27 New York
new-submission cutoff. Broker terminal timestamps and ledger cancellation events
place every cancellation after the 09:30 opening, unlike September 15.

| Sleeve | Requested | Bought | Broker cancellation, New York |
| --- | ---: | ---: | --- |
| dynamic_loo10_rank0_phase1 | 9 | 0 | 09:30:21.257 |
| qqq200_full_rank0_phase1 | 3 | 0 | 09:30:40.592 |
| spy200_full_rank0_phase1 | 3 | 0 | 09:31:05.704 |
| qqq150_ex_crypto_rank0_phase1 | 3 | 2 | 09:31:30.009 |

The fourth order's broker `filled_at` is **09:30:10.093**, filled quantity 2,
average price **$492.00**, cost **$984.00**. Its final `canceled` status applies
to the remaining one share; it does not erase the two filled shares. Total
quantity completion is **2/18 = 11.11%**. The remaining 16 shares were not bought
and were not chased with new orders. Current ownership belongs solely to
`qqq150_ex_crypto_rank0_phase1`, not to the other three sleeves.

## Cancellation And Protection

The normal daemon recorded four `cancel_requested` events with reason
`partial_fill_protection` at 13:30:13, 13:30:40, 13:31:05 and 13:31:29 UTC.
`CandidateEntryBatch::next()` enters protection at the first observed fill and
cancels remaining active buys before submitting an opposite sell stop. Its
checkpoint also contains the generic `entry_admission_or_window_closed` abort
marker; that marker alone is not evidence of a new 09:27 expiry bug.

The broker accepted one SELL STOP GTC for **2 MSFT at $432.96**, exactly 12%
below $492.00. Its broker creation/submission/update times are
13:31:54.207 / 13:31:54.215 / 13:31:54.216 UTC; subsequent direct GETs show `new`,
filled quantity zero, `extended_hours=false`. Both owned shares are covered;
there is no current uncovered quantity or remaining BUY order. A resting stop
does not guarantee its execution price or protect extended-hours trading.

**Important execution limitation:** the interval from the recorded fill to
creation of the stop was approximately **104.1 seconds**. The daemon processes
the cancellation sequence over successive executor cycles, with at least a
15-second wait after each child completes. This observation is not evidence of
instant protection, full intended exposure, or economic parity with a historical
full-fill model. Reducing this delay and underfill requires isolated execution
tests and a separately admitted release, not editing the active hash or bypassing
cancel confirmation. This audit does not claim to have solved those limitations.

## Verification And Evidence

The independent read-only audit completed at 13:32:21 UTC after three attempts
while the stop acknowledgement was moving, then passed again on its first
attempt at **13:32:58 UTC**. Result: `positions_protected`, 2 owned/2 acknowledged,
zero uncovered, five direct order lookups, reconciliation OK; cash difference
approximately -1.96e-10. The stable second snapshot reports equity **$27,569.40**,
cash **$26,583.66**, unrealized account change **+$1.74** relative to activation.
These marks change intraday and are not realized trading profit.

Both trading LaunchAgents remained healthy, with matching heartbeat/lock/PIDs;
status-export last exit zero. The paper HTTPS host and account guard passed;
the exact release remained commissioned. The normal trading outbox delivered
the partial-fill message at **09:30:16 New York**, 104 delivered and zero
pending/failed-pending at 09:32. The separate pre-open analytical block had
already been delivered at 09:03:34 (message 891); it was not duplicated.

Targeted offline verification was rerun for this observed partial-fill path:
`php tests/candidate_opg_auction_boundary.php` passed **230 assertions in 14
restartable scenarios**, without broker access. This is a repeated regression,
not 14 new hypotheses or a new full-suite verification.

Timestamped local evidence (retained under ignored operational reports):

- `var/reports/candidate_forward_20260915/20260916_133221_33389.json`, SHA256
  `2bd6ab731ad18b153e753b7bf1173c5eacd11a68cecb2855a499816d49e6985c`.
- `var/reports/candidate_forward_20260915/20260916_133258_33820.json`, SHA256
  `5aa4d60348667899181bf1d539c4a5175d9dbd2d88cec7ba45674677600cd8b2`.
- `var/reports/heartbeat_20260916_1330/operational/latest_paper_status.json`, SHA256
  `671635b947cd480d8793c0bce5b076b4532d4e42970607c0f1ef9cc98286db75`.
- Read-only `tactical_candidate_event` rows for this run since
  `2026-09-16T13:26:00`, and the `candidate-fill:*:2` notification receipt.

The monthly report at 13:32:18 UTC still has 0/31 elapsed whole days and 2/20
observed market dates, not two completed full sessions; zero completed exits.
The unresolved-entry gate cleared, but observation, reconciliation error-rate
history, completed-exit and weekly-consistency/concentration gates remain.
Earliest review stays **2026-10-16T13:41:18Z**. Historical errors are not reset.
`validation_selected=false`; live remains disabled.

Machine-readable dated summary: `HYBRID_V4_FIRST_FILL_2026-09-16.json`.
