<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/** Builds visually distinct Telegram messages for broker-confirmed intent states. */
final class TacticalIntentStatusMessage
{
    /**
     * @param array<string,mixed> $intent
     * @return array{text:string,rich_message:?array<string,mixed>}
     */
    public static function build(array $intent): array
    {
        $side = strtolower(trim((string) ($intent['side'] ?? '')));
        $status = strtolower(trim((string) ($intent['status'] ?? 'unknown')));
        $symbol = strtoupper(trim((string) ($intent['symbol'] ?? 'UNKNOWN')));
        $sleeve = trim((string) ($intent['sleeve_id'] ?? 'unknown'));
        $session = trim((string) ($intent['scheduled_session'] ?? 'unknown'));
        $requestedQty = max(0.0, (float) ($intent['requested_qty'] ?? 0.0));
        $filledQty = max(0.0, (float) ($intent['cumulative_filled_qty'] ?? 0.0));

        if ($side === 'buy' && $filledQty > 1.0e-9) {
            return self::buyFill(
                $symbol,
                $sleeve,
                $session,
                $status,
                $requestedQty,
                $filledQty,
                max(0.0, (float) ($intent['cumulative_fill_notional'] ?? 0.0)),
            );
        }

        if ($side === 'buy') {
            $text = implode("\n", [
                '🟦 ЗАЯВКА НА ОТКРЫТИЕ • ЕЩЁ НЕ ИСПОЛНЕНА',
                sprintf('%s • запрошено %s акций • статус %s', $symbol, self::quantity($requestedQty), strtoupper($status)),
                'Подтверждённого fill нет: позиция ещё не считается открытой.',
                sprintf('Рукав %s • сессия %s', $sleeve, $session),
            ]);

            return ['text' => $text, 'rich_message' => null];
        }

        $text = sprintf(
            "🧾 Hybrid-v4 %s\n%s %s • сессия %s • рукав %s\nИсполнено: %s / %s",
            strtoupper($status),
            strtoupper($side !== '' ? $side : 'unknown'),
            $symbol,
            $session,
            $sleeve,
            self::quantity($filledQty),
            self::quantity($requestedQty),
        );

        return ['text' => $text, 'rich_message' => null];
    }

    /** @return array{text:string,rich_message:array<string,mixed>} */
    private static function buyFill(
        string $symbol,
        string $sleeve,
        string $session,
        string $status,
        float $requestedQty,
        float $filledQty,
        float $filledNotional,
    ): array {
        $complete = $status === 'filled'
            || ($requestedQty > 0.0 && $filledQty + 1.0e-9 >= $requestedQty);
        $heading = $complete
            ? '🚨 🟢 АКЦИИ ОТКРЫТЫ 🟢 🚨'
            : '⚠️ 🟡 АКЦИИ ЧАСТИЧНО ОТКРЫТЫ 🟡 ⚠️';
        $fillLabel = $complete ? 'КУПЛЕНО' : 'ЧАСТИЧНО КУПЛЕНО';
        $factLabel = $complete
            ? '‼️ ФАКТИЧЕСКОЕ ИСПОЛНЕНИЕ У БРОКЕРА'
            : '‼️ ЧАСТИЧНОЕ ИСПОЛНЕНИЕ У БРОКЕРА';
        $averagePrice = $filledNotional > 0.0 ? $filledNotional / $filledQty : null;
        $quantityLine = sprintf(
            '%s • %s %s / %s',
            $symbol,
            $fillLabel,
            self::quantity($filledQty),
            self::quantity($requestedQty),
        );
        $details = [
            $heading,
            '━━━━━━━━━━━━━━━━━━━━',
            ($complete ? '✅ ' : '🟡 ') . $quantityLine,
        ];
        if ($averagePrice !== null) {
            $details[] = '💵 Средняя цена fill: ' . self::money($averagePrice);
        }
        $details[] = '🗂 Рукав: ' . $sleeve;
        $details[] = '📅 Сессия: ' . $session;
        $details[] = $factLabel;
        $details[] = 'Это не кандидат и не предварительный сигнал.';
        $details[] = '━━━━━━━━━━━━━━━━━━━━';

        $blocks = [
            [
                'type' => 'heading',
                'size' => 1,
                'text' => [
                    'type' => 'marked',
                    'text' => ['type' => 'bold', 'text' => $heading],
                ],
            ],
            ['type' => 'divider'],
            [
                'type' => 'paragraph',
                'text' => [
                    $complete ? '✅ ' : '🟡 ',
                    ['type' => 'bold', 'text' => $symbol],
                    sprintf(
                        ' • %s %s / %s',
                        $fillLabel,
                        self::quantity($filledQty),
                        self::quantity($requestedQty),
                    ),
                ],
            ],
        ];
        if ($averagePrice !== null) {
            $blocks[] = [
                'type' => 'paragraph',
                'text' => [
                    '💵 Средняя цена fill: ',
                    ['type' => 'bold', 'text' => self::money($averagePrice)],
                ],
            ];
        }
        $blocks[] = [
            'type' => 'paragraph',
            'text' => "🗂 Рукав: {$sleeve}\n📅 Сессия: {$session}",
        ];
        $blocks[] = [
            'type' => 'paragraph',
            'text' => [
                ['type' => 'marked', 'text' => ['type' => 'bold', 'text' => $factLabel]],
                "\nЭто не кандидат и не предварительный сигнал.",
            ],
        ];
        $blocks[] = ['type' => 'divider'];

        return [
            'text' => implode("\n", $details),
            'rich_message' => [
                'blocks' => $blocks,
                'skip_entity_detection' => true,
            ],
        ];
    }

    private static function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    private static function money(float $value): string
    {
        return '$' . number_format($value, 2, '.', ',');
    }
}
