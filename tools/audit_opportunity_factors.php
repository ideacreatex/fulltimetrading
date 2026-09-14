#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\OpportunityPolicy as O;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $out = $root . '/var/reports/opportunity_20260910';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
if (file_exists($out . '/factor_audit.json')) { throw new RuntimeException('Factor diagnostic already frozen.'); }
$p = $read($out . '/protocol.json'); $f = $read($out . '/features.json');
$barsFile = $root . '/var/reports/alpaca_stops_data_20260909/sip_all.json';
if (hash_file('sha256', $barsFile) !== $p['input_sha256']['bars'] || hash_file('sha256', $out . '/features.json') !== $p['features_sha256']) { throw new RuntimeException('Input changed.'); }
$bars = D::indexed(D::decode($read($barsFile))); $dates = array_keys($bars['SPY']);
$u = $read($root . '/var/reports/independent_data_20260908/protocol.json')['original_universe'];
$rank = static function (array $v): array {
    asort($v, SORT_NUMERIC); $keys = array_keys($v); $answer = [];
    for ($i = 0; $i < count($keys);) {
        $j = $i; while ($j + 1 < count($keys) && $v[$keys[$j + 1]] === $v[$keys[$i]]) { $j++; }
        for ($k = $i; $k <= $j; $k++) { $answer[$keys[$k]] = ($i + $j) / 2.0; }
        $i = $j + 1;
    }
    return $answer;
};
$ic = static function ($x, $y) use ($rank): ?float {
    $x = $rank($x); $y = $rank($y); $mx = array_sum($x) / count($x); $my = array_sum($y) / count($y); $s = $sx = $sy = 0.0;
    foreach ($x as $k => $v) { $a = $v - $mx; $b = $y[$k] - $my; $s += $a * $b; $sx += $a * $a; $sy += $b * $b; }
    return $sx * $sy > 1e-20 ? $s / sqrt($sx * $sy) : null;
};
$observations = $coverage = [];
foreach ($dates as $i => $date) {
    if ($date < '2021-01-04' || $date > $p['end']) { continue; }
    $period = $date < '2024-01-01' ? 'train' : ($date < '2026-01-01' ? 'validation' : 'holdout');
    foreach ($u as $s) {
        foreach ([252, 504] as $w) { foreach ([3, 10] as $h) {
            $key = 'conditional_' . $w . '_' . $h; $v = $f[$s][$date][$key] ?? null;
            $coverage[$key][$period]['observations'] = ($coverage[$key][$period]['observations'] ?? 0) + 1;
            $coverage[$key][$period]['fitted'] = ($coverage[$key][$period]['fitted'] ?? 0) + (int) ($v !== null);
            $coverage[$key][$period]['negative'] = ($coverage[$key][$period]['negative'] ?? 0) + (int) ($v !== null && $v < 0);
        } }
    }
    foreach ([3, 10, 20] as $h) {
        $exit = $dates[$i + $h + 1] ?? null; $entry = $dates[$i + 1] ?? null;
        $end = $period === 'train' ? '2023-12-31' : ($period === 'validation' ? '2025-12-31' : $p['end']);
        if ($exit === null || $exit > $end) { continue; }
        foreach (O::FACTORS as $family) { foreach ([20, 60] as $w) {
            $x = $y = [];
            foreach ($u as $s) {
                $v = $f[$s][$date][$family . '_' . $w] ?? null;
                if ($v === null || !($f[$s][$date]['eligible'] ?? false) || !isset($bars[$s][$entry], $bars[$s][$exit])) { continue; }
                $x[$s] = $v; $y[$s] = $bars[$s][$exit]->open / $bars[$s][$entry]->open - 1;
            }
            if (count($x) < 5) { continue; }
            $value = $ic($x, $y); if ($value !== null) { $observations[$family . '_' . $w][$h][$period][] = $value; }
        } }
    }
}
$summary = [];
foreach ($observations as $name => $horizons) { foreach ($horizons as $h => $periods) { foreach ($periods as $period => $values) {
    $summary[$name][$h][$period] = ['mean_spearman_ic' => array_sum($values) / count($values), 'dates' => count($values),
        'positive_fraction' => count(array_filter($values, static fn ($x) => $x > 0)) / count($values)];
} } }
A::write($out . '/factor_audit.json', ['completed_at' => gmdate(DATE_ATOM), 'script_sha256' => hash_file('sha256', __FILE__),
    'protocol_sha256' => hash_file('sha256', $out . '/protocol.json'), 'factor_ic' => $summary, 'model_coverage' => $coverage,
    'limits' => 'Diagnostic only, not another selection step. IC measures standalone ordering among eligible stocks, not incremental portfolio alpha. Overlapping horizon observations are dependent; no IID t-statistics or significance claims. Outcomes crossing period boundaries excluded.']);
echo "Factor IC at 3/10/20-session horizons and matured-model coverage written.\n";
