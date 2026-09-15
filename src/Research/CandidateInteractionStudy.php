<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

/** Bounded factorial follow-up. This class is not part of the running release. */
final class CandidateInteractionStudy
{
    public static function cases(): array
    {
        $prior = DeployedCandidateStudy::cases(); $rows = [];
        foreach ([100, 105, 110] as $vvix) {
            foreach ([.5, .75] as $svxy) {
                foreach ([1., 1.1] as $size) {
                    foreach ([false, true] as $breadth) {
                        $factors = ['vvix' => $vvix, 'svxy' => $svxy, 'size' => $size, 'breadth_close20' => $breadth];
                        $changed = (int) ($vvix !== 100) + (int) ($svxy !== .5) + (int) ($size !== 1.) + (int) $breadth;
                        $reuse = $changed === 0 ? 'deployed' : null;
                        if ($changed === 1) {
                            $reuse = match (true) {
                                $vvix !== 100 => 'vvix_confirmation_' . $vvix,
                                $svxy !== .5 => 'svxy_cap_0.75',
                                $size !== 1. => 'risk_scale_1.1',
                                default => 's5tw_high_close_20_1.25',
                            };
                        }
                        $spec = ['family' => 'interaction'];
                        if ($vvix !== 100) { $spec['vvix_confirmation'] = $vvix; }
                        if ($svxy !== .5) { $spec['svxy_cap'] = $svxy; }
                        if ($size !== 1.) { $spec['risk_scale'] = $size; }
                        if ($breadth) { $spec += ['high_event' => 'high_close', 'high_window' => 20, 'high_boost' => 1.25]; }
                        $id = $reuse ?? sprintf('mix_v%d_s%d_r%d_b%d', $vvix, round($svxy * 100), round($size * 100), $breadth ? 20 : 10);
                        $rows[$id] = ['spec' => $reuse !== null ? $prior[$reuse] : $spec, 'factors' => $factors, 'reuse_id' => $reuse];
                    }
                }
            }
        }
        return $rows;
    }

    public static function conditions(): array
    {
        return ['early2017' => ['start' => '2017-01-03', 'end' => '2020-12-31', 'bars_from' => '2016-01-01', 'group' => 'early'],
            'early2019' => ['start' => '2019-01-02', 'end' => '2020-12-31', 'bars_from' => '2016-01-01', 'group' => 'early'],
            'continuous' => ['start' => '2021-01-04', 'end' => '2026-09-04', 'bars_from' => '2020-01-01', 'group' => 'recent'],
            'fresh2023' => ['start' => '2023-01-03', 'end' => '2026-09-04', 'bars_from' => '2020-01-01', 'group' => 'recent']];
    }

    public static function priorCase(string $id, string $scenario, int $cost): ?string
    {
        $case = self::cases()[$id] ?? throw new \InvalidArgumentException('Unknown factorial recipe.');
        $condition = self::conditions()[$scenario] ?? throw new \InvalidArgumentException('Unknown factorial scenario.');
        if (!in_array($cost, [30, 60], true)) { throw new \InvalidArgumentException('Unknown factorial cost.'); }
        $reuse = $case['reuse_id'];
        if ($reuse === null) { return null; }
        if ($condition['group'] === 'early' && !in_array($reuse, ['deployed', 'vvix_confirmation_105', 'vvix_confirmation_110'], true)) { return null; }
        $dir = $condition['group'] === 'early' ? 'deployed_candidate_early_stress_20260915' : 'deployed_candidate_study_20260915';
        return 'var/reports/' . $dir . '/' . $reuse . '__' . $scenario . '__' . $cost . '.json';
    }

    /** Change exactly one adjacent factorial level; all other factors remain identical. */
    public static function neighbors(string $id): array
    {
        $cases = self::cases(); $row = $cases[$id] ?? throw new \InvalidArgumentException('Unknown recipe.'); $neighbors = [];
        foreach ($cases as $other => $case) {
            $changed = [];
            foreach ($row['factors'] as $factor => $value) { if ($case['factors'][$factor] !== $value) { $changed[] = $factor; } }
            if (count($changed) !== 1) { continue; }
            if ($changed[0] === 'vvix' && abs($case['factors']['vvix'] - $row['factors']['vvix']) !== 5) { continue; }
            $neighbors[$other] = $changed[0];
        }
        return $neighbors;
    }
}
