<?php

declare(strict_types=1);

use FulltimeTrading\Trading\TacticalPortfolioNotificationSchedule;

require __DIR__ . '/../bootstrap.php';

function portfolioScheduleExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$account = ['id' => 'paper-account-a', 'status' => 'ACTIVE'];
$otherAccount = ['id' => 'paper-account-b', 'status' => 'ACTIVE'];
$scope = substr(hash('sha256', 'paper-account-a'), 0, 12);

portfolioScheduleExpect(
    TacticalPortfolioNotificationSchedule::openStatus(
        ['is_open' => false, 'timestamp' => '2026-07-21T09:45:00-04:00'],
        $account,
        new DateTimeImmutable('2026-07-21T09:45:01-04:00'),
    ) === null,
    'A closed market must never schedule the opening report.',
);
portfolioScheduleExpect(
    TacticalPortfolioNotificationSchedule::openStatus(
        ['is_open' => true, 'timestamp' => '2026-07-21T09:34:59-04:00'],
        $account,
        new DateTimeImmutable('2026-07-21T09:35:00-04:00'),
    ) === null,
    'The opening report must wait for the post-open snapshot threshold.',
);
portfolioScheduleExpect(
    TacticalPortfolioNotificationSchedule::openStatus(
        ['is_open' => true, 'timestamp' => 'not-a-timestamp'],
        $account,
        new DateTimeImmutable('2026-07-21T09:35:00-04:00'),
    ) === null,
    'An invalid broker timestamp must fail closed.',
);
portfolioScheduleExpect(
    TacticalPortfolioNotificationSchedule::openStatus(
        ['is_open' => true, 'timestamp' => '2026-07-21T09:35:00-04:00'],
        $account,
        new DateTimeImmutable('2026-07-21T10:00:01-04:00'),
    ) === null,
    'A stale broker clock must fail closed even when is_open remains true.',
);
portfolioScheduleExpect(
    TacticalPortfolioNotificationSchedule::openStatus(
        ['is_open' => true, 'timestamp' => '2026-07-21T09:35:00-04:00'],
        [],
        new DateTimeImmutable('2026-07-21T09:35:01-04:00'),
    ) === null,
    'An account-less report must not collide in the durable outbox.',
);

$first = TacticalPortfolioNotificationSchedule::openStatus(
    ['is_open' => true, 'timestamp' => '2026-07-21T09:35:00-04:00'],
    $account,
    new DateTimeImmutable('2026-07-21T09:35:01-04:00'),
);
$repeat = TacticalPortfolioNotificationSchedule::openStatus(
    ['is_open' => true, 'timestamp' => '2026-07-21T09:59:59-04:00'],
    $account,
    new DateTimeImmutable('2026-07-21T10:00:00-04:00'),
);
portfolioScheduleExpect(
    is_array($first)
    && is_array($repeat)
    && $first['key'] === 'portfolio-open:' . $scope . ':2026-07-21:v3'
    && $repeat['key'] === $first['key'],
    'Every executor pass in one account/session must resolve to one durable opening key.',
);
portfolioScheduleExpect($first['catch_up'] === false && $repeat['catch_up'] === false, 'Normal delivery must not be marked catch-up.');

$catchUp = TacticalPortfolioNotificationSchedule::openStatus(
    ['is_open' => true, 'timestamp' => '2026-07-21T11:42:00-04:00'],
    $account,
    new DateTimeImmutable('2026-07-21T11:42:01-04:00'),
);
portfolioScheduleExpect(
    is_array($catchUp) && $catchUp['key'] === $first['key'] && $catchUp['catch_up'] === true,
    'A restart later in the open session must catch up without creating a new key.',
);
$other = TacticalPortfolioNotificationSchedule::openStatus(
    ['is_open' => true, 'timestamp' => '2026-07-21T11:42:00-04:00'],
    $otherAccount,
    new DateTimeImmutable('2026-07-21T11:42:01-04:00'),
);
portfolioScheduleExpect(
    is_array($other) && $other['key'] !== $first['key'],
    'A different paper account must never inherit another account opening delivery.',
);

$requiredAfterClose = TacticalPortfolioNotificationSchedule::requiredOpenKey(
    ['is_open' => false, 'timestamp' => '2026-07-21T16:30:00-04:00'],
    $account,
    ['as_of' => '2026-07-21', 'intended_session' => '2026-07-22'],
    new DateTimeImmutable('2026-07-21T16:30:01-04:00'),
);
portfolioScheduleExpect(
    $requiredAfterClose === $first['key'],
    'A missed opening delivery must remain required after the close signal rolls forward.',
);
$afterCloseCatchUp = TacticalPortfolioNotificationSchedule::openStatus(
    ['is_open' => false, 'timestamp' => '2026-07-21T16:30:00-04:00'],
    $account,
    new DateTimeImmutable('2026-07-21T16:30:01-04:00'),
    TacticalPortfolioNotificationSchedule::OPEN_REPORT_AFTER,
    ['as_of' => '2026-07-21', 'intended_session' => '2026-07-22'],
);
portfolioScheduleExpect(
    is_array($afterCloseCatchUp)
    && $afterCloseCatchUp['key'] === $requiredAfterClose
    && $afterCloseCatchUp['catch_up'] === true,
    'After-close recovery must be able to deliver the same missed opening key as a transparent current-snapshot catch-up.',
);

$decision = str_repeat('a', 64);
$closeSignal = [
    'as_of' => '2026-07-21',
    'intended_session' => '2026-07-22',
    'decision_sha256' => $decision,
];
portfolioScheduleExpect(
    TacticalPortfolioNotificationSchedule::closeStatus(
        ['is_open' => true, 'timestamp' => '2026-07-21T15:59:00-04:00'],
        $account,
        $closeSignal,
        new DateTimeImmutable('2026-07-21T15:59:01-04:00'),
    ) === null,
    'A same-session close report must not be sent while the broker says the market is open.',
);
$close = TacticalPortfolioNotificationSchedule::closeStatus(
    ['is_open' => false, 'timestamp' => '2026-07-21T16:22:00-04:00'],
    $account,
    $closeSignal,
    new DateTimeImmutable('2026-07-21T16:22:01-04:00'),
);
portfolioScheduleExpect(
    is_array($close)
    && $close['key'] === 'portfolio-close:' . $scope . ':2026-07-21:' . $decision . ':v3'
    && $close['catch_up'] === false,
    'The validated after-close decision needs its own versioned exact key.',
);
$closeCatchUp = TacticalPortfolioNotificationSchedule::closeStatus(
    ['is_open' => true, 'timestamp' => '2026-07-22T09:10:00-04:00'],
    $account,
    $closeSignal,
    new DateTimeImmutable('2026-07-22T09:10:01-04:00'),
);
portfolioScheduleExpect(
    is_array($closeCatchUp) && $closeCatchUp['key'] === $close['key'] && $closeCatchUp['catch_up'] === true,
    'A missed close report must catch up with the identical decision key.',
);
$invalidDecision = $closeSignal;
$invalidDecision['decision_sha256'] = 'not-a-hash';
portfolioScheduleExpect(
    TacticalPortfolioNotificationSchedule::closeStatus(
        ['is_open' => false, 'timestamp' => '2026-07-21T16:22:00-04:00'],
        $account,
        $invalidDecision,
        new DateTimeImmutable('2026-07-21T16:22:01-04:00'),
    ) === null,
    'A close report without the validated decision identity must fail closed.',
);

echo "tactical portfolio notification schedule tests passed\n";
