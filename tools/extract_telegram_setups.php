#!/usr/bin/env php
<?php

declare(strict_types=1);

$defaultDir = __DIR__ . '/../materials/telegram_export/ChatExport_2026-06-13 (1)';
$inputDirs = [];
$options = [
    'output' => null,
    'after' => '',
    'before' => '',
    'dedupe' => '1',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $inputDirs[] = substr($arg, 6);
        continue;
    }
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

if ($inputDirs === []) {
    $inputDirs[] = $defaultDir;
}
$inputDirs = array_values(array_unique(array_map(
    static fn (string $dir): string => rtrim((string) (realpath($dir) ?: $dir), '/'),
    $inputDirs,
)));

foreach ($inputDirs as $inputDir) {
    if (!is_dir($inputDir)) {
        fwrite(STDERR, "Directory not found: {$inputDir}\n");
        exit(1);
    }
}

$after = validateDateOption((string) $options['after'], 'after');
$before = validateDateOption((string) $options['before'], 'before');
$dedupeEnabled = booleanOption((string) $options['dedupe']);

$tickerUniverse = [
    'SPY', 'SPX', 'SPXW', 'ES', 'ES1', 'MES', 'QQQ', 'NQ', 'NQ1', 'MNQ', 'NDX', 'IXIC', 'SMH', 'SOXX', 'SOX',
    'RSP', 'XLG', 'IWM', 'RUT', 'DIA', 'DJI', 'YM', 'YM1', 'XLK', 'XLY', 'XLC', 'XLF', 'XLI', 'XLV', 'XLE',
    'XLP', 'XLU', 'XLRE', 'XLB', 'XRT', 'IGV', 'AAPL', 'MSFT', 'NVDA', 'AMZN', 'META', 'GOOGL', 'GOOG', 'AVGO',
    'TSLA', 'AMD', 'NFLX', 'CRM', 'ADBE', 'COST', 'ORCL', 'NOW', 'PLTR', 'LLY', 'UNH', 'JPM', 'V', 'MA', 'XOM',
    'CAT', 'GE', 'UBER', 'PANW', 'CRWD', 'SHOP', 'MELI', 'SMCI', 'MU', 'COIN', 'KO', 'TGT', 'DELL', 'PGR', 'RCKT',
    'INSM', 'USM', 'WDC', 'MRVL', 'UPRO', 'SPXL', 'SPUU', 'SSO', 'TQQQ', 'QLD', 'SOXL', 'SOXS', 'TECL', 'ROM',
    'USD', 'UDOW', 'SVXY', 'SVIX', 'SVYX', 'VIX', 'VVIX', 'DXY', 'US20Y', 'NYA', 'EDOW', 'SX5E', 'SXXP', 'MAGS',
    'M2SL', 'PCC', 'PCSP', 'FNGU', 'BULZ',
];
$tickerLookup = array_fill_keys($tickerUniverse, true);
$cashtagOnly = ['MA' => true, 'V' => true];

$keywordPatterns = [
    'поос' => '~(?<![\p{L}\p{N}_])поос(?![\p{L}\p{N}_])~iu',
    'подхват' => '~подхват\p{L}*~iu',
    'отскок' => '~отскок\p{L}*~iu',
    'лонг' => '~(?<![\p{L}\p{N}_])(?:лонг|long)(?![\p{L}\p{N}_])~iu',
    'вход' => '~(?:точк\p{L}*\s+входа|(?<![\p{L}\p{N}_])вход(?![\p{L}\p{N}_]))~iu',
    'покупка' => '~покуп\p{L}*|купил\p{L}*~iu',
    'позиция' => '~позици\p{L}*~iu',
    'портфель' => '~портфел\p{L}*~iu',
    'стоп' => '~(?<![\p{L}\p{N}_])стоп\p{L}*~iu',
    'бу' => '~(?<![\p{L}\p{N}_])бу(?![\p{L}\p{N}_])~iu',
    'безубыток' => '~без\s*убыт\p{L}*~iu',
    'нагрузка' => '~нагруз\p{L}*~iu',
    'плечо' => '~плеч\p{L}*~iu',
    'перезаход' => '~перезаход\p{L}*~iu',
    'докупка' => '~докуп\p{L}*|добира\p{L}*|добав\p{L}*~iu',
    'фиксация' => '~фиксир\p{L}*|зафиксир\p{L}*~iu',
    'закономерность' => '~закономер\p{L}*~iu',
    'поддержка' => '~поддерж\p{L}*~iu',
    'скользящая' => '~скольз\p{L}*~iu',
    'ema' => '~(?<![\p{L}\p{N}_])(?:ema|ема)(?![\p{L}\p{N}_])~iu',
    'sma' => '~(?<![\p{L}\p{N}_])sma(?![\p{L}\p{N}_])~iu',
    'fbma' => '~(?<![\p{L}\p{N}_])fbma(?:\s*20)?(?![\p{L}\p{N}_])~iu',
    'rsi' => '~(?<![\p{L}\p{N}_])rsi(?![\p{L}\p{N}_])~iu',
    'atr' => '~(?<![\p{L}\p{N}_])atr(?![\p{L}\p{N}_])~iu',
    'паттерн' => '~(?:молот|поглощ|фитил|падающ\p{L}*\s+звезд)~iu',
    'уровень' => '~уров\p{L}*~iu',
    'зеленый сад' => '~зел[её]н\p{L}*\s+сад~iu',
    'сезонность' => '~сезон\p{L}*~iu',
    'удержание' => '~держ(?:у|им|ать)|удерж\p{L}*~iu',
];

$rawEvents = [];
$sourceSummaries = [];
foreach ($inputDirs as $inputDir) {
    $jsonPath = $inputDir . '/result.json';
    if (is_file($jsonPath)) {
        [$sourceEvents, $sourceSummary] = extractJsonExport(
            $inputDir,
            $jsonPath,
            $tickerUniverse,
            $tickerLookup,
            $cashtagOnly,
            $keywordPatterns,
            $after,
            $before,
        );
    } else {
        [$sourceEvents, $sourceSummary] = extractHtmlExport(
            $inputDir,
            $tickerUniverse,
            $tickerLookup,
            $cashtagOnly,
            $keywordPatterns,
            $after,
            $before,
        );
    }
    $rawEvents = array_merge($rawEvents, $sourceEvents);
    $sourceSummaries[] = $sourceSummary;
}

$events = $dedupeEnabled ? deduplicateMirrorEvents($rawEvents) : initializeMirrorMetadata($rawEvents);
usort($events, static function (array $left, array $right): int {
    $byDate = strcmp((string) ($left['date_raw'] ?? ''), (string) ($right['date_raw'] ?? ''));
    if ($byDate !== 0) {
        return $byDate;
    }

    return strcmp((string) ($left['message_id'] ?? ''), (string) ($right['message_id'] ?? ''));
});

$summary = summarizeEvents($events, $sourceSummaries, count($rawEvents));
$payload = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'source_dir' => count($inputDirs) === 1 ? $inputDirs[0] : null,
    'source_dirs' => $inputDirs,
    'period' => [
        'after_exclusive' => $after,
        'before_inclusive' => $before,
    ],
    'dedupe_enabled' => $dedupeEnabled,
    'raw_event_count' => count($rawEvents),
    'event_count' => count($events),
    'duplicates_removed' => count($rawEvents) - count($events),
    'summary' => $summary,
    'events' => $events,
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, 'Unable to encode JSON: ' . json_last_error_msg() . "\n");
    exit(1);
}

$output = $options['output'];
if (is_string($output) && $output !== '') {
    $dirName = dirname($output);
    if (!is_dir($dirName) && !mkdir($dirName, 0775, true) && !is_dir($dirName)) {
        fwrite(STDERR, "Unable to create output directory: {$dirName}\n");
        exit(1);
    }
    if (file_put_contents($output, $json . "\n") === false) {
        fwrite(STDERR, "Unable to write output: {$output}\n");
        exit(1);
    }
    echo "Telegram events: raw=" . count($rawEvents)
        . ', deduplicated=' . count($events)
        . ', mirrors_removed=' . (count($rawEvents) - count($events))
        . " -> {$output}\n";
    exit(0);
}

echo $json . "\n";

function validateDateOption(string $value, string $name): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        fwrite(STDERR, "Invalid --{$name} date, expected YYYY-MM-DD: {$value}\n");
        exit(1);
    }

    return $date->format('Y-m-d');
}

function booleanOption(string $value): bool
{
    return !in_array(mb_strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
}

/**
 * @param list<string> $tickerUniverse
 * @param array<string, true> $tickerLookup
 * @param array<string, true> $cashtagOnly
 * @param array<string, string> $keywordPatterns
 * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
 */
function extractJsonExport(
    string $sourceDir,
    string $jsonPath,
    array $tickerUniverse,
    array $tickerLookup,
    array $cashtagOnly,
    array $keywordPatterns,
    ?string $after,
    ?string $before,
): array {
    $payload = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
    $sourceName = trim((string) ($payload['name'] ?? basename($sourceDir)));
    $sourceType = trim((string) ($payload['type'] ?? 'telegram_json'));
    $messages = $payload['messages'] ?? [];
    if (!is_array($messages)) {
        throw new RuntimeException("Telegram JSON has no messages array: {$jsonPath}");
    }

    $events = [];
    $scanned = 0;
    $inPeriod = 0;
    foreach ($messages as $message) {
        if (!is_array($message) || (string) ($message['type'] ?? 'message') !== 'message') {
            continue;
        }
        $scanned++;
        $dateRaw = (string) ($message['date'] ?? '');
        $date = telegramDate($dateRaw);
        if ($date === null || !dateInPeriod($date, $after, $before)) {
            continue;
        }
        $inPeriod++;

        $text = flattenTelegramText($message['text'] ?? '');
        $media = jsonMediaItems($message, $sourceDir);
        $event = buildEvent(
            sourceDir: $sourceDir,
            sourceName: $sourceName,
            sourceType: $sourceType,
            sourceFile: basename($jsonPath),
            messageId: (string) ($message['id'] ?? ''),
            dateRaw: $dateRaw,
            date: $date,
            author: trim((string) ($message['from'] ?? $sourceName)),
            authorId: trim((string) ($message['from_id'] ?? '')),
            forwardedFrom: trim((string) ($message['forwarded_from'] ?? '')),
            text: $text,
            media: $media,
            tickerUniverse: $tickerUniverse,
            tickerLookup: $tickerLookup,
            cashtagOnly: $cashtagOnly,
            keywordPatterns: $keywordPatterns,
        );
        if (eventIsRelevant($event)) {
            $events[] = $event;
        }
    }

    return [$events, [
        'source_dir' => $sourceDir,
        'source_name' => $sourceName,
        'source_type' => $sourceType,
        'format' => 'telegram_desktop_json',
        'messages_scanned' => $scanned,
        'messages_in_period' => $inPeriod,
        'relevant_events' => count($events),
    ]];
}

/**
 * @param list<string> $tickerUniverse
 * @param array<string, true> $tickerLookup
 * @param array<string, true> $cashtagOnly
 * @param array<string, string> $keywordPatterns
 * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
 */
function extractHtmlExport(
    string $sourceDir,
    array $tickerUniverse,
    array $tickerLookup,
    array $cashtagOnly,
    array $keywordPatterns,
    ?string $after,
    ?string $before,
): array {
    $files = glob($sourceDir . '/messages*.html') ?: [];
    sort($files, SORT_NATURAL);
    if ($files === []) {
        throw new RuntimeException("No result.json or messages*.html found in: {$sourceDir}");
    }

    $sourceName = basename($sourceDir);
    $events = [];
    $lastAuthor = null;
    $scanned = 0;
    $inPeriod = 0;
    foreach ($files as $file) {
        $html = file_get_contents($file);
        if ($html === false) {
            continue;
        }
        if (preg_match('/<div class="text bold">\s*(.*?)\s*<\/div>/s', $html, $nameMatch)) {
            $parsedName = cleanHtmlText($nameMatch[1]);
            if ($parsedName !== '') {
                $sourceName = $parsedName;
            }
        }

        $parts = preg_split('/<div class="message /', $html) ?: [];
        foreach ($parts as $part) {
            if (!preg_match('/id="(message\d+)"/', $part, $idMatch)
                || !preg_match('/<div class="pull_right date details" title="([^"]+)"/', $part, $dateMatch)) {
                continue;
            }
            $scanned++;
            $date = telegramDate(html_entity_decode($dateMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($date === null || !dateInPeriod($date, $after, $before)) {
                continue;
            }
            $inPeriod++;

            $author = $lastAuthor;
            if (preg_match('/<div class="from_name">\s*(.*?)\s*<\/div>/s', $part, $fromMatch)) {
                $author = cleanHtmlText($fromMatch[1]);
                $lastAuthor = $author;
            }
            if ($author === null || $author === '') {
                $author = $sourceName;
            }

            $text = '';
            if (preg_match('/<div class="text">\s*(.*?)\s*<\/div>/s', $part, $textMatch)) {
                $text = cleanHtmlText($textMatch[1]);
            }
            $media = htmlMediaItems($part, $sourceDir);
            $event = buildEvent(
                sourceDir: $sourceDir,
                sourceName: $sourceName,
                sourceType: 'telegram_html',
                sourceFile: basename($file),
                messageId: $idMatch[1],
                dateRaw: html_entity_decode($dateMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                date: $date,
                author: $author,
                authorId: '',
                forwardedFrom: '',
                text: $text,
                media: $media,
                tickerUniverse: $tickerUniverse,
                tickerLookup: $tickerLookup,
                cashtagOnly: $cashtagOnly,
                keywordPatterns: $keywordPatterns,
            );
            if (eventIsRelevant($event)) {
                $events[] = $event;
            }
        }
    }

    return [$events, [
        'source_dir' => $sourceDir,
        'source_name' => $sourceName,
        'source_type' => 'telegram_html',
        'format' => 'telegram_desktop_html',
        'messages_scanned' => $scanned,
        'messages_in_period' => $inPeriod,
        'relevant_events' => count($events),
    ]];
}

function telegramDate(string $dateRaw): ?string
{
    if (trim($dateRaw) === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($dateRaw))->format('Y-m-d');
    } catch (Throwable) {
        return null;
    }
}

function dateInPeriod(string $date, ?string $after, ?string $before): bool
{
    if ($after !== null && $date <= $after) {
        return false;
    }
    if ($before !== null && $date > $before) {
        return false;
    }

    return true;
}

function flattenTelegramText(mixed $value): string
{
    if (is_string($value) || is_numeric($value)) {
        return normalizeMessageText((string) $value);
    }
    if (!is_array($value)) {
        return '';
    }

    $parts = [];
    foreach ($value as $part) {
        if (is_array($part)) {
            $parts[] = flattenTelegramText($part['text'] ?? '');
        } elseif (is_string($part) || is_numeric($part)) {
            $parts[] = (string) $part;
        }
    }

    return normalizeMessageText(implode('', $parts));
}

function normalizeMessageText(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

    return trim($text);
}

function cleanHtmlText(string $html): string
{
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return normalizeMessageText($text);
}

/** @param array<string, mixed> $message @return list<array<string, mixed>> */
function jsonMediaItems(array $message, string $sourceDir): array
{
    $media = [];
    if (isset($message['photo']) && is_string($message['photo']) && $message['photo'] !== '') {
        $media[] = mediaItem('photo', $message['photo'], $sourceDir, [
            'file_size' => $message['photo_file_size'] ?? null,
            'width' => $message['width'] ?? null,
            'height' => $message['height'] ?? null,
        ]);
    }
    if (isset($message['file']) && is_string($message['file']) && $message['file'] !== '') {
        $media[] = mediaItem((string) ($message['media_type'] ?? 'file'), $message['file'], $sourceDir, [
            'file_name' => $message['file_name'] ?? basename($message['file']),
            'file_size' => $message['file_size'] ?? null,
            'mime_type' => $message['mime_type'] ?? null,
            'duration_seconds' => $message['duration_seconds'] ?? null,
            'width' => $message['width'] ?? null,
            'height' => $message['height'] ?? null,
        ]);
    }
    if (isset($message['thumbnail']) && is_string($message['thumbnail']) && $message['thumbnail'] !== '') {
        $media[] = mediaItem('thumbnail', $message['thumbnail'], $sourceDir, [
            'file_size' => $message['thumbnail_file_size'] ?? null,
        ]);
    }
    if (isset($message['poll']) && is_array($message['poll'])) {
        $media[] = [
            'type' => 'poll',
            'relative_path' => null,
            'absolute_path' => null,
            'exists' => true,
            'poll' => $message['poll'],
        ];
    }

    return $media;
}

/** @return list<array<string, mixed>> */
function htmlMediaItems(string $part, string $sourceDir): array
{
    if (!preg_match_all(
        '/href="((?:photos|video_files|files|voice_messages|audio_files|video_messages|stickers)\/[^"#?]+)"/iu',
        $part,
        $matches,
    )) {
        return [];
    }

    $media = [];
    foreach (array_values(array_unique($matches[1])) as $relativePath) {
        $relativePath = html_entity_decode($relativePath, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $topDir = mb_strtolower(strtok($relativePath, '/') ?: 'file');
        $type = match ($topDir) {
            'photos' => 'photo',
            'video_files', 'video_messages' => 'video_file',
            'voice_messages' => 'voice_message',
            'audio_files' => 'audio_file',
            'stickers' => 'sticker',
            default => 'file',
        };
        $media[] = mediaItem($type, $relativePath, $sourceDir);
    }

    return $media;
}

/** @param array<string, mixed> $metadata @return array<string, mixed> */
function mediaItem(string $type, string $relativePath, string $sourceDir, array $metadata = []): array
{
    $relativePath = str_replace('\\', '/', $relativePath);
    $absolutePath = str_starts_with($relativePath, '/') ? $relativePath : $sourceDir . '/' . ltrim($relativePath, '/');
    $resolved = realpath($absolutePath);

    return array_filter([
        'type' => $type,
        'relative_path' => $relativePath,
        'absolute_path' => $resolved !== false ? $resolved : $absolutePath,
        'exists' => is_file($absolutePath),
        'file_name' => $metadata['file_name'] ?? basename($relativePath),
        'file_size' => $metadata['file_size'] ?? (is_file($absolutePath) ? filesize($absolutePath) : null),
        'mime_type' => $metadata['mime_type'] ?? null,
        'duration_seconds' => $metadata['duration_seconds'] ?? null,
        'width' => $metadata['width'] ?? null,
        'height' => $metadata['height'] ?? null,
    ], static fn (mixed $value): bool => $value !== null && $value !== '');
}

/**
 * @param list<array<string, mixed>> $media
 * @param list<string> $tickerUniverse
 * @param array<string, true> $tickerLookup
 * @param array<string, true> $cashtagOnly
 * @param array<string, string> $keywordPatterns
 * @return array<string, mixed>
 */
function buildEvent(
    string $sourceDir,
    string $sourceName,
    string $sourceType,
    string $sourceFile,
    string $messageId,
    string $dateRaw,
    string $date,
    string $author,
    string $authorId,
    string $forwardedFrom,
    string $text,
    array $media,
    array $tickerUniverse,
    array $tickerLookup,
    array $cashtagOnly,
    array $keywordPatterns,
): array {
    $tickers = extractTickers($text, $tickerUniverse, $tickerLookup, $cashtagOnly);
    $keywords = extractKeywords($text, $keywordPatterns);

    return [
        'source_dir' => $sourceDir,
        'source_name' => $sourceName,
        'source_type' => $sourceType,
        'file' => $sourceFile,
        'message_id' => $messageId,
        'date_raw' => $dateRaw,
        'date' => $date,
        'author' => $author,
        'author_id' => $authorId !== '' ? $authorId : null,
        'forwarded_from' => $forwardedFrom !== '' ? $forwardedFrom : null,
        'tickers' => $tickers,
        'keywords' => $keywords,
        'support_mentions' => extractSupportMentions($text),
        'text' => $text,
        'has_text' => $text !== '',
        'has_media' => $media !== [],
        'media_only' => $text === '' && $media !== [],
        'media' => $media,
    ];
}

/**
 * @param list<string> $tickerUniverse
 * @param array<string, true> $tickerLookup
 * @param array<string, true> $cashtagOnly
 * @return list<string>
 */
function extractTickers(string $text, array $tickerUniverse, array $tickerLookup, array $cashtagOnly): array
{
    if ($text === '') {
        return [];
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
    $result = array_values(array_filter(
        array_keys($tickers),
        static fn (string $ticker): bool => isset($tickerLookup[$ticker]),
    ));
    sort($result);

    return $result;
}

/** @param array<string, string> $patterns @return list<string> */
function extractKeywords(string $text, array $patterns): array
{
    if ($text === '') {
        return [];
    }
    $matches = [];
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $text) === 1) {
            $matches[] = $label;
        }
    }

    return $matches;
}

/** @return list<array{period:int,type:string,timeframe:string}> */
function extractSupportMentions(string $text): array
{
    $mentions = [];
    if (preg_match_all(
        '/(?:(5|10|20|21|30|50|100|150|200)\s*(EMA|ЕМА|SMA|MA|МА)\s*(?:\(?\s*(D|Д|W|Н|H|4H|4Ч|15M|15М)\s*\)?)?)/iu',
        $text,
        $matches,
        PREG_SET_ORDER,
    )) {
        foreach ($matches as $match) {
            $mentions[] = [
                'period' => (int) $match[1],
                'type' => strtoupper(str_replace(['ЕМА', 'МА'], ['EMA', 'MA'], $match[2])),
                'timeframe' => strtoupper($match[3] ?? ''),
            ];
        }
    }

    return uniqueArrays($mentions);
}

/** @param array<string, mixed> $event */
function eventIsRelevant(array $event): bool
{
    return ($event['media'] ?? []) !== [] || ($event['tickers'] ?? []) !== [] || ($event['keywords'] ?? []) !== [];
}

/** @param list<array<string, mixed>> $events @return list<array<string, mixed>> */
function initializeMirrorMetadata(array $events): array
{
    return array_map(static function (array $event): array {
        $event['dedupe_key'] = eventDedupeKey($event);
        $event['mirror_sources'] = [mirrorSource($event)];
        $event['mirror_count'] = 1;
        $event['is_mirrored'] = false;

        return $event;
    }, $events);
}

/** @param list<array<string, mixed>> $events @return list<array<string, mixed>> */
function deduplicateMirrorEvents(array $events): array
{
    $deduplicated = [];
    $indicesByKey = [];
    foreach ($events as $event) {
        $key = eventDedupeKey($event);
        $mergeIndex = null;
        foreach ($indicesByKey[$key] ?? [] as $candidateIndex) {
            if (!eventAlreadyContainsSource($deduplicated[$candidateIndex], (string) $event['source_dir'])) {
                $mergeIndex = $candidateIndex;
                break;
            }
        }

        if ($mergeIndex === null) {
            $event['dedupe_key'] = $key;
            $event['mirror_sources'] = [mirrorSource($event)];
            $event['mirror_count'] = 1;
            $event['is_mirrored'] = false;
            $deduplicated[] = $event;
            $indicesByKey[$key][] = array_key_last($deduplicated);
            continue;
        }

        $deduplicated[$mergeIndex] = mergeMirrorEvents($deduplicated[$mergeIndex], $event, $key);
    }

    return array_values($deduplicated);
}

/** @param array<string, mixed> $event */
function eventDedupeKey(array $event): string
{
    $day = (string) ($event['date'] ?? '');
    $normalizedText = mb_strtolower((string) ($event['text'] ?? ''));
    $normalizedText = str_replace('ё', 'е', $normalizedText);
    $normalizedText = preg_replace('/\s+/u', ' ', $normalizedText) ?? $normalizedText;
    $normalizedText = trim($normalizedText);
    if ($normalizedText !== '') {
        return 'text|' . $day . '|' . hash('sha256', $normalizedText);
    }

    $fingerprints = [];
    foreach (($event['media'] ?? []) as $media) {
        if (!is_array($media) || (string) ($media['type'] ?? '') === 'thumbnail') {
            continue;
        }
        $path = $media['absolute_path'] ?? null;
        if (is_string($path) && is_file($path)) {
            $fingerprints[] = hash_file('sha256', $path);
        } elseif ((string) ($media['type'] ?? '') === 'poll') {
            $fingerprints[] = hash('sha256', json_encode($media['poll'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'poll');
        } else {
            $fingerprints[] = hash('sha256', implode('|', [
                (string) ($media['type'] ?? ''),
                (string) ($media['file_size'] ?? ''),
                (string) ($media['width'] ?? ''),
                (string) ($media['height'] ?? ''),
                (string) ($media['file_name'] ?? ''),
            ]));
        }
    }
    sort($fingerprints);
    if ($fingerprints !== []) {
        return 'media|' . $day . '|' . hash('sha256', implode('|', $fingerprints));
    }

    return 'message|' . (string) ($event['source_dir'] ?? '') . '|' . (string) ($event['message_id'] ?? '');
}

/** @param array<string, mixed> $event */
function eventAlreadyContainsSource(array $event, string $sourceDir): bool
{
    foreach (($event['mirror_sources'] ?? []) as $source) {
        if (is_array($source) && (string) ($source['source_dir'] ?? '') === $sourceDir) {
            return true;
        }
    }

    return false;
}

/** @param array<string, mixed> $event @return array<string, mixed> */
function mirrorSource(array $event): array
{
    return [
        'source_dir' => $event['source_dir'] ?? null,
        'source_name' => $event['source_name'] ?? null,
        'source_type' => $event['source_type'] ?? null,
        'file' => $event['file'] ?? null,
        'message_id' => $event['message_id'] ?? null,
        'date_raw' => $event['date_raw'] ?? null,
        'author' => $event['author'] ?? null,
        'media_paths' => array_values(array_filter(array_map(
            static fn (array $media): mixed => $media['absolute_path'] ?? null,
            array_filter($event['media'] ?? [], 'is_array'),
        ))),
    ];
}

/** @param array<string, mixed> $left @param array<string, mixed> $right @return array<string, mixed> */
function mergeMirrorEvents(array $left, array $right, string $key): array
{
    $primary = primaryPreference($right) > primaryPreference($left) ? $right : $left;
    $other = $primary === $left ? $right : $left;
    $leftSources = $left['mirror_sources'] ?? [mirrorSource($left)];
    $rightSources = $right['mirror_sources'] ?? [mirrorSource($right)];
    $primary['mirror_sources'] = uniqueArrays(array_merge($leftSources, $rightSources));
    $primary['mirror_count'] = count($primary['mirror_sources']);
    $primary['is_mirrored'] = $primary['mirror_count'] > 1;
    $primary['dedupe_key'] = $key;
    $primary['media'] = uniqueMedia(array_merge($left['media'] ?? [], $right['media'] ?? []));
    $primary['has_media'] = $primary['media'] !== [];
    $primary['media_only'] = (string) ($primary['text'] ?? '') === '' && $primary['media'] !== [];
    $primary['tickers'] = uniqueSortedStrings(array_merge($left['tickers'] ?? [], $right['tickers'] ?? []));
    $primary['keywords'] = uniqueSortedStrings(array_merge($left['keywords'] ?? [], $right['keywords'] ?? []));
    $primary['support_mentions'] = uniqueArrays(array_merge($left['support_mentions'] ?? [], $right['support_mentions'] ?? []));
    if ((string) ($primary['text'] ?? '') === '' && (string) ($other['text'] ?? '') !== '') {
        $primary['text'] = $other['text'];
        $primary['has_text'] = true;
        $primary['media_only'] = false;
    }

    return $primary;
}

/** @param array<string, mixed> $event */
function primaryPreference(array $event): int
{
    $score = 0;
    if ((string) ($event['author'] ?? '') === 'FTT_Admin Official') {
        $score += 100;
    }
    if (str_contains((string) ($event['source_type'] ?? ''), 'private')) {
        $score += 20;
    }
    if ((string) ($event['text'] ?? '') !== '') {
        $score += 2;
    }
    if (($event['media'] ?? []) !== []) {
        $score++;
    }

    return $score;
}

/** @param list<array<string, mixed>> $media @return list<array<string, mixed>> */
function uniqueMedia(array $media): array
{
    $unique = [];
    foreach ($media as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = (string) ($item['absolute_path'] ?? '') . '|' . (string) ($item['type'] ?? '')
            . '|' . json_encode($item['poll'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $unique[$key] = $item;
    }

    return array_values($unique);
}

/** @param list<string> $values @return list<string> */
function uniqueSortedStrings(array $values): array
{
    $values = array_values(array_unique(array_map('strval', $values)));
    sort($values);

    return $values;
}

/** @template T of array @param list<T> $values @return list<T> */
function uniqueArrays(array $values): array
{
    $unique = [];
    foreach ($values as $value) {
        if (!is_array($value)) {
            continue;
        }
        $key = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($key !== false) {
            $unique[$key] = $value;
        }
    }

    return array_values($unique);
}

/**
 * @param list<array<string, mixed>> $events
 * @param list<array<string, mixed>> $sources
 * @return array<string, mixed>
 */
function summarizeEvents(array $events, array $sources, int $rawEventCount): array
{
    $byAuthor = [];
    $byMediaType = [];
    $mediaEvents = 0;
    $mediaOnlyEvents = 0;
    $textEvents = 0;
    $tickerEvents = 0;
    $mirroredEvents = 0;
    foreach ($events as $event) {
        $author = (string) ($event['author'] ?? '');
        if ($author !== '') {
            $byAuthor[$author] = ($byAuthor[$author] ?? 0) + 1;
        }
        if ((bool) ($event['has_text'] ?? false)) {
            $textEvents++;
        }
        if ((bool) ($event['has_media'] ?? false)) {
            $mediaEvents++;
        }
        if ((bool) ($event['media_only'] ?? false)) {
            $mediaOnlyEvents++;
        }
        if (($event['tickers'] ?? []) !== []) {
            $tickerEvents++;
        }
        if ((bool) ($event['is_mirrored'] ?? false)) {
            $mirroredEvents++;
        }
        foreach (($event['media'] ?? []) as $media) {
            if (!is_array($media)) {
                continue;
            }
            $type = (string) ($media['type'] ?? 'unknown');
            $byMediaType[$type] = ($byMediaType[$type] ?? 0) + 1;
        }
    }
    arsort($byAuthor);
    arsort($byMediaType);

    return [
        'raw_events' => $rawEventCount,
        'events' => count($events),
        'duplicates_removed' => $rawEventCount - count($events),
        'mirrored_events' => $mirroredEvents,
        'text_events' => $textEvents,
        'media_events' => $mediaEvents,
        'media_only_events' => $mediaOnlyEvents,
        'ticker_events' => $tickerEvents,
        'by_author' => $byAuthor,
        'media_items_by_type' => $byMediaType,
        'sources' => $sources,
    ];
}
