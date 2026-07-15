<?php

declare(strict_types=1);

use FulltimeTrading\Support\TelegramMessageClassifier;
use FulltimeTrading\Support\TelegramSignalAlignment;

require __DIR__ . '/../bootstrap.php';

function telegramAlignmentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$classifier = new TelegramMessageClassifier();
$alignment = new TelegramSignalAlignment();

$ordinaryWord = $classifier->classify([
    'text' => 'На следующей неделе будет важен рынок.',
    'tickers' => ['SPY'],
]);
telegramAlignmentAssert(
    !in_array('stop_to_breakeven', $ordinaryWord['types'], true),
    'The letters бу inside будет must not be classified as a breakeven action.',
);

$supportHolding = $classifier->classify([
    'text' => 'Если цена не удерживает 20 скользящую, сразу начинался сквиз вниз.',
    'tickers' => ['XLG'],
    'support_mentions' => [['period' => 20, 'kind' => 'MA']],
]);
telegramAlignmentAssert(
    !in_array('hold', $supportHolding['types'], true),
    'Price holding a support must remain setup analysis, not a position hold instruction.',
);
telegramAlignmentAssert($supportHolding['action'] === 'analysis', 'Support behavior must be classified as analysis.');
telegramAlignmentAssert($supportHolding['market_direction'] === 'neutral', 'A conditional historical downside clause must not become a current bearish call.');

$portfolioCommentary = $classifier->classify([
    'text' => 'SMH выдыхается у сопротивления. MAGS и RSP могут поддержать рынок. Портфель выглядит отлично.',
    'tickers' => ['SMH', 'MAGS', 'RSP'],
]);
telegramAlignmentAssert(
    !in_array('hold', $portfolioCommentary['types'], true),
    'A portfolio mention without an explicit position-hold verb must not become a hold instruction.',
);
telegramAlignmentAssert($portfolioCommentary['action'] === 'analysis', 'Market commentary must remain analysis.');

$explicitPortfolioHold = $classifier->classify([
    'text' => 'Портфель удерживается, позиции не закрываем.',
    'tickers' => [],
]);
telegramAlignmentAssert($explicitPortfolioHold['action'] === 'hold', 'An explicit portfolio hold must remain a hold instruction.');
telegramAlignmentAssert(
    !in_array('exit', $explicitPortfolioHold['types'], true),
    'A negated close instruction must not be classified as an exit.',
);

$ordinaryUsually = $classifier->classify([
    'text' => 'Такие совпадения случаются чаще, чем кажется. Результат обычно оказывается соответствующим.',
    'tickers' => ['TSLA'],
]);
telegramAlignmentAssert(
    $ordinaryUsually['market_direction'] === 'neutral',
    'The substring быч inside обычно must not create a bullish direction.',
);

$explicitLongEntry = $classifier->classify([
    'text' => 'Покупаю TQQQ по сигналу.',
    'tickers' => ['TQQQ'],
]);
telegramAlignmentAssert($explicitLongEntry['action'] === 'entry', 'An explicit buy must be an entry action.');
telegramAlignmentAssert($explicitLongEntry['market_direction'] === 'bullish', 'An explicit buy/long entry must infer bullish direction.');
telegramAlignmentAssert($explicitLongEntry['verified_real_action'] === true, 'A ticker-bound executed buy must be verifiable.');

$explicitShortEntry = $classifier->classify([
    'text' => 'Беру шорт QQQ.',
    'tickers' => ['QQQ'],
]);
telegramAlignmentAssert($explicitShortEntry['action'] === 'entry', 'An explicit short must be an entry action.');
telegramAlignmentAssert($explicitShortEntry['market_direction'] === 'bearish', 'An explicit short entry must infer bearish direction.');
telegramAlignmentAssert($explicitShortEntry['verified_real_action'] === true, 'A ticker-bound explicit short must be verifiable.');

$explicitTickerTake = $classifier->classify([
    'text' => 'Беру TQQQ.',
    'tickers' => ['TQQQ'],
]);
telegramAlignmentAssert($explicitTickerTake['verified_real_action'] === true, 'Taking an extracted ticker must be verifiable without accepting generic беру phrases.');

$openedChart = $classifier->classify([
    'text' => 'Получили сигнал, открыли график, приняли решение.',
    'tickers' => [],
]);
telegramAlignmentAssert($openedChart['action'] !== 'entry', 'Opening a chart must not be classified as opening a position.');

$addedIntrospection = $classifier->classify([
    'text' => 'Дисциплина добавляет самоанализа каждой позиции.',
    'tickers' => [],
]);
telegramAlignmentAssert($addedIntrospection['action'] !== 'add', 'Adding introspection must not be classified as adding to a trade.');

$futurePortfolioIncrease = $classifier->classify([
    'text' => 'Со временем планирую увеличить портфель до миллиона.',
    'tickers' => [],
]);
telegramAlignmentAssert($futurePortfolioIncrease['action'] !== 'add', 'A future portfolio plan must not be classified as an executed add.');

$unidentifiedEntry = $classifier->classify([
    'text' => 'Я открыл позицию и заработал на ней.',
    'tickers' => [],
]);
telegramAlignmentAssert($unidentifiedEntry['action'] === 'entry', 'The text still contains an entry action mention.');
telegramAlignmentAssert($unidentifiedEntry['verified_real_action'] === false, 'An entry without a ticker must not be marked as a verified real action.');

$bookkeepingRecord = $classifier->classify([
    'text' => 'Прибыль и лосс будут зафиксированы в личном кабинете.',
    'tickers' => [],
]);
telegramAlignmentAssert($bookkeepingRecord['action'] !== 'exit', 'A bookkeeping record must not be classified as a position exit.');

$breakeven = $classifier->classify([
    'text' => 'После +1% переводим стоп в БУ.',
    'tickers' => ['TQQQ'],
]);
telegramAlignmentAssert(
    in_array('stop_to_breakeven', $breakeven['types'], true),
    'A standalone БУ token must remain a breakeven action.',
);

$longSignal = [[
    'symbol' => 'SOXL',
    'date' => '2026-06-28',
    'direction' => 'long',
    'strategy' => 'SUPPORT_REGULARITY',
]];
$bearishClassification = $classifier->classify([
    'text' => 'SOXX нарисовал поглощение продавцами у сопротивления, ожидаем пуллбэк.',
    'tickers' => ['SOXX'],
]);
$bearishEvent = [
    'message_action' => $bearishClassification['action'],
    'market_direction' => $bearishClassification['market_direction'],
];
$bearishAlignment = $alignment->evaluate($bearishEvent, $longSignal, true, true);
telegramAlignmentAssert($bearishClassification['market_direction'] === 'bearish', 'Bearish market text must expose bearish direction.');
telegramAlignmentAssert($bearishAlignment['matched'] === false, 'A bearish author message must not match a long model signal.');
telegramAlignmentAssert($bearishAlignment['direction_mismatch'] === true, 'Opposite temporal signals must be reported as a direction mismatch.');
telegramAlignmentAssert($bearishAlignment['reason'] === 'opposite_signal_direction', 'Direction mismatch reason must be explicit.');

$bullishClassification = $classifier->classify([
    'text' => 'QQQ удержал 10 EMA W, сформирован бычий молот и возможно продолжение тренда.',
    'tickers' => ['QQQ'],
]);
$bullishAlignment = $alignment->evaluate([
    'message_action' => $bullishClassification['action'],
    'market_direction' => $bullishClassification['market_direction'],
], $longSignal, true, true);
telegramAlignmentAssert($bullishClassification['market_direction'] === 'bullish', 'Bullish setup must expose bullish direction.');
telegramAlignmentAssert($bullishAlignment['matched'] === true, 'A bullish analysis may align with a long temporal signal.');

$exitClassification = $classifier->classify([
    'text' => 'Закрываем позицию, стратегия нарушена.',
    'tickers' => ['TQQQ'],
]);
$exitAlignment = $alignment->evaluate([
    'message_action' => $exitClassification['action'],
    'market_direction' => 'bullish',
], $longSignal, true, true);
telegramAlignmentAssert($exitClassification['action'] === 'exit', 'Exit action classification mismatch.');
telegramAlignmentAssert($exitAlignment['matched'] === false, 'An exit message must not match an entry signal merely by date/family.');
telegramAlignmentAssert($exitAlignment['action_comparable'] === false, 'Exit must be marked non-comparable to entry signals.');

$mixedClassification = $classifier->classify([
    'text' => 'MAGS и RSP могут поддержать рынок, но SOXX у сопротивления и возможен пуллбэк.',
    'tickers' => ['SOXX'],
]);
$mixedAlignment = $alignment->evaluate([
    'message_action' => $mixedClassification['action'],
    'market_direction' => $mixedClassification['market_direction'],
], $longSignal, true, true);
telegramAlignmentAssert($mixedClassification['market_direction'] === 'mixed', 'Conflicting market evidence must remain mixed.');
telegramAlignmentAssert($mixedAlignment['matched'] === false, 'Mixed direction must not inflate strict signal alignment.');
telegramAlignmentAssert($mixedAlignment['direction_comparable'] === false, 'Mixed direction must be explicitly non-comparable.');

$weeklyAuthorSetup = [
    'message_action' => 'analysis',
    'market_direction' => 'bullish',
    'support_mentions' => [['period' => 10, 'type' => 'EMA', 'timeframe' => 'W']],
];
$dailyModelSetup = [[
    'symbol' => 'TQQQ',
    'date' => '2026-07-10',
    'direction' => 'long',
    'metadata' => ['ma_period' => 10, 'ma_type' => 'ema', 'timeframe' => 'D'],
]];
$setupMismatch = $alignment->evaluate($weeklyAuthorSetup, $dailyModelSetup, true, true, true);
telegramAlignmentAssert($setupMismatch['matched'] === false, 'A weekly author setup must not verify a daily model setup.');
telegramAlignmentAssert($setupMismatch['coarse_matched'] === true, 'The direction association must remain visible as coarse only.');
telegramAlignmentAssert($setupMismatch['setup_mismatch'] === true, 'Timeframe mismatch must be explicit.');
telegramAlignmentAssert($setupMismatch['reason'] === 'support_timeframe_or_ma_mismatch', 'Setup mismatch reason must be explicit.');

$weeklyModelSetup = [[
    'symbol' => 'TQQQ',
    'date' => '2026-07-10',
    'direction' => 'long',
    'metadata' => ['ma_period' => 10, 'ma_type' => 'ema', 'timeframe' => 'W'],
]];
$verifiedSetup = $alignment->evaluate($weeklyAuthorSetup, $weeklyModelSetup, true, true, true);
telegramAlignmentAssert($verifiedSetup['matched'] === true, 'Matching timeframe, MA type and period must verify the setup.');

$multiTickerSetup = [
    'message_action' => 'analysis',
    'market_direction' => 'bullish',
    'tickers' => ['QQQ', 'SMH'],
    'comparison_ticker' => 'QQQ',
    'support_mentions' => [['period' => 10, 'type' => 'EMA', 'timeframe' => 'W']],
];
$ambiguousSetup = $alignment->evaluate($multiTickerSetup, $weeklyModelSetup, true, true, true);
telegramAlignmentAssert($ambiguousSetup['matched'] === false, 'Flat support mentions must not verify one ticker in a multi-ticker message.');
telegramAlignmentAssert($ambiguousSetup['setup_ambiguous'] === true, 'Unbound multi-ticker support must be marked ambiguous.');
telegramAlignmentAssert($ambiguousSetup['reason'] === 'event_support_mentions_not_ticker_bound', 'Ambiguous support reason must be explicit.');

$multiTickerSetup['ticker_support_mentions'] = [
    'QQQ' => [['period' => 10, 'type' => 'EMA', 'timeframe' => 'W']],
];
$tickerBoundSetup = $alignment->evaluate($multiTickerSetup, $weeklyModelSetup, true, true, true);
telegramAlignmentAssert($tickerBoundSetup['matched'] === true, 'Explicit ticker-bound support may verify the corresponding setup.');

$legacyUnverifiedEntry = $alignment->evaluate([
    'message_action' => 'entry',
    'market_direction' => 'bullish',
    'tickers' => ['TQQQ'],
    'support_mentions' => [['period' => 10, 'type' => 'EMA', 'timeframe' => 'W']],
], $weeklyModelSetup, true, true, true);
telegramAlignmentAssert($legacyUnverifiedEntry['matched'] === false, 'A raw legacy entry without an explicit verification flag must fail closed.');
telegramAlignmentAssert($legacyUnverifiedEntry['reason'] === 'entry_action_not_verified', 'Unverified entry reason must be explicit.');

echo "Telegram message classification and alignment OK\n";
