# Isolated VVIX close/quantity parity, 2026-09-15

VVIX reentry thresholds 105 and 110: 16/16 isolated historical execution checks passed across four paths and 30/60 bps. No strategy switch, broker orders, operational database mutation or activation reset.

## Verified

- Every case exactly matches its previously frozen metrics and full equity curve. Parent receipts also match the committed interactions assessment.
- Production CandidateSleeveState and WholeShareSizing match historical risk signals, cooldowns, duplicate-close behavior, final targets and next-session whole-share quantities across all twelve sleeves.
- 185232 sleeve-session observations, 63956 whole-share decisions and 623164 replay assertions. Opening-gap stops preempted 136 sizing comparisons; these are recorded, not presented as successful quantity comparisons.
- Prefix comparisons preserve exact decisions, prices and available volatility. Pre-IPO symbols are not assigned synthetic observations.

## Test-Harness Corrections

Two initial incomplete attempts are preserved by protocol hashes. The first requested PLTR data before its IPO. The second detected missing versus null volatility metadata for PLTR/CRWD, not different trading decisions. The final prefix adapter removes only metadata proven unavailable at that date and rejects any pre-IPO price, target or nonnull volatility. Regression tests cover those rejection paths. Full replay universes and trading logic were not changed.

## Not Yet Proven

- This is component parity, NOT a complete new release admission. The active snapshot builder still binds the deployed indicator definition; these variants were supplied only to an isolated replay.
- The actual broker gateway, persisted candidate ledger, native protective-stop lifecycle, partial fills and cash reservation were not exercised by these historical checks. Related unit tests passed, but that does not constitute variant-specific forward evidence.
- Historical stop records are in split-adjusted units. A further minute audit must use the same adjustment basis or explicit dated raw/split conversion, never compare them directly with nominal broker prices.
- Existing monthly observation continues from 2026-09-15T01:51:22Z. A historical PASS does not bypass runtime identity, account, execution, observation or live gates.

## Evidence

9 relevant test programs passed; 2987 receipt/hash/stop checks; 2872 historical stop records preserved. Runtime identity unchanged. Complete per-case proofs remain under var/reports/candidate_vvix_parity_20260915_v3.

```sh
php tests/candidate_parity_inputs.php
php -d memory_limit=8G tools/verify_candidate_vvix_parity_20260915.php
php -d memory_limit=1G tools/summarize_candidate_vvix_parity_20260915.php
```
