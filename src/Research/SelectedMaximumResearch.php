<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

/** Rebase frozen research recipes, never operational strategy configuration. */
final class SelectedMaximumResearch
{
    public const MAXIMUM = 'balanced__global_confirmed_f6156ed86a11';
    public const OLD_STOP12 = 'balanced__stop_then_confirm_aacf0d177f3b';

    public static function catalogue(array $refinements, array $combinations, array $reentry): array
    {
        $cases = [];
        foreach (['control_original', 'control_balanced', 'maximum', 'maximum_stop12', 'old_stop12'] as $id) {
            $cases[$id] = ['anchor' => $id, 'family' => 'control', 'kind' => 'control'];
        }
        foreach (['maximum', 'maximum_stop12'] as $anchor) {
            $add = static function (string $source, string $id, string $family, array $recipe) use (&$cases, $anchor): void {
                $key = $anchor . '__' . $source . '__' . $id;
                if (isset($cases[$key])) { throw new \RuntimeException('Duplicate recipe.'); }
                $cases[$key] = $recipe + ['anchor' => $anchor, 'family' => $family, 'source' => $source, 'source_id' => $id];
            };
            foreach (BreadthVolatilityGrid::definitions() as $id => $d) {
                $add('breadth', $id, $d['family'], ['kind' => 'breadth', 'definition' => $d, 'mode' => 'preserve_other_components']);
                if (!isset($d['changes']) && $d['family'] !== 'combined') {
                    $add('breadth_replace', $id, $d['family'], ['kind' => 'breadth', 'definition' => $d, 'mode' => 'replace_entire_scale']);
                }
            }
            foreach ($refinements as $id => $d) { $add('refinement', $id, 'refinement', ['kind' => 'refinement', 'definition' => $d]); }
            foreach (AlpacaStopResearchGrid::definitions() as $id => $d) {
                $add('stops', $id, $d['family'], ['kind' => 'direct', 'changes' => $d['changes']]
                    + ($d['circuit'] === [] ? [] : ['circuit' => $d['circuit'], 'confirmation' => $d['confirmation']]));
            }
            foreach (['algorithm' => AlgorithmTrendResearch::cases(), 'adaptive' => AdaptiveResearchFactory::cases(true), 'combinations' => $combinations] as $source => $rows) {
                foreach ($rows as $id => $d) {
                    if ($d['family'] === 'control') { continue; }
                    $add($source, $id, $d['family'], ['kind' => 'direct', 'changes' => $d['changes']]);
                }
            }
            foreach ($reentry as $id => $changes) {
                if ($changes !== []) { $add('reentry_combinations', $id, 'reentry_combined', ['kind' => 'direct', 'changes' => $changes]); }
            }
            foreach (['no_external', 'no_s5tw', 'no_svxy', 'no_portfolio_circuit', 'no_algorithm_policy'] as $name) {
                $add('ablation', $name, 'ablation', ['kind' => 'ablation', 'component' => $name]);
            }
            foreach ([90, 100, 110, 120] as $vvix) {
                foreach ([20, 50, 200] as $svxy) {
                    foreach ([20, 50] as $trend) {
                        $add('confirmation', "vvix{$vvix}_svxy{$svxy}_trend{$trend}", 'confirmation_sensitivity',
                            ['kind' => 'direct', 'changes' => [], 'confirmation' => "calm_{$vvix}_{$svxy}_{$trend}"]);
                    }
                }
            }
        }
        return $cases;
    }

    public static function maps(array $all, array $breadth, array $vvix): array
    {
        $event = BreadthVolatilityResearch::windowScale(BreadthVolatilityResearch::events($breadth)['high_touch'], 10, 1.25);
        $trend = [];
        foreach ([20, 50, 200] as $period) { $trend[$period] = BreadthVolatilityResearch::trend($all['SVXY'], $period); }
        $risk = array_map(static fn ($v): float => $v ? 1.0 : 0.5, $trend[200]);
        $scale = self::cap($event, $risk);
        $confirm = AlpacaStopResearchGrid::confirmations($all, $breadth, $vvix);
        foreach ([90, 100, 110, 120] as $v) {
            foreach ([20, 50, 200] as $s) {
                foreach ([20, 50] as $t) {
                    foreach ($breadth as $date => $_) { $confirm["calm_{$v}_{$s}_{$t}"][$date] = $vvix[$date] < $v && $trend[$s][$date] && $confirm['trend' . $t][$date]; }
                }
            }
        }
        return compact('event', 'risk', 'scale', 'confirm');
    }

    /** A neutral cap of one must not erase the existing 1.25 breadth boost. */
    public static function cap(array $base, array $cap): array
    {
        foreach ($base as $date => $value) {
            if (!array_key_exists($date, $cap)) { throw new \RuntimeException('Missing external risk session.'); }
            $base[$date] = $cap[$date] < 1.0 ? min($value, $cap[$date]) : $value;
        }
        return $base;
    }

    public static function merge(array $base, array $changes): array
    {
        // Explicit global policy experiments must override the inherited dynamic policy too.
        foreach (['dynamic_overrides', 'defensive_overrides'] as $scope) {
            $nested = $base[$scope] ?? [];
            foreach ($changes as $key => $_) { unset($nested[$key]); }
            if (isset($changes[$scope])) { $nested = array_replace($nested, $changes[$scope]); }
            if ($nested === []) { unset($base[$scope]); } else { $base[$scope] = $nested; }
        }
        return array_replace($base, array_diff_key($changes, array_flip(['dynamic_overrides', 'defensive_overrides'])));
    }

    public static function materialize(array $recipe, array $anchors, array $all, array $breadth, array $vvix, array $maps): array
    {
        $anchor = $recipe['anchor'];
        $d = $anchors[$anchor === 'maximum_stop12' ? 'maximum' : $anchor];
        if ($anchor !== 'control_original') { $d['changes']['external_daily_scale'] = $maps['scale']; }
        if ($anchor === 'maximum_stop12') { $d['changes'] = self::merge($d['changes'], ['standing_stop_pct' => 0.12, 'standing_stop_trailing' => true, 'position_exit_cooldown_sessions' => 1]); }
        $changes = $recipe['changes'] ?? [];
        if ($recipe['kind'] === 'breadth') {
            $r = $recipe['definition'];
            $changes = BreadthVolatilityGrid::changes($r, $breadth, $vvix, $all['SVXY']);
            if (isset($changes['external_daily_scale']) && $recipe['mode'] === 'preserve_other_components') {
                $scale = $changes['external_daily_scale'];
                if (in_array($r['family'], ['s5tw', 's5tw_sensitivity'], true)) { $scale = self::cap($scale, $maps['risk']); }
                elseif ($r['family'] === 'svxy') { $scale = self::cap($maps['event'], $scale); }
                elseif ($r['family'] === 'vvix') {
                    if ($r['mode'] === 'inverse') {
                        foreach ($scale as $date => $value) { $scale[$date] = min(2.0, $maps['scale'][$date] * $value); }
                    } else { $scale = self::cap($maps['scale'], $scale); }
                }
                $changes['external_daily_scale'] = $scale;
            }
        } elseif ($recipe['kind'] === 'refinement') {
            $r = $recipe['definition'];
            $scale = BreadthVolatilityResearch::windowScale(BreadthVolatilityResearch::events($breadth)['high_touch'], $r['hold'], $r['boost']);
            foreach ($scale as $date => $value) {
                if ($maps['risk'][$date] < 1) { $scale[$date] = min($scale[$date], $r['defensive']); }
                if ($vvix[$date] >= 120 && $r['vvixCap'] < 1) { $scale[$date] = min($scale[$date], $r['vvixCap']); }
            }
            $changes['external_daily_scale'] = $scale;
        } elseif ($recipe['kind'] === 'ablation') {
            switch ($recipe['component']) {
                case 'no_external': unset($d['changes']['external_daily_scale']); break;
                case 'no_s5tw': $changes['external_daily_scale'] = $maps['risk']; break;
                case 'no_svxy': $changes['external_daily_scale'] = $maps['event']; break;
                case 'no_portfolio_circuit': $d['circuit'] = []; $d['confirmation'] = 'none'; break;
                case 'no_algorithm_policy': unset($d['changes']['algorithm_policy'], $d['changes']['dynamic_overrides']['algorithm_policy']); break;
                default: throw new \RuntimeException('Unknown ablation.');
            }
        }
        $d['changes'] = self::merge($d['changes'], $changes);
        if (isset($recipe['circuit'])) { $d['circuit'] = $recipe['circuit']; }
        if (isset($recipe['confirmation'])) { $d['confirmation'] = $recipe['confirmation']; }
        return $d;
    }

    public static function hash(array $value): string
    {
        $sort = static function (array $a) use (&$sort): array {
            if (!array_is_list($a)) { ksort($a); }
            foreach ($a as &$v) { if (is_array($v)) { $v = $sort($v); } }
            return $a;
        };
        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }

    public static function lag(array $map, float|bool $initial): array
    {
        $previous = $initial;
        foreach ($map as $date => $value) { $map[$date] = $previous; $previous = $value; }
        return $map;
    }
}
