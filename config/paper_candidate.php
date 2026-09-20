<?php

declare(strict_types=1);

return [
    // Experimental paper release. Activation still requires verified inputs, manifest and LaunchAgent commissioning.
    'enabled' => true,
    'paper_only' => true,
    'live_enabled' => false,
    'run_id' => 'hybrid-v4-bull5-weekly-2026-09-20',
    'predecessor_run_id' => 'hybrid-v4-bull5-2026-09-15',
    'profile' => 'maximum-stop12-costband2-whole-bull5-v1',
    'indicator_recipe' => 'bull_v110_ma50_boost105',
    'base_profile_sha256' => '0e29673e0c4c83505077cb1e770f110aaa3e8f55c3612790b4b4c294cfb3705c',
    'execution_contract' => 'candidate-whole-close-stop-v1',
    'data' => ['feed' => 'sip', 'price_adjustment' => 'split', 'execution_prices' => 'raw',
        'external_same_session_required' => true, 'yahoo_fallback' => false],
    'release_manifest' => 'var/releases/candidate-bull5-weekly-v1/manifest.json',
    'entry_limits' => ['maximum_reference_gross' => 1.20, 'maximum_projected_gross' => 1.30,
        'buying_power_reserve_fraction' => .05],
    'monitor_interval_seconds' => 15,
];
