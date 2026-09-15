#!/usr/bin/env php
<?php
declare(strict_types=1);
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $stage = $root . '/var/staging/bull5-v1';
$release = CandidateRelease::verify($root, require $root . '/config/paper_candidate.php');
$files = [];
foreach (['src', 'config', 'tests', 'tools', 'bin'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || $file->isLink() || ($directory !== 'bin' && !in_array($file->getExtension(), $directory === 'config' ? ['php', 'ini'] : ['php'], true))) { continue; }
        $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
    }
}
$files['bootstrap.php'] = $root . '/bootstrap.php';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/staging/bull5-v1/overrides', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || $file->isLink()) { throw new RuntimeException('Invalid staging overlay.'); }
    $relative = substr($file->getPathname(), strlen($root . '/staging/bull5-v1/overrides/') );
    if (!isset($files[$relative])) { throw new RuntimeException('Overlay is not an existing runtime source.'); }
    $files[$relative] = $file->getPathname();
}
$sources = [];
foreach ($files as $relative => $source) {
    $target = $stage . '/' . $relative;
    if (is_link($target) || is_link(dirname($target))) { throw new RuntimeException('Staging symlinks forbidden.'); }
    if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
    if (!copy($source, $target)) { throw new RuntimeException('Staging copy failed.'); }
    chmod($target, fileperms($source) & 0777);
    $sources[$relative] = ['source' => substr($source, strlen($root) + 1), 'sha256' => hash_file('sha256', $target)];
}
$snapshot = json_decode(file_get_contents($root . '/var/reports/candidate_bull_snapshot_20260915/bull_v110_ma50_boost105.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($snapshot['source_files'] as $key => $relative) {
    if (hash_file('sha256', $root . '/' . $relative) !== $snapshot['source_sha256'][$key]) { throw new RuntimeException('Frozen snapshot input changed.'); }
    if (!is_dir(dirname($stage . '/' . $relative))) { mkdir(dirname($stage . '/' . $relative), 0700, true); }
    if (!copy($root . '/' . $relative, $stage . '/' . $relative)) { throw new RuntimeException('Snapshot copy failed.'); }
}
if (is_file($stage . '/.env') || is_file($stage . '/var/db/trading.sqlite') || is_file($stage . '/var/run/candidate_commission.json')) {
    throw new RuntimeException('Staging isolation violated.');
}
if (!is_dir($stage . '/var/reports/staging')) { mkdir($stage . '/var/reports/staging', 0700, true); }
Writer::write($stage . '/var/reports/staging/expected_snapshot.json', $snapshot);
Writer::write($stage . '/stage_sources.json', ['schema' => 'bull5-runtime-stage-v1', 'reference_runtime_hash' => $release['runtime_hash'],
    'recipe' => 'bull_v110_ma50_boost105', 'files' => $sources, 'snapshot_source_sha256' => $snapshot['source_sha256'],
    'orders_submitted' => 0, 'credentials_copied' => false, 'release_admitted' => false]);
if (CandidateRelease::hash($root) !== $release['runtime_hash']) { throw new RuntimeException('Active runtime changed.'); }
echo json_encode(['stage' => $stage, 'source_files' => count($sources), 'orders_submitted' => 0, 'runtime_unchanged' => true]), "\n";
