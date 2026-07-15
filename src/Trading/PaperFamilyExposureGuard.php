<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

final class PaperFamilyExposureGuard
{
    /** @return array<string, float> */
    public function exposureByFamily(array $positions, array $openOrders): array
    {
        $exposure = [];
        foreach ($positions as $position) {
            if (!is_array($position)) {
                continue;
            }
            $family = $this->familyForSymbol((string) ($position['symbol'] ?? ''));
            $marketValue = abs((float) ($position['market_value'] ?? 0.0));
            if ($family !== null && $marketValue > 0.0) {
                $exposure[$family] = ($exposure[$family] ?? 0.0) + $marketValue;
            }
        }

        foreach ($openOrders as $order) {
            if (!is_array($order) || strtolower((string) ($order['side'] ?? '')) !== 'buy') {
                continue;
            }
            $status = strtolower((string) ($order['status'] ?? ''));
            if (in_array($status, ['filled', 'canceled', 'cancelled', 'expired', 'rejected'], true)) {
                continue;
            }
            $family = $this->familyForSymbol((string) ($order['symbol'] ?? ''));
            $remainingNotional = $this->remainingBuyOrderNotional($order);
            if ($family !== null && $remainingNotional > 0.0) {
                $exposure[$family] = ($exposure[$family] ?? 0.0) + $remainingNotional;
            }
        }

        ksort($exposure);

        return $exposure;
    }

    public function grossExposure(array $positions, array $openOrders): float
    {
        $gross = 0.0;
        foreach ($positions as $position) {
            if (is_array($position)) {
                $gross += abs((float) ($position['market_value'] ?? 0.0));
            }
        }
        foreach ($openOrders as $order) {
            if (!is_array($order) || strtolower((string) ($order['side'] ?? '')) !== 'buy') {
                continue;
            }
            $status = strtolower((string) ($order['status'] ?? ''));
            if (in_array($status, ['filled', 'canceled', 'cancelled', 'expired', 'rejected'], true)) {
                continue;
            }
            $gross += $this->remainingBuyOrderNotional($order);
        }

        return $gross;
    }

    /** @param array<string, float> $exposureByFamily */
    public function availableNotional(string $symbol, float $equity, float $capPct, array $exposureByFamily): float
    {
        $family = $this->familyForSymbol($symbol);
        if ($family === null) {
            return INF;
        }
        if ($equity <= 0.0 || $capPct <= 0.0) {
            return 0.0;
        }

        return max(0.0, ($equity * $capPct) - (float) ($exposureByFamily[$family] ?? 0.0));
    }

    /** @param array<string, float> $exposureByFamily */
    public function reserve(string $symbol, float $notional, array &$exposureByFamily): void
    {
        $family = $this->familyForSymbol($symbol);
        if ($family === null || $notional <= 0.0) {
            return;
        }
        $exposureByFamily[$family] = ($exposureByFamily[$family] ?? 0.0) + $notional;
    }

    public function familyForSymbol(string $symbol): ?string
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return null;
        }

        $families = [
            'SP500' => ['SPY', 'SPX', 'ES', 'MES', 'RSP', 'UPRO', 'SPXL', 'SPUU', 'SSO', 'SDS', 'SPXU', 'SH'],
            'NASDAQ_100' => ['QQQ', 'NDX', 'NQ', 'MNQ', 'TQQQ', 'QLD', 'SQQQ', 'QID', 'PSQ'],
            'SEMICONDUCTORS' => ['SMH', 'SOXX', 'SOX', 'SOXL', 'SOXS', 'USD', 'NVDA', 'AMD', 'MU', 'AVGO'],
            'TECH' => ['XLK', 'TECL', 'ROM', 'AAPL', 'MSFT', 'ORCL', 'CRM', 'ADBE', 'NOW'],
            'DOW' => ['DIA', 'DJI', 'YM', 'MYM', 'UDOW', 'DDM', 'SDOW', 'DOG'],
            'RUSSELL_2000' => ['IWM', 'RUT', 'RTY', 'M2K', 'TNA', 'URTY', 'TZA', 'TWM'],
            'FINANCIALS' => ['XLF', 'FAS', 'FAZ', 'JPM', 'V', 'MA'],
            'CONSUMER_DISCRETIONARY' => ['XLY', 'AMZN', 'TSLA'],
            'COMMUNICATIONS' => ['XLC', 'META', 'GOOGL', 'GOOG', 'NFLX'],
            'INDUSTRIALS' => ['XLI', 'CAT', 'GE', 'UBER'],
            'HEALTHCARE' => ['XLV', 'LLY', 'UNH'],
            'ENERGY' => ['XLE', 'XOM', 'SCO', 'UCO'],
            'VOLATILITY' => ['VIX', 'VVIX', 'VIXY', 'UVXY', 'SVIX', 'SVXY', 'SVYX'],
            'MEGA_GROWTH' => ['MAGS', 'FNGU', 'BULZ', 'AAPL', 'MSFT', 'NVDA', 'AMZN', 'META', 'GOOGL', 'TSLA'],
        ];

        foreach ($families as $family => $members) {
            if (in_array($symbol, $members, true)) {
                return $family;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $order */
    private function remainingBuyOrderNotional(array $order): float
    {
        $remainingQty = max(0.0, (float) ($order['qty'] ?? 0.0) - (float) ($order['filled_qty'] ?? 0.0));
        $price = (float) ($order['limit_price'] ?? $order['stop_price'] ?? 0.0);
        $remainingNotional = $remainingQty > 0.0 && $price > 0.0 ? $remainingQty * $price : 0.0;
        $orderNotional = max(0.0, (float) ($order['notional'] ?? 0.0));
        if ($orderNotional > 0.0) {
            $filledNotional = max(0.0, (float) ($order['filled_qty'] ?? 0.0))
                * max(0.0, (float) ($order['filled_avg_price'] ?? 0.0));
            $remainingNotional = max(0.0, $orderNotional - $filledNotional);
        }

        return $remainingNotional;
    }
}
