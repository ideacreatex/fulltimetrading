#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Notifications\TelegramNotifier;
use FulltimeTrading\Support\PaperSignalExplanation as E;
use FulltimeTrading\Support\StatusSnapshotSafety;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$options = ['snapshot' => $root . '/var/reports/paper_clarity_20260914/operational/latest_paper_status.json',
    'output-dir' => $root . '/var/reports/paper_clarity_20260914', 'send-preview' => 'false'];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([^=]+)=(.*)$/D', $arg, $m) || !array_key_exists($m[1], $options)) {
        throw new InvalidArgumentException('Use --snapshot=PATH --output-dir=PATH --send-preview=true|false.');
    }
    $options[$m[1]] = $m[2];
}
if (!in_array($options['send-preview'], ['true', 'false'], true)) { throw new InvalidArgumentException('Invalid preview flag.'); }
$snapshot = json_decode(file_get_contents($options['snapshot']), true, 512, JSON_THROW_ON_ERROR);
$explanation = E::build($snapshot, new DateTimeImmutable());
$out = $options['output-dir'];
if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) { throw new RuntimeException('Cannot create output directory.'); }
StatusSnapshotSafety::writeAtomic($out . '/explanation.json', StatusSnapshotSafety::encodeJson($explanation));
StatusSnapshotSafety::writeAtomic($out . '/explanation.txt', $explanation['text'] . "\n");
$examples = ['# Examples Only: Not Real Trades', 'These fixtures are never sent to Telegram.'];
$i = ['symbol' => 'MSFT', 'side' => 'buy', 'requested_qty' => 5, 'cumulative_filled_qty' => 0, 'status' => 'accepted'];
foreach (['accepted' => [0, 'accepted'], 'partial' => [2, 'partially_filled'], 'partial_canceled' => [2, 'canceled'],
    'filled' => [5, 'filled'], 'rejected' => [0, 'rejected'], 'sale' => [5, 'filled']] as $label => [$qty, $status]) {
    $i['side'] = $label === 'sale' ? 'sell' : 'buy';
    $i['status'] = $status; $i['cumulative_filled_qty'] = $qty; $i['cumulative_fill_notional'] = 100 * $qty;
    $examples[] = "\n## Fixture: {$label}\n\n" . E::intent($i);
}
StatusSnapshotSafety::writeAtomic($out . '/intent_examples.md', implode("\n", $examples) . "\n");
echo $explanation['text'], "\n";
if ($options['send-preview'] !== 'true') { exit(0); }
if (!$explanation['broker_snapshot_verified'] || !$explanation['fresh_cycle'] || !$explanation['same_run']) {
    throw new RuntimeException('Refusing to send an unverified or stale preview. Refresh the isolated status export first.');
}
$text = "ПРОВЕРКА НОВОГО ФОРМАТА. Это разовый текущий статус, не новая сделка.\n\n" . $explanation['text'];
if (strlen($text) > 3800) { throw new RuntimeException('Preview exceeds existing transport byte budget.'); }
$notifier = TelegramNotifier::fromEnv(new HttpClient()) ?? throw new RuntimeException('Configured Telegram destination unavailable.');
$lock = fopen($out . '/telegram_preview.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Preview delivery already running.'); }
$attemptPath = $out . '/telegram_preview_attempt.json';
if (file_exists($attemptPath)) { throw new RuntimeException('A preview was already attempted. No automatic retry after an uncertain send.'); }
$attempt = ['schema' => 1, 'kind' => 'one_time_format_preview_not_trade_outbox', 'attempted_at' => gmdate(DATE_ATOM),
    'snapshot_sha256' => hash_file('sha256', $options['snapshot']), 'message_sha256' => hash('sha256', $text),
    'status' => 'attempted_unconfirmed', 'manual_orders_submitted' => 0];
// Record before the network call: uncertain delivery must not cause duplicate previews.
StatusSnapshotSafety::writeAtomic($attemptPath, StatusSnapshotSafety::encodeJson($attempt));
try {
    $reply = $notifier->sendMessage($text, true);
    $attempt['status'] = 'delivered';
    $attempt['message_id'] = $reply['result']['message_id'];
    $attempt['delivered_at'] = gmdate(DATE_ATOM);
} catch (Throwable) {
    $attempt['status'] = 'delivery_unconfirmed';
    StatusSnapshotSafety::writeAtomic($attemptPath, StatusSnapshotSafety::encodeJson($attempt));
    throw new RuntimeException('Preview delivery unconfirmed; no automatic resend. See local attempt record.');
}
StatusSnapshotSafety::writeAtomic($attemptPath, StatusSnapshotSafety::encodeJson($attempt));
echo 'Preview delivered once; no trade or runtime state changed.', "\n";
