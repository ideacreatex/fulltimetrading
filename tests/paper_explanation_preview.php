<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/paper-explanation-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$snapshot = ['generated_at' => gmdate(DATE_ATOM), 'runtime' => ['paper_only' => true, 'paper_base_host_ok' => true,
    'paper_account_guard' => array_fill_keys(['account_reference_match', 'multiplier_match', 'shorting_match', 'active', 'unblocked'], true)],
    'alpaca' => ['snapshot_complete' => true, 'account' => ['equity' => 30000, 'cash' => 30000, 'buying_power' => 60000], 'positions' => [], 'open_orders' => []],
    'tactical' => ['run' => ['run_id' => 'test', 'status' => 'active'], 'health' => ['ok' => true],
        'cycle' => ['run_id' => 'test', 'generated_at' => gmdate(DATE_ATOM), 'signal' => ['validation_selected' => false],
            'entry_eligibility' => ['allowed_now' => false]]]];
file_put_contents($dir . '/snapshot.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
$fake = <<<'PHP'
namespace FulltimeTrading\Data { final class HttpClient {} }
namespace FulltimeTrading\Notifications {
    final class TelegramNotifier {
        public static function fromEnv($http): self { return new self; }
        public function sendMessage(string $text, bool $quiet): array {
            file_put_contents(getenv('FAKE_PREVIEW_LOG'), $text . "\nSENT\n", FILE_APPEND);
            if (getenv('FAKE_PREVIEW_FAIL') === 'true') { throw new \RuntimeException('uncertain delivery'); }
            return ['ok' => true, 'result' => ['message_id' => 123]];
        }
    }
}
namespace { require $argv[1]; }
PHP;
$checks = 0;
$expect = static function (bool $ok, string $why) use (&$checks): void { ++$checks; if (!$ok) { throw new RuntimeException($why); } };
$run = static function (string $folder, string $flag = 'false', bool $fail = false) use ($dir, $root, $fake): array {
    $argv = [$root . '/tools/preview_paper_explanation.php', '--snapshot=' . $dir . '/snapshot.json',
        '--output-dir=' . $dir . '/' . $folder, '--send-preview=' . $flag];
    // Substitute CLI argv because -r's script operand is not the included script.
    $code = 'namespace { $argv = ' . var_export($argv, true) . '; } ' . str_replace('require $argv[1]', 'require $argv[0]', $fake);
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root,
        array_merge(getenv(), ['FAKE_PREVIEW_LOG' => $dir . '/wire.log', 'FAKE_PREVIEW_FAIL' => $fail ? 'true' : 'false']));
    if (!is_resource($process)) { throw new RuntimeException('Unable to start mock.'); }
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return ['exit' => proc_close($process), 'output' => $output];
};
try {
    $r = $run('dry');
    $expect($r['exit'] === 0, 'Dry preview failed: ' . $r['output']);
    $expect(!file_exists($dir . '/wire.log'), 'Default must not send.');
    $expect(!file_exists($dir . '/dry/telegram_preview_attempt.json'), 'Default must not create delivery attempt.');
    $expect(str_contains(file_get_contents($dir . '/dry/intent_examples.md'), 'Not Real Trades'), 'Fictional fills are labelled.');
    $r = $run('sent', 'true');
    $expect($r['exit'] === 0, 'Mock delivery failed: ' . $r['output']);
    $attempt = json_decode(file_get_contents($dir . '/sent/telegram_preview_attempt.json'), true, 512, JSON_THROW_ON_ERROR);
    $expect($attempt['status'] === 'delivered' && $attempt['message_id'] === 123, 'Confirmed delivery receipt.');
    $wire = file_get_contents($dir . '/wire.log');
    $expect(str_contains($wire, 'ПРОВЕРКА НОВОГО ФОРМАТА') && !str_contains($wire, 'АКЦИИ КУПЛЕНЫ'), 'Only current status preview may be sent.');
    $expect($run('sent', 'true')['exit'] !== 0, 'Duplicate attempt must fail.');
    $expect(file_get_contents($dir . '/wire.log') === $wire, 'Duplicate must not hit transport.');
    $expect($run('uncertain', 'true', true)['exit'] !== 0, 'Unknown delivery must fail.');
    $attempt = json_decode(file_get_contents($dir . '/uncertain/telegram_preview_attempt.json'), true, 512, JSON_THROW_ON_ERROR);
    $expect($attempt['status'] === 'delivery_unconfirmed', 'Uncertain result must be persisted.');
    $wire = file_get_contents($dir . '/wire.log');
    $expect($run('uncertain', 'true')['exit'] !== 0 && file_get_contents($dir . '/wire.log') === $wire, 'No automatic resend after uncertainty.');
    $snapshot['generated_at'] = '2020-01-01T00:00:00Z';
    file_put_contents($dir . '/snapshot.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
    $expect($run('stale', 'true')['exit'] !== 0 && file_get_contents($dir . '/wire.log') === $wire, 'Stale preview cannot send.');
    $expect($run('invalid', 'yes')['exit'] !== 0, 'Strict flag parsing.');
    echo "paper_explanation_preview: {$checks} assertions PASS; mocked transport, no network\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($dir);
}
