<?php

declare(strict_types=1);

use FulltimeTrading\Storage\TacticalPaperRepository;
use FulltimeTrading\Trading\TacticalNotificationHealthGuard;

require __DIR__ . '/../bootstrap.php';

function notificationHealthExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = sys_get_temp_dir() . '/ftt-tactical-notification-health-' . bin2hex(random_bytes(6)) . '.sqlite';
$runId = 'notification-health-test';
$identity = [
    'run_id' => $runId,
    'profile' => 'causal-stock-rotation-hybrid-v4',
    'strategy_hash' => str_repeat('a', 64),
    'runtime_hash' => str_repeat('b', 64),
    'data_contract' => ['feed' => 'test'],
    'live_review_not_before' => '2026-08-17',
];
$cycle = [
    'signal' => ['as_of' => '2026-07-16'],
    'run_status' => 'transition',
    'reconciliation_status' => 'legacy_positions_in_control',
    'broker' => [
        'positions' => [['symbol' => 'TECL', 'qty' => 66]],
        'open_orders' => [],
    ],
    'notification_schedule' => [
        'close_status_key' => 'portfolio-close:accountscope:2026-07-16:' . str_repeat('c', 64) . ':v3',
        'open_status_key' => null,
        'open_status_required_key' => 'portfolio-open:accountscope:2026-07-17:v3',
    ],
];

try {
    $repo = new TacticalPaperRepository($database);
    $repo->migrate();
    $repo->ensureRun($identity, [
        'dynamic_loo10' => 0.60,
        'qqq200_full' => 2.0 / 15.0,
        'spy200_full' => 2.0 / 15.0,
        'qqq150_ex_crypto' => 2.0 / 15.0,
    ]);

    $health = TacticalNotificationHealthGuard::assess($database, $runId, $cycle);
    notificationHealthExpect(!$health['ok'], 'Missing required signal, transition and open-status deliveries must fail closed.');
    notificationHealthExpect(
        in_array('tactical_notification_signal_missing', $health['errors'], true)
        && in_array('tactical_notification_transition_missing', $health['errors'], true)
        && in_array('tactical_notification_open_status_missing', $health['errors'], true),
        'Missing delivery reasons must be explicit.',
    );

    $repo->queueNotification('signal:2026-07-16:transition', 'signal');
    $health = TacticalNotificationHealthGuard::assess($database, $runId, $cycle);
    notificationHealthExpect(
        $health['pending_count'] === 1
        && in_array('tactical_notification_backlog', $health['errors'], true),
        'Every undelivered row must remain visible even before its first attempt.',
    );

    $repo->markNotificationAttempted('signal:2026-07-16:transition', 3600);
    notificationHealthExpect(
        $repo->pendingNotifications() === [],
        'The fixture must prove that retry cooldown hides the row from the delivery query.',
    );
    $health = TacticalNotificationHealthGuard::assess($database, $runId, $cycle);
    notificationHealthExpect(
        $health['failed_pending_count'] === 1
        && in_array('tactical_notification_delivery_failed', $health['errors'], true),
        'A failed attempt in retry cooldown must still fail runtime health.',
    );

    $repo->markNotificationDelivered('signal:2026-07-16:transition');
    $health = TacticalNotificationHealthGuard::assess($database, $runId, $cycle);
    notificationHealthExpect(
        in_array('tactical_notification_signal_missing', $health['errors'], true),
        'A delivered legacy compact signal key must not satisfy the versioned close report.',
    );
    $closeKey = (string) $cycle['notification_schedule']['close_status_key'];
    $repo->queueNotification($closeKey, 'detailed close status');
    $repo->markNotificationAttempted($closeKey);
    $repo->markNotificationDelivered($closeKey);
    $repo->queueNotification('transition:manifest-a', 'transition');
    $repo->markNotificationAttempted('transition:manifest-a');
    $repo->markNotificationDelivered('transition:manifest-a');
    $health = TacticalNotificationHealthGuard::assess($database, $runId, $cycle);
    notificationHealthExpect(
        !$health['ok'] && in_array('tactical_notification_open_status_missing', $health['errors'], true),
        'A scheduled opening status must be delivered before notification health becomes green.',
    );
    $openKey = (string) $cycle['notification_schedule']['open_status_required_key'];
    $repo->queueNotification($openKey, 'opening status');
    $repo->markNotificationAttempted($openKey);
    $repo->markNotificationDelivered($openKey, 4321);
    $payloadCheck = new PDO('sqlite:' . $database);
    $payloadStatement = $payloadCheck->prepare(
        'SELECT payload FROM tactical_paper_notification WHERE notification_key=:key'
    );
    $payloadStatement->execute([':key' => $openKey]);
    $deliveredPayload = json_decode((string) $payloadStatement->fetchColumn(), true);
    notificationHealthExpect(
        ($deliveredPayload['telegram_message_id'] ?? null) === 4321,
        'The acknowledged Telegram message id must remain auditable in the outbox.',
    );
    $health = TacticalNotificationHealthGuard::assess($database, $runId, $cycle);
    notificationHealthExpect($health['ok'], 'All required deliveries with no backlog must be healthy.');
    notificationHealthExpect(
        $health['required'] === [
            'signal' => true,
            'transition' => true,
            'activation' => false,
            'open_status' => true,
        ],
        'Required delivery types must follow the current cycle.',
    );

    // Executor dedupe returns early for an already delivered key. Health must
    // accept the persisted delivery without demanding a fresh event row.
    $repo->queueNotification($closeKey, 'duplicate ignored');
    notificationHealthExpect(
        TacticalNotificationHealthGuard::assess($database, $runId, $cycle)['ok'],
        'An already delivered deduplicated notification must remain verified.',
    );
} finally {
    unset($repo);
    @unlink($database);
    @unlink($database . '-wal');
    @unlink($database . '-shm');
}

echo "tactical notification health guard tests passed\n";
