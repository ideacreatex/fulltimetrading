<?php

declare(strict_types=1);

namespace FulltimeTrading\Strategy;

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Indicators\IndicatorCalculator;

final class MarketRegimeAnalyzer
{
    public function __construct(
        private readonly IndicatorCalculator $indicators,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {
    }

    /**
     * @param array<string, list<Bar>> $marketBars
     * @return array<string, MarketRegime> keyed by Y-m-d
     */
    public function analyze(array $marketBars): array
    {
        if (!isset($marketBars['SPY']) || count($marketBars['SPY']) < 60) {
            return [];
        }

        $emaPeriods = [20, 50];
        $spy = $marketBars['SPY'];
        $bySymbolDate = $this->indicatorStateBySymbolDate($marketBars, $emaPeriods);
        $weights = $this->config['symbol_weights'] ?? [];
        $warningSymbols = $this->config['warning_ema20_symbols'] ?? ['RSP', 'IWM', 'XLK', 'AAPL', 'MSFT', 'NVDA'];

        $regimes = [];
        $spyPeakClose = null;
        foreach ($spy as $i => $bar) {
            $date = $bar->time->format('Y-m-d');
            $spyState = $bySymbolDate['SPY'][$date] ?? null;
            if ($spyState === null) {
                continue;
            }

            $ema20 = $spyState['ema20'];
            $ema50 = $spyState['ema50'];
            $atr = $spyState['atr'];
            if ($ema20 === null || $ema50 === null || $atr === null) {
                continue;
            }

            $warnings = [];
            [$score, $breadthRatio, $warnings] = $this->weightedMarketScore($bySymbolDate, $date, $weights, $warningSymbols, $warnings);
            if ($bar->close > $ema20) {
                $score += 1.0;
            } else {
                $warnings[] = 'SPY close below EMA20';
            }
            if ($ema20 > $ema50) {
                $score += 0.5;
            } else {
                $warnings[] = 'SPY EMA20 not above EMA50';
            }

            $buffer = (float) ($this->config['atr_break_buffer'] ?? 0.20);
            $hardBreak = $bar->close < ($ema20 - $atr * $buffer);
            $spyPeakClose = $spyPeakClose === null ? $bar->close : max($spyPeakClose, $bar->close);
            $spyDrawdownPct = $spyPeakClose > 0.0 ? ($bar->close - $spyPeakClose) / $spyPeakClose : 0.0;
            $requiresSpy = (bool) ($this->config['require_spy_above_ema20'] ?? true);
            $requiresQqq = (bool) ($this->config['require_qqq_above_ema20'] ?? false);
            $qqqState = $bySymbolDate['QQQ'][$date] ?? null;
            $qqqOk = !$requiresQqq
                || ($qqqState !== null && $qqqState['ema20'] !== null && $qqqState['bar']->close >= $qqqState['ema20']);
            if (!$qqqOk) {
                $warnings[] = 'QQQ close below required EMA20';
            }

            if ($breadthRatio < (float) ($this->config['min_weighted_breadth_ratio'] ?? 0.35)) {
                $warnings[] = 'weighted market breadth is weak';
            }

            $allows = !$hardBreak && $qqqOk && (!$requiresSpy || $bar->close >= $ema20);

            $regimes[$date] = new MarketRegime(
                $bar->time,
                $allows,
                $score,
                $warnings,
                $spyDrawdownPct,
                $spyState['rsi14'],
            );
        }

        return $regimes;
    }

    /**
     * @param array<string, list<Bar>> $marketBars
     * @param list<int> $emaPeriods
     * @return array<string, array<string, array{bar:Bar, ema20:?float, ema50:?float, atr:?float, rsi14:?float}>>
     */
    private function indicatorStateBySymbolDate(array $marketBars, array $emaPeriods): array
    {
        $state = [];
        foreach ($marketBars as $symbol => $bars) {
            if (count($bars) < 60) {
                continue;
            }

            $indicators = $this->indicators->forBars($bars, $emaPeriods, 14, 50);
            foreach ($bars as $i => $bar) {
                $state[strtoupper($symbol)][$bar->time->format('Y-m-d')] = [
                    'bar' => $bar,
                    'ema20' => $indicators['ema20'][$i] ?? null,
                    'ema50' => $indicators['ema50'][$i] ?? null,
                    'atr' => $indicators['atr'][$i] ?? null,
                    'rsi14' => $indicators['rsi14'][$i] ?? null,
                ];
            }
        }

        return $state;
    }

    /**
     * @param array<string, array<string, array{bar:Bar, ema20:?float, ema50:?float, atr:?float, rsi14:?float}>> $bySymbolDate
     * @param array<string, int|float> $weights
     * @param list<string> $warningSymbols
     * @param list<string> $warnings
     * @return array{float, float, list<string>}
     */
    private function weightedMarketScore(array $bySymbolDate, string $date, array $weights, array $warningSymbols, array $warnings): array
    {
        $raw = 0.0;
        $max = 0.0;
        $aboveEma20Weight = 0.0;
        $totalWeight = 0.0;
        $warningLookup = array_flip(array_map('strtoupper', $warningSymbols));

        foreach ($bySymbolDate as $symbol => $byDate) {
            $item = $byDate[$date] ?? null;
            if ($item === null || $item['ema20'] === null || $item['ema50'] === null) {
                continue;
            }

            $weight = (float) ($weights[$symbol] ?? 0.15);
            $max += $weight * 1.5;
            $totalWeight += $weight;

            if ($item['bar']->close > $item['ema20']) {
                $raw += $weight;
                $aboveEma20Weight += $weight;
            } elseif (isset($warningLookup[$symbol])) {
                $warnings[] = $symbol . ' close below EMA20';
            }

            if ($item['ema20'] > $item['ema50']) {
                $raw += $weight * 0.5;
            }
        }

        $score = $max > 0.0 ? 3.0 * $raw / $max : 0.0;
        $breadthRatio = $totalWeight > 0.0 ? $aboveEma20Weight / $totalWeight : 0.0;

        return [$score, $breadthRatio, $warnings];
    }
}
