<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

final class CandidateParityInputs
{
    /** A truncated prefix cannot contain synthetic bars for a later IPO. */
    public static function prefixConfig(array $config, array $bars, string $end): array
    {
        $available = [];
        foreach ($config['universe'] as $symbol) {
            if (empty($bars[$symbol])) { throw new \RuntimeException('Missing source series, not a future IPO: ' . $symbol); }
            foreach ($bars[$symbol] as $bar) {
                if (DailyDataAudit::session($bar) <= $end) { $available[] = $symbol; break; }
            }
        }
        if ($available === []) { throw new \RuntimeException('No historical universe at prefix date.'); }
        return array_replace($config, ['universe' => $available]);
    }

    public static function comparableContext(array $context, array $futureSymbols): array
    {
        foreach ($futureSymbols as $symbol) {
            foreach (['closes', 'previous_closes', 'desired'] as $field) {
                if (array_key_exists($symbol, $context[$field])) { throw new \RuntimeException('Pre-IPO price or target leaked: ' . $symbol); }
            }
            if (($context['volatility'][$symbol] ?? null) !== null) { throw new \RuntimeException('Pre-IPO volatility leaked: ' . $symbol); }
            // Missing and null are equivalent only for proven unavailable symbols.
            unset($context['volatility'][$symbol]);
        }
        return $context;
    }
}
