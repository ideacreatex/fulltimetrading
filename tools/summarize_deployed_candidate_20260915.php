#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\DeployedCandidateComparison as C;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $dir = $root . '/var/reports/deployed_candidate_study_20260915';
$read = static fn (string $p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$protocol = $read($dir . '/protocol.json'); $sha = hash_file('sha256', $dir . '/protocol.json');
$summary = $read($dir . '/summary.json');
if ($summary['complete'] !== true || count($summary['results']) !== count($protocol['cases']) * 4 || $summary['protocol_sha256'] !== $sha) {
    throw new RuntimeException('Complete frozen study required.');
}
foreach ($protocol['code_sha256'] as $file => $hash) {
    if (hash_file('sha256', $root . '/' . $file) !== $hash) { throw new RuntimeException('Research source drift: ' . $file); }
}
$results = $annual = $evidence = []; $assertions = 0;
$check = static function (bool $ok, string $why) use (&$assertions): void { ++$assertions; if (!$ok) { throw new RuntimeException($why); } };
$regression = $read($dir . '/regression.json');
$check($regression['failed'] === [] && $regression['count'] >= 53 && $regression['runtime_unchanged'] === true, 'Regression suite incomplete.');
foreach ($regression['tests'] as $test => $proof) {
    $check($proof['exit_code'] === 0 && hash_file('sha256', $root . '/tests/' . $test . '.php') === $proof['sha256'], 'Regression test drift.');
}
foreach ($protocol['cases'] as $id => $spec) {
    foreach (C::CONDITIONS as $condition) {
        [$scenario, $cost] = explode('__', $condition); $name = $id . '__' . $condition;
        $r = $read($dir . '/' . $name . '.json'); $curve = $read($dir . '/' . $name . '_curve.json');
        $check($r['protocol_sha256'] === $sha && $r['id'] === $id && $r['scenario'] === $scenario && $r['cost_bps'] === (int) $cost, 'Case identity mismatch.');
        $check($r['metrics'] === $summary['results'][$name], 'Case/summary metric drift.');
        $check(count($curve) === $r['metrics']['points'], 'Curve point mismatch.');
        $check($curve[0]['date'] === $protocol['starts'][$scenario] && $curve[array_key_last($curve)]['date'] === $protocol['end'], 'Unexpected sample dates.');
        $check(abs($curve[0]['equity'] - $protocol['initial_equity']) < .0001, 'Wrong starting capital.');
        $check(abs(end($curve)['equity'] / $protocol['initial_equity'] - 1 - $r['metrics']['return']) < 1e-9, 'Curve terminal return drift.');
        $last = null; $annualProduct = 1.;
        foreach ($curve as $point) {
            $check($last === null || $point['date'] > $last, 'Nonmonotonic curve.'); $last = $point['date'];
            foreach (['equity', 'equity_low', 'equity_high', 'start_equity'] as $field) {
                $check(is_numeric($point[$field]) && is_finite((float) $point[$field]) && $point[$field] > 0, 'Invalid equity curve.');
            }
        }
        foreach ($r['annual'] as $year => $m) {
            $annualProduct *= 1 + $m['return'];
            $annual[$id][$condition][$year] = ['return' => $m['return'], 'max_drawdown' => $m['max_drawdown'],
                'closed_ticker_episodes' => $r['trade_ledger']['portfolio']['annual_closed'][$year],
                'closed_sleeve_episodes' => $r['trade_ledger']['sleeves']['annual_closed'][$year]];
        }
        $check(abs($annualProduct - 1 - $r['metrics']['return']) < 1e-8, 'Annual returns do not compound to total.');
        foreach (['portfolio', 'sleeves'] as $scope) {
            $ledger = $r['trade_ledger'][$scope];
            $check(array_sum($ledger['annual_closed']) === $ledger['closed_total'] && $ledger['closed_total'] === count($ledger['closed']), 'Annual trade conservation failed.');
            $check($ledger['opened_total'] === $ledger['closed_total'] + count($ledger['open_at_end']), 'Open/closed trade conservation failed.');
        }
        $results[$id][$condition] = $r['metrics'];
        foreach (['.json', '_curve.json'] as $suffix) { $evidence[$name . $suffix] = hash_file('sha256', $dir . '/' . $name . $suffix); }
    }
}
$rows = C::compare($results, (float) $protocol['initial_equity']);
$moneyDd = $defensive = $same = $publication = $frontier = []; $familyCounts = [];
foreach ($rows as $id => &$row) {
    $row['family'] = $protocol['cases'][$id]['family']; $row['annual'] = $annual[$id];
    $familyCounts[$row['family']] = ($familyCounts[$row['family']] ?? 0) + 1;
    if ($row['family'] === 'publication_sensitivity') { $publication[] = $id; continue; }
    if ($id === 'deployed') { continue; }
    if ($row['same_money_drawdown_as_control']) { $same[] = $id; }
    if ($row['dominates_control_money_drawdown']) { $moneyDd[] = $id; }
    if ($row['drawdown_nonworse_all_four'] && $row['best_drawdown_improvement_pp'] >= 1) { $defensive[] = $id; }
    if ($row['dominated_by'] === []) { $frontier[] = $id; }
}
unset($row);
$rank = static function (array $ids) use ($rows): array {
    usort($ids, static fn ($a, $b): int => ($rows[$b]['worst_cagr_delta_pp'] <=> $rows[$a]['worst_cagr_delta_pp']) ?: strcmp($a, $b)); return $ids;
};
$moneyDd = $rank($moneyDd); $defensive = $rank($defensive);
$eligible = array_values(array_diff(array_keys($rows), $publication, ['deployed']));
usort($eligible, static fn ($a, $b): int => ($rows[$b]['metrics']['continuous__30']['cagr'] <=> $rows[$a]['metrics']['continuous__30']['cagr']) ?: strcmp($a, $b));
$shortlist = array_values(array_unique(['deployed', ...array_slice($moneyDd, 0, 2), ...array_slice($defensive, 0, 2), ...array_slice($eligible, 0, 2)]));
$earlyDir = $root . '/var/reports/deployed_candidate_early_stress_20260915'; $early = null;
if (is_file($earlyDir . '/summary.json')) {
    $earlyProtocol = $read($earlyDir . '/protocol.json'); $earlySummary = $read($earlyDir . '/summary.json');
    $check($earlySummary['complete'] === true && count($earlySummary['results']) === 24
        && $earlySummary['protocol_sha256'] === hash_file('sha256', $earlyDir . '/protocol.json')
        && $earlyProtocol['parent_protocol_sha256'] === $sha, 'Early stress identity incomplete.');
    foreach ($earlyProtocol['code_sha256'] as $file => $hash) { $check(hash_file('sha256', $root . '/' . $file) === $hash, 'Early stress code drift.'); }
    $early = ['protocol' => $earlyProtocol, 'summary' => $earlySummary, 'rows' => [], 'evidence_sha256' => [], 'nonworse_all_eight' => []];
    foreach ($earlyProtocol['cases'] as $id => $_) {
        $nonworse = $rows[$id]['dominates_control_money_drawdown'];
        foreach ($earlyProtocol['starts'] as $scenario => $start) {
            foreach ([30, 60] as $cost) {
                $name = $id . '__' . $scenario . '__' . $cost; $r = $read($earlyDir . '/' . $name . '.json');
                $curve = $read($earlyDir . '/' . $name . '_curve.json'); $m = $r['metrics']; $b = $earlySummary['results']['deployed__' . $scenario . '__' . $cost];
                $check($r['protocol_sha256'] === $earlySummary['protocol_sha256'] && $r['id'] === $id && $r['scenario'] === $scenario && $r['cost_bps'] === $cost, 'Early case identity drift.');
                $check($m === $earlySummary['results'][$name] && count($curve) === $m['points'], 'Early metrics/curve mismatch.');
                $check($curve[0]['date'] === $start && end($curve)['date'] === $earlyProtocol['end']
                    && abs($curve[0]['equity'] - $protocol['initial_equity']) < .0001, 'Early sample window/capital mismatch.');
                $check(abs(end($curve)['equity'] / $protocol['initial_equity'] - 1 - $m['return']) < 1e-9, 'Early terminal equity mismatch.');
                $product = 1.; foreach ($r['annual'] as $year) { $product *= 1 + $year['return']; }
                $check(abs($product - 1 - $m['return']) < 1e-8, 'Early annual return mismatch.');
                foreach (['portfolio', 'sleeves'] as $scope) {
                    $l = $r['trade_ledger'][$scope]; $check(array_sum($l['annual_closed']) === $l['closed_total']
                        && $l['opened_total'] === $l['closed_total'] + count($l['open_at_end']), 'Early trade accounting mismatch.');
                }
                $nonworse = $nonworse && $m['return'] >= $b['return'] - 1e-10 && $m['max_drawdown'] >= $b['max_drawdown'] - 1e-10;
                $early['rows'][$id][$scenario . '__' . $cost] = ['metrics' => $m, 'annual' => $r['annual'],
                    'closed_ticker_episodes' => $r['trade_ledger']['portfolio']['annual_closed'], 'closed_sleeve_episodes' => $r['trade_ledger']['sleeves']['annual_closed']];
                foreach (['.json', '_curve.json'] as $suffix) { $early['evidence_sha256'][$name . $suffix] = hash_file('sha256', $earlyDir . '/' . $name . $suffix); }
            }
        }
        if ($nonworse) { $early['nonworse_all_eight'][] = $id; }
    }
}
$report = ['generated_at' => gmdate(DATE_ATOM), 'protocol_sha256' => $sha, 'protocol' => $protocol,
    'case_count' => count($summary['results']), 'verification_assertions' => $assertions,
    'regression' => $regression, 'regression_sha256' => hash_file('sha256', $dir . '/regression.json'),
    'runtime_unchanged' => CandidateRelease::hash($root) === $protocol['deployed_runtime_hash'], 'orders_submitted' => 0,
    'independent_holdout' => false, 'deployment_authority' => false, 'family_counts' => $familyCounts,
    'selection_note' => 'Descriptive post-run ordering; all reused-history trials disclosed. Four conditions overlap and are NOT four independent confirmations. Publication-lag tests are excluded from promotion shortlists.',
    'money_drawdown_dominators' => $moneyDd, 'defensive_tradeoffs' => $defensive, 'same_as_control' => $same,
    'publication_sensitivities' => $publication, 'money_drawdown_frontier' => $frontier,
    'display_shortlist' => $shortlist, 'rows' => $rows, 'evidence_sha256' => $evidence, 'early_stress' => $early];
if (!$report['runtime_unchanged']) { throw new RuntimeException('Active release changed.'); }
foreach (['tools/summarize_deployed_candidate_20260915.php', 'src/Research/DeployedCandidateComparison.php'] as $p) {
    $report['analysis_code_sha256'][$p] = hash_file('sha256', $root . '/' . $p);
}
A::write($dir . '/comparison.json', $report);
A::write($root . '/docs/HYBRID_V4_DEPLOYED_STUDY_2026-09-15.json', $report);
$pct = static fn (float $v): string => number_format($v * 100, 2, '.', '') . '%';
$usd = static fn (float $v): string => '$' . number_format($v, 0, '.', ',');
$lines = ['# Deployed candidate sensitivity study, 2026-09-15', '',
    'Status: offline completed research. No strategy switch, no orders, no live approval.', '',
    '## Design', '',
    '- 124 hypotheses plus the deployed control; 500 complete cases, four per recipe.',
    '- Initial equity $27,567.66. Starts 2021-01-04 and 2023-01-03; both end 2026-09-04.',
    '- Alpaca raw prices for prior-close integer sizing, split-adjusted bars for signals. Frozen S5TW and Cboe VVIX supplements; no Yahoo substitution.',
    '- Costs 30 and 60 basis points (0.30% and 0.60% of traded notional); economic switching hurdle held constant.',
    '- Calendar-day margin interest is modeled at the frozen 6.25% annual rate. The fixed stock basket is not a point-in-time membership/delisting universe; selection/survivorship bias remains possible.',
    '- Both samples have already informed research. This is NOT independent holdout validation. More trials increase overfitting risk.',
    '- Continuous 2021 and fresh 2023 runs are different portfolio paths, not slices of one equity curve.',
    '- All input/source hashes, every recipe, every result and yearly trade counts are retained in the adjacent JSON; raw curves and ledgers are under var/reports/deployed_candidate_study_20260915.', '',
    '## Results', '',
    count($moneyDd) . ' non-publication recipes have non-worse terminal capital AND drawdown in all four conditions with at least one strict improvement. This is a descriptive historical comparison, not an automatic deployment gate.', '',
    count($defensive) . ' recipes reduce drawdown by at least one percentage point somewhere without worsening it in any condition; some sacrifice capital. '
        . count($same) . ' recipes produce unchanged capital/drawdown, so they are not improvements.', '',
    'CAGR is annualized compounded growth, not yearly guaranteed income. DD is maximum drawdown. Dollar amounts include the starting capital. Display selection includes money/DD dominators, defensive alternatives, and the largest 2021/30-bps CAGR; see all trials below.', ''];
foreach ([30, 60] as $cost) {
    $lines[] = '### ' . $cost . ' bps'; $lines[] = '';
    $lines[] = '| Recipe | 2021 terminal | 2021 CAGR | 2021 DD | 2023 terminal | 2023 CAGR | 2023 DD |';
    $lines[] = '|---|---:|---:|---:|---:|---:|---:|';
    foreach ($shortlist as $id) {
        $a = $rows[$id]['metrics']['continuous__' . $cost]; $b = $rows[$id]['metrics']['fresh2023__' . $cost];
        $lines[] = '| ' . $id . ' | ' . $usd($protocol['initial_equity'] * (1 + $a['return'])) . ' | ' . $pct($a['cagr']) . ' | ' . $pct($a['max_drawdown'])
            . ' | ' . $usd($protocol['initial_equity'] * (1 + $b['return'])) . ' | ' . $pct($b['cagr']) . ' | ' . $pct($b['max_drawdown']) . ' |';
    }
    $lines[] = '';
}
if ($early !== null) {
    $lines = [...$lines, '## Earlier Market Regimes', '',
        '24 additional cases: the six predeclared follow-up recipes, starts 2017-01-03 and 2019-01-02, end 2020-12-31, at both costs. Includes the 2018 decline and the COVID shock. No historical prices were invented before IPO: symbols with zero available bars (' . implode(', ', $early['protocol']['unavailable_symbols']) . ') were excluded identically from every sleeve/case. This is still a selected stock basket, not a survivorship-free holdout.', '',
        'Money/DD non-worse than control across all eight checked start/cost conditions: ' . ($early['nonworse_all_eight'] === [] ? 'none' : implode(', ', $early['nonworse_all_eight'])) . '. Overlapping periods do not provide independent statistical confirmations.', '',
        '| Recipe | Cost, bps | 2017 CAGR | 2017 DD | 2019 CAGR | 2019 DD |', '|---|---:|---:|---:|---:|---:|'];
    foreach ($early['rows'] as $id => $conditions) {
        foreach ([30, 60] as $cost) {
            $a = $conditions['early2017__' . $cost]['metrics']; $b = $conditions['early2019__' . $cost]['metrics'];
            $lines[] = '| ' . $id . ' | ' . $cost . ' | ' . $pct($a['cagr']) . ' | ' . $pct($a['max_drawdown']) . ' | ' . $pct($b['cagr']) . ' | ' . $pct($b['max_drawdown']) . ' |';
        }
    }
    $lines[] = '';
}
$lines = [...$lines, '## Yearly Comparison', '',
    '2021-start, 30 bps. A trade is one completed aggregate ticker exposure, counted in its exit year; resizing and partial exits are NOT additional trades. No forced year-end close. 2026 ends September 4.', '',
    '| Recipe | Year | Return | DD | Closed ticker episodes | Closed sleeve episodes |', '|---|---:|---:|---:|---:|---:|'];
foreach ($shortlist as $id) {
    foreach ($annual[$id]['continuous__30'] as $year => $m) {
        $lines[] = '| ' . $id . ' | ' . $year . ' | ' . $pct($m['return']) . ' | ' . $pct($m['max_drawdown']) . ' | ' . $m['closed_ticker_episodes'] . ' | ' . $m['closed_sleeve_episodes'] . ' |';
    }
}
$lines = [...$lines, '', '## Every Trial', '',
    'Deltas are relative to deployed control. Minimum means the worst of the four conditions. Gross exposure increases are shown separately: extra leverage is not free alpha.', '',
    '| Recipe | Family | Min CAGR delta, pp | Min DD improvement, pp | Money/DD dominates | Gross never higher |', '|---|---|---:|---:|---|---|'];
foreach ($rows as $id => $row) {
    $lines[] = '| ' . $id . ' | ' . $row['family'] . ' | ' . number_format($row['worst_cagr_delta_pp'], 3, '.', '') . ' | '
        . number_format($row['worst_drawdown_improvement_pp'], 3, '.', '') . ' | ' . ($row['dominates_control_money_drawdown'] ? 'yes' : 'no') . ' | ' . ($row['gross_nonhigher_all_four'] ? 'yes' : 'no') . ' |';
}
$lines = [...$lines, '', '## Execution Limits And Next Evidence', '',
    '- Daily-bar tests do not establish NBBO fills, queue priority or partial-fill timing. Alpaca paper itself omits market impact, latency slippage, fees and dividends, and randomly simulates partial fills: [Alpaca paper specification](https://docs.alpaca.markets/us/docs/paper-trading).',
    '- OPG acceptance is not a fill. Native stop registration is not a guaranteed stop price or extended-hours protection: [Alpaca order specification](https://docs.alpaca.markets/us/docs/orders-at-alpaca).',
    '- tools/audit_candidate_forward.php independently checks read-only broker observations, intent identities, fill/cash conservation and acknowledged close-based stop coverage. It never repairs, submits or cancels orders.',
    '- Six publication-lag experiments are robustness diagnostics, not permission to feed stale indicators into the active runtime.',
    '- A favorable recipe still requires execution parity, cost/minute checks, parameter-neighborhood review and future independent paper observations. This study does not modify its gate or replace the running release.',
    '- Hourly continuation is attached to the existing task. It must not duplicate a running study, repeat unchanged results, or mutate the active manifest.', '',
    '## Reproduction', '', '```sh',
    'php tests/deployed_candidate_study.php', 'php tests/deployed_candidate_comparison.php', 'php tests/paper_forward_execution_audit.php',
    'php -d memory_limit=8G tools/research_deployed_candidate_20260915.php',
    'php -d memory_limit=8G tools/stress_deployed_candidate_early_20260915.php',
    'php -d memory_limit=1G tools/summarize_deployed_candidate_20260915.php',
    'php tools/audit_candidate_forward.php', '```', '',
    'The 8G limit belongs only to offline research. The active paper daemon memory limit and release hash are unchanged.',
    'Verification: ' . $regression['count'] . ' regression programs passed; ' . $assertions . ' accounting/date/evidence assertions; 500 cases; no operational orders or database writes by research.'];
$markdown = implode("\n", $lines) . "\n";
if (file_put_contents($root . '/docs/HYBRID_V4_DEPLOYED_STUDY_2026-09-15.md', $markdown) === false) { throw new RuntimeException('Cannot write report.'); }
echo json_encode(array_intersect_key($report, array_flip(['case_count', 'verification_assertions', 'runtime_unchanged', 'money_drawdown_dominators', 'defensive_tradeoffs', 'same_as_control', 'display_shortlist'])), JSON_PRETTY_PRINT), "\n";
