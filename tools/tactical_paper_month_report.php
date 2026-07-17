#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Notifications\TelegramNotifier;
use FulltimeTrading\Storage\TacticalPaperRepository;
use FulltimeTrading\Support\Config;

require __DIR__ . '/../bootstrap.php';

$options = [
    'output' => __DIR__ . '/../var/reports/tactical_rotation/paper_month_report.json',
    'markdown' => __DIR__ . '/../var/reports/tactical_rotation/paper_month_report.md',
    'telegram' => 'false',
    'db' => '',
];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $options[$key] = $value;
    }
}
$root = dirname(__DIR__);
$config = Config::fromFile($root . '/config/config.php');
$paper = require $root . '/config/tactical_paper.php';
$runId = (string) $paper['run_id'];
$repo = new TacticalPaperRepository(
    trim((string) $options['db']) !== '' ? (string) $options['db'] : (string) $config->get('database_path'),
);
$repo->migrate();
$run = $repo->run($runId);
$now = new DateTimeImmutable();

if ($run === null) {
    $report = [
        'generated_at' => $now->format(DateTimeInterface::ATOM),
        'run_id' => $runId,
        'status' => 'not_started',
        'eligible_for_human_live_review' => false,
        'failed_gates' => ['paper_run_not_started'],
    ];
} else {
    $since = is_string($run['activated_at'] ?? null) ? (string) $run['activated_at'] : null;
    $snapshots = $since !== null ? $repo->snapshots($runId, $since) : [];
    $intents = $repo->intents($runId, 10000);
    $equities = array_map(static fn (array $row): float => (float) $row['equity'], $snapshots);
    $peak = null;
    $maxDrawdown = 0.0;
    foreach ($equities as $equity) {
        $peak = $peak === null ? $equity : max($peak, $equity);
        if ($peak > 0.0) {
            $maxDrawdown = min($maxDrawdown, $equity / $peak - 1.0);
        }
    }
    $initialEquity = (float) ($run['initial_equity'] ?? 0.0);
    $latestEquity = $equities === [] ? $initialEquity : $equities[array_key_last($equities)];
    $return = $initialEquity > 0.0 ? $latestEquity / $initialEquity - 1.0 : 0.0;
    $activeIntents = $repo->activeIntents($runId);
    $rejected = array_values(array_filter($intents, static fn (array $row): bool => in_array(
        strtolower((string) ($row['status'] ?? '')),
        ['rejected', 'expired'],
        true,
    )));
    $errorSnapshots = array_values(array_filter($snapshots, static function (array $row): bool {
        $status = (string) ($row['reconciliation_status'] ?? '');
        $payloadErrors = is_array($row['payload']['errors'] ?? null)
            ? array_values(array_filter($row['payload']['errors'], static fn (mixed $error): bool => trim((string) $error) !== ''))
            : [];

        return str_starts_with($status, 'blocked_')
            || str_starts_with($status, 'paused_')
            || $payloadErrors !== [];
    }));
    $completedExitIntents = array_values(array_filter($intents, static function (array $row): bool {
        $requested = (float) ($row['requested_qty'] ?? 0.0);

        return strtolower((string) ($row['side'] ?? '')) === 'sell'
            && strtolower((string) ($row['status'] ?? '')) === 'filled'
            && $requested > 0.0
            && (float) ($row['cumulative_filled_qty'] ?? 0.0) + 1.0e-9 >= $requested;
    }));
    $completedExitEpisodes = [];
    foreach ($completedExitIntents as $intent) {
        $completedExitEpisodes[
            (string) ($intent['scheduled_session'] ?? '') . '|' . strtoupper((string) ($intent['symbol'] ?? ''))
        ] = true;
    }
    $dates = [];
    foreach ($snapshots as $snapshot) {
        $dates[substr((string) $snapshot['captured_at'], 0, 10)] = true;
    }
    $weeklyEquity = [];
    foreach ($snapshots as $snapshot) {
        try {
            $week = (new DateTimeImmutable((string) $snapshot['captured_at']))->format('o-W');
        } catch (Throwable) {
            continue;
        }
        $equity = (float) ($snapshot['equity'] ?? 0.0);
        if (!isset($weeklyEquity[$week])) {
            $weeklyEquity[$week] = ['first' => $equity, 'last' => $equity];
        } else {
            $weeklyEquity[$week]['last'] = $equity;
        }
    }
    $weeklyGains = array_map(
        static fn (array $week): float => (float) $week['last'] - (float) $week['first'],
        $weeklyEquity,
    );
    $positiveWeeklyGains = array_values(array_filter($weeklyGains, static fn (float $gain): bool => $gain > 0.01));
    $positiveWeeklyGainTotal = array_sum($positiveWeeklyGains);
    $topPositiveWeekShare = $positiveWeeklyGainTotal > 0.0
        ? max($positiveWeeklyGains) / $positiveWeeklyGainTotal
        : 1.0;
    $elapsedDays = $since === null ? 0 : (int) (new DateTimeImmutable($since))->diff($now)->days;
    $failed = [];
    if ((string) $run['status'] !== 'active') {
        $failed[] = 'run_not_active';
    }
    if ($now->format('Y-m-d') < (string) $paper['live_review_not_before']) {
        $failed[] = 'observation_window_not_finished';
    }
    if ($elapsedDays < 31 || count($dates) < 20) {
        $failed[] = 'insufficient_forward_observation';
    }
    if ($activeIntents !== []) {
        $failed[] = 'unresolved_order_intents';
    }
    if (trim((string) ($run['last_error_code'] ?? '')) !== '') {
        $failed[] = 'run_has_unresolved_error';
    }
    if ($rejected !== []) {
        $failed[] = 'rejected_or_expired_orders';
    }
    if ($snapshots === [] || count($errorSnapshots) / max(1, count($snapshots)) > 0.01) {
        $failed[] = 'reconciliation_error_rate_above_one_percent';
    }
    if (count($completedExitEpisodes) < 2) {
        $failed[] = 'fewer_than_two_completed_exit_episodes';
    }
    if (count($weeklyEquity) < 4 || count($positiveWeeklyGains) < 3) {
        $failed[] = 'insufficient_weekly_consistency';
    }
    if ($topPositiveWeekShare > 0.70) {
        $failed[] = 'single_positive_week_concentration_above_70_percent';
    }
    if ($maxDrawdown < -0.35) {
        $failed[] = 'forward_drawdown_above_35_percent';
    }
    if ($return <= 0.0) {
        $failed[] = 'forward_month_not_profitable';
    }
    $report = [
        'generated_at' => $now->format(DateTimeInterface::ATOM),
        'run_id' => $runId,
        'profile' => $run['profile'],
        'status' => $run['status'],
        'activated_at' => $run['activated_at'],
        'live_review_not_before' => $paper['live_review_not_before'],
        'elapsed_calendar_days' => $elapsedDays,
        'observed_dates' => count($dates),
        'snapshots' => count($snapshots),
        'initial_equity' => $initialEquity,
        'latest_equity' => $latestEquity,
        'return' => $return,
        'max_drawdown' => $maxDrawdown,
        'orders' => [
            'total_intents' => count($intents),
            'active_intents' => count($activeIntents),
            'rejected_or_expired' => count($rejected),
            'completed_exit_intents' => count($completedExitIntents),
            'completed_exit_episodes' => count($completedExitEpisodes),
        ],
        'reconciliation' => [
            'error_snapshots' => count($errorSnapshots),
            'error_rate' => count($errorSnapshots) / max(1, count($snapshots)),
        ],
        'weekly_consistency' => [
            'observed_weeks' => count($weeklyEquity),
            'positive_weeks' => count($positiveWeeklyGains),
            'top_positive_week_share' => $topPositiveWeekShare,
        ],
        'eligible_for_human_live_review' => $failed === [],
        'failed_gates' => $failed,
        'live_trading_automatically_enabled' => false,
    ];
}

$markdown = tacticalMonthMarkdown($report);
tacticalMonthWrite((string) $options['output'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
tacticalMonthWrite((string) $options['markdown'], $markdown . "\n");
echo $markdown . "\n";

if (tacticalMonthBool((string) $options['telegram'])) {
    $notifier = TelegramNotifier::fromEnv(new HttpClient());
    if ($notifier !== null) {
        $notifier->sendMessage($markdown);
    }
}

function tacticalMonthMarkdown(array $report): string
{
    $lines = ['# Hybrid-v4 paper month report', ''];
    $lines[] = '- Status: `' . (string) ($report['status'] ?? 'unknown') . '`';
    $lines[] = '- Observation: `' . (int) ($report['elapsed_calendar_days'] ?? 0) . ' days`';
    $lines[] = '- Equity: `$' . number_format((float) ($report['initial_equity'] ?? 0.0), 2)
        . ' → $' . number_format((float) ($report['latest_equity'] ?? 0.0), 2) . '`';
    $lines[] = '- Return: `' . number_format(100 * (float) ($report['return'] ?? 0.0), 2) . '%`';
    $lines[] = '- Max drawdown: `' . number_format(100 * (float) ($report['max_drawdown'] ?? 0.0), 2) . '%`';
    $lines[] = '- Completed exit episodes: `' . (int) ($report['orders']['completed_exit_episodes'] ?? 0) . '`';
    $lines[] = '- Positive observed weeks: `' . (int) ($report['weekly_consistency']['positive_weeks'] ?? 0)
        . '/' . (int) ($report['weekly_consistency']['observed_weeks'] ?? 0) . '`';
    $lines[] = '- Human live-review gate: `' . (!empty($report['eligible_for_human_live_review']) ? 'passed' : 'blocked') . '`';
    if (!empty($report['failed_gates'])) {
        $lines[] = '- Failed gates: `' . implode(', ', (array) $report['failed_gates']) . '`';
    }
    $lines[] = '- Automatic live trading: `disabled`';

    return implode("\n", $lines);
}

function tacticalMonthWrite(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create month-report directory.');
    }
    $temp = $path . '.tmp-' . bin2hex(random_bytes(5));
    if (file_put_contents($temp, $content) === false || !rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Unable to atomically write month report.');
    }
}

function tacticalMonthBool(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}
