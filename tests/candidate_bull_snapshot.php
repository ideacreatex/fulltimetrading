<?php
declare(strict_types=1);
use FulltimeTrading\Research\CandidateBullSnapshot as Snapshot;
use FulltimeTrading\Research\SelectedMaximumResearch as Hash;
require dirname(__DIR__) . '/bootstrap.php';
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
foreach (Snapshot::IDS as $id) {
    $recipe = Snapshot::recipe($id);
    $a = ['schema' => Snapshot::SCHEMA, 'recipe_id' => $id, 'recipe' => $recipe, 'recipe_sha256' => Hash::hash($recipe),
        'research_only' => true, 'paper_only' => true, 'order_submission_enabled' => false, 'live_enabled' => false,
        'release_admitted' => false, 'validation_selected' => false, 'as_of' => '2026-09-14', 'confirmation' => false,
        'contexts' => ['fixture' => ['desired' => ['MSFT' => 1.]]], 'daily_scale' => 1., 'source_sha256' => ['split' => str_repeat('a', 64)]];
    $a['content_sha256'] = Snapshot::contentHash($a);
    $roundtrip = json_decode(json_encode($a, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    Snapshot::assertMatches($roundtrip, $a, $id); $check(true, 'JSON numeric roundtrip accepted.');
    foreach (['schema', 'recipe_id', 'recipe', 'recipe_sha256', 'research_only', 'paper_only', 'order_submission_enabled',
        'live_enabled', 'release_admitted', 'validation_selected', 'as_of', 'confirmation', 'contexts', 'daily_scale', 'source_sha256', 'checksum'] as $fault) {
        $bad = $a;
        switch ($fault) {
            case 'schema': $bad[$fault] = 'candidate-whole-close-stop-v1'; break;
            case 'recipe_id': $bad[$fault] = 'deployed'; break;
            case 'recipe': $bad[$fault]['bull']['boost'] = 1.1; break;
            case 'recipe_sha256': $bad[$fault] = str_repeat('f', 64); break;
            case 'as_of': $bad[$fault] = '2026-09-15'; break;
            case 'contexts': $bad[$fault]['fixture']['desired']['MSFT'] = 1.1; break;
            case 'daily_scale': $bad[$fault] = 1.05; break;
            case 'source_sha256': $bad[$fault]['split'] = str_repeat('f', 64); break;
            case 'checksum': $bad['content_sha256'] = str_repeat('0', 64); break;
            default: $bad[$fault] = !$bad[$fault];
        }
        if ($fault !== 'checksum') { $bad['content_sha256'] = Snapshot::contentHash($bad); }
        try { Snapshot::assertMatches($bad, $a, $id); } catch (RuntimeException) { $check(true, 'Rejected recomputed forgery: ' . $fault); continue; }
        $check(false, 'Accepted forged ' . $fault);
    }
}
foreach (['deployed', 'bull_v110_ma50_boost110', 'bull_v110_ma200_boost105', '../config'] as $id) {
    try { Snapshot::recipe($id); } catch (InvalidArgumentException) { $check(true, 'Undeclared recipe'); continue; }
    $check(false, 'Unknown recipe accepted.');
}
echo "Candidate bull snapshot: $n assertions PASS\n";
