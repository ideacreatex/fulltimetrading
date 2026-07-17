<?php

declare(strict_types=1);

use FulltimeTrading\Trading\TacticalImplementationIdentity;
use FulltimeTrading\Trading\TacticalSignalArtifactGuard;

require dirname(__DIR__) . '/bootstrap.php';

function artifactGuardExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string,mixed> $artifact */
function artifactGuardExpectRejected(array $artifact, array $profile, array $paper, array $implementation, string $message): void
{
    try {
        TacticalSignalArtifactGuard::validateArtifact($artifact, $profile, $paper, $implementation);
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @param array<string,mixed> $artifact @return array<string,mixed> */
function artifactGuardRefreshDecisionHash(array $artifact): array
{
    $artifact['decision_sha256'] = TacticalSignalArtifactGuard::decisionSha256($artifact);

    return $artifact;
}

/** @param array<string,mixed> $profile @return list<string> */
function artifactGuardProfileSymbols(array $profile): array
{
    $symbols = [];
    foreach ((array) $profile['sleeves'] as $definition) {
        $config = array_replace($profile, (array) ($definition['config'] ?? []));
        $symbols = array_merge(
            $symbols,
            [(string) $config['benchmark']],
            [(string) $config['market_context']['symbol']],
            [(string) $config['signal_market_filter']['symbol']],
            (array) $config['universe'],
        );
    }
    $symbols = array_values(array_unique(array_map('strtoupper', $symbols)));
    sort($symbols, SORT_STRING);

    return $symbols;
}

/** @return array<string,mixed> */
function artifactGuardProvenance(array $symbols, array $dataContract, string $signalDate): array
{
    $coverage = [];
    foreach ($symbols as $symbol) {
        $coverage[$symbol] = [
            'bars' => 1,
            'first_session' => $signalDate,
            'last_session' => $signalDate,
        ];
    }
    $auditContract = (array) $dataContract['cross_feed_audit'];
    $auditSessions = ['2026-07-09', '2026-07-10', '2026-07-13', '2026-07-14', '2026-07-15'];

    return [
        'schema' => 1,
        'mode' => 'frozen_alpaca_sip_plus_completed_alpaca_iex',
        'request' => [
            'symbols' => $symbols,
            'timeframe' => '1Day',
            'start' => '2020-01-01',
            'end' => $signalDate,
        ],
        'boundary' => [
            'frozen_sip_cutoff_inclusive' => $dataContract['historical_cutoff'],
            'recent_iex_start_inclusive' => '2026-07-16',
            'overlap_policy' => $dataContract['overlap_policy'],
            'overlap_sessions' => $dataContract['overlap_sessions'],
        ],
        'cross_feed_audit' => [
            'mode' => 'audit_only_cutoff_overlap_v1',
            'enabled' => true,
            'used' => true,
            'passed' => true,
            'role' => 'audit_only_not_decision_data',
            'decision_data_usage' => 'none',
            'used_for_merged_bars' => false,
            'contract' => $auditContract,
            'feeds' => ['reference' => 'sip', 'candidate' => 'iex'],
            'cache_namespace' => $dataContract['fresh_cache_namespace'],
            'request' => [
                'symbols' => $symbols,
                'timeframe' => '1Day',
                'start' => $auditSessions[0],
                'end' => $dataContract['historical_cutoff'],
            ],
            'window' => [
                'start' => $auditSessions[0],
                'end' => $dataContract['historical_cutoff'],
                'sessions' => $auditSessions,
            ],
            'compared_symbols' => $symbols,
            'compared_sessions' => 5,
            'compared_bars' => 5 * count($symbols),
            'violations' => 0,
            'observed' => [
                'maximum_price_deviation_bps' => [
                    'open' => 90.0,
                    'high' => 70.0,
                    'low' => 80.0,
                    'close' => 15.0,
                ],
                'minimum_iex_to_sip_volume_ratio' => 0.01,
                'maximum_iex_to_sip_volume_ratio' => 0.07,
            ],
            'canonical_sha256' => [
                'frozen_sip' => str_repeat('d', 64),
                'audit_iex' => str_repeat('e', 64),
            ],
        ],
        'segments' => [
            'frozen_sip' => [
                'feed' => 'sip',
                'expected_sha256' => $dataContract['historical_snapshot_sha256'],
                'sha256' => $dataContract['historical_snapshot_sha256'],
                'namespace' => $dataContract['cache_namespace'],
                'snapshot_canonical_sha256' => str_repeat('a', 64),
            ],
            'recent_iex' => [
                'feed' => 'iex',
                'namespace' => $dataContract['fresh_cache_namespace'],
                'used' => true,
                'request' => ['start' => '2026-07-16', 'end' => $signalDate],
                'coverage' => $coverage,
                'canonical_sha256' => str_repeat('b', 64),
            ],
        ],
        'merged' => [
            'effective_completed_session' => $signalDate,
            'canonical_sha256' => str_repeat('c', 64),
        ],
    ];
}

/** @return array<string,mixed> */
function artifactGuardFixture(string $root, array $profile, array $paper): array
{
    $signalDate = '2026-07-16';
    $targets = [];
    $contexts = [];
    foreach ($profile['sleeves'] as $sleeveId => $definition) {
        $config = array_replace($profile, (array) ($definition['config'] ?? []));
        $ranked = (string) $config['universe'][0];
        $targets[$sleeveId] = [
            'signal_date' => $signalDate,
            'execution' => 'next_session_open',
            'action' => 'hold',
            'rebalance_due_next_session' => false,
            'current_symbol' => null,
            'current_gross' => 0.0,
            'symbol' => null,
            'gross' => 0.0,
            'ranked_symbol' => $ranked,
            'ranked_gross' => 0.50,
            'circuit_cooldown_left' => 0,
            'cooldown_after_next_open_tick' => 0,
            'risk_exit_pending' => false,
            'drawdown_rearm_pending' => false,
            'shadow_only' => true,
            'allocation' => (float) $definition['allocation'],
            'initial_equity' => 30_000.0 * (float) $definition['allocation'],
            'capital_scope' => 'independent_static_sleeve',
            'sizing_reference_close' => null,
            'sizing_reference_session' => null,
        ];
        $contexts[$sleeveId] = [
            'status' => 'hold_no_trade',
            'order_eligible' => false,
            'no_chase' => true,
        ];
    }

    return artifactGuardRefreshDecisionHash([
        'schema' => 1,
        'generated_at' => '2026-07-17T02:00:00+00:00',
        'profile' => $profile['profile'],
        'causal_contract' => 'completed close D ranks symbols; target can execute only at open D+1',
        'as_of' => $signalDate,
        'intended_session' => '2026-07-17',
        'implementation' => TacticalImplementationIdentity::current($root, $profile),
        'targets' => $targets,
        'execution_contexts' => $contexts,
        'validation_selected' => true,
        'production_approved' => false,
        'paper_shadow_enabled' => true,
        'order_submission_enabled' => false,
        'order_submission_block_reason' => $profile['order_submission_block_reason'],
        'data_provenance' => artifactGuardProvenance(
            artifactGuardProfileSymbols($profile),
            (array) $paper['data'],
            $signalDate,
        ),
    ]);
}

$root = dirname(__DIR__);
$profile = require $root . '/config/tactical_rotation.php';
$paper = require $root . '/config/tactical_paper.php';
$implementation = TacticalImplementationIdentity::current($root, $profile);
$artifact = artifactGuardFixture($root, $profile, $paper);
TacticalSignalArtifactGuard::validateArtifact($artifact, $profile, $paper, $implementation);

$mutations = [];
$mutations['validation'] = static function (array $value): array {
    $value['validation_selected'] = false;
    return $value;
};
$mutations['provenance'] = static function (array $value): array {
    $value['data_provenance']['segments']['frozen_sip']['sha256'] = str_repeat('0', 64);
    return $value;
};
$mutations['target_universe'] = static function (array $value): array {
    $value['targets']['qqq150_ex_crypto']['ranked_symbol'] = 'MSTR';
    return $value;
};
$mutations['implementation_hash'] = static function (array $value): array {
    $value['implementation']['combined_sha256'] = str_repeat('0', 64);
    return $value;
};
$mutations['gross'] = static function (array $value): array {
    $value['targets']['dynamic_loo10']['gross'] = 1.21;
    return $value;
};
$mutations['current_gross'] = static function (array $value): array {
    $value['targets']['dynamic_loo10']['current_symbol'] = 'PANW';
    $value['targets']['dynamic_loo10']['current_gross'] = 1.21;
    return $value;
};
$mutations['action_due'] = static function (array $value): array {
    $value['targets']['dynamic_loo10']['rebalance_due_next_session'] = true;
    return $value;
};
$mutations['missing_sizing'] = static function (array $value): array {
    $value['targets']['dynamic_loo10']['rebalance_due_next_session'] = true;
    $value['targets']['dynamic_loo10']['action'] = 'rebalance';
    $value['targets']['dynamic_loo10']['symbol'] = 'AAPL';
    $value['targets']['dynamic_loo10']['gross'] = 0.50;
    return $value;
};
$mutations['sleeve_order'] = static function (array $value): array {
    $targets = $value['targets'];
    $value['targets'] = [
        'qqq200_full' => $targets['qqq200_full'],
        'dynamic_loo10' => $targets['dynamic_loo10'],
        'spy200_full' => $targets['spy200_full'],
        'qqq150_ex_crypto' => $targets['qqq150_ex_crypto'],
    ];
    return $value;
};
$validPanwTarget = $artifact;
$validPanwTarget['targets']['dynamic_loo10'] = array_replace(
    $validPanwTarget['targets']['dynamic_loo10'],
    [
        'action' => 'rebalance',
        'rebalance_due_next_session' => true,
        'current_symbol' => 'AAPL',
        'current_gross' => 0.50,
        'symbol' => 'PANW',
        'gross' => 0.50,
        'ranked_symbol' => 'PANW',
        'ranked_gross' => 0.50,
        'sizing_reference_close' => 100.0,
        'sizing_reference_session' => '2026-07-16',
    ],
);
$validPanwTarget = artifactGuardRefreshDecisionHash($validPanwTarget);
TacticalSignalArtifactGuard::validateArtifact($validPanwTarget, $profile, $paper, $implementation);
$mutations['valid_target_old_hash'] = static function (array $ignored) use ($validPanwTarget): array {
    // Every changed field remains individually valid. Only the generator-bound
    // decision hash proves that PANW, rather than AAPL, was the emitted target.
    $value = $validPanwTarget;
    $value['targets']['dynamic_loo10']['action'] = 'resize_or_hold';
    $value['targets']['dynamic_loo10']['symbol'] = 'AAPL';
    $value['targets']['dynamic_loo10']['ranked_symbol'] = 'AAPL';

    return $value;
};
$validAaplTarget = artifactGuardRefreshDecisionHash($mutations['valid_target_old_hash']($artifact));
TacticalSignalArtifactGuard::validateArtifact($validAaplTarget, $profile, $paper, $implementation);

foreach ($mutations as $name => $mutate) {
    $mutated = $mutate($artifact);
    if ($name !== 'valid_target_old_hash') {
        $mutated = artifactGuardRefreshDecisionHash($mutated);
    }
    artifactGuardExpectRejected(
        $mutated,
        $profile,
        $paper,
        $implementation,
        'Tampered tactical shadow must be rejected: ' . $name,
    );
}

// Exercise the exact installer dry-run entrypoint. Validation occurs before
// repository activation or the broker snapshot, so a non-flat transition can
// never make any of these corrupt artifacts pass preflight.
$temp = sys_get_temp_dir() . '/ftt-artifact-guard-' . bin2hex(random_bytes(6));
if (!mkdir($temp, 0775, true) && !is_dir($temp)) {
    throw new RuntimeException('Unable to create artifact-guard test directory.');
}
try {
    foreach (['validation', 'provenance', 'target_universe', 'implementation_hash', 'valid_target_old_hash'] as $name) {
        $path = $temp . '/' . $name . '.json';
        file_put_contents($path, json_encode(
            $name === 'valid_target_old_hash'
                ? $mutations[$name]($artifact)
                : artifactGuardRefreshDecisionHash($mutations[$name]($artifact)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        $db = $temp . '/' . $name . '.sqlite';
        $parts = [
            PHP_BINARY,
            $root . '/bin/trade',
            'tactical-paper-executor',
            '--submit=false',
            '--telegram=false',
            '--db=' . $db,
            '--artifact=' . $path,
            '--output=' . $temp . '/' . $name . '-cycle.json',
            '--lock=' . $temp . '/' . $name . '.lock',
        ];
        $command = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        artifactGuardExpect($exitCode !== 0, 'Installer-style dry-run must reject tampered ' . $name . '.');
        artifactGuardExpect(
            str_contains(implode("\n", $output), 'Signal artifact'),
            'Dry-run must fail at artifact validation before broker/transition logic for ' . $name . '.',
        );
        // bin/trade constructs the legacy repository before dispatching the
        // subcommand, so the SQLite file itself may exist. The tactical
        // repository migration must not have run: that is the boundary which
        // proves corrupt input cannot create or transition tactical state.
        if (is_file($db)) {
            $pdo = new PDO('sqlite:' . $db);
            $statement = $pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'tactical_paper_run'",
            );
            $statement->execute();
            artifactGuardExpect(
                (int) $statement->fetchColumn() === 0,
                'Invalid artifact must fail before creating tactical state for ' . $name . '.',
            );
        }
    }
} finally {
    foreach (glob($temp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($temp);
}

echo "tactical signal artifact guard tests passed\n";
