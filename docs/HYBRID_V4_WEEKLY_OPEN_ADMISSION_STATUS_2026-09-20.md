# Weekly-open repair admitted in isolation, not installed

September 20, 2026: isolated source and evidence commit `4d3244bd` is pushed
on `codex/bull5-weekly-open-repair-20260919`. The package is in the existing
worktree `var/staging/weekly-open-repair-20260919`. The previously outstanding
exact-source admission and separate package identity are now complete.

## Completed Stage

- New run identity: `hybrid-v4-bull5-weekly-2026-09-20`; predecessor is the
  installed `hybrid-v4-bull5-2026-09-15`. No new run was activated.
- New runtime hash: `59a61cb8b98f1325fcda2b76e7947468a11b9bab6ee1142f279f7b9f0f0214f1`.
- Separate manifest `var/releases/candidate-bull5-weekly-v1/manifest.json`
  has experimental `paper_admission=true`, but no commissioning or live approval.
- 60/60 programs passed for the new package, including 67 new evidence-guard
  assertions and the 711-assertion full submitted CLI. Another 14 corruption/
  commissioning checks passed against the generated manifest; 72 lint calls pass.
- The unchanged economic/minute/capital/parity admission logic was rechecked
  against 218 exact reference-file hashes, not replaced by copied PASS flags.
  Historical returns were reused with their original limitations, not rerun
  or presented as a new independent holdout or new profit improvement.
- September 18 signal for September 21 independently rebuilt from copied
  hash-verified Alpaca SIP raw/split and original S5TW/VVIX data. All decision
  fields match the operational artifact; 21 checks pass, peak 371,195,904 bytes.
  This was offline dated-signal parity, not a fresh broker/account preflight.

Only scheduler, package-identity configuration and admission builder differ in
manifest-bound sources. Trading rules, account guards, stops, sizing, circuit,
ledger and installer are unchanged. Full source, machine-readable checksums and
reproduction instructions are in the isolated branch at
`docs/HYBRID_V4_WEEKLY_OPEN_ADMISSION_2026-09-20.md` and `.json`.

## Why Installation Is Still Blocked

This is no longer a missing software-admission result. The actual account has
two MSFT and one native stop; the unchanged installer requires a stable flat
account. No supported held-position migration or automatic error-pause resume
exists here. Do not replace the active runtime/hash, cancel protection or force
liquidation to satisfy that condition.

The package also preserves reviewed starting capital $27,567.66. Actual equity
is $27,571.22: activation still needs an exact actual-capital review at a permitted
handoff, not a capital reset or relaxed tolerance. Fresh official calendar/
account/signal preflight, clean/pushed source, the 120-second stable-flat check
and the existing LaunchAgent commissioning remain required. The dated signal
check does not claim to have passed those conditions.

## Operational Evidence

Audit completed at 08:45:01 UTC: unchanged paused predecessor, same saved
terminal-expiry error, 2 MSFT covered by the acknowledged GTC stop 2 at $438.02,
no uncovered shares, ownership/cash reconciliation OK, equity $27,571.22,
cash $26,583.66, unrealized P/L +$3.56. Both trading LaunchAgent/lock/PHP PIDs
69275/1477 and heartbeats were healthy. The scheduled status exporter now again
returns 2 for the paused trading cycle, rather than the repaired Git-push error.
Trading outbox: 122 delivered, none pending/failed; weekend commentary due=null.
No order, cancellation, API host/key, operational source or ledger mutation by
this verification; no service restart or installer invocation.

Active hash remains `76b2c102b4de98e9351ad78d937d939e173cd734fd4acbd322bf824c2cf4f092`.
Actual activation remains September 15 at 13:41:18 UTC. Month gate remains
4/31 elapsed days, 4/20 market dates, blocked; earliest October 16 at 13:41:18 UTC.
The successor's proposed identity does not reset or inherit this history.

Audit `var/reports/candidate_forward_20260915/20260920_084501_47880.json`, SHA256
`90bd714ee072489f1fb9f4d1fb26fd2b57db14b77f1a56acb8310d3555bfc8de`.
