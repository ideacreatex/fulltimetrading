<?php

declare(strict_types=1);

use FulltimeTrading\Research\OpportunityPolicy as O;

require dirname(__DIR__) . '/bootstrap.php';
$scores = ['A' => [], 'B' => []]; $checks = 0;
foreach (range(-20, 20) as $a) {
    foreach (range(-20, 20) as $b) {
        $f = ['A' => ['d' => ['drift' => $a / 1000]], 'B' => ['d' => ['drift' => $b / 1000]]];
        $normal = ['family' => 'cost_band', 'window' => 20, 'strength' => 2.0];
        $fixedThresholdStress = ['family' => 'cost_band', 'window' => 20, 'strength' => 1.0];
        if (O::choose('A', 'B', $scores, $f, 'd', $normal, 30) !== O::choose('A', 'B', $scores, $f, 'd', $fixedThresholdStress, 60)) {
            throw new RuntimeException('Doubled costs and halved policy multiplier must preserve the switch threshold.');
        }
        $checks++;
    }
}
echo "Fixed-threshold cost stress equivalence OK: $checks switch comparisons\n";
