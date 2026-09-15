<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDefinition as Definition;
use FulltimeTrading\Paper\CandidateRelease as Release;
use FulltimeTrading\Paper\CandidateSignalArtifact as Artifact;
use FulltimeTrading\Paper\CandidateSession as Session;
use FulltimeTrading\Paper\CandidateOrder as Order;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $fixture = sys_get_temp_dir() . '/candidate-cycle-fixture-' . bin2hex(random_bytes(8)); mkdir($fixture, 0700);
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
try {
    foreach (Release::files($root) as $file => $_) {
        if (!is_dir(dirname($fixture . '/' . $file))) { mkdir(dirname($fixture . '/' . $file), 0700, true); }
        copy($root . '/' . $file, $fixture . '/' . $file);
    }
    $candidate = require $fixture . '/config/paper_candidate.php'; $base = require $fixture . '/config/tactical_rotation.php';
    $candidate['enabled'] = false;
    file_put_contents($fixture . '/config/paper_candidate.php', "<?php\nreturn " . var_export($candidate, true) . ";\n");
    $now = new DateTimeImmutable('now', new DateTimeZone('America/New_York')); $calendar = [];
    for ($i = -15; $i <= 15; ++$i) {
        $day = $now->modify(($i >= 0 ? '+' : '') . $i . ' days');
        if ((int) $day->format('N') <= 5) { $calendar[] = ['date' => $day->format('Y-m-d'), 'open' => '09:30', 'close' => '16:00']; }
    }
    $clock = ['timestamp' => $now->format(DATE_ATOM), 'is_open' => (int) $now->format('N') <= 5 && $now->format('H:i') >= '09:30' && $now->format('H:i') < '16:00'];
    $session = Session::resolve($calendar, $clock, $now); $date = $session['signal_date']; $next = $session['scheduled_session'];
    Artifact::write($fixture . '/broker_fixture.json', ['calendar' => $calendar, 'clock' => $clock]);
    // The substitute exists only in this isolated process. It has no HTTP implementation and cannot send an order.
    file_put_contents($fixture . '/fixture_entry.php', <<<'PHP'
<?php
namespace FulltimeTrading\Trading {
    final class AlpacaPaperClient {
        public function __construct($http, $url) { if ($url !== 'https://paper-api.alpaca.markets/v2') { throw new \RuntimeException('fixture rejects nonpaper host'); } }
        private function fixture(): array { return json_decode(file_get_contents(__DIR__ . '/broker_fixture.json'), true, 512, JSON_THROW_ON_ERROR); }
        public function account(): array { return ['id' => 'fixture-account', 'multiplier' => '2', 'shorting_enabled' => true, 'status' => 'ACTIVE',
            'trading_blocked' => false, 'account_blocked' => false, 'equity' => '30000', 'cash' => '30000', 'buying_power' => '60000']; }
        public function clock(): array { return $this->fixture()['clock']; }
        public function calendar($start, $end): array { return $this->fixture()['calendar']; }
        public function positions(): array { return $this->fixture()['positions'] ?? []; }
        public function openOrders(): array { return []; }
        public function submitOrder($body): never { throw new \RuntimeException('TEST FORBIDS ANY POST'); }
        public function cancelOrder($id): never { throw new \RuntimeException('TEST FORBIDS ANY DELETE'); }
    }
}
namespace {
    putenv('APCA_PAPER_BASE_URL=https://paper-api.alpaca.markets/v2');
    putenv('APCA_PAPER_ACCOUNT_ID=fixture-account'); putenv('APCA_PAPER_EXPECTED_MULTIPLIER=2'); putenv('APCA_PAPER_EXPECTED_SHORTING_ENABLED=true');
    require __DIR__ . '/bin/trade';
}
PHP);
    $books = Definition::books($base, [], [], []); $answers = $prices = [];
    foreach ($books as $name => $book) {
        foreach (['', ...$book['config']['universe']] as $symbol) {
            $answers[$name][$symbol] = ['date' => $date, 'previous_session' => (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d'),
                'reentry_conditions_met' => false, 'desired' => [], 'closes' => [], 'previous_closes' => [], 'volatility' => []];
        }
        foreach ($book['config']['universe'] as $symbol) { $prices[$symbol] = 100.; }
    }
    $provenance = ['date' => $date];
    foreach (['raw', 'split', 's5tw', 'vvix'] as $id) {
        $directory = in_array($id, ['raw', 'split'], true) ? 'candidate_execution_data_' : 'candidate_external_data_';
        $path = $fixture . '/var/reports/' . $directory . str_replace('-', '', $date) . '/' . $id . '.json';
        Artifact::write($path, ['isolated_test_fixture' => true]); $provenance[$id . '_sha256'] = hash_file('sha256', $path);
    }
    $inputs = ['date' => $date, 'books' => $books, 'context_answers' => $answers, 'nominal_closes' => $prices, 'confirmation' => false, 'provenance' => $provenance];
    $hash = Release::hash($fixture); $signal = Artifact::build($inputs, $candidate, $hash, $next);
    Artifact::write($fixture . '/signal.json', $signal);
    $proof = array_fill_keys(['tests_passed', 'lint_passed', 'diff_clean', 'frozen_research_unchanged', 'replay_inputs_verified',
        'close_parity_verified', 'minute_audit_verified', 'comparative_benefit_verified', 'snapshot_contract_verified',
        'capital_sensitivity_verified', 'full_command_contract_verified', 'fault_matrix_verified'], true)
        + ['test_count' => 100, 'manual_orders_submitted' => 0, 'operational_database_modified' => false, 'runtime_hash' => $hash, 'failures' => [], 'capital_reviewed' => 30000.];
    Artifact::write($fixture . '/proof.json', $proof);
    Artifact::write($fixture . '/' . $candidate['release_manifest'], ['run_id' => $candidate['run_id'], 'profile' => Definition::PROFILE,
        'execution_contract' => Order::CONTRACT, 'paper_only' => true, 'live_approved' => false, 'paper_admission' => true,
        'runtime_hash' => $hash, 'files' => Release::files($fixture), 'proof_path' => 'proof.json', 'proof_sha256' => hash_file('sha256', $fixture . '/proof.json'), 'capital_reviewed' => 30000.]);
    $invoke = static function (bool $submit = false) use ($fixture): array {
        $p = proc_open([PHP_BINARY, $fixture . '/fixture_entry.php', 'tactical-paper-executor', '--candidate=true', '--submit=' . ($submit ? 'true' : 'false'),
            '--telegram=false', '--db=' . $fixture . '/preflight.sqlite', '--lock=' . $fixture . '/preflight.lock',
            '--mutation-lock=' . $fixture . '/account.lock', '--artifact=' . $fixture . '/signal.json', '--output=' . $fixture . '/cycle.json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $fixture);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $code = proc_close($p);
        return ['code' => $code, 'stdout' => $output, 'report' => is_file($fixture . '/cycle.json') ? json_decode(file_get_contents($fixture . '/cycle.json'), true) : null];
    };
    $result = $invoke(); $r = $result['report'];
    $check($result['code'] === 0, 'Valid isolated full CLI failed: ' . $result['stdout']);
    $check($r['dry_run'] === true && $r['run_status'] === 'active' && $r['errors'] === [], 'Full command stages twelve books without broker mutations.');
    $check(count($r['signal']['targets']) === 12 && $r['submitted'] === [] && $r['entry_submission_enabled'] === false, 'Read-only preflight cannot submit even a due target.');
    $check(!is_file($fixture . '/var/db/trading.sqlite'), 'Operational database path remains untouched.');
    $check($r['report_snapshot_fresh'] === true && $r['account_guard']['account_reference_match'] === true, 'Successful CLI verifies clock, account and source contract.');
    Artifact::write($fixture . '/broker_fixture.json', ['calendar' => $calendar, 'clock' => $clock, 'positions' => [['symbol' => 'MSFT', 'qty' => '1', 'side' => 'long']]]);
    $result = $invoke();
    $check($result['code'] === 2 && in_array('flat_only_isolated_preflight', $result['report']['errors'], true), 'Preflight cannot silently adopt a non-flat broker account.');
    Artifact::write($fixture . '/broker_fixture.json', ['calendar' => $calendar, 'clock' => $clock]);
    $path = $fixture . '/var/reports/candidate_external_data_' . str_replace('-', '', $date) . '/s5tw.json';
    file_put_contents($path, '{"tampered":true}');
    $result = $invoke();
    $check($result['code'] === 2 && $result['report']['entry_submission_enabled'] === false, 'Source corruption blocks the full command.');
    $check(str_contains(implode(' ', $result['report']['errors']), 'provenance'), 'CLI reports source provenance rather than pretending no signal.');
    $result = $invoke(true);
    $check($result['code'] === 2 && $result['report']['submitted'] === []
        && in_array('candidate_release_not_enabled', $result['report']['errors'], true), 'Disabled staged configuration cannot submit.');
    file_put_contents($fixture . '/src/Paper/CandidateOrder.php', "\n// isolated identity-corruption fixture\n", FILE_APPEND);
    $result = $invoke();
    $check($result['code'] === 2 && str_contains(implode(' ', $result['report']['errors']), 'release admission/identity'), 'A source edit invalidates command-level release approval.');
    echo "candidate_cycle_contract: {$n} assertions PASS\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($fixture);
}
