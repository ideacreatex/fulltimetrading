<?php

declare(strict_types=1);

namespace FulltimeTrading\Indicators;

use FulltimeTrading\Domain\Bar;

final class IndicatorCalculator
{
    /**
     * @param list<float> $values
     * @return list<float|null>
     */
    public function ema(array $values, int $period): array
    {
        if ($period <= 1) {
            throw new \InvalidArgumentException('EMA period must be > 1.');
        }

        $result = array_fill(0, count($values), null);
        if (count($values) < $period) {
            return $result;
        }

        $seed = array_sum(array_slice($values, 0, $period)) / $period;
        $result[$period - 1] = $seed;
        $multiplier = 2 / ($period + 1);

        for ($i = $period; $i < count($values); $i++) {
            $previous = $result[$i - 1];
            $result[$i] = ($values[$i] - $previous) * $multiplier + $previous;
        }

        return $result;
    }

    /**
     * @param list<Bar> $bars
     * @return list<float|null>
     */
    public function atr(array $bars, int $period): array
    {
        $trueRanges = [];
        foreach ($bars as $i => $bar) {
            if ($i === 0) {
                $trueRanges[] = $bar->high - $bar->low;
                continue;
            }
            $prevClose = $bars[$i - 1]->close;
            $trueRanges[] = max(
                $bar->high - $bar->low,
                abs($bar->high - $prevClose),
                abs($bar->low - $prevClose),
            );
        }

        return $this->ema($trueRanges, $period);
    }

    /**
     * @param list<float> $values
     * @return list<float|null>
     */
    public function sma(array $values, int $period): array
    {
        $result = array_fill(0, count($values), null);
        $sum = 0.0;
        for ($i = 0; $i < count($values); $i++) {
            $sum += $values[$i];
            if ($i >= $period) {
                $sum -= $values[$i - $period];
            }
            if ($i >= $period - 1) {
                $result[$i] = $sum / $period;
            }
        }

        return $result;
    }

    /**
     * @param list<float> $values
     * @return list<float|null>
     */
    public function rsi(array $values, int $period = 14): array
    {
        $result = array_fill(0, count($values), null);
        if (count($values) <= $period) {
            return $result;
        }

        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $change = $values[$i] - $values[$i - 1];
            $gain += max(0.0, $change);
            $loss += max(0.0, -$change);
        }

        $avgGain = $gain / $period;
        $avgLoss = $loss / $period;
        $result[$period] = $avgLoss === 0.0 ? 100.0 : 100.0 - (100.0 / (1.0 + ($avgGain / $avgLoss)));

        for ($i = $period + 1; $i < count($values); $i++) {
            $change = $values[$i] - $values[$i - 1];
            $avgGain = (($avgGain * ($period - 1)) + max(0.0, $change)) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + max(0.0, -$change)) / $period;
            $result[$i] = $avgLoss === 0.0 ? 100.0 : 100.0 - (100.0 / (1.0 + ($avgGain / $avgLoss)));
        }

        return $result;
    }

    /**
     * @param list<float> $values
     * @return array{macd:list<float|null>, signal:list<float|null>, histogram:list<float|null>}
     */
    public function macd(array $values, int $fast = 12, int $slow = 26, int $signalPeriod = 9): array
    {
        $fastEma = $this->ema($values, $fast);
        $slowEma = $this->ema($values, $slow);
        $macd = array_fill(0, count($values), null);
        foreach ($values as $i => $_) {
            if ($fastEma[$i] === null || $slowEma[$i] === null) {
                continue;
            }
            $macd[$i] = $fastEma[$i] - $slowEma[$i];
        }

        $signalInput = array_map(static fn (?float $value): float => $value ?? 0.0, $macd);
        $signal = $this->ema($signalInput, $signalPeriod);
        $histogram = array_fill(0, count($values), null);
        foreach ($values as $i => $_) {
            if ($macd[$i] === null || $signal[$i] === null) {
                continue;
            }
            $histogram[$i] = $macd[$i] - $signal[$i];
        }

        return ['macd' => $macd, 'signal' => $signal, 'histogram' => $histogram];
    }

    /**
     * @param list<Bar> $bars
     * @param list<int> $emaPeriods
     * @return array<string, list<float|null>>
     */
    public function forBars(array $bars, array $emaPeriods, int $atrPeriod, int $volumeAvgPeriod): array
    {
        $closes = array_map(static fn (Bar $bar): float => $bar->close, $bars);
        $volumes = array_map(static fn (Bar $bar): float => $bar->volume, $bars);

        $indicators = [
            'atr' => $this->atr($bars, $atrPeriod),
            'volume_sma' => $this->sma($volumes, $volumeAvgPeriod),
            'rsi14' => $this->rsi($closes, 14),
        ];
        $macd = $this->macd($closes);
        $indicators['macd'] = $macd['macd'];
        $indicators['macd_signal'] = $macd['signal'];
        $indicators['macd_histogram'] = $macd['histogram'];

        foreach ($emaPeriods as $period) {
            $indicators['ema' . $period] = $this->ema($closes, $period);
            $indicators['sma' . $period] = $this->sma($closes, $period);
        }

        return $indicators;
    }

    /**
     * @param list<Bar> $bars
     * @return list<Bar>
     */
    public function aggregateWeekly(array $bars): array
    {
        $weeks = [];
        foreach ($bars as $bar) {
            $key = $bar->time->format('o-W');
            if (!isset($weeks[$key])) {
                $weeks[$key] = [
                    'symbol' => $bar->symbol,
                    'time' => $bar->time,
                    'open' => $bar->open,
                    'high' => $bar->high,
                    'low' => $bar->low,
                    'close' => $bar->close,
                    'volume' => $bar->volume,
                ];
                continue;
            }

            $weeks[$key]['time'] = $bar->time;
            $weeks[$key]['high'] = max($weeks[$key]['high'], $bar->high);
            $weeks[$key]['low'] = min($weeks[$key]['low'], $bar->low);
            $weeks[$key]['close'] = $bar->close;
            $weeks[$key]['volume'] += $bar->volume;
        }

        return array_map(static fn (array $row): Bar => new Bar(
            $row['symbol'],
            $row['time'],
            $row['open'],
            $row['high'],
            $row['low'],
            $row['close'],
            $row['volume'],
        ), array_values($weeks));
    }
}
