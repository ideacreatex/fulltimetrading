#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDefinition;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\CandidateInteractionStudy as I;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\DeployedCandidateStudy as S;
use FulltimeTrading\Research\OpportunityPolicy as O;
use FulltimeTrading\Research\PaperExecutionRotationEnsembleBacktester as E;
use FulltimeTrading\Research\PortfolioCircuitController as C;
use FulltimeTrading\Research\ResearchTradeLedger as L;
use FulltimeTrading\Research\SelectedMaximumResearch as H;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $dir = $root . '/var/reports/candidate_interactions_20260915';
$lock = \FulltimeTrading\Support\ProcessLock::tryAcquire($root . '/var/run/research_candidate_interactions_20260915.lock');
if ($lock === null) { exit(75); }
$maxNew = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--max-new=(\d+)$/D', $arg, $m)) { throw new InvalidArgumentException('Only --max-new=N is supported.'); }
    $maxNew = (int) $m[1];
}
$read = static fn ($path): array => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$parentPath = $root . '/var/reports/deployed_candidate_study_20260915/protocol.json'; $parent = $read($parentPath);
$earlyPath = $root . '/var/reports/deployed_candidate_early_stress_20260915/protocol.json'; $early = $read($earlyPath);
$candidate = require $root . '/config/paper_candidate.php'; $release = CandidateRelease::verify($root, $candidate);
if ($release['runtime_hash'] !== $parent['deployed_runtime_hash'] || $early['parent_protocol_sha256'] !== hash_file('sha256', $parentPath)) { throw new RuntimeException('Parent identity mismatch.'); }
$paths = ['raw' => 'var/reports/candidate_execution_data_20260914/raw.json', 'split' => 'var/reports/candidate_execution_data_20260914/split.json',
    's5tw' => 'var/reports/breadth_volatility_data_20260909/s5tw.json', 'vvix' => 'var/reports/vvix_data_20260909/cboe_vvix.json'];
$protocol = ['schema' => 'candidate-factorial-interactions-v1', 'cases' => I::cases(), 'conditions' => I::conditions(), 'costs_bps' => [30, 60],
    'initial_equity' => $parent['initial_equity'], 'deployed_runtime_hash' => $release['runtime_hash'],
    'input_paths' => $paths, 'input_sha256' => $parent['input_sha256'], 'code_sha256' => $early['code_sha256'],
    'parent_protocol_sha256' => hash_file('sha256', $parentPath), 'early_protocol_sha256' => hash_file('sha256', $earlyPath),
    'independent_holdout' => false, 'deployment_authority' => false, 'orders_submitted' => 0,
    'planned_comparisons' => 192, 'planned_reused' => 36, 'planned_new' => 156,
    'selection' => 'Adaptive follow-up to previous research; all 24 factorial cells fixed before new results. Compare terminal dollars, drawdown, exposure and adjacent-factor effects across all eight conditions. No automated promotion.',
    'data_contract' => 'Same Alpaca raw integer sizing, split signals, 6.25% annual calendar-day financing and 30/60bps as parents. Early periods exclude only zero-history symbols before end; no pre-IPO synthesis. Current selected basket remains vulnerable to selection/survivorship bias. No NBBO/partial-fill simulation.'];
foreach (['src/Research/CandidateInteractionStudy.php', 'tools/research_candidate_interactions_20260915.php', 'tests/candidate_interaction_study.php'] as $p) { $protocol['code_sha256'][$p] = hash_file('sha256', $root . '/' . $p); }
foreach ($protocol['code_sha256'] as $p => $hash) { if (hash_file('sha256', $root . '/' . $p) !== $hash) { throw new RuntimeException('Frozen source drift: ' . $p); } }
foreach ($paths as $key => $path) { if (hash_file('sha256', $root . '/' . $path) !== $protocol['input_sha256'][$key]) { throw new RuntimeException('Frozen input drift: ' . $key); } }
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
if (is_file($dir . '/protocol.json') && H::hash($read($dir . '/protocol.json')) !== H::hash($protocol)) { throw new RuntimeException('Interaction protocol drift; preserve old run.'); }
A::write($dir . '/protocol.json', $protocol); $sha = hash_file('sha256', $dir . '/protocol.json');
$summary = ['protocol_sha256' => $sha, 'expected_comparisons' => 192, 'complete' => false, 'results' => [], 'reused' => 0, 'computed' => 0, 'orders_submitted' => 0];
$save = static function () use (&$summary, $dir): void { $summary['updated_at'] = gmdate(DATE_ATOM); $summary['complete'] = count($summary['results']) === 192; A::write($dir . '/summary.json', $summary); };
$tasks = [];
foreach (['svxy_cap_0.75', 'risk_scale_1.1', 's5tw_high_close_20_1.25', ...array_keys(I::cases())] as $id) {
    foreach (I::conditions() as $scenario => $condition) {
        foreach ([30, 60] as $cost) { $name = $id . '__' . $scenario . '__' . $cost; $tasks[$name] = compact('id', 'scenario', 'condition', 'cost', 'name'); }
    }
}
// Cached controls/single factors are validated before any new simulation, never recomputed.
foreach ($tasks as $name => $task) {
    $source = I::priorCase($task['id'], $task['scenario'], $task['cost']);
    if ($source === null) { continue; }
    $r = $read($root . '/' . $source); $sourceCurve = substr($source, 0, -5) . '_curve.json';
    $expectedSha = $task['condition']['group'] === 'early' ? $protocol['early_protocol_sha256'] : $protocol['parent_protocol_sha256'];
    if ($r['protocol_sha256'] !== $expectedSha || $r['id'] !== $task['id'] || $r['scenario'] !== $task['scenario'] || $r['cost_bps'] !== $task['cost']) { throw new RuntimeException('Cached case identity drift.'); }
    $result = ['protocol_sha256' => $sha, 'id' => $task['id'], 'scenario' => $task['scenario'], 'cost_bps' => $task['cost'], 'reused' => true,
        'metrics' => $r['metrics'], 'annual' => $r['annual'], 'trade_ledger' => $r['trade_ledger'],
        'curve_path' => $sourceCurve, 'curve_sha256' => hash_file('sha256', $root . '/' . $sourceCurve),
        'source_path' => $source, 'source_sha256' => hash_file('sha256', $root . '/' . $source), 'stop_events' => null];
    if (is_file($dir . '/' . $name . '.json') && H::hash($read($dir . '/' . $name . '.json')) !== H::hash($result)) { throw new RuntimeException('Cached receipt drift.'); }
    A::write($dir . '/' . $name . '.json', $result); $summary['results'][$name] = $r['metrics']; ++$summary['reused'];
}
$save(); echo 'Prior results reused: ', $summary['reused'], "\n";
$profile = require $root . '/config/tactical_rotation.php'; $contexts = []; $newThisRun = 0;
foreach ($tasks as $name => $task) {
    if (isset($summary['results'][$name])) { continue; }
    $path = $dir . '/' . $name . '.json';
    if (is_file($path)) {
        $r = $read($path);
        if ($r['protocol_sha256'] !== $sha || hash_file('sha256', $root . '/' . $r['curve_path']) !== $r['curve_sha256']) { throw new RuntimeException('Computed case resume drift.'); }
    } else {
        if ($maxNew > 0 && $newThisRun >= $maxNew) { break; }
        if (CandidateRelease::hash($root) !== $protocol['deployed_runtime_hash']) { throw new RuntimeException('Operational release changed.'); }
        $group = $task['condition']['group'];
        if (!isset($contexts[$group])) {
            $all = D::decode($read($root . '/' . $paths['split'])); $raw = D::decode($read($root . '/' . $paths['raw'])); $nominal = [];
            foreach ($all as $symbol => $series) {
                $all[$symbol] = array_values(array_filter($series, static fn ($b): bool => D::session($b) <= $task['condition']['end']));
                if ($all[$symbol] === []) { unset($all[$symbol]); }
            }
            foreach ($raw as $symbol => $series) { foreach ($series as $b) { if (D::session($b) <= $task['condition']['end']) { $nominal[$symbol][D::session($b)] = ['open' => $b->open, 'close' => $b->close]; } } }
            unset($raw);
            $calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']); $breadth = B::validateBreadth($read($root . '/' . $paths['s5tw']), $calendar);
            $vvix = array_intersect_key($read($root . '/' . $paths['vvix']), array_flip($calendar)); $features = O::features($all, $vvix, $profile['universe']);
            $bars = array_intersect_key($all, array_flip(array_merge($profile['universe'], ['SPY', 'QQQ'])));
            foreach ($bars as &$series) { $series = array_values(array_filter($series, static fn ($b): bool => D::session($b) >= $task['condition']['bars_from'])); }
            unset($series); $contexts[$group] = compact('all', 'nominal', 'breadth', 'vvix', 'features', 'bars');
        }
        $ctx = $contexts[$group]; $spec = I::cases()[$task['id']]['spec']; $maps = S::maps($spec, $ctx['all'], $ctx['breadth'], $ctx['vvix']);
        $books = S::books($spec, $profile, $maps['scale'], $ctx['features'], $ctx['nominal'], $task['cost']);
        foreach ($books as &$book) { $book['config']['universe'] = array_values(array_intersect($book['config']['universe'], array_keys($ctx['all']))); }
        unset($book);
        $engine = new E($books); $controller = new C(CandidateDefinition::CIRCUIT, $maps['confirmation']); $began = microtime(true);
        $result = $engine->runControlled($ctx['bars'], $task['condition']['start'], $task['condition']['end'], $controller, $protocol['initial_equity']);
        $annual = []; for ($year = (int) substr($task['condition']['start'], 0, 4); $year <= (int) substr($task['condition']['end'], 0, 4); ++$year) { $annual[$year] = $engine->metrics($result, "$year-01-01", ($year + 1) . '-01-01'); }
        $stops = []; foreach ($result['sleeve_curves'] as $sleeve => $curve) { foreach ($curve as $row) { if (($row['standing_stop_event'] ?? null) !== null) { $stops[] = ['sleeve' => $sleeve] + $row['standing_stop_event']; } } }
        $curvePath = 'var/reports/candidate_interactions_20260915/' . $name . '_curve.json';
        A::write($root . '/' . $curvePath, array_map(static fn ($p): array => array_intersect_key($p, array_flip(['date', 'equity', 'start_equity', 'equity_low', 'equity_high', 'period_start_date', 'turnover'])), $result['curve']));
        $r = ['protocol_sha256' => $sha, 'id' => $task['id'], 'scenario' => $task['scenario'], 'cost_bps' => $task['cost'], 'reused' => false,
            'metrics' => $engine->metrics($result), 'annual' => $annual, 'trade_ledger' => L::fromSleeveCurves($result['sleeve_curves']), 'stop_events' => $stops,
            'curve_path' => $curvePath, 'curve_sha256' => hash_file('sha256', $root . '/' . $curvePath),
            'unavailable_symbols' => array_values(array_diff($profile['universe'], array_keys($ctx['all']))), 'elapsed_seconds' => microtime(true) - $began];
        A::write($path, $r); ++$newThisRun; unset($result, $engine, $controller, $books, $ctx, $stops); gc_collect_cycles();
        printf("%s CAGR %.3f%% DD %.3f%%\n", $name, $r['metrics']['cagr'] * 100, $r['metrics']['max_drawdown'] * 100);
    }
    $summary['results'][$name] = $r['metrics']; ++$summary['computed']; $save();
}
$save(); echo 'Interaction comparisons: ', count($summary['results']), "/192; new in this invocation: $newThisRun\n";
