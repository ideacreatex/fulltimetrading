#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Notifications\TelegramNotifier;

require __DIR__ . '/../bootstrap.php';

$options = [
    'provider' => 'alpaca',
    'profile' => 'tuned-daily',
    'symbols' => '',
    'symbols-file' => '',
    'feed' => '',
    'cache-namespace' => '',
    'start' => '2021-01-01',
    'end' => (new DateTimeImmutable('today'))->format('Y-m-d'),
    'as-of' => '',
    'refresh-report' => 'true',
    'monitor' => 'true',
    'report-output' => __DIR__ . '/../var/reports/daily/alpaca_selected_best_partial_live_signal_report.json',
    'text-output' => __DIR__ . '/../var/reports/daily/alpaca_selected_best_partial_live_signal_report.txt',
    'plan-output' => __DIR__ . '/../var/reports/daily/latest_paper_order_plan_cycle.json',
    'monitor-output' => __DIR__ . '/../var/reports/daily/latest_paper_monitor_cycle.json',
    'cycle-output' => __DIR__ . '/../var/reports/daily/latest_paper_cycle.json',
    'submit' => 'false',
    'telegram' => 'true',
    'max-orders' => '',
    'min-score' => '0',
    'model-open-counts' => 'false',
    'ignore-model-open' => 'true',
    'paper-open-counts' => 'true',
    'paper-sync-required' => 'true',
    'allow-layered' => 'false',
    'dedupe' => 'true',
    'force' => 'false',
    'max-gross-exposure-pct' => '',
    'max-open-positions' => '',
    'family-cap' => '',
    'reentry-cooldown-days' => '',
    'allow-same-strength-after-days' => '',
    'break-even-add-on-fraction' => '',
    'swing-stop-mode' => '',
    'partial-take-profit-pct' => '',
    'break-even-profit-pct' => '',
    'order-valid-bars' => '',
    'order-fill-mode' => '',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$startedAt = new DateTimeImmutable();
$steps = [];

if (boolOption((string) $options['refresh-report'])) {
    $reportArgs = [
        PHP_BINARY,
        __DIR__ . '/daily_signal_report.php',
        '--provider=' . (string) $options['provider'],
        '--start=' . (string) $options['start'],
        '--end=' . (string) $options['end'],
        '--output=' . (string) $options['report-output'],
        '--text-output=' . (string) $options['text-output'],
        '--telegram=false',
        '--include-account=true',
    ];
    foreach (reportUniverseArgs($options) as $arg) {
        $reportArgs[] = $arg;
    }
    if ((string) $options['as-of'] !== '') {
        $reportArgs[] = '--as-of=' . (string) $options['as-of'];
    }
    foreach (strategyProfileArgs($options) as $arg) {
        $reportArgs[] = $arg;
    }
    $steps[] = runStep('daily_signal_report', $reportArgs);
}

$planArgs = [
    PHP_BINARY,
    __DIR__ . '/paper_order_plan.php',
    '--report=' . (string) $options['report-output'],
    '--output=' . (string) $options['plan-output'],
    '--submit=' . (string) $options['submit'],
    '--telegram=false',
    '--min-score=' . (string) $options['min-score'],
    '--model-open-counts=' . (string) $options['model-open-counts'],
    '--ignore-model-open=' . (string) $options['ignore-model-open'],
    '--paper-open-counts=' . (string) $options['paper-open-counts'],
    '--paper-sync-required=' . (string) $options['paper-sync-required'],
    '--allow-layered=' . (string) $options['allow-layered'],
    '--dedupe=' . (string) $options['dedupe'],
    '--force=' . (string) $options['force'],
];
if ((string) $options['max-orders'] !== '') {
    $planArgs[] = '--max-orders=' . (string) $options['max-orders'];
}
$steps[] = runStep('paper_order_plan', $planArgs);

$monitorArgs = [
    PHP_BINARY,
    __DIR__ . '/paper_position_monitor.php',
    '--report=' . (string) $options['report-output'],
    '--output=' . (string) $options['monitor-output'],
    '--submit=' . (string) $options['submit'],
    '--telegram=false',
];
if (boolOption((string) $options['monitor'])) {
    $steps[] = runStep('paper_position_monitor', $monitorArgs);
}

$dailySummary = dailyReportSummary((string) $options['report-output']);
$planSummary = orderPlanSummary((string) $options['plan-output']);
$monitorSummary = monitorSummary((string) $options['monitor-output']);
$referenceSummary = referenceSummary((string) $options['profile']);

$payload = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'started_at' => $startedAt->format(DateTimeInterface::ATOM),
    'finished_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'submit_requested' => boolOption((string) $options['submit']),
    'profile' => (string) $options['profile'],
    'report_output' => (string) $options['report-output'],
    'plan_output' => (string) $options['plan-output'],
    'monitor_output' => (string) $options['monitor-output'],
    'daily_summary' => $dailySummary,
    'order_plan_summary' => $planSummary,
    'monitor_summary' => $monitorSummary,
    'reference_summary' => $referenceSummary,
    'steps' => $steps,
    'ok' => allStepsOk($steps),
];

writeJson((string) $options['cycle-output'], $payload);
$text = cycleText($payload);
echo $text . "\n";
echo "Cycle report: " . (string) $options['cycle-output'] . "\n";

if (boolOption((string) $options['telegram'])) {
    $notifier = TelegramNotifier::fromEnv(new HttpClient());
    if ($notifier === null) {
        echo "Telegram warning: missing TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID\n";
    } else {
        try {
            $notifier->sendMessage($text, (bool) $payload['ok']);
            echo "Telegram message sent\n";
        } catch (Throwable $e) {
            echo "Telegram warning: " . $e->getMessage() . "\n";
        }
    }
}

exit((bool) $payload['ok'] ? 0 : 1);

/** @param list<string> $command @return array<string, mixed> */
function runStep(string $name, array $command): array
{
    $startedAt = new DateTimeImmutable();
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return [
            'name' => $name,
            'ok' => false,
            'exit_code' => 127,
            'started_at' => $startedAt->format(DateTimeInterface::ATOM),
            'finished_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'stdout_tail' => '',
            'stderr_tail' => 'Unable to start process.',
        ];
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'name' => $name,
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'started_at' => $startedAt->format(DateTimeInterface::ATOM),
        'finished_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'stdout_tail' => tailText((string) $stdout),
        'stderr_tail' => tailText((string) $stderr),
    ];
}

/** @param list<array<string, mixed>> $steps */
function allStepsOk(array $steps): bool
{
    foreach ($steps as $step) {
        if (empty($step['ok'])) {
            return false;
        }
    }

    return true;
}

function tailText(string $text): string
{
    $text = trim($text);
    if (strlen($text) <= 1200) {
        return $text;
    }

    return substr($text, -1200);
}

/** @return array<string, mixed> */
function dailyReportSummary(string $path): array
{
    $payload = readJsonIfExists($path);
    if ($payload === []) {
        return [];
    }
    $summary = is_array($payload['model']['summary'] ?? null) ? $payload['model']['summary'] : [];
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

    return [
        'as_of' => (string) ($payload['as_of'] ?? ''),
        'action' => (string) ($payload['action'] ?? ''),
        'symbols_loaded' => (int) ($data['symbols_loaded'] ?? 0),
        'symbols_requested' => (int) ($data['symbols_requested'] ?? 0),
        'data_age_days' => (int) ($data['data_age_days'] ?? -1),
        'signals_today' => is_array($payload['signals_today'] ?? null) ? count($payload['signals_today']) : 0,
        'model_open_positions' => is_array($payload['model']['open_positions'] ?? null) ? count($payload['model']['open_positions']) : 0,
        'return_pct' => (float) ($summary['return_pct'] ?? 0.0),
        'annualized_return_pct' => (float) ($summary['annualized_return_pct'] ?? 0.0),
        'max_drawdown_pct' => (float) ($summary['max_drawdown_pct'] ?? 0.0),
        'profit_factor' => $summary['profit_factor'] ?? null,
    ];
}

/** @return array<string, mixed> */
function orderPlanSummary(string $path): array
{
    $payload = readJsonIfExists($path);
    $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : [];
    if ($plan === []) {
        return [];
    }

    return [
        'orders' => is_array($plan['orders'] ?? null) ? count($plan['orders']) : 0,
        'skipped' => is_array($plan['skipped'] ?? null) ? count($plan['skipped']) : 0,
        'submitted' => (int) ($payload['submitted_count'] ?? 0),
        'available_slots' => (int) ($plan['available_slots'] ?? 0),
        'slot_budget' => (float) ($plan['slot_budget'] ?? 0.0),
        'paper_positions_count' => (int) ($plan['paper_positions_count'] ?? 0),
        'paper_open_orders_count' => (int) ($plan['paper_open_orders_count'] ?? 0),
        'maintenance_limit' => $plan['maintenance_limit'] ?? null,
        'estimated_maintenance_used' => $plan['estimated_maintenance_used'] ?? null,
        'estimated_maintenance_pct_of_equity' => $plan['estimated_maintenance_pct_of_equity'] ?? null,
        'symbols' => array_values(array_map(
            static fn (array $order): string => (string) ($order['symbol'] ?? ''),
            is_array($plan['orders'] ?? null) ? $plan['orders'] : [],
        )),
    ];
}

/** @return array<string, mixed> */
function monitorSummary(string $path): array
{
    $payload = readJsonIfExists($path);
    if ($payload === []) {
        return [];
    }

    return [
        'market_open' => (bool) ($payload['market_open'] ?? false),
        'positions_count' => (int) ($payload['positions_count'] ?? 0),
        'open_orders_count' => (int) ($payload['open_orders_count'] ?? 0),
        'actions' => is_array($payload['actions'] ?? null) ? count($payload['actions']) : 0,
        'submitted_orders' => is_array($payload['submitted_orders'] ?? null) ? count($payload['submitted_orders']) : 0,
    ];
}

/** @return array<string, mixed> */
function referenceSummary(string $profile): array
{
    $path = match (strtolower($profile)) {
        'tuned-daily' => __DIR__ . '/../var/reports/alpaca_selected_30000_be_sweep_wide/best_score_report.json',
        'best-consistent' => __DIR__ . '/../var/reports/alpaca_selected_30000_be_sweep_wide/best_consistent_40_35_report.json',
        default => '',
    };
    if ($path === '') {
        return [];
    }
    $payload = readJsonIfExists($path);
    $summary = is_array($payload['report']['summary'] ?? null) ? $payload['report']['summary'] : [];
    if ($summary === []) {
        return [];
    }

    return [
        'path' => $path,
        'return_pct' => (float) ($summary['return_pct'] ?? 0.0),
        'annualized_return_pct' => (float) ($summary['annualized_return_pct'] ?? 0.0),
        'max_drawdown_pct' => (float) ($summary['max_drawdown_pct'] ?? 0.0),
        'profit_factor' => $summary['profit_factor'] ?? null,
        'trades' => (int) ($summary['trades'] ?? 0),
    ];
}

/** @return array<string, mixed> */
function readJsonIfExists(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $payload = json_decode((string) file_get_contents($path), true);

    return is_array($payload) ? $payload : [];
}

/** @param array<string, string> $options @return list<string> */
function reportUniverseArgs(array $options): array
{
    $profile = strtolower((string) ($options['profile'] ?? 'tuned-daily'));
    $symbols = (string) ($options['symbols'] ?? '');
    $symbolsFile = (string) ($options['symbols-file'] ?? '');
    if ($symbols === '' && $symbolsFile === '') {
        $symbols = match ($profile) {
            'tuned-daily', 'best-consistent' => 'UPRO,TQQQ,SOXL,USD,TECL',
            'leverage-growth', 'default' => '',
            default => '',
        };
    }
    $feed = (string) ($options['feed'] ?? '');
    $cacheNamespace = (string) ($options['cache-namespace'] ?? '');
    if (in_array($profile, ['tuned-daily', 'best-consistent'], true)) {
        $feed = $feed !== '' ? $feed : 'iex';
        $cacheNamespace = $cacheNamespace !== '' ? $cacheNamespace : 'alpaca-param-experiment-iex';
    }

    $args = [];
    if ($symbols !== '') {
        $args[] = '--symbols=' . $symbols;
    }
    if ($symbolsFile !== '') {
        $args[] = '--symbols-file=' . $symbolsFile;
    }
    if ($feed !== '') {
        $args[] = '--feed=' . $feed;
    }
    if ($cacheNamespace !== '') {
        $args[] = '--cache-namespace=' . $cacheNamespace;
    }

    return $args;
}

/** @param array<string, string> $options @return list<string> */
function strategyProfileArgs(array $options): array
{
    $profile = strtolower((string) ($options['profile'] ?? 'tuned-daily'));
    $profileValues = match ($profile) {
        'tuned-daily' => [
            'max-gross-exposure-pct' => '2.0',
            'max-open-positions' => '4',
            'family-cap' => '1.00',
            'reentry-cooldown-days' => '0',
            'allow-same-strength-after-days' => '45',
            'break-even-add-on-fraction' => '0',
            'swing-stop-mode' => 'mental',
            'partial-take-profit-pct' => '0.25',
            'break-even-profit-pct' => '0.02',
            'order-valid-bars' => '10',
            'order-fill-mode' => 'same_day_touch',
        ],
        'best-consistent' => [
            'max-gross-exposure-pct' => '2.5',
            'max-open-positions' => '5',
            'family-cap' => '0.85',
            'reentry-cooldown-days' => '0',
            'allow-same-strength-after-days' => '45',
            'break-even-add-on-fraction' => '0',
            'swing-stop-mode' => 'mental',
            'partial-take-profit-pct' => '0.25',
            'break-even-profit-pct' => '0.02',
            'order-valid-bars' => '10',
            'order-fill-mode' => 'same_day_touch',
        ],
        'leverage-growth' => [
            'max-gross-exposure-pct' => '3.5',
            'max-open-positions' => '5',
            'family-cap' => '1.5',
            'reentry-cooldown-days' => '0',
            'allow-same-strength-after-days' => '30',
            'break-even-add-on-fraction' => '0',
            'swing-stop-mode' => 'mental',
            'partial-take-profit-pct' => '0.25',
            'break-even-profit-pct' => '0.02',
            'order-valid-bars' => '10',
            'order-fill-mode' => 'same_day_touch',
        ],
        'default' => [],
        default => throw new RuntimeException('Unknown paper-cycle profile: ' . $profile),
    };

    $args = [];
    foreach ([
        'max-gross-exposure-pct',
        'max-open-positions',
        'family-cap',
        'reentry-cooldown-days',
        'allow-same-strength-after-days',
        'break-even-add-on-fraction',
        'swing-stop-mode',
        'partial-take-profit-pct',
        'break-even-profit-pct',
        'order-valid-bars',
        'order-fill-mode',
    ] as $key) {
        $value = (string) ($options[$key] ?? '');
        if ($value === '' && isset($profileValues[$key])) {
            $value = $profileValues[$key];
        }
        if ($value !== '') {
            $args[] = '--' . $key . '=' . $value;
        }
    }

    return $args;
}

/** @param array<string, mixed> $payload */
function writeJson(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create output directory: ' . $dir);
    }
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/** @param array<string, mixed> $payload */
function cycleText(array $payload): string
{
    $lines = [
        'FTT paper daily cycle',
        'Status: ' . (!empty($payload['ok']) ? 'OK' : 'ERROR'),
        'Mode: ' . (!empty($payload['submit_requested']) ? 'submit' : 'dry-run'),
        'Profile: ' . (string) ($payload['profile'] ?? ''),
    ];
    $daily = is_array($payload['daily_summary'] ?? null) ? $payload['daily_summary'] : [];
    if ($daily !== []) {
        $lines[] = sprintf(
            'Model: total %+0.2f%%, ann %+0.2f%%, DD %0.2f%%, signals %d, loaded %d/%d',
            (float) ($daily['return_pct'] ?? 0.0) * 100,
            (float) ($daily['annualized_return_pct'] ?? 0.0) * 100,
            (float) ($daily['max_drawdown_pct'] ?? 0.0) * 100,
            (int) ($daily['signals_today'] ?? 0),
            (int) ($daily['symbols_loaded'] ?? 0),
            (int) ($daily['symbols_requested'] ?? 0),
        );
    }
    $plan = is_array($payload['order_plan_summary'] ?? null) ? $payload['order_plan_summary'] : [];
    if ($plan !== []) {
        $lines[] = sprintf(
            'Plan: orders %d, skipped %d, slots %d, paper positions %d, open orders %d',
            (int) ($plan['orders'] ?? 0),
            (int) ($plan['skipped'] ?? 0),
            (int) ($plan['available_slots'] ?? 0),
            (int) ($plan['paper_positions_count'] ?? 0),
            (int) ($plan['paper_open_orders_count'] ?? 0),
        );
        if (($plan['estimated_maintenance_pct_of_equity'] ?? null) !== null) {
            $lines[] = sprintf(
                'Maintenance estimate: %0.2f%% of equity, limit $%0.2f',
                (float) ($plan['estimated_maintenance_pct_of_equity'] ?? 0.0) * 100,
                (float) ($plan['maintenance_limit'] ?? 0.0),
            );
        }
        $symbols = is_array($plan['symbols'] ?? null) ? array_filter($plan['symbols']) : [];
        if ($symbols !== []) {
            $lines[] = 'Plan symbols: ' . implode(', ', $symbols);
        }
    }
    $reference = is_array($payload['reference_summary'] ?? null) ? $payload['reference_summary'] : [];
    if ($reference !== []) {
        $lines[] = sprintf(
            'Reference: total %+0.2f%%, ann %+0.2f%%, DD %0.2f%%, PF %s',
            (float) ($reference['return_pct'] ?? 0.0) * 100,
            (float) ($reference['annualized_return_pct'] ?? 0.0) * 100,
            (float) ($reference['max_drawdown_pct'] ?? 0.0) * 100,
            (string) ($reference['profit_factor'] ?? ''),
        );
    }
    foreach (($payload['steps'] ?? []) as $step) {
        if (!is_array($step)) {
            continue;
        }
        $lines[] = sprintf(
            '%s: %s exit %d',
            (string) ($step['name'] ?? 'step'),
            !empty($step['ok']) ? 'OK' : 'ERROR',
            (int) ($step['exit_code'] ?? -1),
        );
        $stderr = trim((string) ($step['stderr_tail'] ?? ''));
        if ($stderr !== '') {
            $lines[] = '  ' . preg_replace('/\s+/', ' ', substr($stderr, 0, 240));
        }
    }

    return implode("\n", $lines);
}

function boolOption(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
}
