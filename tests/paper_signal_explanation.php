<?php

declare(strict_types=1);

use FulltimeTrading\Support\PaperSignalExplanation as E;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $level, string $message): never { throw new RuntimeException($message); });
$checks = 0;
$expect = static function (bool $condition, string $why) use (&$checks): void {
    ++$checks;
    if (!$condition) { throw new RuntimeException($why); }
};
$now = new DateTimeImmutable('2026-09-14T13:20:00Z');
$base = ['generated_at' => $now->format(DATE_ATOM), 'runtime' => ['paper_only' => true, 'paper_base_host_ok' => true,
    'paper_account_guard' => array_fill_keys(['account_reference_match', 'multiplier_match', 'shorting_match', 'active', 'unblocked'], true)],
    'alpaca' => ['snapshot_complete' => true, 'account' => ['equity' => 30000, 'cash' => 30000, 'buying_power' => 60000], 'positions' => [], 'open_orders' => []],
    'tactical' => ['run' => ['run_id' => 'test', 'status' => 'active'], 'health' => ['ok' => true], 'active_intents' => [],
        'cycle' => ['run_id' => 'test', 'generated_at' => $now->format(DATE_ATOM), 'errors' => [],
            'signal' => ['as_of' => '2026-09-11', 'intended_session' => '2026-09-14', 'validation_selected' => true,
                'targets' => ['a' => ['action' => 'hold', 'due' => false, 'ranked_symbol' => 'MSFT', 'current_symbol' => 'MSFT']]],
            'entry_eligibility' => ['allowed_now' => false, 'blocked_reasons' => [['code' => 'no_actionable_signal']], 'executable_buy_legs' => []]]]];
$copy = serialize($base);
$v = E::build($base, $now);
$expect(serialize($base) === $copy, 'Renderer must not mutate input.');
$expect($v['execution_authority'] === 'none' && !$v['reported_plan_permitted'], 'An explanation cannot authorize execution.');
$expect($v['state'] === 'observation', 'Healthy HOLD is an observation.');
$expect(str_contains($v['text'], 'позиций 0') && !str_contains($v['text'], 'АКЦИИ КУПЛЕНЫ'), 'Model MSFT is not a broker position.');
$expect(str_contains($v['text'], 'НЕ заявка и НЕ факт покупки'), 'Watch-only warning is explicit.');
$expect(str_contains($v['text'], 'не разрешение покупать'), 'ACTIVE is not permission.');
$good = $base;
$good['tactical']['cycle']['entry_eligibility'] = ['allowed_now' => true, 'blocked_reasons' => [],
    'executable_buy_legs' => [['symbol' => 'MSFT', 'qty' => 2, 'time_in_force' => 'opg']]];
$expect(E::build($good, $now)['state'] === 'plan_permitted_not_filled', 'A verified plan is not a confirmed fill.');
$mutations = [
    static function (&$s) { $s['generated_at'] = '2026-09-14T12:00:00Z'; },
    static function (&$s) { $s['tactical']['cycle']['generated_at'] = '2026-09-14T12:00:00Z'; },
    static function (&$s) { $s['generated_at'] = '2026-09-14T13:30:00Z'; },
    static function (&$s) { $s['generated_at'] = 'tomorrow'; },
    static function (&$s) { $s['tactical']['cycle']['run_id'] = 'old-run'; },
    static function (&$s) { $s['tactical']['cycle']['signal']['validation_selected'] = false; },
    static function (&$s) { unset($s['tactical']['cycle']['signal']['validation_selected']); },
    static function (&$s) { $s['tactical']['cycle']['signal']['validation_selected'] = 'true'; },
    static function (&$s) { $s['runtime']['paper_account_guard']['active'] = false; },
    static function (&$s) { $s['runtime']['paper_only'] = false; },
    static function (&$s) { $s['runtime']['paper_base_host_ok'] = false; },
    static function (&$s) { $s['alpaca']['snapshot_complete'] = false; },
    static function (&$s) { $s['alpaca']['account'] = null; },
    static function (&$s) { $s['alpaca']['positions'] = null; },
    static function (&$s) { $s['tactical']['health']['ok'] = false; },
    static function (&$s) { $s['tactical']['run']['status'] = 'paused'; },
    static function (&$s) { $s['tactical']['active_intents'] = [['status' => 'new']]; },
    static function (&$s) { $s['tactical']['cycle']['signal']['intended_session'] = '2026-09-15'; },
    static function (&$s) { $s['tactical']['cycle']['entry_eligibility']['executable_buy_legs'] = []; },
    static function (&$s) { $s['tactical']['cycle']['entry_eligibility']['executable_buy_legs'][0]['qty'] = NAN; },
    static function (&$s) { $s['tactical']['cycle']['entry_eligibility']['allowed_now'] = 'true'; },
    static function (&$s) { $s['tactical']['cycle']['errors'] = ['runtime_identity_drift:runtime_hash']; },
];
foreach ($mutations as $i => $mutate) {
    $s = $good; $mutate($s); $v = E::build($s, $now);
    $expect(!$v['reported_plan_permitted'] && !str_contains($v['text'], 'ПЛАН ПОКУПКИ РАЗРЕШЁН'), 'Contradictory evidence must fail closed: ' . $i);
}
$s = $base; $s['alpaca']['snapshot_complete'] = false;
$expect(!str_contains(E::build($s, $now)['text'], 'позиций 0'), 'Failed broker GET must not look flat.');
$s = $base; $s['tactical']['cycle']['signal']['validation_selected'] = false;
$expect(str_contains(E::build($s, $now)['text'], 'не прогноз падения'), 'Qualification is not a bearish signal.');
$s['errors'] = ['runtime_identity_drift:runtime_hash'];
$v = E::build($s, $now);
$expect($v['reasons'][0]['id'] === 'identity' && str_contains($v['text'], 'Перезапуск сам по себе'), 'Identity has priority and correct remedy.');
$s = $base; $s['errors'] = ['secret bearer token in unsafe error'];
$expect(!str_contains(E::build($s, $now)['text'], 'secret bearer'), 'Unknown free-text errors are redacted.');
$s = $base; $s['tactical']['cycle']['signal']['targets']['a']['cooldown_left'] = 5;
$expect(str_contains(E::build($s, $now)['text'], '5 торговых сессий'), 'Pause uses market sessions, not calendar days.');
$s = $base; $s['alpaca']['account']['cash'] = null;
$expect(str_contains(E::build($s, $now)['text'], 'деньги на счёте неизвестно'), 'Unknown cash is not zero.');
$s = $base; $s['alpaca']['open_orders'] = [['symbol' => 'MSFT', 'side' => 'buy', 'qty' => 5, 'filled_qty' => 0]];
$expect(E::build($s, $now)['state'] === 'broker_orders_open', 'Current orders are shown separately.');
$s = $base; $s['errors'] = array_map(static fn ($i) => 'unknown_failure_' . $i, range(1, 80));
$v = E::build($s, $now);
$expect(strlen($v['text']) <= 3800 && preg_match('//u', $v['text']) === 1, 'Telegram preview fits transport byte limit and UTF-8.');
$expect(count($v['reasons']) >= 80, 'Full JSON retains reasons omitted from text.');
$intent = ['symbol' => 'MSFT', 'side' => 'buy', 'requested_qty' => 5, 'cumulative_filled_qty' => 0, 'status' => 'accepted'];
$expect(str_contains(E::intent($intent), 'ЗАЯВКА, НЕ СДЕЛКА'), 'Accepted BUY is not filled.');
foreach (['rejected', 'canceled', 'expired'] as $status) {
    $intent['status'] = $status;
    $expect(str_contains(E::intent($intent), 'БЕЗ ИСПОЛНЕНИЯ'), 'Terminal zero fill: ' . $status);
}
$intent['status'] = 'filled';
$expect(str_contains(E::intent($intent), 'Противоречие') && !str_contains(E::intent($intent), 'АКЦИИ КУПЛЕНЫ'), 'Filled status alone is not fill evidence.');
$intent['cumulative_filled_qty'] = 2;
$intent['cumulative_fill_notional'] = 200;
$intent['status'] = 'canceled';
$expect(str_contains(E::intent($intent), 'КУПЛЕНА ТОЛЬКО ЧАСТЬ') && str_contains(E::intent($intent), 'Остаток заявки больше не активен'), 'Partial then cancel retains the actual fill.');
$intent['cumulative_filled_qty'] = 5; $intent['cumulative_fill_notional'] = 500; $intent['status'] = 'filled';
$expect(str_contains(E::intent($intent), 'АКЦИИ КУПЛЕНЫ') && str_contains(E::intent($intent), '$100.00'), 'Positive complete BUY with average price.');
$intent['side'] = 'sell';
$expect(str_contains(E::intent($intent), 'не подтверждение закрытия всех позиций'), 'A sleeve sale is not a flat account.');
$expect(!str_contains(E::intent($intent), 'P/L $0'), 'Profit must not be fabricated from sale notional.');
$intent['cumulative_filled_qty'] = INF;
$expect(str_contains(E::intent($intent), 'НЕ ПОДТВЕРЖДЕНЫ'), 'Non-finite quantities fail closed.');
foreach (['maximum-stop12-costband2-whole-v1', 'maximum-stop12-costband2-whole-bull5-v1'] as $profile) {
    $paper = $base; $paper['tactical']['run']['profile'] = $profile; $paper['tactical']['cycle']['profile'] = $profile;
    $paper['tactical']['cycle']['paper_only'] = true; $paper['tactical']['cycle']['signal']['paper_admission'] = true;
    $paper['tactical']['cycle']['signal']['validation_selected'] = false;
    $v = E::build($paper, $now);
    $expect(str_contains($v['text'], 'только экспериментальный paper') && !in_array('qualification', array_column($v['reasons'], 'id'), true), 'Admitted paper profile is not mislabeled as rejected history: ' . $profile);
    $expect(!$v['reported_plan_permitted'] && $v['execution_authority'] === 'none', 'Explaining admission never enables an entry.');
    foreach (['missing_admission', 'string_admission', 'nonpaper', 'different_profile', 'different_run'] as $fault) {
        $bad = $paper;
        if ($fault === 'missing_admission') { unset($bad['tactical']['cycle']['signal']['paper_admission']); }
        if ($fault === 'string_admission') { $bad['tactical']['cycle']['signal']['paper_admission'] = 'true'; }
        if ($fault === 'nonpaper') { $bad['tactical']['cycle']['paper_only'] = false; }
        if ($fault === 'different_profile') { $bad['tactical']['cycle']['profile'] = 'unapproved'; }
        if ($fault === 'different_run') { $bad['tactical']['cycle']['run_id'] = 'different'; }
        $v = E::build($bad, $now);
        $expect(!str_contains($v['text'], 'только экспериментальный paper') && !$v['reported_plan_permitted'], 'Missing/mismatched proof is not paper admission: ' . $fault);
    }
}
echo "paper_signal_explanation: {$checks} assertions PASS\n";
