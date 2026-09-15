<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

final class CandidateBullRiskStudy
{
    public static function cases(): array
    {
        $old = CandidateInteractionStudy::cases(); $rows = [];
        foreach ([100, 105, 110] as $vvix) {
            $reference = $vvix === 100 ? 'deployed' : 'vvix_confirmation_' . $vvix;
            $constant = $vvix === 100 ? 'risk_scale_1.1' : "mix_v{$vvix}_s50_r110_b10";
            foreach ([$reference, $constant] as $id) { $rows[$id] = ['spec' => $old[$id]['spec'], 'reuse_id' => $id, 'reference' => $reference, 'bull' => null]; }
            foreach ([50, 200] as $window) {
                foreach ([1.05, 1.10] as $boost) {
                    $id = sprintf('bull_v%d_ma%d_boost%d', $vvix, $window, round($boost * 100));
                    $rows[$id] = ['spec' => $old[$reference]['spec'], 'reuse_id' => null, 'reference' => $reference,
                        'bull' => ['window' => $window, 'boost' => $boost], 'constant_reference' => $constant];
                }
            }
        }
        return $rows;
    }

    public static function maps(array $case, array $all, array $breadth, array $vvix): array
    {
        $maps = DeployedCandidateStudy::maps($case['spec'], $all, $breadth, $vvix);
        $bull = array_fill_keys(array_keys($maps['scale']), false);
        if ($case['bull'] === null) { return $maps + ['bullish' => $bull]; }
        $window = $case['bull']['window']; $boost = $case['bull']['boost'];
        if (!in_array($window, [50, 200], true) || !in_array($boost, [1.05, 1.10], true)) { throw new \InvalidArgumentException('Undeclared bull-risk parameters.'); }
        $spy = self::risingTrend($all['SPY'], $window); $qqq = self::risingTrend($all['QQQ'], $window);
        $svxy = BreadthVolatilityResearch::trend($all['SVXY'], 200);
        foreach ($bull as $date => $_) {
            if (!array_key_exists($date, $spy) || !array_key_exists($date, $qqq) || !array_key_exists($date, $svxy)) { throw new \RuntimeException('Missing completed market session.'); }
            $bull[$date] = $spy[$date] && $qqq[$date] && $svxy[$date];
            if ($bull[$date]) { $maps['scale'][$date] = min(2., $maps['scale'][$date] * $boost); }
        }
        return $maps + ['bullish' => $bull];
    }

    private static function risingTrend(array $bars, int $window): array
    {
        $closes = $averages = $answer = []; $sum = 0.; $last = null;
        foreach ($bars as $bar) {
            $date = DailyDataAudit::session($bar); $close = $bar->close;
            if (($last !== null && $date <= $last) || !is_finite($close) || $close <= 0) { throw new \RuntimeException('Invalid causal market series.'); }
            $last = $date; $i = count($closes); $closes[] = $close; $sum += $close;
            if ($i >= $window) { $sum -= $closes[$i - $window]; }
            $averages[$i] = $i >= $window - 1 ? $sum / $window : null;
            $answer[$date] = $i >= $window + 9 && $close > $averages[$i]
                && $averages[$i] > $averages[$i - 10] && $close > $closes[$i - 5];
        }
        return $answer;
    }
}
