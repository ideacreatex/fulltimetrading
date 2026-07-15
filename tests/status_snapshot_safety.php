<?php

declare(strict_types=1);

use FulltimeTrading\Support\StatusSnapshotSafety;

require __DIR__ . '/../bootstrap.php';

function statusSafetyExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$secret = 'secret-upstream-response-body';
$errorCode = StatusSnapshotSafety::errorCode(new RuntimeException($secret));
statusSafetyExpect($errorCode === StatusSnapshotSafety::ALPACA_SYNC_ERROR, 'Unexpected generic status error code.');
statusSafetyExpect(!str_contains($errorCode, $secret), 'Raw exception text leaked into the Git-safe status error.');
statusSafetyExpect(
    StatusSnapshotSafety::redactedDetail('action contains ' . $secret) === StatusSnapshotSafety::REDACTED_DETAIL,
    'Recent action detail was not redacted.',
);
statusSafetyExpect(StatusSnapshotSafety::redactedDetail('') === null, 'Empty action detail should remain empty.');

try {
    StatusSnapshotSafety::encodeJson(['invalid' => "\xB1\x31"]);
    throw new RuntimeException('Invalid UTF-8 unexpectedly produced a status JSON document.');
} catch (JsonException) {
    // Expected: encoding must fail instead of publishing a blank/partial file.
}

$directory = sys_get_temp_dir() . '/ftt-status-safety-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create status safety test directory.');
}

try {
    $path = $directory . '/latest.json';
    $contents = StatusSnapshotSafety::encodeJson(['ok' => true]);
    StatusSnapshotSafety::writeAtomic($path, $contents);
    statusSafetyExpect(file_get_contents($path) === $contents, 'Atomic status writer did not publish the complete file.');

    try {
        StatusSnapshotSafety::writeAtomic($directory . '/missing/latest.json', $contents);
        throw new RuntimeException('A missing output directory was silently accepted.');
    } catch (RuntimeException $e) {
        statusSafetyExpect(str_contains($e->getMessage(), 'directory does not exist'), 'Unexpected strict-write error.');
    }
} finally {
    @unlink($directory . '/latest.json');
    @rmdir($directory);
}

echo "Status snapshot safety OK\n";
