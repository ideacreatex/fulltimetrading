<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$tempDir = sys_get_temp_dir() . '/ftt-intraday-fail-closed-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create intraday replay test directory.');
}

$config = FulltimeTrading\Support\Config::fromFile(__DIR__ . '/../config/config.php');
$cachePath = rtrim((string) $config->get('cache_path'), '/');
$namespace = 'alpaca-trade-intraday-replay-v1-iex';
$cacheFile = $cachePath . '/' . sha1($namespace . '|TEST|1Min|2026-01-02|2026-01-03') . '.json';
$trailCacheFile = $cachePath . '/' . sha1($namespace . '|TRAIL|1Min|2026-01-02|2026-01-06') . '.json';
$tradesFile = $tempDir . '/trades.json';
$signalsFile = $tempDir . '/signals.json';
$equityFile = $tempDir . '/equity.json';
$outputFile = $tempDir . '/result.json';

try {
    file_put_contents($cacheFile, json_encode([
        'TEST' => [[
            'time' => '2026-01-02T14:30:00+00:00',
            'open' => 100.0,
            'high' => 104.0,
            'low' => 99.0,
            'close' => 102.0,
            'volume' => 1000.0,
        ]],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($trailCacheFile, json_encode([
        'TRAIL' => [
            [
                'time' => '2026-01-02T14:30:00+00:00',
                'open' => 100.0,
                'high' => 104.0,
                'low' => 100.0,
                'close' => 104.0,
                'volume' => 1000.0,
            ],
            [
                'time' => '2026-01-02T14:31:00+00:00',
                'open' => 106.0,
                'high' => 111.0,
                'low' => 106.0,
                'close' => 109.0,
                'volume' => 1000.0,
            ],
            [
                'time' => '2026-01-05T14:30:00+00:00',
                'open' => 106.0,
                'high' => 106.0,
                'low' => 104.0,
                'close' => 105.0,
                'volume' => 1000.0,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($tradesFile, json_encode(['trades' => [[
        'symbol' => 'TEST',
        'strategy' => 'SUPPORT_REGULARITY',
        'entry_date' => '2026-01-02',
        'exit_date' => '2026-01-02',
        'entry' => 100.0,
        'exit' => 100.0,
        'shares' => 10.0,
        'pnl' => 0.0,
        'exit_reason' => 'break_even_stop',
        'events' => [
            '2026-01-01: planned long SUPPORT_REGULARITY limit at 100',
            '2026-01-02: filled limit at 100',
        ],
    ], [
        'symbol' => 'TRAIL',
        'strategy' => 'SUPPORT_REGULARITY',
        'entry_date' => '2026-01-02',
        'exit_date' => '2026-01-05',
        'entry' => 100.0,
        'exit' => 105.0,
        'shares' => 10.0,
        'pnl' => 75.0,
        'exit_reason' => 'stop',
        'events' => [
            '2026-01-01: planned long SUPPORT_REGULARITY limit at 100',
            '2026-01-02: filled limit at 100',
            '2026-01-02: took partial 5 at 110, stop to breakeven',
            '2026-01-02: trailed stop to EMA10 105',
            '2026-01-05: hard stop filled at 105',
        ],
    ]]], JSON_THROW_ON_ERROR));
    file_put_contents($signalsFile, json_encode(['signals' => [[
        'date' => '2026-01-01',
        'symbol' => 'TEST',
        'strategy' => 'SUPPORT_REGULARITY',
        'direction' => 'long',
        'entry' => 100.0,
        'stop' => 90.0,
        'target' => 110.0,
    ], [
        'date' => '2026-01-01',
        'symbol' => 'TRAIL',
        'strategy' => 'SUPPORT_REGULARITY',
        'direction' => 'long',
        'entry' => 100.0,
        'stop' => 90.0,
        'target' => 110.0,
    ]]], JSON_THROW_ON_ERROR));
    file_put_contents($equityFile, json_encode(['equity' => [
        ['date' => '2026-01-01', 'equity' => 1000.0],
        ['date' => '2026-01-02', 'equity' => 1000.0],
    ]], JSON_THROW_ON_ERROR));

    $arguments = [
        PHP_BINARY,
        __DIR__ . '/../tools/replay_trades_intraday.php',
        '--trades=' . $tradesFile,
        '--signals=' . $signalsFile,
        '--equity=' . $equityFile,
        '--output=' . $outputFile,
        '--limit=all',
        '--feed=iex',
        '--offline=true',
        '--bulk-fetch=true',
        '--initial-stop-mode=mental',
        '--break-even-pct=0.05',
        '--partial-pct=0.50',
        '--transaction-cost-bps=10',
    ];
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    exec($command . ' 2>&1', $commandOutput, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("Intraday replay command failed:\n" . implode("\n", $commandOutput));
    }

    $result = json_decode((string) file_get_contents($outputFile), true, 512, JSON_THROW_ON_ERROR);
    if (($result['summary']['input_trades'] ?? null) !== 2 || ($result['summary']['entry_replays'] ?? null) !== 2) {
        throw new RuntimeException('The test trades were not matched and entered.');
    }
    if (($result['summary']['minute_exits'] ?? null) !== 1 || ($result['summary']['missing_minute_exit'] ?? null) !== 1) {
        throw new RuntimeException('Only the fully modeled trailing-stop path may count as a minute exit.');
    }
    if (($result['summary']['daily_fallback_exits'] ?? null) !== 0) {
        throw new RuntimeException('Daily fallback exits must remain disabled.');
    }
    if (($result['equity_reconstruction_status'] ?? null) !== 'blocked' || isset($result['minute_adjusted_equity_summary'])) {
        throw new RuntimeException('An incomplete replay must block portfolio-level minute metrics.');
    }
    $rowsBySymbol = [];
    foreach ($result['rows'] ?? [] as $row) {
        $rowsBySymbol[(string) ($row['symbol'] ?? '')] = $row;
    }
    if (($rowsBySymbol['TEST']['minute_exit_reason'] ?? null) !== 'unresolved_after_daily_exit') {
        throw new RuntimeException('The unresolved row must expose its explicit fail-closed reason.');
    }
    if (
        ($rowsBySymbol['TRAIL']['minute_exit_reason'] ?? null) !== 'minute_stop'
        || abs((float) ($rowsBySymbol['TRAIL']['minute_exit'] ?? 0.0) - 105.0) > 1.0e-9
    ) {
        throw new RuntimeException('A completed-session EMA10 trail must activate at the next session boundary.');
    }

    $subsetArguments = $arguments;
    $limitIndex = array_search('--limit=all', $subsetArguments, true);
    if ($limitIndex === false) {
        throw new RuntimeException('The replay test command is missing its limit option.');
    }
    $subsetArguments[$limitIndex] = '--limit=1';
    $subsetCommand = implode(' ', array_map('escapeshellarg', $subsetArguments));
    exec($subsetCommand . ' 2>&1', $subsetOutput, $subsetExitCode);
    if ($subsetExitCode === 0 || !str_contains(implode("\n", $subsetOutput), 'requires --limit=all')) {
        throw new RuntimeException('Equity reconstruction must reject subset replays before loading data.');
    }
} finally {
    @unlink($cacheFile);
    @unlink($trailCacheFile);
    foreach (glob($tempDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tempDir);
}

echo "Intraday replay fail-closed behavior OK\n";
