# Hybrid-v4 Alpaca paper runbook

## Deployment contract

- Strategy: `causal-stock-rotation-hybrid-v4`.
- Trading host is hard-limited to `https://paper-api.alpaca.markets/v2`.
- Live trading is not implemented and is not automatically enabled.
- Earliest human live-review date: `2026-08-17`.
- Existing TECL/TQQQ are not attributed to hybrid-v4. The legacy monitor keeps
  control until Alpaca is flat and has no open orders.
- Activation snapshots actual account equity only after that flat handoff. The
  historical PANW holding is never chased.

## Signal and execution semantics

The five-year qualification remains frozen Alpaca SIP/split data through
2026-07-15. The immutable cache file is verified by SHA-256 on every signal
build. Completed sessions after the cutoff use Alpaca IEX/split data. The
runtime rejects missing symbols, uneven sessions, invalid OHLCV, overlap,
stale coverage, a changed cache hash, or changed provenance.

A completed close on session D may produce an order only for the next Alpaca
calendar session D+1. Orders are whole-share market-on-open (`time_in_force =
opg`). They may be queued after 19:05 New York on D or submitted before the
09:27 safety cutoff on D+1. A missed entry is never chased. For an existing
hybrid position only, a missed/unfinished exit may be changed to a DAY market
sell after the broker clock confirms that the market is open and before 15:45.
A same-session replacement buy is allowed only during 09:30-09:32 and only
after every required sell is fully filled. A late risk-reduction sell never
revives the stale buy leg.

The cutoff cross-feed audit compares, but never merges, five sessions of IEX
against the immutable SIP decision data. The deployment sample covered 22
symbols and 110 bars with zero violations. Maximum observed open/high/low/close
deviation was 94.28/70.89/80.39/14.05 bps; IEX/SIP volume ratios were
0.01027-0.06224, inside the configured corruption bounds.

## State and recovery

`tactical_paper_run`, `tactical_paper_sleeve`, `tactical_paper_position`,
`tactical_paper_intent`, `tactical_paper_fill_audit`, and
`tactical_paper_snapshot` are stored in the existing operational SQLite file.
Four virtual sleeve books preserve the tested static allocations. Alpaca may
aggregate identical symbols, but each order/fill remains attributed to one
sleeve.

Before an HTTP submit, an immutable intent and deterministic client order ID
are committed. Every restart reconciles that ID before considering new risk.
An unknown POST outcome is looked up first and may be retried at most three
times, after 120 seconds, with the identical body and client ID and only while
its original execution window remains open. Two confirmed not-found results
after the window closes pause the run; later cycles keep looking up the ID but
never revive the stale order.

If the computer was offline for an entire intended session, the next `hold`
artifact is compared with the sleeve ledger. A stale symbol is sold without
buying the model's already-missed replacement. For the same symbol, only
quantity above the model's current gross may be reduced at the current broker
mark; missing quantity is never bought.

Partial fills apply only the newly observed cumulative delta. Unknown positions,
unknown open orders, quantity drift, target drift, a conflicting cross-sleeve
buy/sell, or ambiguous broker state blocks entries while reconciliation keeps
running. A terminal partially filled exit pauses the run and permits at most one
quantity-bounded recovery sell for the remaining broker-and-ledger position;
the run stays paused for review after de-risking.

Telegram events are first inserted into a durable outbox. Delivery failure does
not lose the event; the daemon retries it without duplicating a delivered key.
Bot API delivery is acknowledged only when Telegram returns `ok=true` and a
message ID. Runtime status is unhealthy while any outbox row is undelivered,
including a failed attempt hidden behind its retry cooldown.
The acknowledged Telegram `message_id` is persisted in the outbox payload.
This is logical exactly-once by durable key, not a claim of physical
exactly-once: a process crash after Telegram accepts a request but before the
SQLite acknowledgement can still cause one rare retry duplicate because Bot
API `sendMessage` has no idempotency key.

The same daemon sends two detailed operator reports:

- at 09:35 New York on every broker-confirmed open session, after the opening
  prints have settled enough to show the actual Alpaca portfolio;
- after the completed-close signal refresh (normally from 16:20 New York),
  with the next-session model plan.

The opening key includes a one-way paper-account scope and broker session date,
so repeated 15-second cycles do not duplicate it and another paper account
cannot inherit the delivery. The close key also includes the validated decision
hash, so an old compact `signal:` row cannot suppress the detailed report. If
the computer or internet recovers later, the opening report is delivered with
the same key. After the bell it is explicitly labelled as a catch-up/current
snapshot rather than pretending to preserve the missed 09:35 prices. Both
reports show actual Alpaca positions and open orders, account equity/cash,
portfolio load, average/current prices and P/L, then separately show model
state and entry/add eligibility. `Buying power` is broker capacity, not
strategy permission. A ranked symbol such as PANW is watch-only unless a due
action, active/reconciled runtime and valid order window all agree.

During the legacy handoff, the current unarmed TECL/TQQQ swing stops are shown
as mental daily-close stops. If break-even arms under the configured hard-BE
policy, the report switches that row to `hard intraday monitor-stop`. Neither
mode is a standing stop order at Alpaca: the legacy monitor submits a market
exit after the relevant close or intraday trigger is confirmed.

## Operations

```bash
php bin/trade tactical-paper-executor --submit=false --telegram=false
php bin/trade tactical-paper-month-report
bin/install-hybrid-launchd --status
tail -f var/log/tactical_paper_daemon.log
php tests/tactical_portfolio_notification_schedule.php
php tests/tactical_portfolio_status_message.php
php tests/tactical_notification_health_guard.php
```

The dedicated LaunchAgent is `com.fulltimetrading.hybrid-v4-paper`. It uses
`RunAtLoad` and `KeepAlive`, refreshes the signal after a completed close,
retries network failures, and writes an atomic heartbeat at
`var/run/tactical_paper_daemon_heartbeat.json`. It is intentionally separate
from `com.fulltimetrading.paper-daemon` during the legacy handoff. While TECL or
TQQQ remains, the legacy daemon is exits-only and the hybrid run stays in
`transition`. After the account is flat with no open orders for 120 seconds,
hybrid-v4 snapshots actual paper equity and becomes active. The 31-day forward
observation starts at that activation, not at installation.

Installation requires configured Telegram credentials and does not succeed
until the first real cycle has a persisted delivered signal (and transition or
activation message when applicable) with no pending outbox backlog. If startup
verification fails, rollback first proves that the new launchd PID is gone and
its process lock is released before restoring the previous plist. An incomplete
rollback keeps its backup directory and requires manual launchd/lock inspection.

Emergency stop (does not liquidate positions):

```bash
bin/install-hybrid-launchd --uninstall
```

## One-month acceptance gate

`php bin/trade tactical-paper-month-report` remains blocked until all of these
are true:

- at least 31 calendar days from activation and 20 observed market dates;
- no unresolved order intents;
- no rejected or expired OPG orders;
- no unresolved run error and reconciliation/error snapshots are at most 1%;
- at least two completed exit episodes on different symbol/session pairs;
- at least three positive weeks across four observed weeks, with no single
  positive week contributing more than 70% of all positive weekly gains;
- forward drawdown does not exceed 35%;
- the paper month is profitable;
- the date is on or after 2026-08-17.

Passing these checks only permits a human live review. It does not switch API
hosts or credentials.
