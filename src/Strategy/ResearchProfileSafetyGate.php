<?php

declare(strict_types=1);

namespace FulltimeTrading\Strategy;

final class ResearchProfileSafetyGate
{
    /**
     * @param array<string, mixed> $strategy
     * @param array<string, mixed>|null $profile
     * @return array<string, mixed>
     */
    public function apply(array $strategy, ?array $profile): array
    {
        if ($profile !== null && ($profile['production_approved'] ?? false) !== true) {
            $strategy['entry_submission_enabled'] = false;
            $strategy['entry_submission_block_reason'] = 'research_profile_not_production_approved';
        }

        return $strategy;
    }
}
