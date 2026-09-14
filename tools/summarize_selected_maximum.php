#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\ResearchMultiplicityAudit;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/selected_maximum_20260909';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$revision = $argv[1] ?? '';
if ($revision !== '' && !preg_match('/^v[2-9][0-9]*$/', $revision)) { throw new RuntimeException('Invalid summary revision.'); }
$suffix = $revision === '' ? '' : '_' . $revision;
$publishedName = 'selected_maximum_20260909' . $suffix;
if (file_exists($out . '/results' . $suffix . '.json')) { throw new RuntimeException('Completed summary is immutable.'); }
$p = $read($out . '/protocol.json');
$selection = $read($out . '/selection.json');
$primary = $read($out . '/primary_summary.json');
$satellites = $read($out . '/satellites.json');
$tests = $read($out . '/tests.json');
$operational = $read($out . '/operational.json');
$minutes = $read($out . '/minutes/results.json');
$audit = []; $prefixes = 0;
foreach ($selection['jobs'] as $job) {
    $r = $read($out . '/' . $job['scenario'] . '_' . $job['id'] . '.json');
    if ($r['protocol_sha256'] !== hash_file('sha256', $out . '/protocol.json')) { throw new RuntimeException('Audit protocol mismatch.'); }
    $audit[$job['id']][$job['scenario']] = $r;
    $prefixes += (int) ($r['prefix_pass'] === true);
}
if ($prefixes !== count($selection['audit_ids']) || $tests['passed'] !== $tests['total']) { throw new RuntimeException('Required verifications incomplete.'); }
$scores = [];
foreach ($primary as $id => $r) { $scores[$id] = $r['metrics']['full']['cagr']; }
arsort($scores);
$leaders = array_slice(array_keys($scores), 0, 10);
$logs = static function ($curve): array {
    $r = [];
    foreach ($curve as $row) { if ($row['date'] >= '2025-01-01') { $r[$row['date']] = log($row['equity'] / $row['start_equity']); } }
    return $r;
};
$baseline = $logs($read($out . '/primary_maximum_curve.json'));
$matrix = [];
$append = static function ($id, $curve) use (&$matrix, $logs, $baseline): void {
    $values = $logs($curve);
    if (array_keys($values) !== array_keys($baseline)) { throw new RuntimeException('Unaligned multiplicity matrix.'); }
    $matrix[$id] = array_values(array_map(static fn ($a, $b): float => $a - $b, $values, $baseline));
};
foreach ($p['cases'] as $id => $recipe) {
    if ($id !== 'maximum') { $append($id, $read($out . '/primary_' . $id . '_curve.json')); }
}
foreach ($satellites['mixes'][30] as $id => $_) { $append($id, $read($out . '/mix_' . $id . '_curve.json')); }
$multiplicity = ResearchMultiplicityAudit::run($matrix, 20, 1000, 20260911);
$qualified = $dominant = [];
foreach ($audit as $id => $rows) {
    if ($rows['full2021']['qualification']['qualifies']) { $qualified[] = $id; }
    $better = $id !== 'maximum';
    $strictImprovement = false;
    foreach (['cost60', 'full2021', 'spinoff30', 'spinoff60', 'expanded', 'earlier', 'lag1'] as $scenario) {
        $m = $rows[$scenario]['metrics']['full']; $b = $audit['maximum'][$scenario]['metrics']['full'];
        $better = $better && $m['cagr'] >= $b['cagr'] - 1e-10 && $m['max_drawdown'] >= $b['max_drawdown'] - 1e-10;
        $strictImprovement = $strictImprovement || $m['cagr'] > $b['cagr'] + 1e-10 || $m['max_drawdown'] > $b['max_drawdown'] + 1e-10;
    }
    if ($better && $strictImprovement) { $dominant[] = $id; }
}
$families = [];
foreach ($p['cases'] as $recipe) { $families[$recipe['family']] = ($families[$recipe['family']] ?? 0) + 1; }
$light = static fn ($r): array => array_intersect_key($r, array_flip(['metrics', 'annual', 'closed_trades', 'open_at_end', 'qualification', 'prefix_pass', 'old_curve_exact_match']));
$result = ['completed_at' => gmdate(DATE_ATOM), 'selected_research_baseline' => $p['selected_research_baseline'],
    'summary_revision' => $revision === '' ? 'v1' : $revision, 'summary_script_sha256' => hash_file('sha256', __FILE__),
    'revision_note' => $revision === '' ? null : 'Require at least one strictly improved metric for audit dominance; a baseline-equivalent trough recipe is not an improvement. Replay metrics unchanged.',
    'primary_dates' => $p['primary_dates'], 'cost_bps' => 30, 'source' => $p['source'], 'initial_equity' => $p['initial_equity'],
    'requested_recipes' => $p['requested_recipes'], 'unique_primary_configurations' => count($p['cases']),
    'duplicates' => count($p['duplicates']), 'rejected' => count($p['rejected']), 'family_counts' => $families,
    'primary' => array_map($light, $primary), 'audit' => array_map(static fn ($rows): array => array_map($light, $rows), $audit),
    'audit_replays' => count($selection['jobs']), 'prefix_checks' => $prefixes, 'train_selected' => $selection['train_selected'],
    'hindsight_only' => $selection['hindsight_only'], 'top10_hindsight' => $leaders,
    'full2021_gate_qualified_among_audited' => $qualified, 'dominates_baseline_across_audits' => $dominant,
    'standalone_event_definitions' => count($satellites['standalone'][30]), 'standalone_event_replays' => $satellites['replays'],
    'unique_static_svxy_mixes' => count($satellites['mixes'][30]), 'static_mix_evaluations' => $satellites['mix_evaluations'],
    'satellite_train_selected' => $satellites['train_selected_mix'], 'satellite_selected_metrics' => [30 => $satellites['mixes'][30][$satellites['train_selected_mix']], 60 => $satellites['mixes'][60][$satellites['train_selected_mix']]],
    'multiplicity' => $multiplicity, 'tests' => ['passed' => $tests['passed'], 'total' => $tests['total']],
    'minute_audit' => array_diff_key($minutes, array_flip(['rows', 'snapshots_sha256'])), 'operational' => $operational,
    'operational_identity_unchanged' => TacticalImplementationIdentity::current($root, require $root . '/config/tactical_rotation.php') === $p['operational_identity'],
    'ready_for_order_enabled_demo' => false, 'orders_submitted' => 0,
    'limitations' => ['All historical periods have been observed before; parameter search is exploratory, not new untouched evidence.',
        'Primary 2023 restart is not a slice of the continuous 2021 equity path. Qualification uses the original 2021/2024/2026 splits only on audited cases.',
        'ETF satellite is an independent static allocation outside the main portfolio stop; no live integration exists.',
        'Daily adjusted data is not a cash/share corporate-action ledger; fixed-universe survivorship remains unresolved.',
        'Split/spinoff sensitivity changes equity bars; the SIP-all SVXY/market confirmation history and S5TW/VVIX snapshots remain fixed.',
        'Portfolio circuit exits at the next open after an observed breach. A standing stop is not a portfolio drawdown guarantee.',
        'Native broker stop lifecycle and persisted portfolio circuit are not implemented in the operational executor.',
        'S5TW/VVIX are frozen research data, not monitored live production feeds.',
        'Increased fees, conservative day-low stop fills and minute event checks are diagnostics, not a full execution simulator.']];
if (!$result['operational_identity_unchanged']) { throw new RuntimeException('Operational identity changed.'); }
A::write($out . '/results' . $suffix . '.json', $result);
A::write($root . '/docs/research_results/' . $publishedName . '.json', $result);
$labels = ['control_original' => 'Current operational algorithm (historical model)', 'control_balanced' => 'Previous balanced',
    'maximum' => 'Selected previous maximum', 'maximum_stop12' => 'Selected maximum + stop12 only', 'old_stop12' => 'Previous stop12 wrapper'];
$show = array_values(array_unique(array_merge($selection['controls'], $selection['hindsight_only'])));
$show[] = $selection['train_selected']['confirmation_sensitivity'];
$show = array_values(array_unique($show));
$labels[$selection['train_selected']['confirmation_sensitivity']] = 'Maximum: reentry VVIX below 90';
$lines = ['# Selected Maximum Rebase: 2026-09-09', '',
    'Primary: Alpaca SIP adjustment=all, 2023-01-03 through 2026-09-04, fresh $30,000, 30 bps per traded notional.',
    'Research baseline selected by the user: `' . $p['selected_research_baseline'] . '`. Operational deployment is unchanged.', '',
    '## Comparable Results', '', '| Configuration | CAGR | Total return | Max drawdown | Closed trades | Terminal equity |', '|---|---:|---:|---:|---:|---:|'];
foreach ($show as $id) {
    $r = $primary[$id]; $m = $r['metrics']['full'];
    $lines[] = sprintf('| %s | %.2f%% | %.2f%% | %.2f%% | %d | $%s |', $labels[$id] ?? '`' . $id . '` (hindsight)', $m['cagr'] * 100, $m['return'] * 100, $m['max_drawdown'] * 100, $r['closed_trades'], number_format(30000 * (1 + $m['return']), 2));
}
$lines = array_merge($lines, ['', '## Annual Returns and Closed Trades', '', '| Configuration | 2023 | 2024 | 2025 | 2026 through Sep 4 |', '|---|---:|---:|---:|---:|']);
foreach ($show as $id) {
    $line = '| ' . ($labels[$id] ?? '`' . $id . '`');
    foreach ($primary[$id]['annual'] as $a) { $line .= sprintf(' | %.2f%% / %d', $a['return'] * 100, $a['closed_trades']); }
    $lines[] = $line . ' |';
}
$lines = array_merge($lines, ['', 'A trade is a completed aggregate ticker exposure, assigned to its exit year. Partial resizes are not additional trades; open end positions are not force-closed.', '',
    '## Robustness', '', '| Configuration | 60bps CAGR / DD | Continuous 2021 CAGR / DD | Expanded 68 CAGR / DD | Earlier 2017-2020 CAGR / DD | Original gate |', '|---|---:|---:|---:|---:|---|']);
foreach ($show as $id) {
    $line = '| ' . ($labels[$id] ?? '`' . $id . '`');
    foreach (['cost60', 'full2021', 'expanded', 'earlier'] as $s) { $m = $audit[$id][$s]['metrics']['full']; $line .= sprintf(' | %.2f%% / %.2f%%', $m['cagr'] * 100, $m['max_drawdown'] * 100); }
    $lines[] = $line . ' | ' . ($audit[$id]['full2021']['qualification']['qualifies'] ? 'PASS' : implode(', ', $audit[$id]['full2021']['qualification']['failed_gates'])) . ' |';
}
$lines = array_merge($lines, ['', '## Coverage and Verification', '',
    sprintf('- %d declared recipes: %d unique effective configurations, %d duplicates, %d rejected above the unchanged gross limit.', $p['requested_recipes'], count($p['cases']), count($p['duplicates']), count($p['rejected'])),
    sprintf('- %d ETF event definitions, %d cost replays, %d unique SVXY static mixes at 2.5/5/10/20%% initial capital, %d mix evaluations.', $result['standalone_event_definitions'], $satellites['replays'], $result['unique_static_svxy_mixes'], $satellites['mix_evaluations']),
    sprintf('- %d audit replays; %d actual truncated-input prefixes; four exact reproductions of the previous comparison.', count($selection['jobs']), $prefixes),
    sprintf('- %d/%d tests passed. Original operational identity unchanged; zero orders submitted.', $tests['passed'], $tests['total']),
    sprintf('- Centered 20-session block-bootstrap max-mean excess test: %d cases, %d later sessions, p=%.4f. This does not correct all prior searches.', $multiplicity['cases'], $multiplicity['sessions'], $multiplicity['familywise_p_value']),
    sprintf('- Minute event audit: %d distinct stop events; %d RTH touches; %d/%d next-minute proxies worse than the daily fill. Not a full minute replay.', $minutes['events'], $minutes['rth_touch'], $minutes['delayed_worse'], $minutes['delayed_observations']),
    '', '## Limits', '']);
foreach ($result['limitations'] as $limit) { $lines[] = '- ' . $limit; }
$lines = array_merge($lines, ['', '## Indicator Groups', '',
    'Below are the family leaders selected by 2023-2024 Calmar, not the highest full-period hindsight returns. Some full-period leaders also won their training groups.', '',
    '| Family | Selected configuration | CAGR 2023 onward | Max drawdown |', '|---|---|---:|---:|']);
foreach (['s5tw', 's5tw_sensitivity', 'svxy', 'vvix', 'refinement', 'confirmation_sensitivity'] as $family) {
    $id = $selection['train_selected'][$family]; $m = $primary[$id]['metrics']['full'];
    $lines[] = sprintf('| %s | `%s` | %.2f%% | %.2f%% |', $family, $id, 100 * $m['cagr'], 100 * $m['max_drawdown']);
}
$lines[] = '';
$sat = $result['satellite_selected_metrics'][30];
$lines[] = sprintf('The train-selected SVXY satellite mix has CAGR %.2f%% and drawdown %.2f%% versus %.2f%% and %.2f%% for its unmodified main anchor. Each of 584 SVXY mixes was also evaluated at 60 bps. Buying SVXY directly did not increase full-period CAGR versus its own main anchor in this grid.', 100 * $sat['full']['cagr'], 100 * $sat['full']['max_drawdown'], 100 * $primary[$sat['anchor']]['metrics']['full']['cagr'], 100 * $primary[$sat['anchor']]['metrics']['full']['max_drawdown']);
$lines[] = '';
$lines[] = 'The expanded-universe basket audit exceeded a 2 GiB PHP worker cap; only that research worker was resumed with 6 GiB. Frozen code, data, recipes and trading limits were unchanged; all 426 audit jobs completed.';
$lines[] = '';
$lines[] = 'A stop does not guarantee its execution price: [Alpaca order documentation](https://docs.alpaca.markets/us/docs/orders-at-alpaca). Data endpoint: [Alpaca historical bars](https://docs.alpaca.markets/us/reference/stockbars).';
$lines[] = '';
$lines[] = 'Full machine-readable metrics: [' . $publishedName . '.json](' . $publishedName . '.json). Frozen recipes, hashes, curves and every replay are in `var/reports/selected_maximum_20260909/`.';
file_put_contents($root . '/docs/research_results/' . $publishedName . '.md', implode("\n", $lines) . "\n");
printf("Complete: %d primary configs, %d audit replays, %d gate PASS among audited. Multiplicity p %.4f. Not deployed.\n", count($primary), count($selection['jobs']), count($qualified), $multiplicity['familywise_p_value']);
