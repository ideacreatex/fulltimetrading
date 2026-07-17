<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/**
 * One canonical identity shared by the shadow generator and paper executor.
 * A shadow produced by any other implementation/config revision is data, not
 * an executable instruction.
 */
final class TacticalImplementationIdentity
{
    private const PATHS = [
        'config/tactical_rotation.php',
        'config/tactical_paper.php',
        'src/Backtest/CausalTacticalRotationBacktester.php',
        'src/Backtest/CausalTacticalRotationEnsembleBacktester.php',
        'src/Backtest/TacticalRotationQualification.php',
        'src/Data/FrozenSipIexDailyBarsProvider.php',
        'src/Data/VerifiedCacheSnapshotMarketDataProvider.php',
        'src/Trading/TacticalRotationShadowContext.php',
        'src/Trading/TacticalSignalArtifactGuard.php',
        'src/Trading/TacticalImplementationIdentity.php',
        'tools/run_tactical_rotation_backtest.php',
    ];

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public static function current(string $root, array $profile): array
    {
        $root = rtrim($root, '/');
        if ($root === '') {
            throw new \InvalidArgumentException('Tactical implementation root must not be empty.');
        }
        $files = [];
        foreach (self::PATHS as $relative) {
            $hash = hash_file('sha256', $root . '/' . $relative);
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \RuntimeException('Unable to hash tactical implementation file: ' . $relative);
            }
            $files[$relative] = $hash;
        }
        $profileSha256 = hash(
            'sha256',
            json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $identityLines = ['schema=1', 'profile=' . $profileSha256];
        foreach ($files as $path => $hash) {
            $identityLines[] = $path . '=' . $hash;
        }

        return [
            'schema' => 1,
            'files_sha256' => $files,
            'profile_sha256' => $profileSha256,
            'combined_sha256' => hash('sha256', implode("\n", $identityLines)),
        ];
    }
}
