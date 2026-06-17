#!/usr/bin/env php
<?php

declare(strict_types=1);

$inputs = [
    __DIR__ . '/../materials/telegram_export/ChatExport_2026-06-13 (1)',
    __DIR__ . '/../materials/video_transcripts',
    __DIR__ . '/../var/reports/telegram_setups.json',
];
$output = __DIR__ . '/../var/reports/universe_from_materials.json';
$txtOutput = __DIR__ . '/../var/reports/universe_symbols.txt';
$leveragedOutput = __DIR__ . '/../var/reports/universe_leveraged_symbols.txt';
$leveragedLongOutput = __DIR__ . '/../var/reports/universe_leveraged_long_symbols.txt';
$inverseHedgeOutput = __DIR__ . '/../var/reports/universe_inverse_hedge_symbols.txt';
$withLeverageOutput = __DIR__ . '/../var/reports/universe_symbols_with_leverage.txt';
$withLongLeverageOutput = __DIR__ . '/../var/reports/universe_symbols_with_long_leverage.txt';
$withTrendHedgeOutput = __DIR__ . '/../var/reports/universe_symbols_with_trend_hedges.txt';
$coreMarketOutput = __DIR__ . '/../var/reports/universe_core_market_symbols.txt';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--input=')) {
        $inputs[] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--output=')) {
        $output = substr($arg, 9);
    } elseif (str_starts_with($arg, '--symbols-output=')) {
        $txtOutput = substr($arg, 17);
    } elseif (str_starts_with($arg, '--leveraged-output=')) {
        $leveragedOutput = substr($arg, 19);
    } elseif (str_starts_with($arg, '--leveraged-long-output=')) {
        $leveragedLongOutput = substr($arg, 24);
    } elseif (str_starts_with($arg, '--inverse-hedge-output=')) {
        $inverseHedgeOutput = substr($arg, 23);
    } elseif (str_starts_with($arg, '--with-leverage-output=')) {
        $withLeverageOutput = substr($arg, 23);
    } elseif (str_starts_with($arg, '--with-long-leverage-output=')) {
        $withLongLeverageOutput = substr($arg, 28);
    } elseif (str_starts_with($arg, '--with-trend-hedge-output=')) {
        $withTrendHedgeOutput = substr($arg, 26);
    }
}

$marketContext = [
    'SPY', 'SPX', 'ES', 'ES1', 'QQQ', 'NQ', 'NQ1', 'NDX', 'IXIC', 'SMH', 'RSP', 'IWM', 'DIA', 'YM', 'YM1',
    'XLK', 'XLY', 'XLC', 'XLF', 'XLI', 'XLV', 'XLE', 'XLP', 'XLU', 'XLRE', 'XLB', 'XRT', 'IGV',
    'QQQE', 'IYW', 'IBIT', 'GLD',
    'VIX', 'VVIX', 'SVXY', 'SVIX', 'SVYX', 'DXY', 'US20Y', 'US10Y', 'NYA', 'EDOW', 'SX5E', 'SXXP', 'MAGS', 'M2SL', 'PCC', 'PCSP',
    'S5FD', 'S5TW', 'S5FI', 'S5OH', 'S5TH', 'NDFD', 'NDTW', 'NDFI', 'NDOH', 'NDTH', 'DIFD', 'DITW', 'DIFI', 'DIOH', 'DITH',
    'S1FD', 'SFTW',
];
$coreMarketTradables = ['SPY', 'QQQ', 'SMH', 'RSP', 'DIA', 'IWM', 'XLK', 'XLY', 'XLC', 'XLF', 'XLI', 'XLV'];
$leveragedLong = [
    'UPRO', 'SPXL', 'SPUU', 'SSO',
    'TQQQ', 'QLD',
    'SOXL', 'USD', 'TECL', 'ROM',
    'UDOW', 'TNA', 'FAS',
    'FNGU', 'BULZ',
    'MSFU', 'MSFX',
];
$shortVolatility = ['SVXY', 'SVIX', 'SVYX'];
$inverseHedge = ['SPXU', 'SDS', 'SQQQ', 'QID', 'SCO', 'SOXS'];
$leveraged = array_values(array_unique(array_merge($leveragedLong, $inverseHedge)));
$excludeSymbols = ['SPYF', 'NASD', 'XYZ', 'MACRO', 'CN', 'NBC', 'CNBC', 'MSF', 'QX', 'VI', 'SMS', 'MMA', 'UPR', 'US', 'EM', 'QQ', 'ETR', 'SO', 'APO'];
$badWords = [
    'EMA', 'SMA', 'MA', 'RSI', 'MACD', 'ATR', 'D', 'W', 'H', 'UTC', 'USD', 'USA', 'PDF', 'HTTP', 'HTTPS',
    'FTT', 'TV', 'IB', 'AI', 'API', 'CSV', 'JSON', 'PINE', 'URL', 'OK', 'NO', 'YTD', 'CEO', 'IPO',
    'CN', 'NBC', 'CNBC', 'MSF', 'QX', 'VI', 'SMS', 'MMA', 'UPR', 'US', 'EM', 'QQ', 'ETR', 'SO', 'APO',
];

$counts = [];
$sources = [];
foreach ($inputs as $input) {
    if (!file_exists($input)) {
        continue;
    }

    if (is_dir($input)) {
        $files = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($input)));
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['html', 'txt', 'vtt', 'srt', 'json'], true)) {
                continue;
            }
            collectSymbols((string) file_get_contents($file->getPathname()), $file->getFilename(), $file->getExtension(), $counts, $sources, $badWords);
        }
        continue;
    }

    collectSymbols((string) file_get_contents($input), basename($input), pathinfo($input, PATHINFO_EXTENSION), $counts, $sources, $badWords);
}

arsort($counts);
$tradable = [];
$context = [];
$leveragedSymbols = [];
$leveragedLongSymbols = [];
$shortVolatilitySymbols = [];
$inverseHedgeSymbols = [];
$unknown = [];
foreach ($counts as $symbol => $count) {
    if (in_array($symbol, $excludeSymbols, true)) {
        $unknown[$symbol] = row($symbol, $count, $sources);
    } elseif (in_array($symbol, $leveragedLong, true)) {
        $leveragedSymbols[$symbol] = row($symbol, $count, $sources);
        $leveragedLongSymbols[$symbol] = row($symbol, $count, $sources);
    } elseif (in_array($symbol, $shortVolatility, true)) {
        $context[$symbol] = row($symbol, $count, $sources);
        $shortVolatilitySymbols[$symbol] = row($symbol, $count, $sources);
    } elseif (in_array($symbol, $inverseHedge, true)) {
        $leveragedSymbols[$symbol] = row($symbol, $count, $sources);
        $inverseHedgeSymbols[$symbol] = row($symbol, $count, $sources);
    } elseif (in_array($symbol, $marketContext, true)) {
        $context[$symbol] = row($symbol, $count, $sources);
    } elseif (isTradableCandidate($symbol)) {
        $tradable[$symbol] = row($symbol, $count, $sources);
    } else {
        $unknown[$symbol] = row($symbol, $count, $sources);
    }
}

foreach ($leveragedLong as $symbol) {
    if (!isset($leveragedLongSymbols[$symbol])) {
        $row = [
            'symbol' => $symbol,
            'mentions' => $counts[$symbol] ?? 0,
            'sources' => ['author_core_leveraged_universe'],
        ];
        $leveragedSymbols[$symbol] = $row;
        $leveragedLongSymbols[$symbol] = $row;
    }
}
foreach ($inverseHedge as $symbol) {
    if (!isset($inverseHedgeSymbols[$symbol])) {
        $row = [
            'symbol' => $symbol,
            'mentions' => $counts[$symbol] ?? 0,
            'sources' => ['author_core_inverse_hedge_universe'],
        ];
        $leveragedSymbols[$symbol] = $row;
        $inverseHedgeSymbols[$symbol] = $row;
    }
}

$payload = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'inputs' => array_values(array_unique($inputs)),
    'tradable_count' => count($tradable),
    'market_context_count' => count($context),
    'leveraged_count' => count($leveragedSymbols),
    'leveraged_long_count' => count($leveragedLongSymbols),
    'short_volatility_count' => count($shortVolatilitySymbols),
    'inverse_hedge_count' => count($inverseHedgeSymbols),
    'unknown_count' => count($unknown),
    'tradable' => array_values($tradable),
    'leveraged' => array_values($leveragedSymbols),
    'leveraged_long' => array_values($leveragedLongSymbols),
    'short_volatility' => array_values($shortVolatilitySymbols),
    'inverse_hedge' => array_values($inverseHedgeSymbols),
    'market_context' => array_values($context),
    'unknown' => array_values(array_slice($unknown, 0, 200, true)),
    'symbols' => array_keys($tradable),
    'leveraged_symbols' => array_keys($leveragedSymbols),
    'leveraged_long_symbols' => array_keys($leveragedLongSymbols),
    'inverse_hedge_symbols' => array_keys($inverseHedgeSymbols),
    'core_market_symbols' => $coreMarketTradables,
    'symbols_with_leverage' => array_values(array_unique(array_merge(array_keys($tradable), $coreMarketTradables, array_keys($leveragedSymbols)))),
    'symbols_with_long_leverage' => array_values(array_unique(array_merge(array_keys($tradable), $coreMarketTradables, array_keys($leveragedLongSymbols)))),
    'symbols_with_trend_hedges' => array_values(array_unique(array_merge(array_keys($tradable), $coreMarketTradables, array_keys($leveragedLongSymbols), array_keys($inverseHedgeSymbols)))),
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, 'Unable to encode JSON: ' . json_last_error_msg() . "\n");
    exit(1);
}

foreach ([$output, $txtOutput, $leveragedOutput, $leveragedLongOutput, $inverseHedgeOutput, $withLeverageOutput, $withLongLeverageOutput, $withTrendHedgeOutput, $coreMarketOutput] as $file) {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Unable to create output directory: {$dir}\n");
        exit(1);
    }
}
file_put_contents($output, $json . "\n");
file_put_contents($txtOutput, implode("\n", array_keys($tradable)) . "\n");
file_put_contents($leveragedOutput, implode("\n", array_keys($leveragedSymbols)) . "\n");
file_put_contents($leveragedLongOutput, implode("\n", array_keys($leveragedLongSymbols)) . "\n");
file_put_contents($inverseHedgeOutput, implode("\n", array_keys($inverseHedgeSymbols)) . "\n");
file_put_contents($coreMarketOutput, implode("\n", $coreMarketTradables) . "\n");
file_put_contents($withLeverageOutput, implode("\n", $payload['symbols_with_leverage']) . "\n");
file_put_contents($withLongLeverageOutput, implode("\n", $payload['symbols_with_long_leverage']) . "\n");
file_put_contents($withTrendHedgeOutput, implode("\n", $payload['symbols_with_trend_hedges']) . "\n");

echo 'Universe tradable symbols: ' . count($tradable) . "\n";
echo 'Market context symbols: ' . count($context) . "\n";
echo 'Leveraged symbols: ' . count($leveragedSymbols) . "\n";
echo 'Long leveraged symbols: ' . count($leveragedLongSymbols) . "\n";
echo 'Short volatility symbols: ' . count($shortVolatilitySymbols) . "\n";
echo 'Inverse hedge symbols: ' . count($inverseHedgeSymbols) . "\n";
echo "Report: {$output}\n";
echo "Symbols: {$txtOutput}\n";
echo "Leveraged symbols: {$leveragedOutput}\n";
echo "Long leveraged symbols: {$leveragedLongOutput}\n";
echo "Inverse hedge symbols: {$inverseHedgeOutput}\n";
echo "Core market symbols: {$coreMarketOutput}\n";
echo "Symbols with leverage: {$withLeverageOutput}\n";
echo "Symbols with long leverage: {$withLongLeverageOutput}\n";
echo "Symbols with trend hedges: {$withTrendHedgeOutput}\n";

/**
 * @param array<string, int> $counts
 * @param array<string, array<string, true>> $sources
 * @param list<string> $badWords
 */
function collectSymbols(string $content, string $source, string $extension, array &$counts, array &$sources, array $badWords): void
{
    $symbols = [];
    $extension = strtolower($extension);

    if ($extension === 'json') {
        $payload = json_decode($content, true);
        if (is_array($payload)) {
            collectSymbolsFromPayload($payload, $symbols);
        }
    }

    if (preg_match_all('/\$([A-Z][A-Z0-9.]{0,9})\b/u', $content, $matches)) {
        foreach ($matches[1] as $symbol) {
            $symbols[] = strtoupper($symbol);
        }
    }
    if (preg_match_all('/ShowCashtag\(&quot;([A-Z][A-Z0-9.]{0,9})&quot;\)/u', $content, $matches)) {
        foreach ($matches[1] as $symbol) {
            $symbols[] = strtoupper($symbol);
        }
    }

    $plainText = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $allowUppercaseScan = in_array($extension, ['txt', 'vtt', 'srt'], true);
    if ($allowUppercaseScan && preg_match_all('/(?<![A-Z0-9])([A-Z]{2,5})(?![A-Z0-9])/u', $plainText, $matches)) {
        foreach ($matches[1] as $symbol) {
            $symbols[] = strtoupper($symbol);
        }
    }

    foreach ($symbols as $symbol) {
        $symbol = normalizeSymbol($symbol);
        if ($symbol === null) {
            continue;
        }
        if (in_array($symbol, $badWords, true)) {
            continue;
        }
        if (preg_match('/^\d/', $symbol)) {
            continue;
        }
        $counts[$symbol] = ($counts[$symbol] ?? 0) + 1;
        $sources[$symbol][$source] = true;
    }
}

function normalizeSymbol(string $symbol): ?string
{
    $symbol = strtoupper(trim($symbol));
    $aliases = [
        'SQQ' => 'SQQQ',
        'UPR' => 'UPRO',
        'QQ' => 'QQQ',
    ];

    return $aliases[$symbol] ?? $symbol;
}

/** @param mixed $payload @param list<string> $symbols */
function collectSymbolsFromPayload(mixed $payload, array &$symbols): void
{
    if (!is_array($payload)) {
        return;
    }
    foreach ($payload as $key => $value) {
        if ($key === 'tickers' && is_array($value)) {
            foreach ($value as $ticker) {
                if (is_string($ticker)) {
                    $symbols[] = strtoupper($ticker);
                }
            }
        } elseif ($key === 'symbol' && is_string($value)) {
            $symbols[] = strtoupper($value);
        } else {
            collectSymbolsFromPayload($value, $symbols);
        }
    }
}

/** @param array<string, int> $counts @param array<string, array<string, true>> $sources */
function row(string $symbol, int $count, array $sources): array
{
    return [
        'symbol' => $symbol,
        'mentions' => $count,
        'sources' => array_slice(array_keys($sources[$symbol] ?? []), 0, 10),
    ];
}

function isTradableCandidate(string $symbol): bool
{
    if (strlen($symbol) < 2 || strlen($symbol) > 5) {
        return false;
    }
    if (preg_match('/^(S5|ND|DI)/', $symbol)) {
        return false;
    }

    return true;
}
