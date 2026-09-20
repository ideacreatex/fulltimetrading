# Friday weekly-notification incident and paused paper run

September 20 update: an isolated scheduler repair now passes targeted red/green
regressions, but is NOT deployed. See
`HYBRID_V4_WEEKLY_OPEN_REPAIR_STATUS_2026-09-20.md` for commit, scope and remaining
release work. The operational scheduler and saved pause remain unchanged.

Verified September 19, 2026, 21:04-21:13 UTC. Compared with the last completed
daily baseline, September 16 at 20:51-20:54 UTC. Scheduled heartbeat messages
alone are not evidence that the intervening checks executed.

## Current State

Bull5 remains installed and commissioned with runtime hash
`76b2c102b4de98e9351ad78d937d939e173cd734fd4acbd322bf824c2cf4f092`.
Run `hybrid-v4-bull5-2026-09-15` is **paused**, since September 18 at 13:31:01 UTC,
with `candidate_terminal_incomplete:79796b9b9712`. This is not runtime drift,
a dead daemon, a drawdown circuit, or insufficient buying power.

- Repeated read-only broker audits: exactly 2 MSFT, average $492, cost $984;
  cash $26,583.66, equity $27,571.22, buying power $54,154.88.
- Unrealized P/L $3.56; total run return +0.0129%; no completed sales. Equity
  increased $7.21 versus the September 16 daily baseline of $27,564.01.
- Native SELL STOP GTC, 2 MSFT at $438.02, broker status `new`, filled zero;
  order `9f121ce3-334a-47e0-8284-6e6f46db4cb1`. Owned/covered 2/2, uncovered zero.
  The prior September 16 stop was $432.96. The higher level follows the
  September 17 completed close of $497.75 times 0.88, not an intraday quote.
- Independent ownership/cash reconciliation passes; difference is about
  -$0.000000000196. Only one positive fill-audit record remains: 2 MSFT on
  September 16. The September 18 entry batch bought zero of 19 requested shares.
- LaunchAgent/PHP/lock PIDs remain 69275 tactical and 1477 legacy exits-only;
  heartbeats fresh. Exporter exists and exits 2 for the paused cycle, not daemon
  death. Paper HTTPS host and all account guards pass. No restart is justified.
- Complete signal now September 18, intended session September 21; no source
  warnings. The model's 10/3/3/3 targets are not orders or permission to buy.
  One of these sleeves already owns 2 shares. Entry remains blocked by pause.
- Trading outbox 121 delivered, zero pending/failed (106 at the last baseline).
  September 18 close/weekly reports were delivered September 19 at 01:28:50 UTC.
  Separate assistant outbox has no September 17/18 receipts; its last delivery
  is September 16 close. These assistant blocks were missed, not delivered.
  Current calendar context is a weekend, `due=null`; no late or duplicate send.
- Legacy monitor recorded 699 bounded broker-read failures from September 16
  21:02:45 through September 17 14:43:30 UTC. Current reads/monitor are healthy.
  The September 16 complete signal arrived September 17 at 14:47:52 UTC, after
  the opening auction; no manual late entries were made.

## Observed Chain

September 18, all times UTC:

| Time | Evidence |
| --- | --- |
| 13:15:24.910335 | Existing 2-share stop canceled by the normal entry-batch transition. |
| 13:15:50-13:17:06 | Four BUY OPG orders accepted: 10+3+3+3 MSFT. |
| 13:29:40 | Executor window locked for new OPG, accepted orders still waiting. |
| 13:30:12 | A new Thursday-dated weekly report queued; first BUY cancellation requested. |
| 13:30:15 | Weekly report delivered. |
| 13:30:38 | Next BUY cancellation requested despite completed Telegram delivery. |
| 13:30:59.081136 | Third BUY expired at the broker, zero of 3 shares filled. |
| 13:31:01 | Ledger paused the run for this terminal incomplete order. |
| 13:31:04 | Last BUY cancellation requested as part of cleanup. |
| 13:31:29.313647 | Restored native 2-share stop submitted/acknowledged by the broker. |
| 13:31:51 | Entry batch cleared after protection acknowledgment. |

The stop-cancellation-to-restoration interval was about 964.40 seconds,
including about 89.31 seconds after the regular-session opening. This is a real
protection gap, not evidence of uninterrupted protection. The original stop is
not eligible for extended-hours execution either. Current coverage is verified,
but it does not erase this historical exposure gap.

## Reproduced Cause

`TacticalPortfolioNotificationSchedule::weeklyCloseStatus()` compares the
artifact's ISO week with `clock.next_open`. At Friday's opening, `next_open`
advances to Monday while the newest completed artifact still describes Thursday.
The function incorrectly schedules a weekly close for Thursday during Friday's
session. A matching notification was queued in the actual incident cycle.

`candidate_paper_cycle.php` queues that notification before evaluating
`outboxReady`. Any pending notification for this run makes `paper_admission`
false. `CandidateEntryBatch::next()` then durably aborts the accepted batch,
even though its 09:32 waiting deadline has not expired. Cancellation is labelled
`entry_window_expired`, hiding the admission/outbox trigger. Delivery in the
same cycle cannot undo the persisted abort. The third order's actual broker
expiry separately triggers the existing terminal-incomplete pause.

`php tools/reproduce_candidate_weekly_open_20260919.php` reproduces the chain
with 51 assertions, a temporary twelve-book SQLite ledger, fake broker objects,
and the real schedule/window/planner classes. The control with unchanged gates
waits at 09:30:05; inserting only the scheduled weekly notification cancels.
After fake expiry, cleanup restores protection for the original two shares,
keeps the pause, and does not retry the unfilled entry. Output explicitly says
`known_failure_reproduced_not_fixed`, not release PASS or historical improvement.

The incident log does not retain every original gate value. The outbox cause
is a source-level inference supported by the real queued/delivered timestamps
and an isolated causal reproduction. Broker expiry itself is directly confirmed.
We cannot establish that the canceled orders would otherwise have filled, or
that this timing defect caused the separate broker expiry.

## Safe Changes And Remaining Work

Only read-only diagnostics and presentation changed: the independent audit now
includes the saved run error and explicitly separates protection/reconciliation
success from entry authorization; the status explanation decodes the pause and
terminal-incomplete error in Russian. It does not suggest restarting a healthy
service, imply a flat account, or authorize resumption. The source reproduction
and these diagnostics are outside the active release manifest.

Verified: 68 explanation assertions, 45 forward-audit assertions, 52 commentary
assertions, existing notification-schedule and observation-time programs. The
existing schedule tests passing does NOT clear the newly reproduced failure.
Runtime identity/commissioning still match after the edits; actual audits pass
for protection and reconciliation. The trading cycle remains blocked.

At this incident audit, the scheduler, active entry/outbox admission and
terminal-expiry behavior had NOT been patched or relaxed. A separately admitted
repair must distinguish the
last completed market session from `next_open`, include Friday/open, delayed
Friday data, holiday-shortened week and catch-up cases, and test the full
queue/gate/batch integration. Any decision to alter active-batch admission or
expiry recovery needs its own safety evidence. Do not erase the pause, copy a
new hash, install over held shares/orders, or force liquidation for deployment.

Month report: 4/31 elapsed calendar days, 4/20 market dates, 5 snapshot dates,
maximum drawdown -0.0614%, zero completed exits, one positive observed week.
Blocked by paused run, observation duration, unresolved error, expired order,
error rate, insufficient exits/weeks and concentration. Earliest calendar review
remains October 16 at 13:41:18 UTC. No history/capital reset; live disabled.

Evidence (local ignored artifacts, retained):

- `var/reports/candidate_forward_20260915/20260919_211018_52199.json`, SHA256
  `97642ca5007f95608adfa2dd0bc2d983e6431506f75a1b23c5d0093c0a918095`.
- `var/reports/heartbeat_20260919_2100/operational/latest_paper_status.json`, SHA256
  `e9e6d9a12aa75e19e180344ab98de8b7f645830e7401eac39d283e5369e838e7`.
- `var/reports/heartbeat_20260919_2100/after/latest_paper_status.json`, SHA256
  `88700aa4a9bb9a5a91f0601aa2378fac5998c68bdf343145a2ccb9f2debae51c`.
- Operational append-only events, fill audit, notification receipts and bounded
  September 18 13:29-13:32 excerpts from `var/log/tactical_paper_daemon.log`.

Broker semantics checked September 19: auction-only OPG and stop-price/extended
hours limitations in [Alpaca order documentation](https://docs.alpaca.markets/us/docs/orders-at-alpaca).
No modeled or paper fill is a guarantee of live execution.
