#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDefinition;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\DeployedCandidateStudy as S;
use FulltimeTrading\Research\OpportunityPolicy as O;
use FulltimeTrading\Research\PaperExecutionRotationEnsembleBacktester as E;
use FulltimeTrading\Research\PortfolioCircuitController as C;
use FulltimeTrading\Research\ResearchTradeLedger as L;
use FulltimeTrading\Research\SelectedMaximumResearch;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $out = $root . '/var/reports/deployed_candidate_early_stress_20260915';
$lock = \FulltimeTrading\Support\ProcessLock::tryAcquire($root . '/var/run/research_deployed_candidate_early_20260915.lock');
if ($lock === null) { exit(75); }
$read = static fn ($p): array => json_decode(file_get_contents($root . '/' . $p), true, 512, JSON_THROW_ON_ERROR);
$parentPath = 'var/reports/deployed_candidate_study_20260915/protocol.json'; $parent = $read($parentPath);
$selected = ['deployed', 'vvix_confirmation_105', 'vvix_confirmation_110', 'dynamic_weight_0.35', 'dynamic_weight_0.4', 'circuit_0.15_pause_10'];
$protocol = ['schema' => 'deployed-candidate-early-stress-v1', 'cases' => array_intersect_key(S::cases(), array_flip($selected)),
    'starts' => ['early2017' => '2017-01-03', 'early2019' => '2019-01-02'], 'end' => '2020-12-31', 'costs_bps' => [30, 60],
    'initial_equity' => $parent['initial_equity'], 'parent_protocol_sha256' => hash_file('sha256', $root . '/' . $parentPath),
    'independent_holdout' => false, 'deployment_authority' => false, 'orders_submitted' => 0,
    'selection' => 'Adaptive follow-up chosen after observing the post-2021 sensitivity batch. Six recipes frozen before this early-period run.',
    'universe_rule' => 'Fixed research basket; remove only symbols with zero bars on/before sample end from each sleeve. No price forward-fill or synthetic pre-IPO history. Other minimum-history filters unchanged. Survivorship/selection bias remains possible.',
    'deployed_runtime_hash' => $parent['deployed_runtime_hash'], 'input_sha256' => $parent['input_sha256'], 'code_sha256' => $parent['code_sha256']];
$protocol['code_sha256']['tools/stress_deployed_candidate_early_20260915.php'] = hash_file('sha256', __FILE__);
$paths = ['raw' => 'var/reports/candidate_execution_data_20260914/raw.json', 'split' => 'var/reports/candidate_execution_data_20260914/split.json',
    's5tw' => 'var/reports/breadth_volatility_data_20260909/s5tw.json', 'vvix' => 'var/reports/vvix_data_20260909/cboe_vvix.json'];
foreach ($paths as $key => $path) { if (hash_file('sha256', $root . '/' . $path) !== $protocol['input_sha256'][$key]) { throw new RuntimeException('Frozen input drift.'); } }
foreach ($protocol['code_sha256'] as $path => $hash) { if (hash_file('sha256', $root . '/' . $path) !== $hash) { throw new RuntimeException('Frozen code drift.'); } }
$all = D::decode($read($paths['split'])); $raw = D::decode($read($paths['raw'])); $nominal = [];
foreach ($all as $symbol => $series) {
    $all[$symbol] = array_values(array_filter($series, static fn ($b): bool => D::session($b) <= $protocol['end']));
    if ($all[$symbol] === []) { unset($all[$symbol]); }
}
foreach ($raw as $symbol => $series) { foreach ($series as $b) { if (D::session($b) <= $protocol['end']) { $nominal[$symbol][D::session($b)] = ['open' => $b->open, 'close' => $b->close]; } } }
unset($raw);
$profile = require $root . '/config/tactical_rotation.php';
$protocol['unavailable_symbols'] = array_values(array_diff($profile['universe'], array_keys($all)));
$protocol['available_first_dates'] = array_map(static fn ($series): string => D::session($series[0]), $all);
if (!is_dir($out)) { mkdir($out, 0775, true); }
if (is_file($out . '/protocol.json') && SelectedMaximumResearch::hash($read('var/reports/deployed_candidate_early_stress_20260915/protocol.json')) !== SelectedMaximumResearch::hash($protocol)) {
    throw new RuntimeException('Early stress protocol drift.');
}
A::write($out . '/protocol.json', $protocol); $sha = hash_file('sha256', $out . '/protocol.json');
$calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
$breadth = B::validateBreadth($read($paths['s5tw']), $calendar); $vvix = array_intersect_key($read($paths['vvix']), array_flip($calendar));
$features = O::features($all, $vvix, $profile['universe']);
$bars = array_intersect_key($all, array_flip(array_merge($profile['universe'], ['SPY', 'QQQ']))); $results = [];
foreach ($protocol['cases'] as $id => $spec) {
    $maps = S::maps($spec, $all, $breadth, $vvix);
    foreach ($protocol['starts'] as $scenario => $start) {
        foreach ($protocol['costs_bps'] as $cost) {
            $name = $id . '__' . $scenario . '__' . $cost; $path = $out . '/' . $name . '.json';
            if (is_file($path)) {
                $r = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if ($r['protocol_sha256'] !== $sha) { throw new RuntimeException('Early stress resume drift.'); }
            } else {
                if (CandidateRelease::hash($root) !== $protocol['deployed_runtime_hash']) { throw new RuntimeException('Active release changed.'); }
                $books = S::books($spec, $profile, $maps['scale'], $features, $nominal, $cost);
                foreach ($books as &$book) { $book['config']['universe'] = array_values(array_intersect($book['config']['universe'], array_keys($all))); }
                unset($book);
                $engine = new E($books); $controller = new C(array_replace(CandidateDefinition::CIRCUIT, $spec['circuit'] ?? []), $maps['confirmation']);
                $result = $engine->runControlled($bars, $start, $protocol['end'], $controller, $protocol['initial_equity']); $annual = [];
                for ($year = (int) substr($start, 0, 4); $year <= 2020; ++$year) { $annual[$year] = $engine->metrics($result, "$year-01-01", ($year + 1) . '-01-01'); }
                $r = ['protocol_sha256' => $sha, 'id' => $id, 'scenario' => $scenario, 'cost_bps' => $cost,
                    'metrics' => $engine->metrics($result), 'annual' => $annual, 'trade_ledger' => L::fromSleeveCurves($result['sleeve_curves'])];
                A::write($path, $r); A::write($out . '/' . $name . '_curve.json', array_map(static fn ($p): array => array_intersect_key($p,
                    array_flip(['date', 'equity', 'start_equity', 'equity_low', 'equity_high', 'period_start_date', 'turnover'])), $result['curve']));
                unset($result, $engine, $controller, $books); gc_collect_cycles();
            }
            $results[$name] = $r['metrics']; printf("%s CAGR %.3f%% DD %.3f%%\n", $name, $r['metrics']['cagr'] * 100, $r['metrics']['max_drawdown'] * 100);
        }
    }
}
A::write($out . '/summary.json', ['protocol_sha256' => $sha, 'results' => $results, 'expected_cases' => 24, 'complete' => count($results) === 24,
    'independent_holdout' => false, 'orders_submitted' => 0, 'completed_at' => gmdate(DATE_ATOM)]);
echo 'Early stress cases: ', count($results), "/24\n";
