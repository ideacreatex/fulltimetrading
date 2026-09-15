#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
use FulltimeTrading\Research\CandidateInteractionAssessment as Assessment;
use FulltimeTrading\Research\CandidateInteractionStudy as Study;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $dir = $root . '/var/reports/candidate_interactions_20260915';
$read = static fn ($path): array => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$p = $read($dir . '/protocol.json'); $s = $read($dir . '/summary.json'); $sha = hash_file('sha256', $dir . '/protocol.json');
$checks = 0; $check = static function (bool $ok, string $why) use (&$checks): void { ++$checks; if (!$ok) { throw new RuntimeException($why); } };
$check($s['complete'] === true && count($s['results']) === 192 && $s['computed'] === 156 && $s['reused'] === 36 && $s['protocol_sha256'] === $sha, 'Full declared factorial required.');
foreach ($p['code_sha256'] as $file => $hash) { $check(hash_file('sha256', $root . '/' . $file) === $hash, 'Frozen code drift.'); }
foreach ($p['input_paths'] as $name => $file) { $check(hash_file('sha256', $root . '/' . $file) === $p['input_sha256'][$name], 'Frozen data drift.'); }
$check(CandidateRelease::hash($root) === $p['deployed_runtime_hash'], 'Active release changed.');
$results = $annual = $receipts = []; $stopCount = 0;
foreach (Study::cases() as $id => $_) {
    foreach (Study::conditions() as $scenario => $window) {
        foreach ([30, 60] as $cost) {
            $key = $scenario . '__' . $cost; $name = $id . '__' . $key; $path = $dir . '/' . $name . '.json'; $r = $read($path);
            $check($r['protocol_sha256'] === $sha && $r['id'] === $id && $r['scenario'] === $scenario && $r['cost_bps'] === $cost, 'Case identity mismatch.');
            $check($r['metrics'] === $s['results'][$name], 'Metric/summary drift.');
            $check(hash_file('sha256', $root . '/' . $r['curve_path']) === $r['curve_sha256'], 'Curve identity drift.');
            $check($r['reused'] === (Study::priorCase($id, $scenario, $cost) !== null), 'Reuse classification drift.');
            if ($r['reused']) {
                $check($r['source_path'] === Study::priorCase($id, $scenario, $cost) && hash_file('sha256', $root . '/' . $r['source_path']) === $r['source_sha256'], 'Cached source changed.');
                $source = $read($root . '/' . $r['source_path']);
                $check($source['metrics'] === $r['metrics'] && $source['annual'] === $r['annual'] && $source['trade_ledger'] === $r['trade_ledger'], 'Cached result was altered.');
            }
            $curve = $read($root . '/' . $r['curve_path']); $m = $r['metrics'];
            $check(count($curve) === $m['points'] && $curve[0]['date'] === $window['start'] && end($curve)['date'] === $window['end'], 'Wrong replay dates.');
            $check(abs($curve[0]['equity'] - $p['initial_equity']) < 1e-8 && abs($curve[0]['start_equity'] - $p['initial_equity']) < 1e-8, 'Capital baseline changed.');
            $factor = end($curve)['equity'] / $p['initial_equity']; $days = (new DateTimeImmutable($curve[0]['period_start_date']))->diff(new DateTimeImmutable(end($curve)['date']))->days;
            $check(abs($factor - 1 - $m['return']) < 1e-9 && abs($factor ** (365.25 / $days) - 1 - $m['cagr']) < 1e-9, 'Return/CAGR mismatch.');
            $peak = $p['initial_equity']; $dd = $turnover = 0.; $lastDate = null; $lastEquity = null; $dates = [];
            foreach ($curve as $point) {
                $check($lastDate === null || $point['date'] > $lastDate, 'Nonmonotonic curve.');
                foreach (['equity', 'start_equity', 'equity_low', 'equity_high'] as $field) { $check(is_numeric($point[$field]) && is_finite((float) $point[$field]) && $point[$field] > 0, 'Invalid equity.'); }
                $check($lastEquity === null || abs($point['start_equity'] - $lastEquity) < max(1., $lastEquity) * 1e-10, 'Capital discontinuity.');
                $peak = max($peak, $point['equity_high'], $point['equity']); $dd = min($dd, min($point['equity'], $point['equity_low']) / $peak - 1);
                $turnover += $point['turnover']; $lastEquity = $point['equity']; $lastDate = $point['date']; $dates[$lastDate] = true;
            }
            $check(abs($dd - $m['max_drawdown']) < 1e-10 && abs($turnover - $m['turnover']) < 1e-9, 'Drawdown/turnover do not match saved curve.');
            $product = 1.;
            foreach ($r['annual'] as $year => $yearMetrics) {
                $product *= 1 + $yearMetrics['return'];
                $annual[$id][$key][$year] = ['return' => $yearMetrics['return'], 'drawdown' => $yearMetrics['max_drawdown'],
                    'closed_ticker_episodes' => $r['trade_ledger']['portfolio']['annual_closed'][$year], 'closed_sleeve_episodes' => $r['trade_ledger']['sleeves']['annual_closed'][$year]];
            }
            $check(abs($product - $factor) < 1e-8, 'Annual compounding mismatch.');
            foreach (['portfolio', 'sleeves'] as $scope) {
                $ledger = $r['trade_ledger'][$scope];
                $check(array_sum($ledger['annual_closed']) === $ledger['closed_total'] && count($ledger['closed']) === $ledger['closed_total']
                    && $ledger['opened_total'] === $ledger['closed_total'] + count($ledger['open_at_end']), 'Trade conservation mismatch.');
            }
            foreach ($r['stop_events'] ?? [] as $stop) {
                $check(Assessment::validStopEvent($stop, $dates), 'Invalid saved stop event.'); ++$stopCount;
            }
            $results[$id][$key] = $m; $receipts[$name] = ['case_sha256' => hash_file('sha256', $path), 'curve_path' => $r['curve_path'],
                'curve_sha256' => $r['curve_sha256'], 'reused' => $r['reused'], 'source_path' => $r['source_path'] ?? null, 'source_sha256' => $r['source_sha256'] ?? null];
        }
    }
}
$assessment = Assessment::assess($results, $p['initial_equity']); $tests = [];
foreach (['candidate_interaction_study', 'candidate_interaction_assessment', 'deployed_candidate_study', 'deployed_candidate_comparison', 'paper_forward_execution_audit', 'alpaca_paper_client_guard'] as $test) {
    $output = []; $exit = 0; exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg($root . '/tests/' . $test . '.php') . ' 2>&1', $output, $exit);
    $tests[$test] = ['exit_code' => $exit, 'output' => implode("\n", $output), 'sha256' => hash_file('sha256', $root . '/tests/' . $test . '.php')];
    $check($exit === 0, 'Relevant regression failed: ' . $test);
}
$check(CandidateRelease::hash($root) === $p['deployed_runtime_hash'], 'Active release changed during verification.');
$analysisHashes = [];
foreach (['src/Research/CandidateInteractionAssessment.php', 'tools/summarize_candidate_interactions_20260915.php'] as $file) { $analysisHashes[$file] = hash_file('sha256', $root . '/' . $file); }
$proof = ['completed_at' => gmdate(DATE_ATOM), 'protocol_sha256' => $sha, 'protocol' => $p, 'comparison_count' => 192, 'new_replays' => 156,
    'reused_results' => 36, 'verification_assertions' => $checks, 'tests' => $tests, 'analysis_code_sha256' => $analysisHashes,
    'saved_stop_events' => $stopCount, 'stop_event_price_basis' => 'split-adjusted historical units, NOT broker nominal stop prices; minute checks require date-specific raw/split conversion',
    'runtime_unchanged' => true, 'orders_submitted' => 0, 'assessment' => $assessment, 'annual' => $annual, 'receipts' => $receipts];
Writer::write($dir . '/assessment.json', $proof); Writer::write($root . '/docs/HYBRID_V4_INTERACTIONS_2026-09-15.json', $proof);
$pct = static fn ($x): string => number_format(100 * $x, 2, '.', '') . '%'; $fmt = static fn ($x): string => number_format($x, 2, '.', '');
$ranked = $assessment['rows']; uasort($ranked, static fn ($a, $b): int => $b['metrics']['continuous__30']['return'] <=> $a['metrics']['continuous__30']['return']);
$leader = array_key_first($ranked); $leading = $ranked[$leader];
$lines = ['# Candidate interactions, 2026-09-15', '', 'Offline follow-up only. No runtime changes, broker submissions, gate changes or capital reset.', '',
    '## Experiment', '',
    '- 24 factorial cells: VVIX reentry 100/105/110, SVXY defensive cap 0.50/0.75, risk size 1.00/1.10, S5TW high touch/10 sessions versus high close/20 sessions. Both breadth modes retain the 1.25 multiplier.',
    '- 18 combined hypotheses plus six existing single-factor/control recipes. Four start/end paths at 30/60 bps give 192 comparisons: 156 new replays and 36 hash-verified reused results. The prior 524 runs were not rerun.',
    '- Initial equity $27,567.66. Alpaca raw integer sizing, split-only signals, dated S5TW/Cboe VVIX, fixed 6.25% financing; unchanged close-based 12% stop and 12 sleeves.',
    '- Early paths: 2017-01-03 and 2019-01-02 through 2020-12-31. Recent paths: 2021-01-04 and 2023-01-03 through 2026-09-04. Earlier zero-history COIN excluded identically, without synthetic pre-IPO data.',
    '- These overlapping samples and adaptively selected factors are NOT independent holdout validation. The basket is selected, not a point-in-time constituent/delist universe.', '',
    '## Cross-Regime Findings', '',
    'A higher-return tradeoff does exist: ' . $leader . ' raises terminal capital in the 2021-start path by '
        . $fmt($leading['deltas']['continuous__30']['capital_delta_pct']) . '% at 30 bps and '
        . $fmt($leading['deltas']['continuous__60']['capital_delta_pct']) . '% at 60 bps, while deepening maximum drawdown by '
        . $fmt(-$leading['deltas']['continuous__30']['drawdown_improvement_pp']) . ' and '
        . $fmt(-$leading['deltas']['continuous__60']['drawdown_improvement_pp']) . ' percentage points respectively. Its worst capital delta across the eight paths is '
        . $fmt($leading['worst_capital_delta_pct']) . '%. This may be economically interesting, but it is not a uniform cross-regime improvement.', '',
    'The all-eight labels below are descriptive robustness tests, NOT a new protective gate or a requirement that profit must always rise while drawdown falls. Money-only and drawdown-only benefits remain visible, as requested.', '',
    'Terminal capital AND drawdown non-worse in all eight conditions, with at least one strict improvement: ' . ($assessment['money_drawdown_dominators'] === [] ? 'none' : implode(', ', $assessment['money_drawdown_dominators'])) . '.', '',
    'Terminal capital non-worse (drawdown may worsen): ' . ($assessment['capital_nonworse'] === [] ? 'none' : implode(', ', $assessment['capital_nonworse'])) . '.', '',
    'Drawdown non-worse (capital may fall): ' . ($assessment['drawdown_nonworse'] === [] ? 'none' : implode(', ', $assessment['drawdown_nonworse'])) . '.', '',
    'CAGR is compounded annualized growth, not a guaranteed annual return. A positive DD delta means shallower drawdown. The minimum below is the worst of eight conditions, not an average that hides an unfavorable period.', '',
    '| Recipe | Worst capital delta | Worst DD improvement, pp | Money/DD non-worse | Peak gross never higher |', '|---|---:|---:|---|---|'];
foreach ($assessment['rows'] as $id => $row) {
    $lines[] = '| ' . $id . ' | ' . $fmt($row['worst_capital_delta_pct']) . '% | ' . $fmt($row['worst_drawdown_improvement_pp']) . ' | '
        . ($row['money_drawdown_dominates_control'] ? 'yes' : ($id === 'deployed' ? 'control' : 'no')) . ' | ' . ($row['gross_nonhigher_all_eight'] ? 'yes' : 'no') . ' |';
}
$lines = [...$lines, '', '## Single-Factor Crisis Check', '', '| Recipe | Start | Cost, bps | CAGR | Maximum DD |', '|---|---|---:|---:|---:|'];
foreach (['deployed', 'svxy_cap_0.75', 'risk_scale_1.1', 's5tw_high_close_20_1.25', 'vvix_confirmation_110'] as $id) {
    foreach (['early2017', 'early2019'] as $scenario) {
        foreach ([30, 60] as $cost) { $m = $results[$id][$scenario . '__' . $cost]; $lines[] = '| ' . $id . ' | ' . $scenario . ' | ' . $cost . ' | ' . $pct($m['cagr']) . ' | ' . $pct($m['max_drawdown']) . ' |'; }
    }
}
$lines = [...$lines, '', 'SVXY is an indicator here, not a portfolio holding. Its daily target changed from -1x to -0.5x after February 27, 2018; the 2017 path crosses this product change whereas the 2019 path does not. Do not interpret the full SVXY history as a stationary exposure: [ProShares SVXY specification](https://www.proshares.com/our-etfs/strategic/svxy).', '',
    '## Every Recent Result', '', '| Recipe | Start | Cost, bps | Terminal capital | CAGR | Maximum DD |', '|---|---|---:|---:|---:|---:|'];
foreach ($results as $id => $conditions) {
    foreach (['continuous', 'fresh2023'] as $scenario) {
        foreach ([30, 60] as $cost) {
            $m = $conditions[$scenario . '__' . $cost]; $lines[] = '| ' . $id . ' | ' . $scenario . ' | ' . $cost . ' | $'
                . number_format($p['initial_equity'] * (1 + $m['return']), 0, '.', ',') . ' | ' . $pct($m['cagr']) . ' | ' . $pct($m['max_drawdown']) . ' |';
        }
    }
}
$lines = [...$lines, '', '## Evidence And Limits', '',
    '- Adjacent-factor comparisons hold all other settings constant; VVIX neighbors are 100/105 and 105/110. Every edge and all eight deltas are retained in the JSON, not just the winning combinations.',
    '- Every yearly return, drawdown and closed ticker/sleeve episode count is retained for all 192 comparisons. A trade is a completed ticker exposure, not a broker order or partial fill.',
    '- Saved new-case stop events use split-adjusted historical price units. They are not directly comparable to nominal broker/minute prices without conversion. No new minute, NBBO, queue or partial-fill validation is claimed here.',
    '- No automatic deployment. New releases require execution/data gates and a safe lifecycle, not a hash rewrite of the running paper strategy. Monthly activation and the current capital baseline remain unchanged.',
    '- This batch narrows candidates; it does not justify an unlimited search for the largest in-sample number. Independent forward execution and sensitivity to nearby settings are the next useful evidence.', '',
    'Verification: ' . count($tests) . ' relevant test programs passed; ' . $checks . ' curve/accounting/identity checks; '
        . $stopCount . ' historical stop records preserved. No operational database writes or orders.', '',
    '```sh', 'php tests/candidate_interaction_study.php', 'php tests/candidate_interaction_assessment.php',
    'php -d memory_limit=8G tools/research_candidate_interactions_20260915.php',
    'php -d memory_limit=1G tools/summarize_candidate_interactions_20260915.php', '```'];
if (file_put_contents($root . '/docs/HYBRID_V4_INTERACTIONS_2026-09-15.md', implode("\n", $lines) . "\n") === false) { throw new RuntimeException('Cannot persist report.'); }
echo json_encode(['comparisons' => 192, 'new_replays' => 156, 'reused' => 36, 'verification_assertions' => $checks,
    'money_drawdown_dominators' => $assessment['money_drawdown_dominators'], 'capital_nonworse' => $assessment['capital_nonworse'],
    'drawdown_nonworse' => $assessment['drawdown_nonworse'], 'runtime_unchanged' => true], JSON_PRETTY_PRINT), "\n";
