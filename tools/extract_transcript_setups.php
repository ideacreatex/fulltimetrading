#!/usr/bin/env php
<?php

declare(strict_types=1);

$dir = __DIR__ . '/../materials/video_transcripts';
$output = __DIR__ . '/../var/reports/transcript_setups.json';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dir = substr($arg, 6);
    } elseif (str_starts_with($arg, '--output=')) {
        $output = substr($arg, 9);
    }
}

if (!is_dir($dir)) {
    $parent = dirname($dir);
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        throw new RuntimeException('Unable to create materials directory: ' . $parent);
    }
    mkdir($dir, 0775, true);
}

$keywords = [
    'поос', 'подхват', 'панель приборов', 'правило клуба', 'fbma', 'сезон', 'президент',
    'вход', 'стоп', 'бу', 'нагруз', 'портфель', 'ema', 'sma', 'rsi', 'macd', 'vix',
    'широта', 's5fd', 's5tw', 's5fi', 's5oh', 's5th', 'ndfd', 'ndtw', 'ndfi', 'ndoh', 'ndth',
];

$events = [];
$files = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)));
foreach ($files as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['txt', 'vtt', 'srt'], true)) {
        continue;
    }

    $text = normalizeTranscript((string) file_get_contents($file->getPathname()));
    $chunks = chunkText($text, 1200);
    foreach ($chunks as $i => $chunk) {
        $lower = mb_strtolower($chunk);
        $matched = [];
        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                $matched[] = $keyword;
            }
        }
        $tickers = extractSymbols($chunk);
        if ($matched === [] && $tickers === []) {
            continue;
        }

        $events[] = [
            'source_file' => $file->getFilename(),
            'chunk' => $i + 1,
            'tickers' => $tickers,
            'keywords' => array_values(array_unique($matched)),
            'text' => $chunk,
        ];
    }
}

$payload = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'source_dir' => $dir,
    'event_count' => count($events),
    'events' => $events,
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    throw new RuntimeException('Unable to encode JSON: ' . json_last_error_msg());
}

$outDir = dirname($output);
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    throw new RuntimeException('Unable to create output directory: ' . $outDir);
}
file_put_contents($output, $json . "\n");

echo 'Transcript setup chunks: ' . count($events) . "\n";
echo "Report: {$output}\n";

function normalizeTranscript(string $text): string
{
    $text = preg_replace('/^\d+\s*$/m', '', $text) ?? $text;
    $text = preg_replace('/\d{2}:\d{2}:\d{2}[,.]\d{3}\s+-->\s+\d{2}:\d{2}:\d{2}[,.]\d{3}/', '', $text) ?? $text;
    $text = preg_replace('/WEBVTT|Kind:.*|Language:.*/iu', '', $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;

    return trim($text);
}

/** @return list<string> */
function chunkText(string $text, int $maxChars): array
{
    $sentences = preg_split('/(?<=[.!?。])\s+|\n/u', $text) ?: [];
    $chunks = [];
    $current = '';
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if ($sentence === '') {
            continue;
        }
        if (mb_strlen($current . ' ' . $sentence) > $maxChars && $current !== '') {
            $chunks[] = $current;
            $current = $sentence;
        } else {
            $current = trim($current . ' ' . $sentence);
        }
    }
    if ($current !== '') {
        $chunks[] = $current;
    }

    return $chunks;
}

/** @return list<string> */
function extractSymbols(string $text): array
{
    $bad = [
        'EMA', 'SMA', 'MA', 'RSI', 'MACD', 'ATR', 'USD', 'USA', 'FTT', 'FBMA', 'IPO',
        'CN', 'NBC', 'CNBC', 'MSF', 'QX', 'VI', 'SMS', 'MMA', 'UPR', 'US', 'EM', 'QQ', 'ETR', 'SO', 'APO',
    ];
    $symbols = [];
    if (preg_match_all('/\$([A-Z][A-Z0-9.]{0,9})\b/u', $text, $matches)) {
        foreach ($matches[1] as $symbol) {
            $symbol = normalizeSymbol($symbol);
            if ($symbol !== null && !in_array($symbol, $bad, true)) {
                $symbols[$symbol] = true;
            }
        }
    }
    if (preg_match_all('/(?<![A-Z0-9])([A-Z]{2,5})(?![A-Z0-9])/u', $text, $matches)) {
        foreach ($matches[1] as $symbol) {
            $symbol = normalizeSymbol($symbol);
            if ($symbol !== null && !in_array($symbol, $bad, true)) {
                $symbols[$symbol] = true;
            }
        }
    }

    return array_keys($symbols);
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
