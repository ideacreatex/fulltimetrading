<?php
declare(strict_types=1);

use FulltimeTrading\Trading\TacticalPortfolioNotificationSchedule as Schedule;

require dirname(__DIR__) . '/bootstrap.php';
$checks = 0;
$check = static function (bool $ok, string $why) use (&$checks): void {
    ++$checks;
    if (!$ok) { throw new RuntimeException($why); }
};
$case = static function (string $label, string $now, bool $open, string $nextOpen, string $asOf, mixed $intended, bool $expected)
    use ($check): ?array {
    $clock = ['is_open' => $open, 'timestamp' => $now, 'next_open' => $nextOpen];
    $signal = ['as_of' => $asOf, 'intended_session' => $intended, 'decision_sha256' => str_repeat('a', 64)];
    $before = serialize([$clock, $signal]);
    $result = Schedule::weeklyCloseStatus($clock, ['id' => 'offline-account'], $signal, new DateTimeImmutable($now));
    $check(($result !== null) === $expected, $label);
    $check($before === serialize([$clock, $signal]), 'Read-only scheduling: ' . $label);
    if ($result !== null) {
        $check($result['session_date'] === $asOf, 'Weekly report retains its exact completed session.');
    }
    return $result;
};

foreach (['09:30:00', '09:35:00', '12:00:00', '15:59:59'] as $time) {
    $case('No Thursday weekly at Friday ' . $time, '2026-09-18T' . $time . '-04:00', true,
        '2026-09-21T09:30:00-04:00', '2026-09-17', '2026-09-18', false);
}
$case('No weekly before Friday opening', '2026-09-18T09:29:59-04:00', false,
    '2026-09-18T09:30:00-04:00', '2026-09-17', '2026-09-18', false);
foreach (['2026-09-18T16:22:00-04:00', '2026-09-19T12:00:00-04:00', '2026-09-21T09:00:00-04:00'] as $time) {
    $case('Delayed Thursday data never becomes Friday close: ' . $time, $time, false,
        '2026-09-21T09:30:00-04:00', '2026-09-17', '2026-09-18', false);
}
$friday = $case('Actual Friday close', '2026-09-18T16:22:00-04:00', false,
    '2026-09-21T09:30:00-04:00', '2026-09-18', '2026-09-21', true);
$monday = $case('Monday preopen catch-up', '2026-09-21T09:00:00-04:00', false,
    '2026-09-21T09:30:00-04:00', '2026-09-18', '2026-09-21', true);
$later = $case('Monday open catch-up', '2026-09-21T10:00:00-04:00', true,
    '2026-09-22T09:30:00-04:00', '2026-09-18', '2026-09-21', true);
$check($friday['key'] === $monday['key'] && $friday['key'] === $later['key'], 'Catch-up/restart preserves the existing durable key.');
$check(!$friday['catch_up'] && $monday['catch_up'] && $later['catch_up'], 'Catch-up remains explicit.');
$case('Friday close cannot precede opening', '2026-09-18T09:00:00-04:00', false,
    '2026-09-18T09:30:00-04:00', '2026-09-18', '2026-09-21', false);
$case('Friday close cannot occur during the session', '2026-09-18T15:00:00-04:00', true,
    '2026-09-21T09:30:00-04:00', '2026-09-18', '2026-09-21', false);
$case('Thursday before Friday holiday', '2026-07-02T16:22:00-04:00', false,
    '2026-07-06T09:30:00-04:00', '2026-07-02', '2026-07-06', true);
$case('Friday holiday catch-up', '2026-07-03T09:30:00-04:00', false,
    '2026-07-06T09:30:00-04:00', '2026-07-02', '2026-07-06', true);
$case('Thanksgiving Wednesday is not the week end', '2026-11-27T09:30:00-05:00', true,
    '2026-11-30T09:30:00-05:00', '2026-11-25', '2026-11-27', false);
$case('Short Friday completed close', '2026-11-27T13:22:00-05:00', false,
    '2026-11-30T09:30:00-05:00', '2026-11-27', '2026-11-30', true);
$case('DST fall Friday open', '2026-11-06T09:30:00-05:00', true,
    '2026-11-09T09:30:00-05:00', '2026-11-05', '2026-11-06', false);
$case('DST spring Friday close', '2026-03-06T16:22:00-05:00', false,
    '2026-03-09T09:30:00-04:00', '2026-03-06', '2026-03-09', true);
$case('ISO year boundary and New Year holiday', '2026-12-31T16:22:00-05:00', false,
    '2027-01-04T09:30:00-05:00', '2026-12-31', '2027-01-04', true);
foreach ([null, '', false, 20260921, [], ['2026-09-21'], 'tomorrow', '2026-02-30', '2026-9-21', '2026-09-18', '2026-09-17', '2026-09-21T09:30:00-04:00'] as $bad) {
    $case('Invalid/missing/non-increasing intended session: ' . var_export($bad, true), '2026-09-18T16:22:00-04:00', false,
        '2026-09-21T09:30:00-04:00', '2026-09-18', $bad, false);
}
$case('Future planned session inconsistent with broker next opening', '2026-09-18T16:22:00-04:00', false,
    '2026-09-21T09:30:00-04:00', '2026-09-18', '2026-09-28', false);
$clock = ['is_open' => false, 'timestamp' => '2026-09-18T16:22:00-04:00', 'next_open' => '2026-09-21T09:30:00-04:00'];
$signal = ['as_of' => '2026-09-18', 'intended_session' => '2026-09-21', 'decision_sha256' => str_repeat('a', 64)];
$check(Schedule::weeklyCloseStatus($clock, ['id' => 'fixture'], $signal, new DateTimeImmutable('2026-09-18T17:00:00-04:00')) === null, 'Stale broker clock still fails closed.');
$signal['decision_sha256'] = 'bad';
$check(Schedule::weeklyCloseStatus($clock, ['id' => 'fixture'], $signal, new DateTimeImmutable($clock['timestamp'])) === null, 'Invalid decision identity still fails closed.');
echo "Weekly completed-session boundary: $checks assertions PASS; no broker access\n";
