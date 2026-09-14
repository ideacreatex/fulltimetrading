#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\ResearchMultiplicityAudit as M;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $calendar = ($argv[1] ?? '') === 'calendar';
$name = $calendar ? 'opportunity_calendar_20260910' : 'opportunity_20260910'; $out = $root . '/var/reports/' . $name;
$read = static fn ($f): array => json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
if (file_exists($out . '/results.json')) { throw new RuntimeException('Summary already exists.'); }
$p = $read($out . '/protocol.json'); $selection = $read($out . '/selection.json'); $primary = $read($out . '/primary.json');
foreach ($p['code_sha256'] as $f => $hash) { if ($hash !== hash_file('sha256', $root . '/' . $f)) { throw new RuntimeException('Code drift.'); } }
$result = ['completed_at' => gmdate(DATE_ATOM), 'protocol_sha256' => hash_file('sha256', $out . '/protocol.json'),
    'script_sha256' => hash_file('sha256', __FILE__), 'cases' => count($p['cases']), 'alternatives' => count($p['cases']) - 2,
    'phase_count' => $p['phase_count'] ?? 1, 'adaptive_followup' => $p['adaptive_followup'] ?? false,
    'primary_replays' => 0, 'audit_replays' => 0, 'prefix_passes' => 0, 'old_curve_matches' => 0, 'primary_qualification_passes' => [],
    'selected' => $selection['selected'], 'summary' => [], 'audit' => [], 'dominates_own_anchor_all_primary' => [], 'dominates_maximum_all_primary' => []];
$dominates = static fn ($m, $b): bool => $m['cagr'] > $b['cagr'] + 1e-10 && $m['max_drawdown'] >= $b['max_drawdown'] - 1e-10;
$signatures = [];
foreach ($p['cases'] as $id => $d) {
    $own = $maximum = true; $fingerprints = [];
    foreach ($primary[$id] as $scenario => $phases) {
        $cagrs = $dds = [];
        foreach ($phases as $phase => $r) {
            $m = $r['metrics']['full']; $cagrs[] = $m['cagr']; $dds[] = $m['max_drawdown'];
            $result['primary_replays']++; $result['old_curve_matches'] += (int) ($r['old_curve_match'] === true);
            if (($r['qualification']['qualifies'] ?? false)) { $result['primary_qualification_passes'][] = [$id, $scenario, $phase]; }
            $own = $own && $dominates($m, $primary[$d['anchor']][$scenario][$phase]['metrics']['full']);
            $maximum = $maximum && $dominates($m, $primary['maximum'][$scenario][$phase]['metrics']['full']);
            $fingerprints[] = hash_file('sha256', $out . '/' . $scenario . '_p' . $phase . '_' . $id . '_curve.json');
        }
        $result['summary'][$id][$scenario] = ['phase0' => $phases[0], 'cagr_by_phase' => $cagrs, 'drawdown_by_phase' => $dds,
            'minimum_cagr' => min($cagrs), 'worst_drawdown' => min($dds)];
    }
    $signatures[$id] = hash('sha256', json_encode($fingerprints));
    if ($own) { $result['dominates_own_anchor_all_primary'][] = $id; }
    if ($maximum) { $result['dominates_maximum_all_primary'][] = $id; }
}
$result['distinct_primary_behaviors'] = count(array_unique($signatures));
foreach ($selection['jobs'] as $j) {
    ['id' => $id, 'scenario' => $s, 'phase' => $phase] = $j; $r = $read($out . '/' . $s . '_p' . $phase . '_' . $id . '.json');
    $result['audit'][$id][$s][$phase] = $r; $result['audit_replays']++; $result['prefix_passes'] += (int) ($r['prefix_pass'] === true);
}
$result['dominates_own_anchor_all_primary_and_audits'] = [];
foreach ($selection['ids'] as $id) {
    if (!in_array($id, $result['dominates_own_anchor_all_primary'], true)) { continue; }
    $pass = true; $anchor = $p['cases'][$id]['anchor'];
    foreach ($result['audit'][$id] as $s => $phases) {
        if ($s === 'prefix2023') { continue; }
        foreach ($phases as $phase => $r) { $pass = $pass && $dominates($r['metrics']['full'], $result['audit'][$anchor][$s][$phase]['metrics']['full']); }
    }
    if ($pass) { $result['dominates_own_anchor_all_primary_and_audits'][] = $id; }
}
$matrix = []; $reference = [];
foreach ($p['phases'] as $phase) {
    $ref = $read($out . '/continuous_p' . $phase . '_maximum_curve.json');
    foreach ($ref as $row) { if ($row['date'] >= '2024-01-01') { $reference[$phase][$row['date']] = log($row['equity'] / $row['start_equity']); } }
}
foreach ($p['cases'] as $id => $d) {
    if ($id === 'maximum') { continue; }
    foreach ($p['phases'] as $phase) {
        $v = []; $curve = $read($out . '/continuous_p' . $phase . '_' . $id . '_curve.json');
        foreach ($curve as $row) { if (isset($reference[$phase][$row['date']])) { $v[$row['date']] = log($row['equity'] / $row['start_equity']) - $reference[$phase][$row['date']]; } }
        if (array_keys($v) !== array_keys($reference[$phase])) { throw new RuntimeException('Bootstrap dates do not align.'); }
        $matrix[$id . '_p' . $phase] = array_values($v);
    }
}
$result['multiplicity'] = M::run($matrix, 20, 1000, 20260910);
$result['multiplicity']['comparison'] = '2024 onward excess log returns versus unchanged maximum with matched phase structure; includes every candidate/phase. Prior/adaptive searches not corrected away.';
$result['tests'] = $read($root . '/var/reports/opportunity_20260910/tests.json');
foreach ($result['tests'] as $test) { if ($test['exit_code'] !== 0) { throw new RuntimeException('Regression failure.'); } }
$profile = require $root . '/config/tactical_rotation.php';
$result['operational_identity_unchanged'] = TacticalImplementationIdentity::current($root, $profile) === $p['operational_identity'];
if (!$result['operational_identity_unchanged']) { throw new RuntimeException('Operational identity changed.'); }
$result['orders_submitted'] = 0;
A::write($out . '/results.json', $result); A::write($root . '/docs/research_results/' . $name . '.json', $result);
echo json_encode(array_diff_key($result, array_flip(['summary', 'audit', 'tests'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
