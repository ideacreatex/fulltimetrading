<?php

declare(strict_types=1);

namespace FulltimeTrading\Support;

final class TelegramMessageClassifier
{
    /** @param array<string, mixed> $event @return array<string, mixed> */
    public function classify(array $event): array
    {
        $text = $this->normalize((string) ($event['text'] ?? ''));
        $tickers = array_map('strtoupper', is_array($event['tickers'] ?? null) ? $event['tickers'] : []);
        $supportMentions = is_array($event['support_mentions'] ?? null) ? $event['support_mentions'] : [];

        $patterns = [
            'exit' => [
                '~(?<!не )закрыва(?:ем|ю)|закрыл[аи]?|закрыти[ея]\s+позиции|выш(?:ел|ла|ли)|выхожу|(?<!не )(?:фиксирую|фиксируем|зафиксировал[аи]?|зафиксировали)|продал[аи]?|(?<!не )продаю|снял[аи]?\s+позици|нарушен\p{L}*\s+стратеги|стратеги\p{L}*\s+наруш|сломал\p{L}*\s+стратеги~u',
                '~(?<![\p{L}\p{N}_])(?:выход|exit|sell)(?![\p{L}\p{N}_])~u',
            ],
            'stop_to_breakeven' => [
                '~стоп\p{L}*\s+в\s+(?:бу|нол)|без\s*убыт\p{L}*|break\s*even|breakeven~u',
                '~(?<![\p{L}\p{N}_])бу(?![\p{L}\p{N}_])~u',
            ],
            'add' => [
                '~(?<![\p{L}\p{N}_])(?:докуп\p{L}*|добира\p{L}*|долив\p{L}*)(?![\p{L}\p{N}_])~u',
                '~(?<![\p{L}\p{N}_])(?:добавил[аи]?|добавля(?:ю|ем)|увеличил[аи]?|увеличива(?:ю|ем)|усилил[аи]?)(?![\p{L}\p{N}_])\s+(?:позици\p{L}*|размер\p{L}*\s+позици\p{L}*|объем\p{L}*|нагрузк\p{L}*|лот\p{L}*)~u',
                '~(?<![\p{L}\p{N}_])(?:leverage|load|add)(?![\p{L}\p{N}_])~u',
            ],
            'entry' => [
                '~(?<![\p{L}\p{N}_])(?:взял[аи]?|беру|купил[аи]?|покупаю|заш[её]л|лонг|long)(?![\p{L}\p{N}_])~u',
                '~(?<![\p{L}\p{N}_])(?:открыва(?:ю|ем)|открыл[аи]?)(?![\p{L}\p{N}_])\s+(?:нов\p{L}*\s+)?(?:позици\p{L}*|сделк\p{L}*|лонг|long|шорт|short)~u',
                '~(?:вхож\p{L}*|заш[её]л|открыва(?:ю|ем)|беру)\s+(?:в\s+)?(?:шорт|short)|(?<![\p{L}\p{N}_])шортим(?![\p{L}\p{N}_])~u',
            ],
            'hold' => [
                '~(?<![\p{L}\p{N}_])держ(?:у|им)(?![\p{L}\p{N}_])|не\s+закрыва\p{L}*|не\s+трога\p{L}*~u',
                '~(?:позици\p{L}*|сделк\p{L}*|остат\p{L}*|раннер\p{L}*|портфел\p{L}*)\s+(?:остае\p{L}*|открыт\p{L}*|держ\p{L}*|удержива\p{L}*)~u',
                '~(?:держ\p{L}*|удержива\p{L}*|оставля\p{L}*)\s+(?:позици\p{L}*|сделк\p{L}*|остат\p{L}*|раннер\p{L}*|портфел\p{L}*)~u',
                '~сидим\s+(?:в\s+)?(?:позици\p{L}*|сделк\p{L}*)~u',
            ],
            'risk_context' => [
                '~(?<![\p{L}\p{N}_])(?:vix|vvix|dxy|us20y|m2sl|pcc|pcsp)(?![\p{L}\p{N}_])~u',
                '~рынок|индекс|ширин\p{L}*\s+рынка|breadth|волатильн\p{L}*|страх|нестабил\p{L}*|опасн\p{L}*|риск|премаркет|постмаркет|regular\s+session~u',
                '~нагрузк\p{L}*|плеч\p{L}*|размер\p{L}*\s+позици\p{L}*|портфел\p{L}*~u',
            ],
            'setup_analysis' => [
                '~(?<![\p{L}\p{N}_])(?:поос|ema|ема|sma|rsi|macd)(?![\p{L}\p{N}_])~u',
                '~поддерж\p{L}*|сопротив\p{L}*|закономер\p{L}*|скольз\p{L}*|молот\p{L}*|поглощ\p{L}*|фитил\p{L}*|уров\p{L}*|перепрод\p{L}*|отскок\p{L}*~u',
                '~зон\p{L}*\s+для\s+покупк\p{L}*|точк\p{L}*\s+входа|сигнал\p{L}*\s+на\s+вход~u',
            ],
        ];

        $scores = array_fill_keys(array_keys($patterns), 0);
        $reasons = array_fill_keys(array_keys($patterns), []);
        foreach ($patterns as $type => $expressions) {
            foreach ($expressions as $expression) {
                if (preg_match($expression, $text, $match) === 1) {
                    $scores[$type]++;
                    $reasons[$type][] = (string) ($match[0] ?? $expression);
                }
            }
        }

        if ($supportMentions !== []) {
            $scores['setup_analysis'] += 2;
            $reasons['setup_analysis'][] = 'support_mentions';
        }
        if (array_intersect($tickers, ['VIX', 'VVIX', 'DXY', 'US20Y', 'M2SL', 'PCC', 'PCSP', 'NYA', 'SX5E', 'SXXP']) !== []) {
            $scores['risk_context'] += 2;
            $reasons['risk_context'][] = 'context_ticker';
        }
        if (array_intersect($tickers, ['SPY', 'SPX', 'ES', 'QQQ', 'NQ', 'NDX', 'IXIC', 'RSP', 'SMH', 'DIA', 'YM']) !== []) {
            $scores['risk_context'] += 1;
            $reasons['risk_context'][] = 'market_ticker';
        }

        $types = array_keys(array_filter($scores, static fn (int $score): bool => $score > 0));
        if ($types === []) {
            $types = ['other'];
            $scores['other'] = 1;
            $reasons['other'] = ['no_rule_match'];
        }
        $primary = $this->primaryType($scores);
        $direction = $this->direction($text, $primary);
        $verification = $this->verifyRealAction($primary, $text, $tickers);

        return [
            'primary_type' => $primary,
            'types' => $types,
            'scores' => $scores,
            'reasons' => array_filter($reasons, static fn (array $items): bool => $items !== []),
            'action' => match ($primary) {
                'exit' => 'exit',
                'stop_to_breakeven' => 'stop_to_breakeven',
                'add' => 'add',
                'entry' => 'entry',
                'hold' => 'hold',
                'risk_context', 'setup_analysis' => 'analysis',
                default => 'other',
            },
            'verified_real_action' => $verification['verified'],
            'action_verification_reason' => $verification['reason'],
            'market_direction' => $direction['direction'],
            'direction_scores' => $direction['scores'],
            'direction_reasons' => $direction['reasons'],
        ];
    }

    /** @param list<string> $tickers @return array{verified:bool,reason:string} */
    private function verifyRealAction(string $primaryType, string $text, array $tickers): array
    {
        if (!in_array($primaryType, ['entry', 'add', 'exit'], true)) {
            return ['verified' => false, 'reason' => 'message_is_not_an_executed_trade_action'];
        }
        if ($tickers === []) {
            return ['verified' => false, 'reason' => 'trade_action_has_no_ticker'];
        }
        if (preg_match('~(?<![\p{L}\p{N}_])(?:можем|можно|планир\p{L}*|хочу|буду|если)(?![\p{L}\p{N}_])~u', $text) === 1) {
            return ['verified' => false, 'reason' => 'trade_action_is_conditional_or_planned'];
        }

        $tickerPattern = implode('|', array_map(static fn (string $ticker): string => preg_quote($ticker, '~'), $tickers));
        $explicitTickerTake = $tickerPattern !== '' && preg_match(
            '~(?<![\p{L}\p{N}_])беру(?![\p{L}\p{N}_])\s+(?:(?:в\s+)?(?:шорт|short|лонг|long)\s+)?\$?(?:' . $tickerPattern . ')(?![\p{L}\p{N}_])~iu',
            $text,
        ) === 1;
        $executed = match ($primaryType) {
            'entry' => $explicitTickerTake || preg_match('~(?<![\p{L}\p{N}_])(?:взял[аи]?|купил[аи]?|покупаю|заш[её]л|лонг|long)(?![\p{L}\p{N}_])|(?:открыл[аи]?|открыва(?:ю|ем))\s+(?:нов\p{L}*\s+)?(?:позици\p{L}*|сделк\p{L}*|лонг|long|шорт|short)|беру\s+(?:в\s+)?(?:шорт|short)~u', $text) === 1,
            'add' => preg_match('~(?<![\p{L}\p{N}_])(?:докупил[аи]?|докупаю|добрал[аи]?|добираю|долил[аи]?|добавил[аи]?|добавляю|увеличил[аи]?|увеличиваю)(?![\p{L}\p{N}_])~u', $text) === 1,
            'exit' => preg_match('~(?<![\p{L}\p{N}_])(?:закрыл[аи]?|выш(?:ел|ла|ли)|выхожу|продал[аи]?|продаю|зафиксировал[аи]?)(?![\p{L}\p{N}_])~u', $text) === 1,
            default => false,
        };

        return $executed
            ? ['verified' => true, 'reason' => 'ticker_and_executed_action_present']
            : ['verified' => false, 'reason' => 'executed_trade_verb_not_present'];
    }

    /** @param array<string, int> $scores */
    private function primaryType(array $scores): string
    {
        foreach (['exit', 'stop_to_breakeven', 'add', 'entry', 'hold', 'risk_context', 'setup_analysis', 'other'] as $type) {
            if ((int) ($scores[$type] ?? 0) > 0) {
                return $type;
            }
        }

        return 'other';
    }

    /** @return array{direction:string,scores:array<string,int>,reasons:array<string,list<string>>} */
    private function direction(string $text, string $primaryType): array
    {
        $patterns = [
            'bullish' => [
                '~(?<![\p{L}\p{N}_])(?:быч\p{L}*|восходящ\p{L}*)(?![\p{L}\p{N}_])|продолжени\p{L}*\s+тренда|движени\p{L}*\s+вверх|свинг\p{L}*\s+вверх|(?<!не )поддерж\p{L}*\s+рынок~u',
                '~удержал\p{L}*\s+(?:\d+\s*)?(?:ema|sma|ma|скольз)|зон\p{L}*\s+для\s+покупк|сетап\p{L}*\s+подхвата~u',
            ],
            'bearish' => [
                '~(?<![\p{L}\p{N}_])(?:медвеж\p{L}*|нисходящ\p{L}*|падени\p{L}*)(?![\p{L}\p{N}_])|движени\p{L}*\s+вниз|сквиз\p{L}*\s+вниз|контртрендов\p{L}*~u',
                '~(?<![\p{L}\p{N}_])(?:пуллб[эе]к\p{L}*|сопротивлен\p{L}*|выдыха\p{L}*)(?![\p{L}\p{N}_])|поглощени\p{L}*\s+продавц|давлени\p{L}*\s+на\s+рынок|падающ\p{L}*\s+звезд~u',
            ],
        ];
        $scores = ['bullish' => 0, 'bearish' => 0];
        $reasons = ['bullish' => [], 'bearish' => []];
        foreach ($patterns as $direction => $expressions) {
            foreach ($expressions as $expression) {
                if (preg_match($expression, $text, $match) === 1) {
                    $scores[$direction]++;
                    $reasons[$direction][] = (string) ($match[0] ?? $expression);
                }
            }
        }
        foreach (['bullish', 'bearish'] as $direction) {
            foreach ($reasons[$direction] as $index => $reason) {
                $quoted = preg_quote($reason, '~');
                if (preg_match('~(?<![\p{L}\p{N}_])если(?![\p{L}\p{N}_])[^.!?]{0,180}' . $quoted . '~u', $text) !== 1) {
                    continue;
                }
                unset($reasons[$direction][$index]);
                $scores[$direction] = max(0, $scores[$direction] - 1);
            }
            $reasons[$direction] = array_values($reasons[$direction]);
        }
        if ($primaryType === 'entry') {
            if (preg_match('~(?<![\p{L}\p{N}_])(?:(?:взял[аи]?|беру|купил[аи]?|покупаю|заш[её]л)(?![\p{L}\p{N}_]|\s+(?:в\s+)?(?:шорт|short))|лонг|long)(?![\p{L}\p{N}_])~u', $text, $match) === 1) {
                $scores['bullish']++;
                $reasons['bullish'][] = (string) ($match[0] ?? 'explicit_long_entry');
            }
            if (preg_match('~(?<![\p{L}\p{N}_])(?:шорт|short|шортим)(?![\p{L}\p{N}_])~u', $text, $match) === 1) {
                $scores['bearish']++;
                $reasons['bearish'][] = (string) ($match[0] ?? 'explicit_short_entry');
            }
        }
        $direction = match (true) {
            $scores['bullish'] > 0 && $scores['bearish'] > 0 => 'mixed',
            $scores['bullish'] > 0 => 'bullish',
            $scores['bearish'] > 0 => 'bearish',
            default => 'neutral',
        };

        return [
            'direction' => $direction,
            'scores' => $scores,
            'reasons' => array_filter($reasons, static fn (array $items): bool => $items !== []),
        ];
    }

    private function normalize(string $text): string
    {
        $text = str_replace('ё', 'е', mb_strtolower($text));

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
