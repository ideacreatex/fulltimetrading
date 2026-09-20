<?php
declare(strict_types=1);

namespace FulltimeTrading\Support;

use FulltimeTrading\Paper\CandidateDataSnapshot as Data;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateRelease as Release;

/** Read-only admission input checks; never commissions, provisions or submits. */
final class WeeklyOpenReleaseEvidence
{
    public const RUN = 'hybrid-v4-bull5-weekly-2026-09-20';
    public const MANIFEST = 'var/releases/candidate-bull5-weekly-v1/manifest.json';
    public const REFERENCE_HASH = '76b2c102b4de98e9351ad78d937d939e173cd734fd4acbd322bf824c2cf4f092';
    public const SCHEDULER = 'src/Trading/TacticalPortfolioNotificationSchedule.php';
    public const SCHEDULER_HASH = '8248761d8d355fb5c27b4eb80986ee90984307d9bbb488ab5db630318cd65a5a';
    public const HELPER = 'src/Support/WeeklyOpenReleaseEvidence.php';

    public static function requiredPrograms(array $prior): array
    {
        $paths = ['tools/verify_staged_bull5_snapshot.php'];
        foreach (array_keys($prior['tests']) as $path) {
            $paths[] = str_ends_with($path, '.php') ? $path : 'tests/' . $path . '.php';
        }
        foreach (['staged_bull5_fault_matrix', 'staged_bull5_maps', 'paper_market_commentary',
            'candidate_opg_auction_boundary', 'candidate_weekly_cli', 'candidate_weekly_open_integration',
            'tactical_weekly_session_boundary', 'tactical_notification_health_guard', 'weekly_open_release_evidence'] as $name) {
            $paths[] = 'tests/' . $name . '.php';
        }
        $paths = array_values(array_unique($paths)); sort($paths, SORT_STRING);
        foreach ($paths as $path) { self::need(preg_match('~^(tests|tools)/[a-z0-9_]+\.php$~D', $path) === 1, 'Invalid test path'); }
        return $paths;
    }

    public static function configuration(array $candidate, array $reference): void
    {
        self::need(($reference['run_id'] ?? null) === 'hybrid-v4-bull5-2026-09-15', 'Wrong predecessor');
        self::need($candidate === array_replace($reference, ['run_id' => self::RUN,
            'predecessor_run_id' => $reference['run_id'], 'release_manifest' => self::MANIFEST]),
            'Only new package identity may differ in candidate configuration');
    }

    public static function inspect(string $root, string $reference, string $receiptPath): array
    {
        self::need(realpath($reference . '/var/staging/weekly-open-repair-20260919') === $root && $reference !== $root,
            'Admission requires the isolated repair worktree');
        foreach (['.env', 'var/db/trading.sqlite', 'var/run/candidate_commission.json'] as $file) {
            self::need(!file_exists($root . '/' . $file), 'Stage must remain uncommissioned and credential-free');
        }
        self::need(preg_match('~^var/reports/weekly_open_regressions/[0-9_]+\.json$~D', $receiptPath) === 1, 'Invalid receipt path');
        $candidate = require $root . '/config/paper_candidate.php';
        $config = require $reference . '/config/paper_candidate.php';
        self::configuration($candidate, $config);
        $manifest = Release::verify($reference, $config);
        self::need($manifest['runtime_hash'] === self::REFERENCE_HASH, 'Reference release changed');
        $prior = Data::read($reference . '/' . $manifest['proof_path']);
        $receipt = Data::read($root . '/' . $receiptPath);
        $files = Release::files($root);
        $paths = self::requiredPrograms($prior);
        self::need(count($paths) >= 60, 'Incomplete reference regression set');
        self::regression($receipt, $files, $manifest, $paths,
            static fn (string $path): string => self::fileHash($root, $path));
        self::need($receipt['snapshot_verification'] === Data::read($root . '/var/reports/staging/snapshot_verification.json'),
            'Snapshot receipt drift');

        // The expected snapshot is independently bound to the admitted parent, not to a new self-signed fixture.
        $binding = 'docs/HYBRID_V4_BULL_EXECUTION_2026-09-15.json';
        self::need(self::fileHash($reference, $binding) === ($prior['evidence_sha256'][$binding] ?? null), 'Parent snapshot binding changed');
        $bound = Data::read($reference . '/' . $binding);
        $expectedPath = 'var/reports/candidate_bull_snapshot_20260915/bull_v110_ma50_boost105.json';
        $expectedHash = $bound['snapshot_binding']['receipts']['bull_v110_ma50_boost105']['artifact_sha256'];
        self::need(self::fileHash($reference, $expectedPath) === $expectedHash, 'Parent independent snapshot changed');
        $expected = Data::read($reference . '/' . $expectedPath); $inputs = [];
        foreach ($expected['source_files'] as $id => $path) {
            self::need(preg_match('~^var/reports/candidate_(execution|external)_data_20260914/[a-z0-9_]+\.json$~D', $path) === 1,
                'Unexpected historical input path');
            $inputs[$path] = $expected['source_sha256'][$id];
            self::need(self::fileHash($reference, $path) === $inputs[$path], 'Reference historical input changed');
        }
        $inputs['var/reports/staging/expected_snapshot.json'] = $expectedHash;
        self::need($inputs === $receipt['stage_input_inventory']['input_sha256'], 'Independent input inventory differs');
        self::need($receipt['stage_input_inventory']['snapshot_receipt_sha256'] === self::fileHash($reference, $binding),
            'Independent receipt hash differs');
        return ['results' => $receipt['results'], 'snapshot' => $receipt['snapshot_verification'],
            'receipt_path' => $receiptPath, 'receipt_sha256' => self::fileHash($root, $receiptPath),
            'helper_sha256' => self::fileHash($root, self::HELPER),
            'reference_proof_sha256' => $manifest['proof_sha256'],
            'runtime_delta' => array_keys(array_diff_assoc($files, $manifest['files']))];
    }

    /** Pure contract plus injected read-only file hashes, also used by corruption tests. */
    public static function regression(array $p, array $files, array $reference, array $required, callable $hash): void
    {
        self::need(($p['schema'] ?? null) === 'weekly-open-isolated-regressions-v1', 'Wrong regression schema');
        foreach (['passed', 'complete', 'reference_runtime_unchanged', 'tested_runtime_unchanged', 'support_files_unchanged',
            'stage_inputs_unchanged', 'isolation_verified', 'credential_environment_stripped', 'network_transport_disabled'] as $key) {
            self::need(($p[$key] ?? null) === true, 'Missing verification: ' . $key);
        }
        foreach (['release_admission_proof', 'release_manifest_published', 'deployed', 'operational_database_modified'] as $key) {
            self::need(($p[$key] ?? null) === false, 'Unexpected regression side effect: ' . $key);
        }
        self::need(($p['real_orders_submitted'] ?? null) === 0 && ($p['failed_programs'] ?? null) === []
            && ($p['deferred_programs'] ?? null) === [], 'Incomplete or mutating regression');
        $runtime = hash('sha256', Order::json($files));
        self::need(($p['runtime_hash'] ?? null) === $runtime && ($p['runtime_files'] ?? null) === $files, 'Untested runtime');
        self::need(($p['reference_runtime_hash'] ?? null) === self::REFERENCE_HASH
            && $reference['runtime_hash'] === self::REFERENCE_HASH
            && ($p['reference_proof_sha256'] ?? null) === $reference['proof_sha256'], 'Wrong reference proof');
        self::need(array_keys($files) === array_keys($reference['files']), 'Runtime file set changed');
        $delta = array_keys(array_diff_assoc($files, $reference['files'])); sort($delta, SORT_STRING);
        self::need($delta === ['config/paper_candidate.php', self::SCHEDULER, 'tools/verify_staged_bull5_release.php']
            && $files[self::SCHEDULER] === self::SCHEDULER_HASH, 'Unreviewed runtime change');
        $tests = array_keys($p['results'] ?? []); sort($tests, SORT_STRING); sort($required, SORT_STRING);
        self::need($tests === $required && ($p['programs'] ?? null) === count($required), 'Required program missing or substituted');
        foreach ($p['results'] as $path => $result) {
            self::need(preg_match('~^(tests|tools)/[a-z0-9_]+\.php$~D', $path) === 1, 'Unsafe test path');
            self::need(($result['exit_code'] ?? null) === 0 && ($result['sha256'] ?? null) === $hash($path), 'Test source/result drift: ' . $path);
        }
        $support = $p['support_files'] ?? [];
        $supportPaths = array_keys($support); sort($supportPaths, SORT_STRING);
        self::need($supportPaths === [self::HELPER, 'tests/fixtures/candidate_weekly_cli_runtime.php'], 'Support source inventory incomplete');
        foreach ($support as $path => $sha) { self::need($sha === $hash($path), 'Support source drift'); }
        self::need(($p['runner_sha256'] ?? null) === $hash('tools/verify_weekly_open_regressions.php'), 'Regression runner drift');
        $inventory = $p['stage_input_inventory'] ?? [];
        self::need(($inventory['runtime_files'] ?? null) === $files && ($inventory['runtime_hash'] ?? null) === $runtime
            && ($inventory['reference_runtime_hash'] ?? null) === self::REFERENCE_HASH
            && ($inventory['credentials_copied'] ?? null) === false && ($inventory['release_admitted'] ?? null) === false,
            'Wrong isolated input inventory');
        self::need(count($inventory['input_sha256'] ?? []) === 9, 'Incomplete real snapshot inputs');
        foreach ($inventory['input_sha256'] as $path => $sha) {
            self::need(preg_match('~^var/reports/(candidate_(execution|external)_data_20260914|staging)/[a-z0-9_]+\.json$~D', $path) === 1,
                'Unsafe input path');
            self::need($sha === $hash($path), 'Staged input drift');
        }
        $snapshot = $p['snapshot_verification'] ?? [];
        self::need(($snapshot['passed'] ?? null) === true && ($snapshot['runtime_hash'] ?? null) === $runtime
            && ($snapshot['orders_submitted'] ?? null) === 0 && ($snapshot['release_admitted'] ?? null) === false
            && ($snapshot['peak_memory_bytes'] ?? 0) > 0 && $snapshot['peak_memory_bytes'] <= 512 * 1024 * 1024
            && ($snapshot['signal_sha256'] ?? null) === $hash('var/reports/staging/signal.json'), 'Snapshot verification drift');
    }

    private static function fileHash(string $root, string $path): string
    {
        $real = realpath($root . '/' . $path);
        self::need($real !== false && str_starts_with($real, $root . '/') && is_file($real), 'Missing or escaped evidence file');
        return hash_file('sha256', $real);
    }

    private static function need(bool $ok, string $why): void
    {
        if (!$ok) { throw new \RuntimeException($why); }
    }
}
