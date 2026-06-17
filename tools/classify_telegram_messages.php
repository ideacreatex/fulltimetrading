#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = [
    'input' => __DIR__ . '/../var/reports/telegram_setups.json',
    'output' => __DIR__ . '/../var/reports/telegram_classified.json',
    'authors' => '',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$payload = json_decode((string) file_get_contents((string) $options['input']), true, 512, JSON_THROW_ON_ERROR);
$events = $payload['events'] ?? [];
if (!is_array($events)) {
    throw new RuntimeException('Input has no events array.');
}

$authors = array_values(array_filter(array_map('trim', explode(',', (string) $options['authors']))));
$authorLookup = $authors !== [] ? array_fill_keys($authors, true) : [];

$classifiedEvents = [];
foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }
    if ($authorLookup !== [] && !isset($authorLookup[(string) ($event['author'] ?? '')])) {
        continue;
    }
    $classification = classifyEvent($event);
    $event['message_type'] = $classification['primary_type'];
    $event['message_types'] = $classification['types'];
    $event['classification_scores'] = $classification['scores'];
    $event['classification_reasons'] = $classification['reasons'];
    $classifiedEvents[] = $event;
}

$summary = summarize($classifiedEvents);
$result = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'input' => $options['input'],
    'authors' => $authors,
    'event_count' => count($classifiedEvents),
    'summary' => $summary,
    'events' => $classifiedEvents,
];

$output = (string) $options['output'];
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create output directory: ' . $dir);
}
file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "Report: {$output}\n";

/** @param array<string, mixed> $event @return array{primary_type:string, types:list<string>, scores:array<string,int>, reasons:array<string,list<string>>} */
function classifyEvent(array $event): array
{
    $text = normalizeText((string) ($event['text'] ?? ''));
    $tickers = array_map('strtoupper', $event['tickers'] ?? []);
    $supportMentions = $event['support_mentions'] ?? [];

    $patterns = [
        'exit' => [
            'закрываем', 'закрыл', 'закрыла', 'закрываю', 'закрытие позиции', 'вышел', 'вышла', 'выхожу',
            'фиксир', 'зафикс', 'продал', 'продаю', 'снял позицию', 'нарушена стратегия', 'стратегия наруш',
            'сломалась стратегия', 'выход', 'exit', 'sell',
        ],
        'stop_to_breakeven' => [
            'стоп в бу', 'стопы в бу', 'стоп в ноль', 'стопы в ноль', 'безубыт', 'без убыт',
            'бу ', ' бу', 'break even', 'breakeven',
        ],
        'add' => [
            'докуп', 'добав', 'добира', 'нагруж', 'увелич', 'усил', 'долив', 'долива',
            'плеч', 'leverage', 'load', 'add',
        ],
        'entry' => [
            'взял', 'беру', 'купил', 'купила', 'покупаю', 'покупка', 'вход', 'точка входа',
            'зашел', 'зашла', 'открываю', 'открыл', 'открыла', 'лонг', 'long', 'сигнал на вход',
        ],
        'hold' => [
            'держу', 'держим', 'держать', 'оставляю', 'оставляем', 'не закрываем', 'не трогаем',
            'позиция открыта', 'позиции открыты', 'сидим', 'портфель',
        ],
        'risk_context' => [
            'vix', 'vvix', 'dxy', 'us20y', 'm2sl', 'pcc', 'pcsp', 'рынок', 'индекс', 'индексы',
            'ширина рынка', 'breadth', 'волатильн', 'страх', 'нестабил', 'опасн', 'риск',
            'regular session', 'премаркет', 'постмаркет',
        ],
        'setup_analysis' => [
            'поос', 'поддерж', 'сопротив', 'закономер', 'ema', 'ема', 'sma', 'ma ', 'скольз',
            'rsi', 'macd', 'молот', 'поглощ', 'фитил', 'уров', 'перепрод', 'отскок',
        ],
    ];

    $scores = array_fill_keys(array_keys($patterns), 0);
    $reasons = array_fill_keys(array_keys($patterns), []);
    foreach ($patterns as $type => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                $scores[$type]++;
                $reasons[$type][] = $needle;
            }
        }
    }

    if ($supportMentions !== []) {
        $scores['setup_analysis'] += 2;
        $reasons['setup_analysis'][] = 'support_mentions';
    }
    if (array_intersect($tickers, ['VIX', 'VVIX', 'DXY', 'US20Y', 'M2SL', 'PCC', 'PCSP', 'NYA', 'SX5E', 'SXXP']) !== []) {
        $scores['risk_context'] += 2;
        $reasons['risk_context'][] = 'context_ticker';
    }
    if (array_intersect($tickers, ['SPY', 'SPX', 'ES', 'QQQ', 'NQ', 'NDX', 'IXIC', 'RSP', 'SMH', 'DIA', 'YM']) !== []) {
        $scores['risk_context'] += 1;
        $reasons['risk_context'][] = 'market_ticker';
    }

    $types = [];
    foreach ($scores as $type => $score) {
        if ($score > 0) {
            $types[] = $type;
        }
    }
    if ($types === []) {
        $types[] = 'other';
        $scores['other'] = 1;
        $reasons['other'] = ['no_rule_match'];
    }

    $primary = primaryType($scores);

    return [
        'primary_type' => $primary,
        'types' => $types,
        'scores' => $scores,
        'reasons' => array_filter($reasons, static fn (array $items): bool => $items !== []),
    ];
}

/** @param array<string, int> $scores */
function primaryType(array $scores): string
{
    $priority = [
        'exit',
        'stop_to_breakeven',
        'add',
        'entry',
        'hold',
        'risk_context',
        'setup_analysis',
        'other',
    ];

    foreach ($priority as $type) {
        if ((int) ($scores[$type] ?? 0) > 0) {
            return $type;
        }
    }

    return 'other';
}

function normalizeText(string $text): string
{
    $text = mb_strtolower($text);
    $text = str_replace('ё', 'е', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

/** @param list<array<string, mixed>> $events @return array<string, mixed> */
function summarize(array $events): array
{
    $byPrimary = [];
    $byType = [];
    $byAuthor = [];
    foreach ($events as $event) {
        $primary = (string) ($event['message_type'] ?? 'other');
        $byPrimary[$primary] = ($byPrimary[$primary] ?? 0) + 1;
        foreach (($event['message_types'] ?? []) as $type) {
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }
        $author = (string) ($event['author'] ?? '');
        if ($author !== '') {
            $byAuthor[$author][$primary] = ($byAuthor[$author][$primary] ?? 0) + 1;
        }
    }
    arsort($byPrimary);
    arsort($byType);
    foreach ($byAuthor as &$counts) {
        arsort($counts);
    }
    unset($counts);
    ksort($byAuthor);

    return [
        'events' => count($events),
        'by_primary_type' => $byPrimary,
        'by_any_type' => $byType,
        'by_author_primary_type' => $byAuthor,
    ];
}
