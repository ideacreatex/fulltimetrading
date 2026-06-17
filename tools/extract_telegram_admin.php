#!/usr/bin/env php
<?php

declare(strict_types=1);

$dir = $argv[1] ?? __DIR__ . '/../materials/telegram_export/ChatExport_2026-06-13 (1)';
$author = $argv[2] ?? 'FTT_Admin Official';

if (!is_dir($dir)) {
    fwrite(STDERR, "Directory not found: {$dir}\n");
    exit(1);
}

$files = glob(rtrim($dir, '/') . '/messages*.html') ?: [];
sort($files, SORT_NATURAL);

$messages = [];
foreach ($files as $file) {
    $html = file_get_contents($file);
    if ($html === false) {
        continue;
    }

    $parts = preg_split('/<div class="message /', $html) ?: [];
    foreach ($parts as $part) {
        if (!str_contains($part, '<div class="from_name">')) {
            continue;
        }

        if (!preg_match('/id="(message\d+)"/', $part, $idMatch)) {
            continue;
        }
        if (!preg_match('/<div class="pull_right date details" title="([^"]+)"/', $part, $dateMatch)) {
            continue;
        }
        if (!preg_match('/<div class="from_name">\s*(.*?)\s*<\/div>/s', $part, $fromMatch)) {
            continue;
        }

        $from = trim(html_entity_decode(strip_tags($fromMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($from !== $author) {
            continue;
        }

        $text = '';
        if (preg_match('/<div class="text">\s*(.*?)\s*<\/div>/s', $part, $textMatch)) {
            $text = $textMatch[1];
            $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
            $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
            $text = trim($text);
        }

        if ($text === '') {
            continue;
        }

        $messages[] = [
            'file' => basename($file),
            'id' => $idMatch[1],
            'date' => $dateMatch[1],
            'author' => $from,
            'text' => $text,
        ];
    }
}

$keywords = [
    'панель', 'прибор', 'поос', 'ema', 'rsi', 'spy', 'qqq', 'smh', 'vix', 'dxy',
    'широт', 's5', 'nd', 'сезон', 'стоп', 'atr', 'просад', 'шарп', 'сортино',
    'бенчмарк', 'sp500', 'пуллбэк', 'подхват',
];

$filtered = array_values(array_filter(
    $messages,
    static function (array $message) use ($keywords): bool {
        $text = mb_strtolower((string) $message['text']);
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    },
));

$out = [
    'author' => $author,
    'total_messages' => count($messages),
    'filtered_messages' => count($filtered),
    'messages' => $filtered,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
