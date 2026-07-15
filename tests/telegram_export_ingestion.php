<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

function telegramIngestionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function telegramIngestionRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        if (is_dir($child)) {
            telegramIngestionRemoveTree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}

$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/ftt-telegram-ingestion-' . bin2hex(random_bytes(6));
$sourceA = $temp . '/public';
$sourceB = $temp . '/private';
$output = $temp . '/result.json';
foreach ([$sourceA . '/photos', $sourceB . '/photos'] as $directory) {
    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create Telegram fixture directory.');
    }
}

try {
    file_put_contents($sourceA . '/photos/chart-a.jpg', 'same-media-content');
    file_put_contents($sourceB . '/photos/chart-b.jpg', 'same-media-content');
    $message = static fn (int $id, string $date, string $text, ?string $photo = null): array => array_filter([
        'id' => $id,
        'type' => 'message',
        'date' => $date,
        'from' => 'FTT_Admin Official',
        'from_id' => 'user1',
        'text' => $text,
        'photo' => $photo,
    ], static fn (mixed $value): bool => $value !== null);
    $payloadA = [
        'name' => 'Public FTT',
        'type' => 'public_channel',
        'messages' => [
            $message(1, '2026-06-13T10:00:00', 'старый стоп', null),
            $message(2, '2026-06-14T10:00:00', 'TQQQ стоп в БУ', 'photos/chart-a.jpg'),
            $message(3, '2026-06-15T10:00:00', '', 'photos/chart-a.jpg'),
        ],
    ];
    $payloadB = [
        'name' => 'Private FTT',
        'type' => 'private_supergroup',
        'messages' => [
            $message(20, '2026-06-14T10:00:04', 'TQQQ стоп в БУ', 'photos/chart-b.jpg'),
            $message(30, '2026-06-15T10:00:04', '', 'photos/chart-b.jpg'),
        ],
    ];
    file_put_contents($sourceA . '/result.json', json_encode($payloadA, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    file_put_contents($sourceB . '/result.json', json_encode($payloadB, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

    $command = [
        PHP_BINARY,
        $root . '/tools/extract_telegram_setups.php',
        '--dir=' . $sourceA,
        '--dir=' . $sourceB,
        '--after=2026-06-13',
        '--dedupe=1',
        '--output=' . $output,
    ];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Telegram extractor test.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    telegramIngestionAssert($exit === 0, 'Telegram extractor failed: ' . $stdout . $stderr);

    $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
    telegramIngestionAssert(($result['raw_event_count'] ?? null) === 4, 'Only four in-period relevant source events are expected.');
    telegramIngestionAssert(($result['event_count'] ?? null) === 2, 'Text and media mirrors must each deduplicate to one event.');
    telegramIngestionAssert(($result['duplicates_removed'] ?? null) === 2, 'Two mirrored events must be removed.');
    $events = $result['events'] ?? [];
    telegramIngestionAssert(($events[0]['mirror_count'] ?? null) === 2, 'Text mirror provenance must preserve both sources.');
    telegramIngestionAssert(count($events[0]['media'] ?? []) === 2, 'Both absolute media paths must survive text-mirror merging.');
    telegramIngestionAssert(($events[1]['media_only'] ?? false) === true, 'A media-only author event must not be discarded.');
    telegramIngestionAssert(($events[1]['mirror_count'] ?? null) === 2, 'Media-only mirrors must deduplicate by content hash.');
    $resolvedTemp = (string) (realpath($temp) ?: $temp);
    foreach ($events as $event) {
        foreach (($event['media'] ?? []) as $media) {
            telegramIngestionAssert(str_starts_with((string) ($media['absolute_path'] ?? ''), $resolvedTemp), 'Media path must remain absolute and source-specific.');
        }
    }
} finally {
    telegramIngestionRemoveTree($temp);
}

echo "Telegram Desktop export ingestion OK\n";
