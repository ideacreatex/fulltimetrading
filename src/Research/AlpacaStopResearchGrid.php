<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

final class AlpacaStopResearchGrid
{
    public static function definitions(): array
    {
        $cases = [];
        $add = static function (string $family, array $changes, array $circuit = [], string $confirmation = 'none') use (&$cases): void {
            $row = compact('family', 'changes', 'circuit', 'confirmation');
            $id = $family . '_' . substr(hash('sha256', json_encode($row)), 0, 12);
            if (isset($cases[$id])) { throw new \RuntimeException('Duplicate stop hypothesis.'); }
            $cases[$id] = $row;
        };
        foreach ([0.03, 0.05, 0.08, 0.12, 0.18] as $stop) {
            foreach ([1, 3, 5, 10, 20] as $pause) {
                foreach ([false, true] as $trailing) {
                    $add('standing', ['standing_stop_pct' => $stop, 'standing_stop_trailing' => $trailing, 'position_exit_cooldown_sessions' => $pause]);
                }
            }
        }
        foreach ([0.05, 0.08, 0.10, 0.12, 0.15, 0.20] as $dd) {
            foreach ([3, 10, 20, 40] as $pause) { $add('global_fixed', [], ['drawdown' => $dd, 'pause' => $pause]); }
        }
        foreach (['trend20', 'trend50', 'trend200', 'rebound3', 'breadth_recovery', 'volatility_calm'] as $confirmation) {
            foreach ([0.08, 0.12, 0.18] as $dd) {
                foreach ([3, 5] as $minimum) {
                    foreach ([0.5, 1.0] as $scale) {
                        $add('global_confirmed', [], ['drawdown' => $dd, 'release' => 'confirmed', 'minimum_pause' => $minimum,
                            'initial_scale' => $scale], $confirmation);
                    }
                }
            }
            foreach ([0.10, 0.15] as $dd) {
                foreach ([10, 20] as $pause) { $add('global_either', [], ['drawdown' => $dd, 'release' => 'either', 'minimum_pause' => 3,
                    'pause' => $pause, 'initial_scale' => 0.5], $confirmation); }
            }
        }
        foreach ([0.05, 0.08, 0.12] as $stop) {
            foreach ([0.10, 0.15] as $dd) {
                foreach (['none', 'trend50', 'rebound3'] as $confirmation) {
                    $add('combined', ['standing_stop_pct' => $stop, 'position_exit_cooldown_sessions' => 3],
                        ['drawdown' => $dd, 'pause' => 10, 'release' => $confirmation === 'none' ? 'fixed' : 'confirmed',
                            'minimum_pause' => 3, 'initial_scale' => 0.5], $confirmation);
                }
            }
            foreach (['trend20', 'trend50', 'rebound3'] as $confirmation) {
                $add('stop_then_confirm', ['standing_stop_pct' => $stop, 'position_exit_cooldown_sessions' => 1],
                    ['drawdown' => 0.15, 'on_position_stop' => true, 'release' => 'confirmed', 'minimum_pause' => 3, 'initial_scale' => 0.5], $confirmation);
            }
        }
        foreach ([0.08, 0.12, 0.15] as $dd) {
            foreach ([5, 20] as $pause) { $add('observed_daily_low', [], ['drawdown' => $dd, 'pause' => $pause, 'trigger_basis' => 'low']); }
        }
        return $cases;
    }

    public static function confirmations(array $bars, array $breadth, array $vvix): array
    {
        $indexed = DailyDataAudit::indexed(['SPY' => $bars['SPY'], 'QQQ' => $bars['QQQ'], 'SVXY' => $bars['SVXY']]);
        $trends = [];
        foreach (['SPY', 'QQQ', 'SVXY'] as $symbol) {
            foreach ([20, 50, 200] as $period) { $trends[$symbol][$period] = BreadthVolatilityResearch::trend($bars[$symbol], $period); }
        }
        $dates = array_keys($indexed['SPY']);
        $result = [];
        foreach ($dates as $i => $date) {
            if (!isset($breadth[$date], $vvix[$date], $indexed['QQQ'][$date], $indexed['SVXY'][$date])) { throw new \RuntimeException('Missing confirmation history: ' . $date); }
            foreach ([20, 50, 200] as $period) { $result['trend' . $period][$date] = $trends['SPY'][$period][$date] && $trends['QQQ'][$period][$date]; }
            $rebound = $i >= 3;
            foreach (['SPY', 'QQQ'] as $symbol) {
                $rebound = $rebound && $indexed[$symbol][$date]->close / $indexed[$symbol][$dates[max(0, $i - 3)]]->close - 1 >= 0.03;
            }
            $result['rebound3'][$date] = $rebound;
            $result['breadth_recovery'][$date] = $breadth[$date]['close'] >= 40 && $result['trend20'][$date];
            $result['volatility_calm'][$date] = $vvix[$date] < 100 && $trends['SVXY'][20][$date] && $result['trend20'][$date];
            $result['none'][$date] = true;
        }
        return $result;
    }
}
