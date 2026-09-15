<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

use FulltimeTrading\Research\DailyDataAudit;
use FulltimeTrading\Research\BreadthVolatilityResearch;
use FulltimeTrading\Research\SelectedMaximumResearch;
use FulltimeTrading\Research\OpportunityPolicy;
use FulltimeTrading\Research\PaperExecutionRotationBacktester;

/** Validates both providers' dated input artifacts before building a signal. No freshness substitution. */
final class CandidateDataSnapshot
{
    /** Independently usable by protective maintenance while an external indicator is unpublished. */
    public static function rawCloses(string $root, string $date, array $symbols): array
    {
        CandidateOrder::date($date);
        $dir = $root . '/var/reports/candidate_execution_data_' . str_replace('-', '', $date);
        $protocol = self::read($dir . '/protocol.json'); $manifest = self::read($dir . '/raw_manifest.json');
        if (($protocol['provider'] ?? null) !== 'Alpaca' || ($protocol['feed'] ?? null) !== 'sip' || ($protocol['end'] ?? null) !== $date
            || !hash_equals($manifest['protocol_sha256'] ?? '', hash_file('sha256', $dir . '/protocol.json'))
            || !hash_equals($manifest['sha256'] ?? '', hash_file('sha256', $dir . '/raw.json'))) {
            throw new \RuntimeException('Candidate protective price provenance failed.');
        }
        $data = self::read($dir . '/raw.json'); $closes = [];
        foreach ($symbols as $symbol) {
            $series = $data[$symbol] ?? []; $last = $series === [] ? null : $series[array_key_last($series)];
            if (!is_array($last) || (new \DateTimeImmutable($last['t']))->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d') !== $date
                || !is_numeric($last['c'] ?? null) || !is_finite((float) $last['c']) || $last['c'] <= 0) {
                throw new \RuntimeException('Candidate protective close missing: ' . $symbol);
            }
            $closes[$symbol] = (float) $last['c'];
        }
        return $closes;
    }

    public static function verifyProvenance(string $root, array $provenance): void
    {
        $date = $provenance['date'] ?? ''; CandidateOrder::date($date);
        $suffix = str_replace('-', '', $date);
        foreach (['raw', 'split', 's5tw', 'vvix'] as $id) {
            $directory = in_array($id, ['raw', 'split'], true) ? 'candidate_execution_data_' : 'candidate_external_data_';
            $path = $root . '/var/reports/' . $directory . $suffix . '/' . $id . '.json';
            if (!is_file($path) || !hash_equals($provenance[$id . '_sha256'] ?? '', hash_file('sha256', $path))) {
                throw new \RuntimeException('Candidate source provenance failed: ' . $id);
            }
        }
    }

    public static function load(string $root, string $date, array $candidate): array
    {
        CandidateOrder::date($date);
        if (($candidate['paper_only'] ?? null) !== true || ($candidate['live_enabled'] ?? null) !== false
            || ($candidate['profile'] ?? null) !== CandidateDefinition::PROFILE
            || ($candidate['indicator_recipe'] ?? null) !== CandidateDefinition::INDICATOR_RECIPE
            || ($candidate['data']['feed'] ?? null) !== 'sip' || ($candidate['data']['yahoo_fallback'] ?? null) !== false
            || !hash_equals($candidate['base_profile_sha256'] ?? '', hash_file('sha256', $root . '/config/tactical_rotation.php'))) {
            throw new \RuntimeException('Candidate data/profile contract drift.');
        }
        $prices = $root . '/var/reports/candidate_execution_data_' . str_replace('-', '', $date);
        $external = $root . '/var/reports/candidate_external_data_' . str_replace('-', '', $date);
        $protocol = self::read($prices . '/protocol.json');
        if ($protocol['provider'] !== 'Alpaca' || $protocol['feed'] !== 'sip' || $protocol['end'] !== $date) {
            throw new \RuntimeException('Unexpected candidate price provider/session.');
        }
        $provenance = ['date' => $date]; $decoded = [];
        foreach (['raw', 'split'] as $adjustment) {
            $manifest = self::read($prices . '/' . $adjustment . '_manifest.json');
            $path = $prices . '/' . $adjustment . '.json';
            if (!hash_equals($manifest['sha256'], hash_file('sha256', $path))
                || !hash_equals($manifest['protocol_sha256'], hash_file('sha256', $prices . '/protocol.json'))) {
                throw new \RuntimeException('Candidate price snapshot changed.');
            }
            $decoded[$adjustment] = DailyDataAudit::decode(self::read($path));
            $coverage = DailyDataAudit::coverage($decoded[$adjustment]);
            foreach ($coverage as $symbol => $row) {
                if ($row['last'] !== $date || $row['internal_missing_sessions'] !== [] || $row['extra_sessions'] !== []) {
                    throw new \RuntimeException('Candidate price coverage failed: ' . $symbol);
                }
            }
            $provenance[$adjustment . '_sha256'] = $manifest['sha256'];
        }
        $profile = require $root . '/config/tactical_rotation.php';
        $required = array_unique([...$profile['universe'], 'SPY', 'QQQ', 'SVXY']);
        foreach ($decoded as $series) { if (array_diff($required, array_keys($series)) !== []) { throw new \RuntimeException('Missing candidate symbols.'); } }
        $calendar = array_keys(DailyDataAudit::indexed(['SPY' => $decoded['split']['SPY']])['SPY']);
        $m = self::read($external . '/manifest.json');
        if ($m['end'] !== $date || $m['alpaca_calendar_sha256'] !== $provenance['split_sha256']) { throw new \RuntimeException('Candidate external calendar drift.'); }
        $indicators = [];
        foreach (['s5tw', 'vvix'] as $id) {
            $path = $external . '/' . $id . '.json';
            if (($m['snapshots'][$id]['last'] ?? null) !== $date
                || !hash_equals($m['snapshots'][$id]['sha256'], hash_file('sha256', $path))) {
                throw new \RuntimeException('Candidate external indicator is stale or changed: ' . $id);
            }
            $indicators[$id] = self::read($path); $provenance[$id . '_sha256'] = $m['snapshots'][$id]['sha256'];
        }
        $breadth = BreadthVolatilityResearch::validateBreadth($indicators['s5tw'], $calendar);
        foreach ($calendar as $d) {
            if (!is_numeric($indicators['vvix'][$d] ?? null) || !is_finite((float) $indicators['vvix'][$d]) || $indicators['vvix'][$d] <= 0) {
                throw new \RuntimeException('Candidate VVIX calendar incomplete.');
            }
        }
        $maps = CandidateDefinition::indicatorMaps($decoded['split'], $breadth, $indicators['vvix']);
        $features = OpportunityPolicy::features($decoded['split'], $indicators['vvix'], $profile['universe']);
        $nominal = $nominalCloses = [];
        foreach ($decoded['raw'] as $symbol => $series) {
            foreach ($series as $bar) { $nominal[$symbol][DailyDataAudit::session($bar)] = ['open' => $bar->open, 'close' => $bar->close]; }
            $nominalCloses[$symbol] = $nominal[$symbol][$date]['close'];
        }
        $books = CandidateDefinition::books($profile, $maps['scale'], $features, $nominal); $contexts = $kernels = $contextAnswers = $answerCache = [];
        // The replay's warmup anchor is part of the signal contract; do not silently expand it.
        $bars = $decoded['split'];
        foreach ($bars as $symbol => $series) {
            $bars[$symbol] = array_values(array_filter($series, static fn ($b): bool => DailyDataAudit::session($b) >= '2020-01-01'));
        }
        foreach ($books as $name => $book) {
            // Three calendar phases share identical close features; retain four kernels, not twelve.
            $key = hash('sha256', CandidateOrder::json(array_diff_key($book['config'],
                array_flip(['rebalance_phase', 'nominal_prices', 'opportunity_features', 'external_daily_scale']))));
            if (!isset($kernels[$key])) {
                $historical = (new PaperExecutionRotationBacktester($book['config']))->paperSignalContexts($bars, $date);
                $answers = [];
                foreach ([null, ...$book['config']['universe']] as $incumbent) {
                    $answers[$incumbent ?? ''] = $historical($date, $incumbent);
                }
                // Keep only this close's possible incumbent decisions, not years of per-kernel features.
                unset($historical);
                $answerCache[$key] = $answers;
                $kernels[$key] = static function (string $requestedDate, ?string $incumbent) use ($date, $answers): array {
                    if ($requestedDate !== $date || !isset($answers[$incumbent ?? ''])) { throw new \RuntimeException('Decision is outside the frozen close snapshot.'); }
                    return $answers[$incumbent ?? ''];
                };
            }
            $contexts[$name] = $kernels[$key];
            $contextAnswers[$name] = $answerCache[$key];
        }
        return ['date' => $date, 'books' => $books, 'contexts' => $contexts, 'context_answers' => $contextAnswers, 'nominal_closes' => $nominalCloses,
            'confirmation' => $maps['confirm']['volatility_calm'][$date], 'provenance' => $provenance];
    }

    public static function read(string $path): array
    {
        if (!is_file($path)) { throw new \RuntimeException('Missing candidate artifact: ' . basename($path)); }
        $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new \RuntimeException('Invalid candidate artifact.'); }
        return $value;
    }
}
