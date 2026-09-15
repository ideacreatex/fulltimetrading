#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
use FulltimeTrading\Research\CandidateBullRiskAssessment as Assessment;
use FulltimeTrading\Research\CandidateBullRiskStudy as Study;
use FulltimeTrading\Research\CandidateInteractionAssessment as StopAudit;
use FulltimeTrading\Research\CandidateInteractionStudy as Prior;
use FulltimeTrading\Research\SelectedMaximumResearch as Hash;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $dir = $root . '/var/reports/candidate_bull_risk_20260915';
$read = static fn ($path): array => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$p = $read($dir . '/protocol.json'); $s = $read($dir . '/summary.json'); $sha = hash_file('sha256', $dir . '/protocol.json');
$checks = 0; $check = static function (bool $ok, string $why) use (&$checks): void { ++$checks; if (!$ok) { throw new RuntimeException($why); } };
$check($s['complete'] === true && count($s['results']) === 144 && $s['computed'] === 96 && $s['reused'] === 48 && $s['protocol_sha256'] === $sha, 'Full declared matrix required.');
foreach ($p['code_sha256'] as $file => $hash) { $check(hash_file('sha256', $root . '/' . $file) === $hash, 'Frozen source drift: ' . $file); }
foreach ($p['input_paths'] as $key => $file) { $check(hash_file('sha256', $root . '/' . $file) === $p['input_sha256'][$key], 'Frozen input drift.'); }
$check(CandidateRelease::verify($root, require $root . '/config/paper_candidate.php')['runtime_hash'] === $p['deployed_runtime_hash'], 'Operational release changed.');
$results = $annual = $receipts = $eligibility = $parities = []; $stopCount = $newStopCount = 0;
foreach ($p['parity_controls'] as $name) {
    $r = $read($dir . '/' . $name . '.json'); $parity = $read($dir . '/parity_' . $name . '.json');
    $expected = ['metrics' => $r['metrics'], 'annual' => $r['annual'], 'trade_ledger' => $r['trade_ledger'], 'curve' => $read($root . '/' . $r['curve_path'])];
    $check($parity['protocol_sha256'] === $sha && $parity['passed'] === true && $parity['result_sha256'] === Hash::hash($expected), 'Control replay parity missing.');
    $parities[$name] = $parity;
}
foreach (Study::cases() as $id => $case) {
    foreach (Prior::conditions() as $scenario => $window) {
        foreach ([30, 60] as $cost) {
            $key = $scenario . '__' . $cost; $name = $id . '__' . $key; $path = $dir . '/' . $name . '.json'; $r = $read($path);
            $check($r['protocol_sha256'] === $sha && $r['id'] === $id && $r['scenario'] === $scenario && $r['cost_bps'] === $cost, 'Case identity mismatch.');
            $check($r['metrics'] === $s['results'][$name], 'Summary metrics drift.');
            $check(hash_file('sha256', $root . '/' . $r['curve_path']) === $r['curve_sha256'], 'Curve hash changed.');
            $check($r['reused'] === ($case['reuse_id'] !== null), 'Incorrect reuse classification.');
            if ($r['reused']) {
                $check($r['source_path'] === 'var/reports/candidate_interactions_20260915/' . $name . '.json'
                    && hash_file('sha256', $root . '/' . $r['source_path']) === $r['source_sha256'], 'Cached source hash mismatch.');
                $source = $read($root . '/' . $r['source_path']);
                $check($source['protocol_sha256'] === $p['parent_protocol_sha256'] && $source['metrics'] === $r['metrics']
                    && $source['annual'] === $r['annual'] && $source['trade_ledger'] === $r['trade_ledger'] && $source['stop_events'] === $r['stop_events'], 'Reused result modified.');
            }
            $m = $r['metrics']; $curve = $read($root . '/' . $r['curve_path']);
            $check(count($curve) === $m['points'] && $curve[0]['date'] === $window['start'] && end($curve)['date'] === $window['end'], 'Replay dates mismatch.');
            $check(abs($curve[0]['equity'] - $p['initial_equity']) < 1e-8 && abs($curve[0]['start_equity'] - $p['initial_equity']) < 1e-8, 'Starting equity mismatch.');
            $factor = end($curve)['equity'] / $p['initial_equity'];
            $days = (new DateTimeImmutable($curve[0]['period_start_date']))->diff(new DateTimeImmutable(end($curve)['date']))->days;
            $check(abs($factor - 1 - $m['return']) < 1e-9 && abs($factor ** (365.25 / $days) - 1 - $m['cagr']) < 1e-9, 'Return/CAGR do not match curve.');
            $peak = $p['initial_equity']; $dd = $turnover = 0.; $previousDate = null; $previousEquity = null; $dates = [];
            foreach ($curve as $point) {
                $check($previousDate === null || $point['date'] > $previousDate, 'Nonmonotonic curve.');
                foreach (['equity', 'start_equity', 'equity_low', 'equity_high'] as $field) { $check(is_numeric($point[$field]) && is_finite((float) $point[$field]) && $point[$field] > 0, 'Invalid equity field.'); }
                $check($previousEquity === null || abs($point['start_equity'] - $previousEquity) < max(1., $previousEquity) * 1e-10, 'Discontinuous capital.');
                $check(is_numeric($point['turnover']) && is_finite((float) $point['turnover']) && $point['turnover'] >= 0, 'Invalid turnover.');
                $peak = max($peak, $point['equity_high'], $point['equity']); $dd = min($dd, min($point['equity'], $point['equity_low']) / $peak - 1);
                $turnover += $point['turnover']; $previousDate = $point['date']; $previousEquity = $point['equity']; $dates[$previousDate] = true;
            }
            $check(abs($dd - $m['max_drawdown']) < 1e-10 && abs($turnover - $m['turnover']) < 1e-9, 'DD/turnover mismatch.');
            $product = 1.;
            foreach ($r['annual'] as $year => $a) {
                $product *= 1 + $a['return'];
                $annual[$id][$key][$year] = ['return' => $a['return'], 'drawdown' => $a['max_drawdown'],
                    'closed_ticker_episodes' => $r['trade_ledger']['portfolio']['annual_closed'][$year], 'closed_sleeve_episodes' => $r['trade_ledger']['sleeves']['annual_closed'][$year]];
            }
            $check(abs($product - $factor) < 1e-8, 'Yearly compounding mismatch.');
            foreach (['portfolio', 'sleeves'] as $scope) {
                $ledger = $r['trade_ledger'][$scope];
                $check(array_sum($ledger['annual_closed']) === $ledger['closed_total'] && count($ledger['closed']) === $ledger['closed_total']
                    && $ledger['opened_total'] === $ledger['closed_total'] + count($ledger['open_at_end']), 'Trade count conservation failed.');
            }
            foreach ($r['stop_events'] ?? [] as $stop) { $check(StopAudit::validStopEvent($stop, $dates), 'Invalid saved stop event.'); ++$stopCount; if (!$r['reused']) { ++$newStopCount; } }
            if (!$r['reused']) {
                $eligible = $r['boost_eligible_dates']; $check(count($eligible) === $r['boost_eligible_sessions'] && count(array_unique($eligible)) === count($eligible), 'Eligible session count mismatch.');
                foreach ($eligible as $date) { $check(isset($dates[$date]), 'Boost outside tested sessions.'); }
                $eligibility[$id][$key] = ['sessions' => count($eligible), 'fraction' => count($eligible) / count($curve)];
                if ($cost === 60) { $check($eligibility[$id][$scenario . '__30']['sessions'] === count($eligible), 'Market regime must not depend on simulated costs.'); }
            }
            $results[$id][$key] = $m;
            $receipts[$name] = ['case_sha256' => hash_file('sha256', $path), 'curve_path' => $r['curve_path'], 'curve_sha256' => $r['curve_sha256'],
                'reused' => $r['reused'], 'source_path' => $r['source_path'] ?? null, 'source_sha256' => $r['source_sha256'] ?? null];
        }
    }
}
$assessment = Assessment::assess($results, $p['initial_equity']); $tests = [];
foreach (['candidate_bull_risk_study', 'candidate_bull_risk_assessment', 'candidate_interaction_study', 'candidate_interaction_assessment',
    'deployed_candidate_study', 'deployed_candidate_comparison', 'paper_forward_execution_audit', 'alpaca_paper_client_guard'] as $test) {
    $output = []; $exit = 0; exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg($root . '/tests/' . $test . '.php') . ' 2>&1', $output, $exit);
    $tests[$test] = ['exit_code' => $exit, 'output' => implode("\n", $output), 'sha256' => hash_file('sha256', $root . '/tests/' . $test . '.php')];
    $check($exit === 0, 'Relevant regression failed: ' . $test);
}
$check(CandidateRelease::hash($root) === $p['deployed_runtime_hash'], 'Active release changed during verification.');
$analysisHashes = [];
foreach (['src/Research/CandidateBullRiskAssessment.php', 'tools/summarize_candidate_bull_20260915.php', 'tests/candidate_bull_risk_assessment.php'] as $file) { $analysisHashes[$file] = hash_file('sha256', $root . '/' . $file); }
$proof = ['completed_at' => gmdate(DATE_ATOM), 'protocol_sha256' => $sha, 'protocol' => $p, 'comparisons' => 144, 'new_replays' => 96, 'reused' => 48,
    'helper_parity_reruns' => 2, 'parity' => $parities, 'verification_assertions' => $checks, 'tests' => $tests, 'analysis_code_sha256' => $analysisHashes,
    'saved_stop_events' => $stopCount, 'new_case_stop_events' => $newStopCount, 'stop_price_basis' => 'Split-adjusted historical units, not nominal broker stop prices.',
    'runtime_unchanged' => true, 'orders_submitted' => 0, 'assessment' => $assessment, 'annual' => $annual, 'eligibility' => $eligibility, 'receipts' => $receipts];
Writer::write($dir . '/assessment.json', $proof); Writer::write($root . '/docs/HYBRID_V4_BULL_RISK_2026-09-15.json', $proof);
$fmt = static fn ($v): string => number_format($v, 2, '.', ''); $pct = static fn ($v): string => number_format(100 * $v, 2, '.', '') . '%';
$ranked = array_filter($assessment['rows'], static fn ($row): bool => $row['parameters']['bull'] !== null);
uasort($ranked, static fn ($a, $b): int => $b['metrics']['continuous__30']['return'] <=> $a['metrics']['continuous__30']['return']);
$leader = array_key_first($ranked); $lines = ['# Conditional bull-risk study, 2026-09-15', '',
    'Offline research only. No deployment authority, operational database changes, manual orders, gate edits, capital reset or activation change.', '', '## Protocol', '',
    '- Twelve predeclared sizing hypotheses: SMA50/SMA200 confirmation, 1.05/1.10 risk boost, VVIX reentry 100/105/110. Six cached anchor/constant-risk controls. Four paths at 30/60 bps: 144 comparisons, 96 new and 48 reused. Two additional helper-parity reruns are verification, not new hypotheses.',
    '- Bull confirmation uses completed SPY and QQQ closes above their own average, rising averages over ten sessions and positive five-session returns; SVXY must be above SMA200. Outside confirmation exposure is identical to the own-VVIX anchor. Stops and circuit confirmation are unchanged.',
    '- Alpaca raw whole-share sizing and split-adjusted signals; dated S5TW/Cboe VVIX; 6.25% annual calendar-day financing; initial equity $27,567.66; 12 sleeves and close-based 12% stop. No Yahoo substitution.',
    '- Early paths start 2017-01-03/2019-01-02 and end 2020-12-31; recent paths start 2021-01-04/2023-01-03 and end 2026-09-04. No pre-IPO synthesis: only symbols with zero history before end are omitted.',
    '- This is adaptively chosen research on overlapping, already examined periods, NOT independent holdout. The selected basket is not a point-in-time constituent/delist universe.', '', '## Conditional Sizing Results', '',
    'Highest terminal capital among the twelve new rules on the 2021-start, 30-bps path: ' . $leader . '. This ranking is descriptive, not release selection. Negative early-regime results and cost sensitivity are retained below.', '',
    'Relative capital changes compare final dollars, not annual growth. A positive drawdown delta means a shallower drawdown. Worst-of-eight measures are descriptive, NOT a new mandatory gate: useful money-only and risk-only tradeoffs remain eligible for further investigation.', '',
    '| Rule | 2021/30 capital vs anchor | 2021/30 DD improvement, pp | Worst capital vs anchor | Worst DD improvement vs anchor, pp |', '|---|---:|---:|---:|---:|'];
foreach ($ranked as $id => $row) {
    $c = $row['comparisons']['anchor']; $d = $c['deltas']['continuous__30'];
    $lines[] = '| ' . $id . ' | ' . $fmt($d['capital_delta_pct']) . '% | ' . $fmt($d['drawdown_improvement_pp']) . ' | ' . $fmt($c['worst_capital_delta_pct']) . '% | ' . $fmt($c['worst_drawdown_improvement_pp']) . ' |';
}
$lines = [...$lines, '', '## Leader Versus Controls', '', '| Recipe | Path | Cost, bps | Terminal capital | CAGR | Maximum DD |', '|---|---|---:|---:|---:|---:|'];
foreach (array_unique(['deployed', $ranked[$leader]['parameters']['reference'], $ranked[$leader]['parameters']['constant_reference'], $leader]) as $id) {
    foreach (Prior::conditions() as $scenario => $_) { foreach ([30, 60] as $cost) {
        $m = $results[$id][$scenario . '__' . $cost];
        $lines[] = '| ' . $id . ' | ' . $scenario . ' | ' . $cost . ' | $' . $fmt($p['initial_equity'] * (1 + $m['return'])) . ' | ' . $pct($m['cagr']) . ' | ' . $pct($m['max_drawdown']) . ' |';
    } }
}
$lines = [...$lines, '', '## Yearly Results', '', 'Continuous 2021 start and 30 bps, not separate annual resets. Trades are completed ticker exposure episodes, not orders or partial fills. The JSON retains every year for every one of the 144 comparisons.', '',
    '| Recipe | Year | Return | Maximum DD | Closed ticker episodes | Closed sleeve episodes |', '|---|---:|---:|---:|---:|---:|'];
foreach (array_unique(['deployed', $ranked[$leader]['parameters']['reference'], $leader]) as $id) {
    foreach ($annual[$id]['continuous__30'] as $year => $a) { $lines[] = '| ' . $id . ' | ' . $year . ' | ' . $pct($a['return']) . ' | ' . $pct($a['drawdown']) . ' | ' . $a['closed_ticker_episodes'] . ' | ' . $a['closed_sleeve_episodes'] . ' |'; }
}
$lines = [...$lines, '', '## Evidence And Limits', '',
    '- Every comparison against deployed, the same-VVIX anchor and constant risk is in the JSON. Neighbor settings change one factor only. Conditional boosts are checked independently of circuit reentry; a bull market never bypasses its pause.',
    '- Eligibility means the sizing rule was available on that session, not a claim that every eligible session increased an actual position. Integer shares, held assets and circuit state can prevent an exposure change.',
    '- Prefix tests verify that future bars cannot change past regime flags. Two exact historical helper parity checks compare complete curves, metrics, annual results and trade ledgers with frozen controls.',
    '- Historical stops use split-adjusted prices. They do not prove broker nominal price/quantity parity; date-specific raw/split conversion and isolated execution tests remain necessary for any new release.',
    '- No new minute, NBBO, queue, partial-fill, live-market or independent forward profitability evidence is claimed. SVXY history spans its 2018 exposure change in the 2017 path, but not in the 2019 path; see the prior interactions report.',
    '- All 144 result/curve hashes, all annual returns and counts, all simulated new stop events, both parity proofs and frozen input/source hashes are retained. Current paper positions and orders are not part of this backtest.', '',
    'Verification: ' . count($tests) . ' relevant programs passed, ' . $checks . ' accounting/date/hash checks, ' . $newStopCount . ' new-case stop events preserved. Active release unchanged; zero broker orders.', '',
    '```sh', 'php tests/candidate_bull_risk_study.php', 'php tests/candidate_bull_risk_assessment.php',
    'php -d memory_limit=8G tools/research_candidate_bull_20260915.php', 'php -d memory_limit=1G tools/summarize_candidate_bull_20260915.php', '```'];
if (file_put_contents($root . '/docs/HYBRID_V4_BULL_RISK_2026-09-15.md', implode("\n", $lines) . "\n") === false) { throw new RuntimeException('Cannot write report.'); }
echo json_encode(['comparisons' => 144, 'new_replays' => 96, 'reused' => 48, 'verification_assertions' => $checks, 'leader_recent30' => $leader,
    'leader_vs_anchor' => $ranked[$leader]['comparisons']['anchor'], 'runtime_unchanged' => true], JSON_PRETTY_PRINT), "\n";
