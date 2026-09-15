<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

use FulltimeTrading\Paper\CandidateDataSnapshot;
use FulltimeTrading\Paper\CandidateDefinition;
use FulltimeTrading\Paper\CandidateOrder;
use FulltimeTrading\Paper\CandidateRelease;

/** Offline binding only. Its distinct schema must never enter the active order path. */
final class CandidateBullSnapshot
{
    public const SCHEMA = 'candidate-bull-research-snapshot-v1';
    public const IDS = ['bull_v100_ma50_boost105', 'bull_v105_ma50_boost105', 'bull_v110_ma50_boost105'];
    private const DATA_KEYS = ['external_daily_scale', 'opportunity_features', 'nominal_prices'];

    public static function recipe(string $id): array
    {
        if (!in_array($id, self::IDS, true)) { throw new \InvalidArgumentException('Undeclared isolated snapshot recipe.'); }
        return CandidateBullRiskStudy::cases()[$id];
    }

    /** Rebuild trusted expectations from verified files, never from an artifact's own claimed recipe/hash. */
    public static function buildAll(string $root, string $date, array $candidate): array
    {
        CandidateOrder::date($date);
        $release = CandidateRelease::verify($root, $candidate);
        $files = self::files($date); $hashes = self::hashes($root, $files);
        $base = CandidateDataSnapshot::load($root, $date, $candidate);
        $baseAnswers = $base['context_answers']; $provenance = $base['provenance']; $closes = $base['nominal_closes'];
        unset($base); gc_collect_cycles();
        $profile = require $root . '/config/tactical_rotation.php';
        $all = DailyDataAudit::decode(CandidateDataSnapshot::read($root . '/' . $files['split']));
        $raw = DailyDataAudit::decode(CandidateDataSnapshot::read($root . '/' . $files['raw']));
        $nominal = [];
        foreach ($raw as $symbol => $series) { foreach ($series as $bar) {
            $nominal[$symbol][DailyDataAudit::session($bar)] = ['open' => $bar->open, 'close' => $bar->close];
        } }
        unset($raw);
        $calendar = array_keys(DailyDataAudit::indexed(['SPY' => $all['SPY']])['SPY']);
        $breadth = BreadthVolatilityResearch::validateBreadth(CandidateDataSnapshot::read($root . '/' . $files['s5tw']), $calendar);
        $vvix = array_intersect_key(CandidateDataSnapshot::read($root . '/' . $files['vvix']), array_flip($calendar));
        $features = OpportunityPolicy::features($all, $vvix, $profile['universe']);
        $bars = [];
        foreach ($all as $symbol => $series) {
            $bars[$symbol] = array_values(array_filter($series, static fn ($b): bool => DailyDataAudit::session($b) >= '2020-01-01'));
        }
        $code = [];
        foreach (['CandidateBullSnapshot', 'CandidateBullRiskStudy', 'CandidateInteractionStudy', 'DeployedCandidateStudy'] as $class) {
            $path = 'src/Research/' . $class . '.php'; $code[$path] = hash_file('sha256', $root . '/' . $path);
        }
        $answer = [];
        foreach (self::IDS as $id) {
            $case = self::recipe($id); $maps = CandidateBullRiskStudy::maps($case, $all, $breadth, $vvix);
            $books = DeployedCandidateStudy::books($case['spec'], $profile, $maps['scale'], $features, $nominal, 30);
            if ($books !== CandidateDefinition::books($profile, $maps['scale'], $features, $nominal)) {
                throw new \RuntimeException('Unexpected changes beyond declared indicator maps.');
            }
            $contexts = $cache = [];
            foreach ($books as $name => $book) {
                $key = SelectedMaximumResearch::hash(array_diff_key($book['config'], array_flip(['rebalance_phase', ...self::DATA_KEYS])));
                if (!isset($cache[$key])) {
                    $historical = (new PaperExecutionRotationBacktester($book['config']))->paperSignalContexts($bars, $date); $a = [];
                    foreach ([null, ...$book['config']['universe']] as $held) { $a[$held ?? ''] = $historical($date, $held); }
                    unset($historical); $cache[$key] = $a;
                }
                $contexts[$name] = $cache[$key];
                $books[$name]['config'] = array_diff_key($book['config'], array_flip(self::DATA_KEYS));
            }
            $artifact = ['schema' => self::SCHEMA, 'recipe_id' => $id, 'recipe' => $case, 'recipe_sha256' => SelectedMaximumResearch::hash($case),
                'as_of' => $date, 'reference_run_id' => $candidate['run_id'], 'reference_runtime_hash' => $release['runtime_hash'],
                'base_profile_sha256' => $candidate['base_profile_sha256'], 'source_files' => $files, 'source_sha256' => $hashes,
                'builder_sha256' => $code, 'provenance' => $provenance, 'books' => $books, 'contexts' => $contexts,
                'nominal_closes' => $closes, 'confirmation' => $maps['confirmation'][$date], 'bullish' => $maps['bullish'][$date],
                'daily_scale' => $maps['scale'][$date], 'scale_series_sha256' => SelectedMaximumResearch::hash($maps['scale']),
                'confirmation_series_sha256' => SelectedMaximumResearch::hash($maps['confirmation']),
                'matches_deployed_close_contexts' => SelectedMaximumResearch::hash($contexts) === SelectedMaximumResearch::hash($baseAnswers),
                'research_only' => true, 'paper_only' => true, 'order_submission_enabled' => false, 'live_enabled' => false,
                'release_admitted' => false, 'validation_selected' => false];
            $artifact['content_sha256'] = self::contentHash($artifact); $answer[$id] = $artifact;
            unset($maps, $books, $contexts, $cache); gc_collect_cycles();
        }
        if ($hashes !== self::hashes($root, $files) || CandidateRelease::hash($root) !== $release['runtime_hash']) {
            throw new \RuntimeException('Source/runtime changed during snapshot construction.');
        }
        foreach ($code as $path => $hash) { if (hash_file('sha256', $root . '/' . $path) !== $hash) { throw new \RuntimeException('Research builder changed during construction.'); } }
        return $answer;
    }

    public static function verify(string $root, array $artifact, string $expectedRecipe, array $candidate): void
    {
        self::recipe($expectedRecipe);
        $expected = self::buildAll($root, $artifact['as_of'] ?? '', $candidate)[$expectedRecipe];
        self::assertMatches($artifact, $expected, $expectedRecipe);
    }

    /** $trusted must be freshly rebuilt, not deserialized from the artifact under test. */
    public static function assertMatches(array $artifact, array $trusted, string $expectedRecipe): void
    {
        $recipe = self::recipe($expectedRecipe);
        foreach ([$artifact, $trusted] as $a) {
            if (($a['schema'] ?? null) !== self::SCHEMA || ($a['recipe_id'] ?? null) !== $expectedRecipe
                || SelectedMaximumResearch::hash($a['recipe'] ?? []) !== SelectedMaximumResearch::hash($recipe)
                || ($a['recipe_sha256'] ?? null) !== SelectedMaximumResearch::hash($recipe)
                || ($a['research_only'] ?? null) !== true || ($a['paper_only'] ?? null) !== true
                || ($a['order_submission_enabled'] ?? null) !== false || ($a['live_enabled'] ?? null) !== false
                || ($a['release_admitted'] ?? null) !== false || ($a['validation_selected'] ?? null) !== false
                || !hash_equals($a['content_sha256'] ?? '', self::contentHash($a))) {
                throw new \RuntimeException('Isolated bull snapshot identity/integrity failed.');
            }
        }
        if (!hash_equals(self::contentHash($artifact), self::contentHash($trusted))) {
            throw new \RuntimeException('Snapshot does not reproduce trusted recipe/data computation.');
        }
    }

    public static function contentHash(array $artifact): string
    {
        return SelectedMaximumResearch::hash(array_diff_key($artifact, ['content_sha256' => true]));
    }

    private static function files(string $date): array
    {
        $suffix = str_replace('-', '', $date); $files = [];
        foreach (['raw', 'split', 'protocol', 'raw_manifest', 'split_manifest'] as $id) {
            $files[$id] = 'var/reports/candidate_execution_data_' . $suffix . '/' . $id . '.json';
        }
        foreach (['s5tw', 'vvix', 'manifest'] as $id) { $files[$id] = 'var/reports/candidate_external_data_' . $suffix . '/' . $id . '.json'; }
        return $files;
    }

    private static function hashes(string $root, array $files): array
    {
        $hashes = [];
        foreach ($files as $id => $file) {
            if (!is_file($root . '/' . $file)) { throw new \RuntimeException('Missing isolated snapshot source: ' . $id); }
            $hashes[$id] = hash_file('sha256', $root . '/' . $file);
        }
        return $hashes;
    }
}
