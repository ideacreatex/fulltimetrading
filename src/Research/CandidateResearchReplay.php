<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

use FulltimeTrading\Paper\CandidateDefinition;

/** Shared offline replay, with no broker gateway or operational database. */
final class CandidateResearchReplay
{
    public static function context(string $root, array $paths, array $window, array $profile): array
    {
        $read = static fn ($path): array => json_decode(file_get_contents($root . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
        $all = DailyDataAudit::decode($read($paths['split'])); $raw = DailyDataAudit::decode($read($paths['raw'])); $nominal = [];
        foreach ($all as $symbol => $series) {
            $all[$symbol] = array_values(array_filter($series, static fn ($b): bool => DailyDataAudit::session($b) <= $window['end']));
            if ($all[$symbol] === []) { unset($all[$symbol]); }
        }
        foreach ($raw as $symbol => $series) { foreach ($series as $bar) { if (DailyDataAudit::session($bar) <= $window['end']) { $nominal[$symbol][DailyDataAudit::session($bar)] = ['open' => $bar->open, 'close' => $bar->close]; } } }
        unset($raw);
        $calendar = array_keys(DailyDataAudit::indexed(['SPY' => $all['SPY']])['SPY']);
        $breadth = BreadthVolatilityResearch::validateBreadth($read($paths['s5tw']), $calendar);
        $vvix = array_intersect_key($read($paths['vvix']), array_flip($calendar));
        $features = OpportunityPolicy::features($all, $vvix, $profile['universe']);
        $bars = array_intersect_key($all, array_flip(array_merge($profile['universe'], ['SPY', 'QQQ'])));
        foreach ($bars as &$series) { $series = array_values(array_filter($series, static fn ($b): bool => DailyDataAudit::session($b) >= $window['bars_from'])); }
        unset($series);
        return compact('all', 'nominal', 'breadth', 'vvix', 'features', 'bars');
    }

    public static function run(array $case, array $context, array $profile, array $window, int $cost, float $capital, callable $mapper): array
    {
        $maps = $mapper($case, $context['all'], $context['breadth'], $context['vvix']);
        $books = DeployedCandidateStudy::books($case['spec'], $profile, $maps['scale'], $context['features'], $context['nominal'], $cost);
        foreach ($books as &$book) { $book['config']['universe'] = array_values(array_intersect($book['config']['universe'], array_keys($context['all']))); }
        unset($book);
        $engine = new PaperExecutionRotationEnsembleBacktester($books);
        $controller = new PortfolioCircuitController(array_replace(CandidateDefinition::CIRCUIT, $case['spec']['circuit'] ?? []), $maps['confirmation']);
        $began = microtime(true); $result = $engine->runControlled($context['bars'], $window['start'], $window['end'], $controller, $capital); $annual = [];
        for ($year = (int) substr($window['start'], 0, 4); $year <= (int) substr($window['end'], 0, 4); ++$year) { $annual[$year] = $engine->metrics($result, "$year-01-01", ($year + 1) . '-01-01'); }
        $stops = [];
        foreach ($result['sleeve_curves'] as $sleeve => $curve) { foreach ($curve as $row) { if (($row['standing_stop_event'] ?? null) !== null) { $stops[] = ['sleeve' => $sleeve] + $row['standing_stop_event']; } } }
        $curve = array_map(static fn ($point): array => array_intersect_key($point,
            array_flip(['date', 'equity', 'start_equity', 'equity_low', 'equity_high', 'period_start_date', 'turnover'])), $result['curve']);
        $eligible = array_filter($maps['bullish'] ?? [], static fn ($v, $date): bool => $v && $date >= $window['start'] && $date <= $window['end'], ARRAY_FILTER_USE_BOTH);
        return ['metrics' => $engine->metrics($result), 'annual' => $annual, 'trade_ledger' => ResearchTradeLedger::fromSleeveCurves($result['sleeve_curves']),
            'stop_events' => $stops, 'curve' => $curve, 'boost_eligible_sessions' => count($eligible), 'boost_eligible_dates' => array_keys($eligible),
            'unavailable_symbols' => array_values(array_diff($profile['universe'], array_keys($context['all']))), 'elapsed_seconds' => microtime(true) - $began];
    }
}
