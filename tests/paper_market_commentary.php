<?php
declare(strict_types=1);
use FulltimeTrading\Notifications\PaperMarketCommentary as Report;
use FulltimeTrading\Notifications\PaperCommentaryOutbox as Outbox;
use FulltimeTrading\Paper\CandidateRelease;
require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$reject = static function (callable $fn, string $why) use ($check): void {
    try { $fn(); } catch (Throwable) { $check(true, $why); return; } $check(false, $why);
};
$zone = new DateTimeZone('America/New_York'); $date = '2026-09-15';
$calendar = [['date' => $date, 'open' => '09:30', 'close' => '16:00']];
$phase = static function (string $time, bool $open, array $calendar) use ($zone): ?array {
    $now = new DateTimeImmutable($time, $zone);
    return Report::phase($calendar, ['timestamp' => $now->format(DATE_ATOM), 'is_open' => $open], $now);
};
foreach (['09:29', '09:34'] as $time) { $check($phase($date . ' ' . $time, $time === '09:34', $calendar) === null, 'Wait for open plus five minutes.'); }
$check($phase($date . ' 09:35', true, $calendar) === ['phase' => 'open', 'session_date' => $date], 'Opening eligibility.');
$check($phase($date . ' 10:45', true, $calendar)['phase'] === 'open', 'Bounded opening catch-up.');
$check($phase($date . ' 11:01', true, $calendar) === null, 'Do not call midday an opening report.');
$check($phase($date . ' 16:19', false, $calendar) === null, 'Wait for completed close data.');
$check($phase($date . ' 16:20', false, $calendar)['phase'] === 'close', 'Closing eligibility.');
$check($phase('2026-09-19 17:00', false, $calendar) === null, 'Weekend silent.');
$check($phase($date . ' 17:00', false, []) === null, 'Holiday silent.');
$check($phase($date . ' 13:20', false, [['date' => $date, 'open' => '09:30', 'close' => '13:00']])['phase'] === 'close', 'Early market close uses broker calendar.');
$now = new DateTimeImmutable('2026-03-10T13:35:00Z');
$check(Report::phase([['date' => '2026-03-10', 'open' => '09:30', 'close' => '16:00']], ['timestamp' => $now->format(DATE_ATOM), 'is_open' => true], $now)['phase'] === 'open', 'US/EU DST mismatch does not shift broker-local opening.');
$reject(fn () => Report::phase($calendar, ['timestamp' => $date . 'T13:35:00Z', 'is_open' => true], new DateTimeImmutable($date . 'T13:40:00Z')), 'Stale clock rejected.');
$reject(fn () => $phase($date . ' 09:40', false, $calendar), 'Clock/calendar contradiction rejected.');
$now = new DateTimeImmutable($date . ' 09:45', $zone);
$context = ['captured_at' => $now->format(DATE_ATOM), 'paper_only' => true, 'run_id' => 'paper-test', 'due' => ['phase' => 'open', 'session_date' => $date],
    'account' => ['equity' => 27567.66, 'cash' => 27567.66], 'positions' => [], 'open_orders' => [['symbol' => 'MSFT']]];
$draft = ['schema' => 'paper-market-commentary-v1', 'phase' => 'open', 'session_date' => $date, 'market' => 'Trend observation, not a prediction.',
    'algorithm' => 'Target nine shares; a target is not a fill.', 'watch' => 'Await broker confirmation.', 'sources' => [['label' => 'Alpaca', 'url' => 'https://docs.alpaca.markets/us/docs/orders-at-alpaca']]];
$message = Report::render($draft, $context, $now);
$check(str_contains($message, 'АНАЛИТИЧЕСКИЙ КОММЕНТАРИЙ') && str_contains($message, 'НЕ торговая команда'), 'Separate opinion header.');
$check(str_contains($message, 'позиций 0; открытых заявок 1'), 'Orders never become positions.');
$check(preg_match('//u', $message) === 1 && strlen($message) <= 3800, 'Safe untruncated UTF-8.');
foreach (['stale', 'future', 'wrong_phase', 'wrong_date', 'bad_account', 'fill_banner', 'oversize', 'url', 'url_credentials'] as $kind) {
    $a = $draft; $c = $context;
    if ($kind === 'stale') { $c['captured_at'] = $now->modify('-16 minutes')->format(DATE_ATOM); }
    if ($kind === 'future') { $c['captured_at'] = $now->modify('+1 minute')->format(DATE_ATOM); }
    if ($kind === 'wrong_phase') { $a['phase'] = 'close'; }
    if ($kind === 'wrong_date') { $a['session_date'] = '2026-09-14'; }
    if ($kind === 'bad_account') { $c['account']['equity'] = NAN; }
    if ($kind === 'fill_banner') { $a['algorithm'] = 'АКЦИИ ОТКРЫТЫ'; }
    if ($kind === 'oversize') { $a['market'] = str_repeat('x', 3900); }
    if ($kind === 'url') { $a['sources'][0]['url'] = 'http://example.com'; }
    if ($kind === 'url_credentials') { $a['sources'][0]['url'] = 'https://secret@example.com'; }
    $reject(fn () => Report::render($a, $c, $now), 'Reject ' . $kind);
}
$check(Report::key('paper-test', $date, 'open') !== Report::key('paper-test', $date, 'close'), 'Opening and closing have distinct keys.');
$db = new PDO('sqlite::memory:'); $outbox = new Outbox($db); $calls = 0;
$sender = static function ($text) use (&$calls): array { ++$calls; return ['ok' => true, 'result' => ['message_id' => 42]]; };
$key = Report::key('paper-test', $date, 'open'); $r = $outbox->deliver($key, $message, $sender);
$check($r['status'] === 'delivered' && $calls === 1, 'Acknowledged delivery stored.');
$outbox->deliver($key, 'Revised draft must not duplicate daily block.', $sender); $check($calls === 1, 'At most one report for a run/date/phase.');
$other = new Outbox($db); $other->deliver($key, $message, $sender); $check($calls === 1, 'Second worker deduplicated.');
$bad = Report::key('paper-test', $date, 'close');
$reject(fn () => $outbox->deliver($bad, $message, static function ($text): never { throw new RuntimeException('secret transport token'); }), 'Ambiguous delivery recorded.');
$check($outbox->receipt($bad)['status'] === 'uncertain' && !str_contains($outbox->receipt($bad)['error'], 'secret'), 'Uncertainty retained without secret logging.');
$outbox->deliver($bad, $message, $sender); $check($calls === 1, 'No duplicate after unknown delivery outcome.');
$reject(fn () => $outbox->deliver('invalid-ack', $message, static fn ($m): array => ['ok' => false]), 'Malformed acknowledgment not success.');
$check($outbox->receipt('invalid-ack')['status'] === 'uncertain', 'Rejected acknowledgment recorded.');
$r = $outbox->deliver('numeric-string-ack', $message, static fn ($m): array => ['ok' => true, 'result' => ['message_id' => '43']]);
$check($r['status'] === 'delivered' && (int) $r['message_id'] === 43, 'Same acknowledgment contract as Telegram notifier.');
$files = CandidateRelease::files(dirname(__DIR__));
foreach (['src/Notifications/PaperMarketCommentary.php', 'src/Notifications/PaperCommentaryOutbox.php', 'tools/paper_market_commentary.php'] as $file) { $check(!isset($files[$file]), 'No active runtime hash changes.'); }
echo "Paper market commentary: $n assertions PASS\n";
