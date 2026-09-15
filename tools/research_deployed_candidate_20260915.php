#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDefinition;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\DeployedCandidateStudy as Study;
use FulltimeTrading\Research\OpportunityPolicy as O;
use FulltimeTrading\Research\PaperExecutionRotationEnsembleBacktester as E;
use FulltimeTrading\Research\PortfolioCircuitController as C;
use FulltimeTrading\Research\ResearchTradeLedger as L;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $out = $root . '/var/reports/deployed_candidate_study_20260915';
$lock = \FulltimeTrading\Support\ProcessLock::tryAcquire($root . '/var/run/research_deployed_candidate_20260915.lock');
if ($lock === null) { exit(75); }
$options = ['max-cases' => '0'];
foreach (array_slice($argv, 1) as $arg) { if (str_starts_with($arg, '--') && str_contains($arg, '=')) { [$k, $v] = explode('=', substr($arg, 2), 2); $options[$k] = $v; } }
$read = static fn ($p): array => json_decode(file_get_contents($root . '/' . $p), true, 512, JSON_THROW_ON_ERROR);
$candidate = require $root . '/config/paper_candidate.php'; $release = CandidateRelease::verify($root, $candidate);
$profile = require $root . '/config/tactical_rotation.php';
$paths = ['raw' => 'var/reports/candidate_execution_data_20260914/raw.json', 'split' => 'var/reports/candidate_execution_data_20260914/split.json',
    's5tw' => 'var/reports/breadth_volatility_data_20260909/s5tw.json', 'vvix' => 'var/reports/vvix_data_20260909/cboe_vvix.json'];
$parent = $read('var/reports/candidate_capital_27567_66_20260915/protocol.json');
$protocol = ['schema' => 'deployed-candidate-local-sensitivity-v1', 'end' => '2026-09-04',
    'starts' => ['continuous' => '2021-01-04', 'fresh2023' => '2023-01-03'], 'costs_bps' => [30, 60],
    'initial_equity' => 27567.66, 'cases' => Study::cases(), 'deployed_runtime_hash' => $release['runtime_hash'],
    'independent_holdout' => false, 'deployment_authority' => false, 'orders_submitted' => 0,
    'selection' => 'All declared cases retained; compare cash, drawdown and gross across BOTH starts and BOTH cost levels. Historical Pareto candidates need independent review, not automatic promotion.',
    'limitations' => 'Retrospective, adaptively reused history. Split-only bars, fixed prior-close whole quantities. No NBBO/queue/partial-fill simulation. Publication lags are explicit what-if tests, not permission to use stale paper inputs.'];
foreach ($paths as $key => $path) {
    $sha = hash_file('sha256', $root . '/' . $path);
    if (!hash_equals($parent['input_sha256'][$key], $sha)) { throw new RuntimeException('Frozen input drift: ' . $key); }
    $protocol['input_sha256'][$key] = $sha;
}
foreach (['src/Research/DeployedCandidateStudy.php', 'tools/research_deployed_candidate_20260915.php',
    ...array_keys($parent['code_sha256'])] as $path) { $protocol['code_sha256'][$path] = hash_file('sha256', $root . '/' . $path); }
if (!is_dir($out)) { mkdir($out, 0775, true); }
if (is_file($out . '/protocol.json') && \FulltimeTrading\Research\SelectedMaximumResearch::hash($read('var/reports/deployed_candidate_study_20260915/protocol.json'))
    !== \FulltimeTrading\Research\SelectedMaximumResearch::hash($protocol)) { throw new RuntimeException('Study protocol drift; do not overwrite old cases.'); }
A::write($out . '/protocol.json', $protocol); $protocolSha = hash_file('sha256', $out . '/protocol.json');
$raw = D::decode($read($paths['raw'])); $nominal = [];
foreach ($raw as $symbol => $series) { foreach ($series as $b) { $nominal[$symbol][D::session($b)] = ['open' => $b->open, 'close' => $b->close]; } }
unset($raw);
$all = D::decode($read($paths['split']));
foreach ($all as $symbol => $series) { $all[$symbol] = array_values(array_filter($series, static fn ($b): bool => D::session($b) <= $protocol['end'])); }
$calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
$breadth = B::validateBreadth($read($paths['s5tw']), $calendar); $vvix = $read($paths['vvix']);
$features = O::features($all, $vvix, $profile['universe']);
$bars = array_intersect_key($all, array_flip(array_merge($profile['universe'], ['SPY', 'QQQ'])));
foreach ($bars as $symbol => $series) { $bars[$symbol] = array_values(array_filter($series, static fn ($b): bool => D::session($b) >= '2020-01-01')); }
$parentSummary = $read('var/reports/candidate_capital_27567_66_20260915/summary.json');
$summary = []; $newCases = 0;
foreach ($protocol['cases'] as $id => $spec) {
    $maps = Study::maps($spec, $all, $breadth, $vvix);
    foreach ($protocol['starts'] as $scenario => $start) {
        foreach ($protocol['costs_bps'] as $cost) {
            $caseId = $id . '__' . $scenario . '__' . $cost; $path = $out . '/' . $caseId . '.json';
            if (is_file($path)) {
                $saved = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if ($saved['protocol_sha256'] !== $protocolSha) { throw new RuntimeException('Case resume drift.'); }
            } else {
                if ((int) $options['max-cases'] > 0 && $newCases >= (int) $options['max-cases']) { break 3; }
                if (CandidateRelease::hash($root) !== $release['runtime_hash']) { throw new RuntimeException('Operational release changed during study.'); }
                $books = Study::books($spec, $profile, $maps['scale'], $features, $nominal, $cost);
                $engine = new E($books); $controller = new C(array_replace(CandidateDefinition::CIRCUIT, $spec['circuit'] ?? []), $maps['confirmation']);
                $began = microtime(true); $result = $engine->runControlled($bars, $start, $protocol['end'], $controller, $protocol['initial_equity']);
                $metrics = $engine->metrics($result);
                if ($id === 'deployed') {
                    $expected = $parentSummary['results']['split_' . $scenario . '_' . $cost . '_candidate_whole_previous_close'];
                    foreach (['return', 'cagr', 'max_drawdown', 'max_gross_bound', 'turnover'] as $key) {
                        if (abs($metrics[$key] - $expected[$key]) > 1e-10) { throw new RuntimeException('Deployed baseline mismatch: ' . $key); }
                    }
                }
                $annual = [];
                for ($year = (int) substr($start, 0, 4); $year <= 2026; ++$year) { $annual[$year] = $engine->metrics($result, "$year-01-01", ($year + 1) . '-01-01'); }
                $ledger = L::fromSleeveCurves($result['sleeve_curves']);
                $curve = array_map(static fn ($r): array => array_intersect_key($r, array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $result['curve']);
                $saved = ['protocol_sha256' => $protocolSha, 'id' => $id, 'family' => $spec['family'], 'scenario' => $scenario, 'cost_bps' => $cost,
                    'metrics' => $metrics, 'annual' => $annual, 'trade_ledger' => $ledger, 'sleeves' => count($books), 'elapsed_seconds' => microtime(true) - $began];
                A::write($path, $saved); A::write(substr($path, 0, -5) . '_curve.json', $curve); ++$newCases;
                printf("%s CAGR %.3f%% DD %.3f%% %.1fs\n", $caseId, $metrics['cagr'] * 100, $metrics['max_drawdown'] * 100, $saved['elapsed_seconds']);
                unset($result, $engine, $controller, $books, $ledger, $curve); gc_collect_cycles();
            }
            $summary[$caseId] = $saved['metrics'];
        }
    }
}
A::write($out . '/summary.json', ['protocol_sha256' => $protocolSha, 'results' => $summary, 'expected_cases' => count($protocol['cases']) * 4,
    'complete' => count($summary) === count($protocol['cases']) * 4, 'updated_at' => gmdate(DATE_ATOM), 'orders_submitted' => 0]);
echo 'Study cases available: ', count($summary), '/', count($protocol['cases']) * 4, "\n";
