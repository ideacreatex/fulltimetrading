<?php

declare(strict_types=1);

use FulltimeTrading\Support\StatusExportGitPublisher;

require __DIR__ . '/../bootstrap.php';

function gitGuardExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $arguments */
function gitGuardRun(array $arguments, string $cwd): string
{
    $process = proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start git.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            "git %s failed (%d): %s",
            implode(' ', $arguments),
            $exitCode,
            trim((string) $stderr),
        ));
    }

    return (string) $stdout;
}

function gitGuardWrite(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create test directory: ' . $dir);
    }
    file_put_contents($path, $content);
}

function gitGuardExpectRefusal(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        gitGuardExpect(str_contains($e->getMessage(), $messagePart), 'Unexpected refusal: ' . $e->getMessage());

        return;
    }

    throw new RuntimeException('Expected safe-push refusal containing: ' . $messagePart);
}

function gitGuardRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child) && !is_link($child)) {
            gitGuardRemoveTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

$baseDir = sys_get_temp_dir() . '/ftt-status-git-guard-' . bin2hex(random_bytes(6));
$remoteDir = $baseDir . '/remote.git';
$workDir = $baseDir . '/work';
$jsonPath = $workDir . '/var/status/latest_paper_status.json';
$mdPath = $workDir . '/var/status/latest_paper_status.md';

try {
    mkdir($baseDir, 0775, true);
    gitGuardRun(['init', '--bare', $remoteDir], $baseDir);
    gitGuardRun(['init', '-b', 'main', $workDir], $baseDir);
    gitGuardRun(['config', 'user.name', 'FTT Test'], $workDir);
    gitGuardRun(['config', 'user.email', 'ftt-test@example.invalid'], $workDir);

    gitGuardWrite($jsonPath, "{}\n");
    gitGuardWrite($mdPath, "# Initial\n");
    gitGuardWrite($workDir . '/README.md', "initial\n");
    gitGuardRun(['add', '.'], $workDir);
    gitGuardRun(['commit', '-m', 'Initial'], $workDir);
    gitGuardRun(['remote', 'add', 'origin', $remoteDir], $workDir);
    gitGuardRun(['push', '-u', 'origin', 'main'], $workDir);

    $publisher = new StatusExportGitPublisher($workDir);

    // A normal status-only update is committed and pushed.
    gitGuardWrite($jsonPath, "{\"cycle\":1}\n");
    $result = $publisher->commitFiles($jsonPath, $mdPath, true, 'origin', 'main');
    gitGuardExpect(str_contains($result, 'pushed'), 'Expected the aligned status update to be pushed.');
    $paths = array_values(array_filter(preg_split('/\R/', trim(gitGuardRun(['show', '--format=', '--name-only', 'HEAD'], $workDir))) ?: []));
    gitGuardExpect($paths === ['var/status/latest_paper_status.json'], 'The status commit contained an unexpected path.');

    // A pre-existing status-only commit must be pushed even when this run produces no new diff.
    gitGuardWrite($mdPath, "# Backlog\n");
    gitGuardRun(['add', 'var/status/latest_paper_status.md'], $workDir);
    gitGuardRun(['commit', '-m', 'Queued paper status'], $workDir);
    $result = $publisher->commitFiles($jsonPath, $mdPath, true, 'origin', 'main');
    gitGuardExpect(str_contains($result, 'pushed'), 'Expected a status-only backlog to be retried.');
    $localHead = trim(gitGuardRun(['rev-parse', 'HEAD'], $workDir));
    $remoteHead = trim(gitGuardRun(['--git-dir=' . $remoteDir, 'rev-parse', 'refs/heads/main'], $baseDir));
    gitGuardExpect($localHead === $remoteHead, 'The status-only backlog did not reach the remote.');

    // Unrelated staged work stays staged and is not included in the status commit.
    gitGuardWrite($workDir . '/README.md', "staged but not published\n");
    gitGuardRun(['add', 'README.md'], $workDir);
    gitGuardWrite($jsonPath, "{\"cycle\":2}\n");
    $publisher->commitFiles($jsonPath, $mdPath, true, 'origin', 'main');
    gitGuardExpect(trim(gitGuardRun(['diff', '--cached', '--name-only'], $workDir)) === 'README.md', 'Unrelated staged work was consumed.');
    gitGuardRun(['restore', '--staged', 'README.md'], $workDir);
    gitGuardRun(['restore', 'README.md'], $workDir);

    // Feature branches are refused before staging the generated status files.
    gitGuardRun(['checkout', '-b', 'feature/status-test'], $workDir);
    gitGuardWrite($jsonPath, "{\"feature\":true}\n");
    $headBefore = trim(gitGuardRun(['rev-parse', 'HEAD'], $workDir));
    gitGuardExpectRefusal(
        static fn () => $publisher->commitFiles($jsonPath, $mdPath, true, 'origin', 'main'),
        'current branch must be main',
    );
    gitGuardExpect(trim(gitGuardRun(['rev-parse', 'HEAD'], $workDir)) === $headBefore, 'Feature refusal changed HEAD.');
    gitGuardExpect(trim(gitGuardRun(['diff', '--cached', '--name-only'], $workDir)) === '', 'Feature refusal staged files.');
    gitGuardRun(['restore', 'var/status/latest_paper_status.json'], $workDir);
    gitGuardRun(['checkout', 'main'], $workDir);

    // A custom output directory cannot be smuggled into the new status commit.
    $otherJson = $workDir . '/var/other/latest_paper_status.json';
    $otherMd = $workDir . '/var/other/latest_paper_status.md';
    gitGuardWrite($otherJson, "{}\n");
    gitGuardWrite($otherMd, "# Other\n");
    gitGuardExpectRefusal(
        static fn () => $publisher->commitFiles($otherJson, $otherMd, true, 'origin', 'main'),
        'output files must be exactly',
    );
    gitGuardExpect(trim(gitGuardRun(['diff', '--cached', '--name-only'], $workDir)) === '', 'Custom outputs were staged.');
    gitGuardExpectRefusal(
        static fn () => $publisher->commitFiles($otherJson, $otherMd, false, 'origin', 'main'),
        'output files must be exactly',
    );
    gitGuardExpect(trim(gitGuardRun(['diff', '--cached', '--name-only'], $workDir)) === '', 'Custom local-only outputs were staged.');

    // Fetch and push URLs may differ. An exact lease must not recreate a deleted push target branch.
    $pushRemoteDir = $baseDir . '/push-remote.git';
    gitGuardRun(['clone', '--bare', $remoteDir, $pushRemoteDir], $baseDir);
    gitGuardRun(['--git-dir=' . $pushRemoteDir, 'update-ref', '-d', 'refs/heads/main'], $baseDir);
    gitGuardRun(['remote', 'set-url', '--push', 'origin', $pushRemoteDir], $workDir);
    gitGuardWrite($jsonPath, "{\"lease\":\"deleted\"}\n");
    gitGuardExpectRefusal(
        static fn () => $publisher->commitFiles($jsonPath, $mdPath, true, 'origin', 'main'),
        'Git push failed',
    );
    $pushRefs = gitGuardRun(['--git-dir=' . $pushRemoteDir, 'for-each-ref', '--format=%(refname)', 'refs/heads'], $baseDir);
    gitGuardExpect(!str_contains($pushRefs, 'refs/heads/main'), 'Exact lease unexpectedly recreated a deleted branch.');
    gitGuardRun(['remote', 'set-url', '--push', 'origin', $remoteDir], $workDir);
    $publisher->commitFiles($jsonPath, $mdPath, true, 'origin', 'main');

    // A divergent push URL must also stay untouched when its tip differs from the fetched commit.
    $rootCommit = trim(gitGuardRun(['rev-list', '--max-parents=0', 'HEAD'], $workDir));
    gitGuardRun(['--git-dir=' . $pushRemoteDir, 'update-ref', 'refs/heads/main', $rootCommit], $baseDir);
    gitGuardRun(['remote', 'set-url', '--push', 'origin', $pushRemoteDir], $workDir);
    gitGuardWrite($jsonPath, "{\"lease\":\"diverged\"}\n");
    gitGuardExpectRefusal(
        static fn () => $publisher->commitFiles($jsonPath, $mdPath, true, 'origin', 'main'),
        'Git push failed',
    );
    $divergentHead = trim(gitGuardRun(['--git-dir=' . $pushRemoteDir, 'rev-parse', 'refs/heads/main'], $baseDir));
    gitGuardExpect($divergentHead === $rootCommit, 'Exact lease overwrote a divergent push target.');
    gitGuardRun(['remote', 'set-url', '--push', 'origin', $remoteDir], $workDir);
    $publisher->commitFiles($jsonPath, $mdPath, true, 'origin', 'main');

    // Inspect commit history, not net diff: a code change followed by its revert must still block push.
    gitGuardWrite($workDir . '/README.md', "temporary code change\n");
    gitGuardRun(['add', 'README.md'], $workDir);
    gitGuardRun(['commit', '-m', 'Temporary code change'], $workDir);
    gitGuardRun(['revert', '--no-edit', 'HEAD'], $workDir);
    gitGuardWrite($jsonPath, "{\"cycle\":3}\n");
    $headBefore = trim(gitGuardRun(['rev-parse', 'HEAD'], $workDir));
    gitGuardExpectRefusal(
        static fn () => $publisher->commitFiles($jsonPath, $mdPath, true, 'origin', 'main'),
        'outgoing commits contain non-status paths',
    );
    gitGuardExpect(trim(gitGuardRun(['rev-parse', 'HEAD'], $workDir)) === $headBefore, 'History refusal changed HEAD.');
    gitGuardExpect(trim(gitGuardRun(['diff', '--cached', '--name-only'], $workDir)) === '', 'History refusal staged status files.');
} finally {
    gitGuardRemoveTree($baseDir);
}

echo "Paper status Git guard OK\n";
