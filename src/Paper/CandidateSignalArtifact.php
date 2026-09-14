<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

final class CandidateSignalArtifact
{
    private const DATA_KEYS = ['external_daily_scale', 'opportunity_features', 'nominal_prices'];

    public static function build(array $inputs, array $candidate, string $runtimeHash, string $nextSession): array
    {
        $books = $inputs['books'];
        foreach ($books as &$book) { $book['config'] = array_diff_key($book['config'], array_flip(self::DATA_KEYS)); }
        unset($book);
        $artifact = ['schema' => CandidateOrder::CONTRACT, 'run_id' => $candidate['run_id'], 'profile' => CandidateDefinition::PROFILE,
            'as_of' => $inputs['date'], 'scheduled_session' => $nextSession, 'generated_at' => gmdate(DATE_ATOM),
            'runtime_hash' => $runtimeHash, 'base_profile_sha256' => $candidate['base_profile_sha256'], 'books' => $books,
            'contexts' => $inputs['context_answers'], 'nominal_closes' => $inputs['nominal_closes'],
            'confirmation' => $inputs['confirmation'], 'provenance' => $inputs['provenance'],
            'paper_only' => true, 'order_submission_enabled' => false, 'validation_selected' => false];
        $artifact['content_sha256'] = hash('sha256', CandidateOrder::json(array_diff_key($artifact, ['generated_at' => true])));
        return $artifact;
    }

    public static function validate(array $a, array $candidate, string $runtimeHash, array $baseProfile): array
    {
        if (!hash_equals($a['content_sha256'] ?? '', hash('sha256', CandidateOrder::json(array_diff_key($a, ['content_sha256' => true, 'generated_at' => true]))))) {
            throw new \RuntimeException('Candidate signal content checksum failed.');
        }
        if (($a['schema'] ?? null) !== CandidateOrder::CONTRACT || ($a['paper_only'] ?? null) !== true
            || ($a['order_submission_enabled'] ?? null) !== false || ($a['validation_selected'] ?? null) !== false
            || ($a['run_id'] ?? null) !== $candidate['run_id'] || ($a['profile'] ?? null) !== CandidateDefinition::PROFILE
            || ($a['runtime_hash'] ?? null) !== $runtimeHash || ($a['base_profile_sha256'] ?? null) !== $candidate['base_profile_sha256']) {
            throw new \RuntimeException('Candidate signal identity failed.');
        }
        CandidateOrder::date($a['as_of'] ?? ''); CandidateOrder::date($a['scheduled_session'] ?? '');
        if ($a['scheduled_session'] <= $a['as_of'] || !is_bool($a['confirmation'] ?? null)) { throw new \RuntimeException('Candidate signal session invalid.'); }
        $expected = CandidateDefinition::books($baseProfile, [], [], []);
        foreach ($expected as &$book) { $book['config'] = array_diff_key($book['config'], array_flip(self::DATA_KEYS)); }
        unset($book);
        if (CandidateOrder::json($a['books'] ?? null) !== CandidateOrder::json($expected)) { throw new \RuntimeException('Candidate signal recipe drift.'); }
        if (array_diff_key($expected, $a['contexts'] ?? []) !== [] || count($a['contexts'] ?? []) !== 12) { throw new \RuntimeException('Candidate context set incomplete.'); }
        foreach ($a['nominal_closes'] ?? [] as $price) {
            if (!is_numeric($price) || !is_finite((float) $price) || $price <= 0) { throw new \RuntimeException('Invalid nominal signal price.'); }
        }
        $contexts = [];
        foreach ($expected as $name => $book) {
            $answers = $a['contexts'][$name];
            foreach (['', ...$book['config']['universe']] as $incumbent) {
                $context = $answers[$incumbent] ?? null;
                if (!is_array($context) || ($context['date'] ?? null) !== $a['as_of'] || !is_bool($context['reentry_conditions_met'] ?? null)
                    || !is_array($context['desired'] ?? null) || count($context['desired']) > 1) { throw new \RuntimeException('Malformed candidate close context.'); }
                foreach ($context['desired'] as $symbol => $gross) {
                    if (!in_array($symbol, $book['config']['universe'], true) || !is_numeric($gross) || !is_finite((float) $gross)
                        || $gross <= 0 || $gross > $book['config']['max_gross'] || !isset($a['nominal_closes'][$symbol])) {
                        throw new \RuntimeException('Candidate target exceeds its recipe.');
                    }
                }
            }
            $date = $a['as_of'];
            $contexts[$name] = static function (string $requestedDate, ?string $incumbent) use ($date, $answers): array {
                if ($requestedDate !== $date || !isset($answers[$incumbent ?? ''])) { throw new \RuntimeException('Unknown candidate context.'); }
                return $answers[$incumbent ?? ''];
            };
        }
        return ['date' => $a['as_of'], 'books' => $a['books'], 'contexts' => $contexts, 'nominal_closes' => $a['nominal_closes'],
            'confirmation' => $a['confirmation'], 'provenance' => $a['provenance'] + ['signal_artifact_sha256' => $a['content_sha256']]];
    }

    public static function write(string $path, array $artifact): void
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) { throw new \RuntimeException('Cannot create candidate artifact directory.'); }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, CandidateOrder::json($artifact) . "\n", LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary); throw new \RuntimeException('Cannot commit candidate artifact.');
        }
    }

    /** A broken entry artifact must not stop the daemon that maintains existing protection. */
    public static function needsRefresh(string $path, array $candidate, string $runtimeHash, array $baseProfile, array $session): bool
    {
        try {
            if (!is_file($path)) { return true; }
            $artifact = CandidateDataSnapshot::read($path);
            self::validate($artifact, $candidate, $runtimeHash, $baseProfile);
            return $artifact['as_of'] !== $session['signal_date'] || $artifact['scheduled_session'] !== $session['scheduled_session'];
        } catch (\Throwable) { return true; }
    }
}
