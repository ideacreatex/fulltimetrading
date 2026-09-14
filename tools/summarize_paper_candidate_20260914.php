#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $dir = $root . '/var/reports/paper_candidate_20260914';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$tests = $read($dir . '/tests_verification.json'); $op = $read($dir . '/operational_final_verification.json');
if ($tests['passed'] !== $tests['total'] || !$tests['operational_identity_unchanged'] || !$op['operational_identity_unchanged']) { throw new RuntimeException('Verification incomplete.'); }
$cases = [];
foreach (['continuous', 'fresh2023'] as $start) {
    foreach (['baseline', 'slippage50', 'slippage100', 'daily_low'] as $scenario) {
        $r = $read($dir . '/' . $start . '_' . $scenario . '.json');
        $cases[$start][$scenario] = ['metrics' => $r['metrics'], 'annual' => $r['annual'], 'qualification' => $r['qualification'],
            'stops_across_sleeves' => count($r['stops']), 'exact_frozen_curve_match' => $r['exact_frozen_curve_match']];
    }
}
$etfs = $read($root . '/var/reports/standard_etfs_20260914/summary.json');
$wick = $read($root . '/var/reports/standard_etfs_20260914/rth_wick_sensitivity/summary.json');
$minutes = $read($dir . '/minutes/results.json'); unset($minutes['rows'], $minutes['snapshots_sha256']);
$identity = $read($dir . '/identity_after_recovery.json');
if (!$identity['runtime_identity']['matches'] || !$identity['runtime_identity']['strategy_matches']) { throw new RuntimeException('Runtime changed.'); }
$code = ['bin/install-hybrid-launchd', 'config/php-paper.d/90-hybrid-memory.ini', 'tools/paper_status_export.php',
    'src/Research/StandardEtfResearch.php', 'tests/standard_etf_research.php', 'tests/hybrid_php_memory_profile.php',
    'tests/paper_status_observation_time.php', 'tools/audit_paper_candidate_20260914.php', 'tools/audit_candidate_minutes_20260914.php',
    'tools/audit_etf_data_20260914.php', 'tools/audit_standard_etf_wicks_20260914.php', 'tools/research_standard_etfs_20260914.php',
    'tools/verify_paper_candidate_20260914.php', 'tools/summarize_paper_candidate_20260914.php'];
$report = ['generated_at' => gmdate(DATE_ATOM), 'candidate_id' => 'maximum_stop12__cost_band_2',
    'deployment' => ['status' => 'existing_paper_runtime_recovered_candidate_not_deployed', 'strategy_changed' => false,
        'runtime_hash_matches_existing_run' => true, 'activation_unchanged' => $identity['run']['activated_at'],
        'paper_repairs' => ['Scoped finite PHP memory limit 128M -> 512M; one existing LaunchAgent bootout/bootstrap.',
            'Status exporter timestamps health after broker/local reads, removing false future-heartbeat alerts.'],
        'candidate_blockers' => ['Original historical qualification fails four gates.',
            'Current executor supports four sleeves; candidate has twelve independent sleeves and additional standing stops/shared controller.',
            'Candidate SIP/all + fractional backtest has not been qualified against operational SIP/split + IEX and whole-share order semantics.',
            'Candidate-specific minute touch audit does not establish native order election/fill/reconciliation parity.'],
        'risk_gates_bypassed' => false, 'manual_orders_submitted' => 0, 'live_enabled' => false],
    'candidate_stress' => $cases, 'candidate_minute_audit' => $minutes, 'standard_etfs' => $etfs, 'wick_sensitivity' => $wick,
    'data_quality' => $read($root . '/var/reports/etf_data_audit_20260914/results.json'),
    'operational' => array_diff_key($op, array_flip(['schema', 'month_report'])),
    'month_report' => $read($dir . '/operational_final/month_report.json'),
    'tests' => ['passed' => $tests['passed'], 'total' => $tests['total'], 'diff_check' => $tests['diff_check']],
    'code_sha256' => array_combine($code, array_map(static fn ($file): string => hash_file('sha256', $root . '/' . $file), $code)),
    'scope' => '108 new standard ETF configurations, not 108 independent strategies. 648 primary standalone replays, 108 historical prefixes, 2592 mixes. Additional 432 RTH-wick sensitivity replays and 2592 mixes. Eight candidate daily stress replays, 67 unique candidate minute stop events. Development reruns excluded.',
    'limits' => ['No independent new holdout; selected history has been examined repeatedly.',
        'Positive hypothetical CAGR does not establish future performance or paper execution readiness.',
        '512M is an operational mitigation, not a streaming rewrite of the frozen snapshot/report code.',
        'The initial development run with incorrect summary threshold keys is quarantined in standard_etfs_20260914_invalid_gate_summary_attempt and excluded.'],
    'sources' => ['https://arxiv.org/abs/2607.19497', 'https://arxiv.org/abs/2607.00883',
        'https://github.com/HKUDS/Vibe-Trading/pull/1431', 'https://github.com/HKUDS/Vibe-Trading/pull/1413',
        'https://github.com/HKUDS/Vibe-Trading/pull/1424', 'https://www.quantconnect.com/docs/v2/writing-algorithms/strategy-library',
        'https://www.aqr.com/Insights/Research/Journal-Article/A-Century-of-Evidence-on-Trend-Following-Investing',
        'https://docs.alpaca.markets/us/docs/paper-trading', 'https://docs.alpaca.markets/us/docs/orders-at-alpaca',
        'https://www.php.net/manual/en/configuration.file.php']];
$path = $root . '/docs/research_results/paper_candidate_20260914.json'; A::write($path, $report);
echo $path, "\n";
