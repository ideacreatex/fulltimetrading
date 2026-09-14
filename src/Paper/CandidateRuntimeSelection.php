<?php
declare(strict_types=1);

namespace FulltimeTrading\Paper;

use FulltimeTrading\Storage\TacticalPaperRepository;

/** Reporting follows actual activation, never a merely staged configuration file. */
final class CandidateRuntimeSelection
{
    public static function select(string $root, array $legacy, TacticalPaperRepository $repository): array
    {
        if (!is_file($root . '/config/paper_candidate.php')) { return $legacy; }
        $candidate = require $root . '/config/paper_candidate.php'; $run = $repository->run($candidate['run_id']);
        if ($run === null || !is_string($run['activated_at'] ?? null) || $run['activated_at'] === ''
            || !in_array($run['status'], ['active', 'paused'], true)) { return $legacy; }
        $contract = json_decode($run['data_contract'], true, 512, JSON_THROW_ON_ERROR);
        if (($contract['execution_contract'] ?? null) !== CandidateOrder::CONTRACT || ($contract['paper_only'] ?? null) !== true
            || $run['profile'] !== CandidateDefinition::PROFILE) { throw new \RuntimeException('Candidate reporting identity drift.'); }
        $prior = $repository->run($legacy['run_id']);
        if ($prior !== null && !empty($prior['activated_at']) && new \DateTimeImmutable($prior['activated_at']) > new \DateTimeImmutable($run['activated_at'])) { return $legacy; }
        return $candidate + ['live_review_not_before' => (new \DateTimeImmutable($run['activated_at']))->modify('+31 days')->format(DATE_ATOM),
            'execution' => ['monitor_interval_seconds' => $candidate['monitor_interval_seconds']]];
    }

    public static function isStandingProtection(array $intent): bool
    {
        return ($intent['payload']['contract'] ?? null) === CandidateOrder::CONTRACT && ($intent['leg'] ?? null) === 'protective_stop'
            && ($intent['payload']['body']['time_in_force'] ?? null) === 'gtc' && ($intent['side'] ?? null) === 'sell'
            && in_array($intent['status'] ?? null, ['accepted', 'new', 'done_for_day'], true)
            && !empty($intent['order_id']) && !isset($intent['payload']['cancel_request'])
            && (float) ($intent['cumulative_filled_qty'] ?? -1) === 0.;
    }
}
