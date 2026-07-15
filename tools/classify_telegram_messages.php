#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Support\TelegramMessageClassifier;

require __DIR__ . '/../bootstrap.php';

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
$classifier = new TelegramMessageClassifier();

$classifiedEvents = [];
foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }
    if ($authorLookup !== [] && !isset($authorLookup[(string) ($event['author'] ?? '')])) {
        continue;
    }
    $classification = $classifier->classify($event);
    $event['message_type'] = $classification['primary_type'];
    $event['message_types'] = $classification['types'];
    $event['classification_scores'] = $classification['scores'];
    $event['classification_reasons'] = $classification['reasons'];
    $event['message_action'] = $classification['action'];
    $event['verified_real_action'] = $classification['verified_real_action'];
    $event['action_verification_reason'] = $classification['action_verification_reason'];
    $event['market_direction'] = $classification['market_direction'];
    $event['direction_scores'] = $classification['direction_scores'];
    $event['direction_reasons'] = $classification['direction_reasons'];
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

/** @param list<array<string, mixed>> $events @return array<string, mixed> */
function summarize(array $events): array
{
    $byPrimary = [];
    $byType = [];
    $byAuthor = [];
    $byAction = [];
    $byVerifiedAction = [];
    $byDirection = [];
    $verifiedRealActions = 0;
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
        $action = (string) ($event['message_action'] ?? 'other');
        $direction = (string) ($event['market_direction'] ?? 'neutral');
        $byAction[$action] = ($byAction[$action] ?? 0) + 1;
        if (($event['verified_real_action'] ?? false) === true) {
            $verifiedRealActions++;
            $byVerifiedAction[$action] = ($byVerifiedAction[$action] ?? 0) + 1;
        }
        $byDirection[$direction] = ($byDirection[$direction] ?? 0) + 1;
    }
    arsort($byPrimary);
    arsort($byType);
    arsort($byAction);
    arsort($byVerifiedAction);
    arsort($byDirection);
    foreach ($byAuthor as &$counts) {
        arsort($counts);
    }
    unset($counts);
    ksort($byAuthor);

    return [
        'events' => count($events),
        'by_primary_type' => $byPrimary,
        'by_any_type' => $byType,
        'by_action' => $byAction,
        'verified_real_actions' => $verifiedRealActions,
        'by_verified_action' => $byVerifiedAction,
        'by_market_direction' => $byDirection,
        'by_author_primary_type' => $byAuthor,
    ];
}
