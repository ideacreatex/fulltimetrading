<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

final class CandidateRelease
{
    public static function files(string $root): array
    {
        $files = ['bootstrap.php', 'config/config.php', 'config/paper_candidate.php', 'config/tactical_rotation.php',
            'bin/trade', 'bin/install-hybrid-launchd', 'tools/candidate_paper_cycle.php', 'tools/candidate_paper_daemon.php',
            'tools/prepare_candidate_signal.php', 'tools/fetch_candidate_execution_data.php', 'tools/fetch_candidate_external_data.php',
            'tools/verify_candidate_release.php', 'src/Storage/SqliteRepository.php', 'src/Data/MarketDataProvider.php',
            'src/Storage/TacticalPaperRepository.php', 'src/Trading/AlpacaPaperClient.php', 'src/Trading/AlpacaPaperAccountGuard.php',
            'src/Trading/TacticalOrderGateway.php', 'src/Trading/TacticalRotationExecutionWindow.php', 'src/Trading/WholeShareSizing.php',
            'src/Data/HttpClient.php', 'src/Data/AlpacaBarsProvider.php', 'src/Domain/Bar.php', 'src/Indicators/IndicatorCalculator.php',
            'src/Support/Config.php', 'src/Support/EnvLoader.php', 'src/Support/ProcessLock.php', 'src/Notifications/TelegramNotifier.php',
            'src/Backtest/CausalTacticalRotationBacktester.php', 'src/Backtest/CausalTacticalRotationEnsembleBacktester.php'];
        foreach (['AdaptiveResearchFactory', 'AdaptiveRotationBacktester', 'AdaptiveRotationEnsembleBacktester',
            'AlgorithmSignalPolicy', 'AlgorithmTrendResearch', 'AlpacaStopResearchGrid', 'BreadthVolatilityResearch',
            'DailyDataAudit', 'HybridV4Research', 'OpportunityPolicy', 'PortfolioCircuitController', 'SelectedMaximumResearch',
            'PaperExecutionRotationBacktester', 'PaperExecutionRotationEnsembleBacktester'] as $name) {
            $files[] = 'src/Research/' . $name . '.php';
        }
        foreach (glob($root . '/src/Paper/*.php') as $file) { $files[] = substr($file, strlen($root) + 1); }
        $files = array_values(array_unique($files)); sort($files, SORT_STRING); $hashes = [];
        foreach ($files as $file) {
            if (!is_file($root . '/' . $file)) { throw new \RuntimeException('Candidate release file missing: ' . $file); }
            $hashes[$file] = hash_file('sha256', $root . '/' . $file);
        }
        return $hashes;
    }

    public static function assess(array $proof): array
    {
        $failed = [];
        foreach (['tests_passed', 'lint_passed', 'diff_clean', 'frozen_research_unchanged', 'replay_inputs_verified',
            'close_parity_verified', 'minute_audit_verified', 'comparative_benefit_verified', 'snapshot_contract_verified',
            'capital_sensitivity_verified', 'full_command_contract_verified', 'fault_matrix_verified'] as $check) {
            if (($proof[$check] ?? null) !== true) { $failed[] = $check; }
        }
        if (($proof['test_count'] ?? 0) < 49) { $failed[] = 'insufficient_regression_suite'; }
        if (($proof['failures'] ?? null) !== []) { $failed[] = 'verification_exception'; }
        if (($proof['manual_orders_submitted'] ?? null) !== 0 || ($proof['operational_database_modified'] ?? null) !== false) { $failed[] = 'verification_not_isolated'; }
        return ['policy' => 'experimental-capital-or-drawdown-execution-v1', 'paper_admission' => $failed === [],
            'failed_checks' => $failed, 'strict_validation_selected' => false, 'live_approved' => false,
            'fresh_current_signal_still_required' => true, 'independent_holdout' => false];
    }

    public static function hash(string $root): string { return hash('sha256', CandidateOrder::json(self::files($root))); }

    public static function verify(string $root, array $candidate): array
    {
        $m = CandidateDataSnapshot::read($root . '/' . $candidate['release_manifest']);
        if (($m['paper_only'] ?? null) !== true || ($m['live_approved'] ?? null) !== false || ($m['paper_admission'] ?? null) !== true
            || ($m['run_id'] ?? null) !== $candidate['run_id'] || ($m['profile'] ?? null) !== CandidateDefinition::PROFILE
            || ($m['execution_contract'] ?? null) !== CandidateOrder::CONTRACT || ($m['files'] ?? null) !== self::files($root)
            || !hash_equals($m['runtime_hash'] ?? '', self::hash($root))) {
            throw new \RuntimeException('Candidate release admission/identity failed.');
        }
        $proofPath = $root . '/' . ($m['proof_path'] ?? '');
        if (!is_file($proofPath) || !hash_equals($m['proof_sha256'] ?? '', hash_file('sha256', $proofPath))) {
            throw new \RuntimeException('Candidate release proof missing or changed.');
        }
        $proof = CandidateDataSnapshot::read($proofPath);
        if (self::assess($proof)['paper_admission'] !== true || ($proof['runtime_hash'] ?? null) !== $m['runtime_hash']) {
            throw new \RuntimeException('Candidate release proof no longer admits this build.');
        }
        if (($m['capital_reviewed'] ?? null) !== ($proof['capital_reviewed'] ?? null) || !is_numeric($m['capital_reviewed'] ?? null) || $m['capital_reviewed'] <= 0) {
            throw new \RuntimeException('Candidate starting-capital proof invalid.');
        }
        return $m;
    }
}
