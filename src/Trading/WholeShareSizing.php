<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/** Whole-share targets use only the decision-time NAV and nominal prices. */
final class WholeShareSizing
{
    public static function target(float $nav, array $currentNotionals, ?string $symbol, float $gross, array $prices, float $costBps): array
    {
        self::positive($nav, 'NAV');
        if (!is_finite($gross) || $gross < 0 || $gross > 1.30 || !is_finite($costBps) || $costBps < 0 || $costBps > 1000) {
            throw new \InvalidArgumentException('Invalid gross or transaction-cost envelope.');
        }
        foreach ($currentNotionals as $value) {
            if (!is_numeric($value) || !is_finite((float) $value) || $value < 0) { throw new \InvalidArgumentException('Long-only finite holdings required.'); }
        }
        if ($symbol === null) {
            if ($gross !== 0.0) { throw new \InvalidArgumentException('Cash target must have zero gross.'); }
            return [];
        }
        if (!preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/D', $symbol) || $gross <= 0) { throw new \InvalidArgumentException('Invalid invested target.'); }
        $price = $prices[$symbol] ?? null;
        if (!is_numeric($price)) { throw new \InvalidArgumentException('Missing nominal decision price.'); }
        self::positive((float) $price, 'nominal price');
        $price = (float) $price; $rate = $costBps / 10000;
        $other = array_sum($currentNotionals) - (float) ($currentNotionals[$symbol] ?? 0.0);
        $cost = static fn (int $qty): float => $rate * ($other + abs($qty * $price - (float) ($currentNotionals[$symbol] ?? 0.0)));
        if ($nav * $gross / $price > PHP_INT_MAX / 2) { throw new \InvalidArgumentException('Share count overflow.'); }
        $low = 0; $high = (int) floor($nav * $gross / $price);
        while ($low < $high) {
            $mid = $low + intdiv($high - $low + 1, 2);
            if ($mid * $price <= $gross * ($nav - $cost($mid)) + 1e-9) { $low = $mid; }
            else { $high = $mid - 1; }
        }
        return $low > 0 ? [$symbol => $low] : [];
    }

    /** Apply the already fixed quantities to actual execution prices, never resize using the future open. */
    public static function execute(float $nav, array $currentWeights, array $quantities, array $prices, float $costBps): array
    {
        self::positive($nav, 'execution NAV');
        if (!is_finite($costBps) || $costBps < 0 || $costBps > 1000) { throw new \InvalidArgumentException('Invalid cost.'); }
        $notionals = [];
        foreach ($quantities as $symbol => $qty) {
            if (!is_int($qty) || $qty <= 0 || !is_numeric($prices[$symbol] ?? null)) { throw new \InvalidArgumentException('Positive whole shares and nominal prices required.'); }
            self::positive((float) $prices[$symbol], 'execution price');
            $notionals[$symbol] = $qty * (float) $prices[$symbol];
        }
        $costs = []; $turnover = 0.0;
        foreach (array_unique(array_merge(array_keys($currentWeights), array_keys($notionals))) as $symbol) {
            $weight = (float) ($currentWeights[$symbol] ?? 0.0);
            if (!is_finite($weight) || $weight < 0) { throw new \InvalidArgumentException('Invalid current weight.'); }
            $traded = abs(($notionals[$symbol] ?? 0.0) - $nav * $weight);
            $turnover += $traded / $nav;
            $costs[$symbol] = $traded * $costBps / 10000 / $nav;
        }
        $fraction = array_sum($costs); $post = $nav * (1 - $fraction);
        self::positive($post, 'post-cost NAV');
        return ['weights' => array_map(static fn ($n): float => $n / $post, $notionals),
            'cost_fraction' => $fraction, 'turnover' => $turnover, 'costs' => $costs, 'post_cost_nav' => $post,
            'cash' => $post - array_sum($notionals), 'quantities' => $quantities];
    }

    private static function positive(float $value, string $label): void
    {
        if (!is_finite($value) || $value <= 0) { throw new \InvalidArgumentException('Positive finite ' . $label . ' required.'); }
    }
}
