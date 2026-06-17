#!/usr/bin/env php
<?php

declare(strict_types=1);

$dir = __DIR__ . '/../materials/telegram_export/ChatExport_2026-06-13 (1)';
$output = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dir = substr($arg, 6);
    } elseif (str_starts_with($arg, '--output=')) {
        $output = substr($arg, 9);
    }
}

if (!is_dir($dir)) {
    fwrite(STDERR, "Directory not found: {$dir}\n");
    exit(1);
}

$tickerUniverse = [
    'SPY', 'SPX', 'SPXW', 'ES', 'ES1', 'QQQ', 'NQ', 'NQ1', 'NDX', 'IXIC', 'SMH', 'RSP', 'IWM', 'DIA', 'YM', 'YM1',
    'XLK', 'XLY', 'XLC', 'XLF', 'XLI', 'XLV', 'XLE', 'XLP', 'XLU', 'XLRE', 'XLB', 'XRT', 'IGV',
    'AAPL', 'MSFT', 'NVDA', 'AMZN', 'META', 'GOOGL', 'GOOG', 'AVGO', 'TSLA', 'AMD', 'NFLX', 'CRM', 'ADBE',
    'COST', 'ORCL', 'NOW', 'PLTR', 'LLY', 'UNH', 'JPM', 'V', 'MA', 'XOM', 'CAT', 'GE', 'UBER', 'PANW',
    'CRWD', 'SHOP', 'MELI', 'SMCI', 'MU', 'COIN', 'KO', 'TGT', 'DELL', 'PGR', 'RCKT', 'INSM', 'USM',
    'UPRO', 'SPXL', 'SPUU', 'SSO', 'TQQQ', 'QLD', 'SOXL', 'UDOW', 'SVXY', 'SVIX', 'SVYX',
    'VIX', 'VVIX', 'DXY', 'US20Y', 'NYA', 'EDOW', 'SX5E', 'SXXP', 'MAGS', 'M2SL', 'PCC', 'PCSP',
];
$tickerLookup = array_fill_keys($tickerUniverse, true);
$cashtagOnly = ['MA' => true, 'V' => true];

$keywords = [
    'поос', 'подхват', 'отскок', 'лонг', 'long', 'взял', 'вход', 'точка входа', 'покуп', 'купил',
    'держу', 'позици', 'портфель', 'стоп', 'бу', 'безубыт', 'нагруз', 'добав', 'фикс',
    'поддерж', 'закономер', 'ema', 'ема', 'sma', 'ma ', 'скольз', 'молот', 'поглощ',
    'фитил', 'перепрод', 'rsi', 'macd', 'уров', 'юбиле', 'цель', 'наруш',
];

$files = glob(rtrim($dir, '/') . '/messages*.html') ?: [];
sort($files, SORT_NATURAL);

$messages = [];
$lastAuthor = null;
foreach ($files as $file) {
    $html = file_get_contents($file);
    if ($html === false) {
        continue;
    }

    $parts = preg_split('/<div class="message /', $html) ?: [];
    foreach ($parts as $part) {
        if (!preg_match('/id="(message\d+)"/', $part, $idMatch)) {
            continue;
        }
        if (!preg_match('/<div class="pull_right date details" title="([^"]+)"/', $part, $dateMatch)) {
            continue;
        }

        $author = $lastAuthor;
        if (preg_match('/<div class="from_name">\s*(.*?)\s*<\/div>/s', $part, $fromMatch)) {
            $author = trim(html_entity_decode(strip_tags($fromMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $lastAuthor = $author;
        }
        if ($author === null || $author === '') {
            continue;
        }

        if (!preg_match('/<div class="text">\s*(.*?)\s*<\/div>/s', $part, $textMatch)) {
            continue;
        }

        $text = $textMatch[1];
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);
        if ($text === '') {
            continue;
        }

        $lower = mb_strtolower($text);
        $matchedKeywords = [];
        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                $matchedKeywords[] = $keyword;
            }
        }
        if ($matchedKeywords === []) {
            continue;
        }

        $tickers = [];
        if (preg_match_all('/\$([A-Z][A-Z0-9]{0,5})\b/u', $text, $matches)) {
            foreach ($matches[1] as $ticker) {
                $tickers[strtoupper($ticker)] = true;
            }
        }
        foreach ($tickerUniverse as $ticker) {
            if (isset($cashtagOnly[$ticker])) {
                continue;
            }
            if (preg_match('/(?<![A-Z0-9])' . preg_quote($ticker, '/') . '(?![A-Z0-9])/u', $text)) {
                $tickers[$ticker] = true;
            }
        }
        $tickers = array_values(array_filter(
            array_keys($tickers),
            static fn (string $ticker): bool => isset($tickerLookup[$ticker]),
        ));
        sort($tickers);
        if ($tickers === []) {
            continue;
        }

        $supportMentions = [];
        if (preg_match_all('/(?:(5|10|20|30|50|100|150|200)\s*(EMA|ЕМА|SMA|MA|МА)\s*(?:\(?\s*(D|Д|W|Н|H|4H|4Ч|15M|15М)\s*\)?)?)/iu', $text, $maMatches, PREG_SET_ORDER)) {
            foreach ($maMatches as $match) {
                $supportMentions[] = [
                    'period' => (int) $match[1],
                    'type' => strtoupper(str_replace(['ЕМА', 'МА'], ['EMA', 'MA'], $match[2])),
                    'timeframe' => strtoupper($match[3] ?? ''),
                ];
            }
        }

        $messages[] = [
            'file' => basename($file),
            'message_id' => $idMatch[1],
            'date_raw' => $dateMatch[1],
            'date' => (new DateTimeImmutable($dateMatch[1]))->format('Y-m-d'),
            'author' => $author,
            'tickers' => $tickers,
            'keywords' => array_values(array_unique($matchedKeywords)),
            'support_mentions' => $supportMentions,
            'text' => $text,
        ];
    }
}

$payload = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'source_dir' => $dir,
    'events' => $messages,
    'event_count' => count($messages),
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, 'Unable to encode JSON: ' . json_last_error_msg() . "\n");
    exit(1);
}

if ($output !== null) {
    $dirName = dirname($output);
    if (!is_dir($dirName) && !mkdir($dirName, 0775, true) && !is_dir($dirName)) {
        fwrite(STDERR, "Unable to create output directory: {$dirName}\n");
        exit(1);
    }
    file_put_contents($output, $json . "\n");
    echo "Telegram setup events extracted: " . count($messages) . " -> {$output}\n";
    exit(0);
}

echo $json . "\n";
