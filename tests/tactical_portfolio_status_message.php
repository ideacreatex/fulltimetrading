<?php

declare(strict_types=1);

use FulltimeTrading\Trading\TacticalPortfolioStatusMessage;

require __DIR__ . '/../bootstrap.php';

function portfolioMessageExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function portfolioMessageHas(string $haystack, string $needle, string $message): void
{
    portfolioMessageExpect(str_contains($haystack, $needle), $message . ' Missing: ' . $needle);
}

/** @return array<string,mixed> */
function portfolioBuyLeg(string $symbol, string $sleeve = 'dynamic_loo10', bool $dependency = false): array
{
    return [
        'sleeve_id' => $sleeve,
        'symbol' => $symbol,
        'side' => 'buy',
        'requested_qty' => 3,
        'payload' => [
            'time_in_force' => $dependency ? 'day' : 'opg',
            'required_exit_decision_ids' => $dependency ? [str_repeat('a', 64)] : [],
        ],
    ];
}

$signal = [
    'as_of' => '2026-07-20',
    'intended_session' => '2026-07-21',
    'targets' => [
        'dynamic_loo10' => [
            'action' => 'hold',
            'symbol' => null,
            'gross' => 0.0,
            'current_symbol' => 'PANW',
            'current_gross' => 0.703788,
            'ranked_symbol' => 'PANW',
            'ranked_gross' => 0.688272,
            'cooldown_left' => 0,
            'drawdown_rearm_pending' => false,
            'risk_exit_pending' => false,
            'due' => false,
            'execution_status' => 'hold_no_trade',
            'no_chase' => true,
        ],
        'qqq200_full' => [
            'action' => 'hold',
            'current_symbol' => null,
            'current_gross' => 0.0,
            'ranked_symbol' => 'PANW',
            'ranked_gross' => 0.743631,
            'cooldown_left' => 43,
            'drawdown_rearm_pending' => true,
            'due' => false,
            'execution_status' => 'hold_no_trade',
            'no_chase' => true,
        ],
        'spy200_full' => [
            'action' => 'hold',
            'current_symbol' => null,
            'current_gross' => 0.0,
            'ranked_symbol' => 'PANW',
            'ranked_gross' => 0.71,
            'cooldown_left' => 43,
            'drawdown_rearm_pending' => true,
            'due' => false,
            'execution_status' => 'hold_no_trade',
            'no_chase' => true,
        ],
        'qqq150_ex_crypto' => [
            'action' => 'hold',
            'current_symbol' => null,
            'current_gross' => 0.0,
            'ranked_symbol' => 'PANW',
            'ranked_gross' => 0.69,
            'cooldown_left' => 43,
            'drawdown_rearm_pending' => true,
            'due' => false,
            'execution_status' => 'hold_no_trade',
            'no_chase' => true,
        ],
    ],
];
$run = [
    'status' => 'transition',
    'activated_at' => null,
    'initial_equity' => null,
];
$positions = [
    [
        'symbol' => 'TECL',
        'qty' => '66',
        'avg_entry_price' => '224.505455',
        'current_price' => '186.50',
        'market_value' => '12309.00',
        'unrealized_pl' => '-2508.36',
        'unrealized_plpc' => '-0.16929',
        'change_today' => '0.0481',
    ],
    [
        'symbol' => 'TQQQ',
        'qty' => '165',
        'avg_entry_price' => '75.34',
        'current_price' => '69.92',
        'market_value' => '11536.80',
        'unrealized_pl' => '-894.30',
        'unrealized_plpc' => '-0.07194',
        'change_today' => '0.0332',
    ],
];
$legacyStates = [
    'TECL' => [
        'status' => 'open',
        'qty' => 66,
        'stop_price' => 178.9308,
        'break_even_trigger_price' => 215.89,
        'break_even_armed' => false,
        'partial_done' => false,
    ],
    'TQQQ' => [
        'status' => 'open',
        'qty' => 165,
        'stop_price' => 69.20,
        'break_even_trigger_price' => 76.8468,
        'break_even_armed' => false,
        'partial_done' => false,
    ],
];
$now = new DateTimeImmutable('2026-07-21T10:05:00-04:00');
$eligibility = TacticalPortfolioStatusMessage::entryEligibility(
    $run,
    'legacy_positions_in_control',
    $signal,
    [],
    [],
    $positions,
    $now,
);
portfolioMessageExpect($eligibility['allowed_now'] === false, 'Transition must block every new entry and add.');

$message = TacticalPortfolioStatusMessage::build(
    'open',
    $now,
    [
        'status' => 'ACTIVE',
        'equity' => '25750.00',
        'last_equity' => '24800.00',
        'cash' => '1905.99',
        'buying_power' => '15345.00',
        'long_market_value' => '23845.80',
        'short_market_value' => '0',
    ],
    $positions,
    [],
    $legacyStates,
    $run,
    'legacy_positions_in_control',
    $signal,
    [],
    $eligibility,
    [],
    ['is_open' => true],
);

portfolioMessageHas($message, '🌅 ОТКРЫТИЕ • ALPACA PAPER', 'The opening report needs an unmistakable phase header.');
portfolioMessageHas($message, 'TECL • 66 шт. • legacy', 'The report must list the real TECL position.');
portfolioMessageHas($message, 'TQQQ • 165 шт. • legacy', 'The report must list the real TQQQ position.');
portfolioMessageHas($message, 'вход $224.51 → сейчас $186.50', 'The report must show average and current prices.');
portfolioMessageHas($message, 'P/L -$2,508.36 (-16.93%)', 'The report must show unrealized P/L.');
portfolioMessageHas($message, 'ментальный close-stop $178.93', 'The stop must be labelled as a mental daily-close stop.');
portfolioMessageHas($message, 'Close-stop не стоит у брокера', 'The report must not imply a hard broker stop exists.');
portfolioMessageHas($message, 'Buying power — лимит брокера, а не разрешение стратегии', 'Buying power must be separated from strategy permission.');
portfolioMessageHas($message, 'МОЖНО ОТКРЫТЬ / ДОКУПИТЬ СЕЙЧАС: НЕТ', 'The actionable verdict must be explicit.');
portfolioMessageHas($message, 'legacy-позиции ещё под прежними правилами (TECL, TQQQ)', 'The blocking reason must name controlled legacy symbols.');
portfolioMessageHas($message, 'модель PANW 70.4%', 'The report must expose the model state hidden by the old HOLD message.');
portfolioMessageHas($message, 'кандидат PANW 68.8%', 'The ranked candidate should be visible.');
portfolioMessageHas($message, 'Кандидат/лидер — это наблюдение, не команда на покупку.', 'A candidate must not look like an Alpaca instruction.');
portfolioMessageHas($message, 'tactical cash $0.00 / nav $0.00 — техническое состояние, не потеря денег', 'Transition zero NAV needs an operator-safe explanation.');
portfolioMessageExpect(strlen($message) <= 3800, 'The detailed report must stay below the safe Telegram byte limit.');

$rebalanceSignal = [
    'intended_session' => '2026-07-22',
    'targets' => [
        'dynamic_loo10' => [
            'action' => 'rebalance',
            'symbol' => 'MSFT',
            'gross' => 0.70,
            'current_symbol' => null,
            'current_gross' => 0.0,
            'due' => true,
        ],
    ],
];
$activeRun = ['status' => 'active', 'activated_at' => '2026-07-20T12:00:00Z'];
$future = TacticalPortfolioStatusMessage::entryEligibility(
    $activeRun,
    'reconciled',
    $rebalanceSignal,
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-21T16:25:00-04:00'),
    [
        'status' => 'waiting_for_opg_window',
        'opg_submit_allowed' => false,
        'rotation_reentry_allowed' => false,
    ],
    [portfolioBuyLeg('MSFT')],
);
portfolioMessageExpect(
    $future['allowed_now'] === false
    && ($future['actions'][0]['type'] ?? null) === 'new_entry'
    && ($future['actions'][0]['symbol'] ?? null) === 'MSFT'
    && ($future['blocked_reasons'][0]['code'] ?? null) === 'entry_window_not_open',
    'A future opening action must be visible but wait for the real executor window.',
);

$todaySignal = $rebalanceSignal;
$todaySignal['intended_session'] = '2026-07-22';
$insideWindow = TacticalPortfolioStatusMessage::entryEligibility(
    $activeRun,
    'reconciled',
    $todaySignal,
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-22T09:20:00-04:00'),
    [
        'status' => 'submit_preopen',
        'opg_submit_allowed' => true,
        'rotation_reentry_allowed' => false,
    ],
    [portfolioBuyLeg('MSFT')],
);
portfolioMessageExpect($insideWindow['allowed_now'] === true, 'A clean active run may queue the due entry only inside the pre-open window.');

$afterWindow = TacticalPortfolioStatusMessage::entryEligibility(
    $activeRun,
    'reconciled',
    $todaySignal,
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-22T09:35:00-04:00'),
    [
        'status' => 'risk_exit_recovery_window',
        'opg_submit_allowed' => false,
        'rotation_reentry_allowed' => false,
    ],
    [portfolioBuyLeg('MSFT')],
);
portfolioMessageExpect(
    $afterWindow['allowed_now'] === false
    && ($afterWindow['blocked_reasons'][0]['code'] ?? null) === 'entry_window_closed',
    'The opening report must not encourage a chased entry after the order window.',
);

$addSignal = [
    'intended_session' => '2026-07-22',
    'targets' => [
        'dynamic_loo10' => [
            'action' => 'resize_or_hold',
            'symbol' => 'PANW',
            'gross' => 0.80,
            'current_symbol' => 'PANW',
            'current_gross' => 0.65,
            'due' => true,
        ],
    ],
];
$add = TacticalPortfolioStatusMessage::entryEligibility(
    $activeRun,
    'reconciled',
    $addSignal,
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-22T09:20:00-04:00'),
    [
        'status' => 'submit_preopen',
        'opg_submit_allowed' => true,
        'rotation_reentry_allowed' => false,
    ],
    [portfolioBuyLeg('PANW')],
);
portfolioMessageExpect(
    $add['allowed_now'] === true && ($add['actions'][0]['type'] ?? null) === 'new_entry',
    'A flat tactical ledger must be identified as a real new entry even when the model shadow already names the symbol.',
);
$actualAddSignal = $addSignal;
$actualAddSignal['targets']['dynamic_loo10']['gross'] = 0.65;
$actualAddSignal['targets']['dynamic_loo10']['current_gross'] = 0.65;
$actualAdd = TacticalPortfolioStatusMessage::entryEligibility(
    $activeRun,
    'reconciled',
    $actualAddSignal,
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-22T09:20:00-04:00'),
    [
        'status' => 'submit_preopen',
        'opg_submit_allowed' => true,
        'rotation_reentry_allowed' => false,
    ],
    [portfolioBuyLeg('PANW')],
    [[
        'sleeve_id' => 'dynamic_loo10',
        'positions' => [['symbol' => 'PANW', 'qty' => 5]],
    ]],
);
portfolioMessageExpect(
    $actualAdd['allowed_now'] === true && ($actualAdd['actions'][0]['type'] ?? null) === 'add',
    'An authoritative executable PANW buy on an actually owned tactical position must be shown as an add even if shadow gross is unchanged.',
);

$rotationSignal = [
    'intended_session' => '2026-07-22',
    'targets' => [
        'dynamic_loo10' => [
            'action' => 'rebalance',
            'symbol' => 'MSFT',
            'gross' => 0.75,
            'current_symbol' => 'PANW',
            'current_gross' => 0.70,
            'due' => true,
        ],
    ],
];
$rotationPreopen = TacticalPortfolioStatusMessage::entryEligibility(
    $activeRun,
    'submit_preopen',
    $rotationSignal,
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-22T09:20:00-04:00'),
    [
        'status' => 'submit_preopen',
        'opg_submit_allowed' => true,
        'rotation_reentry_allowed' => false,
    ],
    [[
        'sleeve_id' => 'dynamic_loo10',
        'symbol' => 'PANW',
        'side' => 'sell',
        'requested_qty' => 3,
        'payload' => ['time_in_force' => 'opg'],
    ]],
    [[
        'sleeve_id' => 'dynamic_loo10',
        'positions' => [['symbol' => 'PANW', 'qty' => 3]],
    ]],
);
portfolioMessageExpect(
    $rotationPreopen['allowed_now'] === false
    && ($rotationPreopen['actions'][0]['type'] ?? null) === 'rotation'
    && ($rotationPreopen['blocked_reasons'][0]['code'] ?? null) === 'rotation_exit_first',
    'A PANW-to-MSFT rotation must not advertise its replacement buy before the exit fill.',
);
$rotationReentry = TacticalPortfolioStatusMessage::entryEligibility(
    $activeRun,
    'rotation_reentry_and_risk_exit_window',
    $rotationSignal,
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-22T09:31:00-04:00'),
    [
        'status' => 'rotation_reentry_and_risk_exit_window',
        'opg_submit_allowed' => false,
        'rotation_reentry_allowed' => true,
    ],
    [portfolioBuyLeg('MSFT', 'dynamic_loo10', true)],
    [[
        'sleeve_id' => 'dynamic_loo10',
        'positions' => [],
    ]],
);
portfolioMessageExpect(
    $rotationReentry['allowed_now'] === true
    && ($rotationReentry['actions'][0]['type'] ?? null) === 'rotation'
    && ($rotationReentry['actions'][0]['from_symbol'] ?? null) === 'закрытая позиция'
    && ($rotationReentry['executable_buy_legs'][0]['has_exit_dependency'] ?? false) === true,
    'A dependency-proven 09:30-09:32 replacement remains a rotation after the sell fill empties the ledger.',
);

$hardLegacy = $legacyStates;
$hardLegacy['TECL']['break_even_armed'] = true;
$hardMessage = TacticalPortfolioStatusMessage::build(
    'open',
    $now,
    ['status' => 'ACTIVE', 'equity' => 25750, 'cash' => 1906, 'buying_power' => 15345],
    [$positions[0]],
    [],
    $hardLegacy,
    $run,
    'legacy_positions_in_control',
    $signal,
    [],
    $eligibility,
    [],
    ['is_open' => true, 'timestamp' => '2026-07-21T10:05:00-04:00'],
    ['swing_stop_mode' => 'mental', 'break_even_stop_mode' => 'hard'],
);
portfolioMessageHas($hardMessage, 'hard intraday monitor-stop $178.93', 'An armed hard BE stop must not be described as daily-close mental.');
portfolioMessageHas($hardMessage, 'не является standing stop-order', 'Hard monitor behavior must not imply a broker-resident stop order.');

$modelBreakEvenLegacy = $legacyStates;
$modelBreakEvenLegacy['TECL']['payload']['model_position'] = [
    'break_even_armed' => true,
    'hard_stop_active' => true,
];
$modelBreakEvenMessage = TacticalPortfolioStatusMessage::build(
    'open',
    $now,
    ['status' => 'ACTIVE', 'equity' => 25750, 'cash' => 1906, 'buying_power' => 15345],
    [$positions[0]],
    [],
    $modelBreakEvenLegacy,
    $run,
    'legacy_positions_in_control',
    $signal,
    [],
    $eligibility,
    [],
    ['is_open' => true, 'timestamp' => '2026-07-21T10:05:00-04:00'],
    ['swing_stop_mode' => 'mental', 'break_even_stop_mode' => 'hard'],
);
portfolioMessageHas($modelBreakEvenMessage, 'hard intraday monitor-stop $178.93', 'Model-position BE state must select the effective hard stop mode.');
portfolioMessageHas($modelBreakEvenMessage, 'БУ ДА', 'Model-position BE state must be reflected in the displayed BE status.');
portfolioMessageExpect(
    !str_contains($modelBreakEvenMessage, 'триггер БУ $215.89'),
    'An effective model-position BE state must hide the already-crossed BE trigger.',
);

$closedLegacy = $legacyStates;
$closedLegacy['TECL']['status'] = 'closed';
$closedLegacy['TECL']['qty'] = 0;
$hybridMessage = TacticalPortfolioStatusMessage::build(
    'status',
    $now,
    ['status' => 'ACTIVE', 'equity' => 25750, 'cash' => 1906, 'buying_power' => 15345],
    [$positions[0]],
    [],
    $closedLegacy,
    ['status' => 'active', 'activated_at' => '2026-07-20T12:00:00Z'],
    'reconciled_hold',
    $signal,
    [],
    $eligibility,
    [],
    ['is_open' => true, 'timestamp' => '2026-07-21T10:05:00-04:00'],
);
portfolioMessageHas($hybridMessage, 'TECL • 66 шт. • hybrid', 'A closed stale legacy row must not claim a future hybrid position.');
portfolioMessageExpect(
    !str_contains($hybridMessage, 'close-stop $178.93'),
    'A closed or quantity-mismatched legacy state must not leak a stale stop.',
);

echo "tactical portfolio status message tests passed\n";
