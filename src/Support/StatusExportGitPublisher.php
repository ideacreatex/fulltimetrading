<?php

declare(strict_types=1);

namespace FulltimeTrading\Support;

final class StatusExportGitPublisher
{
    private const ALLOWED_PATHS = [
        'var/status/latest_paper_status.json',
        'var/status/latest_paper_status.md',
    ];

    private string $repositoryDir;

    public function __construct(string $repositoryDir)
    {
        $resolved = realpath($repositoryDir);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Git repository directory does not exist: ' . $repositoryDir);
        }
        $this->repositoryDir = $resolved;
    }

    public function commitFiles(string $jsonPath, string $mdPath, bool $push, string $remote, string $branch): string
    {
        $statusPaths = $this->relativeStatusPaths([$jsonPath, $mdPath]);
        $remoteCommit = null;

        if ($push) {
            $remoteCommit = $this->safePushPreflight($statusPaths, $remote, $branch);
        }

        $add = $this->run(['git', 'add', '--', ...$statusPaths]);
        $this->requireSuccess($add, 'Git add failed');

        $diff = $this->run(['git', 'diff', '--cached', '--quiet', '--', ...$statusPaths]);
        if ($diff['exit_code'] === 1) {
            $commit = $this->run(['git', 'commit', '-m', 'Update paper status snapshot', '--', ...$statusPaths]);
            $this->requireSuccess($commit, 'Git commit failed');
        } elseif ($diff['exit_code'] !== 0) {
            throw new \RuntimeException('Unable to inspect staged paper status changes: ' . $this->commandError($diff));
        } elseif (!$push) {
            return 'Git status export unchanged.';
        }

        if (!$push) {
            return 'Git status export committed locally.';
        }

        // Recheck immediately before push so a concurrent code commit cannot pass the earlier preflight.
        $this->requireCurrentBranch($branch);
        $pushCommit = $this->resolveCommit('refs/heads/' . $branch, 'Unable to resolve the target branch before push');
        $this->validateOutgoingCommits((string) $remoteCommit, $pushCommit);

        $ahead = $this->run(['git', 'rev-list', '--count', $remoteCommit . '..' . $pushCommit]);
        $this->requireSuccess($ahead, 'Unable to count outgoing commits');
        if ((int) trim($ahead['stdout']) === 0) {
            return 'Git status export unchanged and already up to date.';
        }

        // Push the exact validated object, never a moving HEAD ref.
        $targetRef = 'refs/heads/' . $branch;
        $lease = '--force-with-lease=' . $targetRef . ':' . $remoteCommit;
        $pushResult = $this->run(['git', 'push', $lease, '--', $remote, $pushCommit . ':' . $targetRef]);
        $this->requireSuccess($pushResult, 'Git push failed');

        return 'Git status export committed and pushed.';
    }

    /** @param list<string> $statusPaths */
    private function safePushPreflight(array $statusPaths, string $remote, string $branch): string
    {
        $expected = self::ALLOWED_PATHS;
        sort($expected, SORT_STRING);
        $actual = array_values(array_unique($statusPaths));
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException('Git push refused: output files must be exactly ' . implode(', ', self::ALLOWED_PATHS));
        }

        $this->validateRemoteAndBranch($remote, $branch);
        $this->requireCurrentBranch($branch);

        $remoteRef = 'refs/remotes/' . $remote . '/' . $branch;
        $refspec = '+refs/heads/' . $branch . ':' . $remoteRef;
        $fetch = $this->run(['git', 'fetch', '--quiet', '--no-tags', '--', $remote, $refspec]);
        $this->requireSuccess($fetch, 'Git push refused: fetch failed');

        $remoteCommit = $this->resolveCommit($remoteRef, 'Git push refused: fetched remote branch is not a commit');

        $this->validateOutgoingCommits($remoteCommit, 'HEAD');

        return $remoteCommit;
    }

    private function validateRemoteAndBranch(string $remote, string $branch): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $remote)) {
            throw new \RuntimeException('Git push refused: invalid remote name');
        }
        if ($branch === '' || str_starts_with($branch, '-')) {
            throw new \RuntimeException('Git push refused: invalid target branch');
        }

        $branchCheck = $this->run(['git', 'check-ref-format', 'refs/heads/' . $branch]);
        $this->requireSuccess($branchCheck, 'Git push refused: invalid target branch');
        $remoteCheck = $this->run(['git', 'remote', 'get-url', $remote]);
        $this->requireSuccess($remoteCheck, 'Git push refused: remote is not configured');
    }

    private function requireCurrentBranch(string $branch): void
    {
        $current = $this->run(['git', 'symbolic-ref', '--quiet', '--short', 'HEAD']);
        if ($current['exit_code'] !== 0 || trim($current['stdout']) !== $branch) {
            $actual = trim($current['stdout']);
            throw new \RuntimeException(sprintf(
                'Git push refused: current branch must be %s, got %s',
                $branch,
                $actual !== '' ? $actual : 'detached HEAD',
            ));
        }
    }

    private function validateOutgoingCommits(string $remoteCommit, string $headCommit): void
    {
        $ancestor = $this->run(['git', 'merge-base', '--is-ancestor', $remoteCommit, $headCommit]);
        if ($ancestor['exit_code'] === 1) {
            throw new \RuntimeException('Git push refused: fetched remote branch is not an ancestor of HEAD');
        }
        $this->requireSuccess($ancestor, 'Git push refused: unable to verify remote ancestry');

        $log = $this->run([
            'git',
            'log',
            '-m',
            '--format=',
            '--name-only',
            '-z',
            '--no-renames',
            $remoteCommit . '..' . $headCommit,
            '--',
        ]);
        $this->requireSuccess($log, 'Git push refused: unable to inspect outgoing commits');

        $forbidden = [];
        foreach (explode("\0", $log['stdout']) as $path) {
            if ($path !== '' && !in_array($path, self::ALLOWED_PATHS, true)) {
                $forbidden[$path] = true;
            }
        }
        if ($forbidden !== []) {
            throw new \RuntimeException(
                'Git push refused: outgoing commits contain non-status paths: ' . implode(', ', array_keys($forbidden)),
            );
        }
    }

    private function resolveCommit(string $ref, string $message): string
    {
        $resolve = $this->run(['git', 'rev-parse', '--verify', $ref . '^{commit}']);
        $this->requireSuccess($resolve, $message);
        $commit = trim($resolve['stdout']);
        if (!preg_match('/^[0-9a-f]{40,64}$/', $commit)) {
            throw new \RuntimeException($message . ': invalid object id');
        }

        return $commit;
    }

    /** @param list<string> $paths @return list<string> */
    private function relativeStatusPaths(array $paths): array
    {
        $prefix = $this->repositoryDir . DIRECTORY_SEPARATOR;
        $relative = [];
        foreach ($paths as $path) {
            $resolved = realpath($path);
            if ($resolved === false || !is_file($resolved) || !str_starts_with($resolved, $prefix)) {
                throw new \RuntimeException('Paper status output is outside the repository: ' . $path);
            }
            $relative[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($prefix)));
        }

        return $relative;
    }

    /** @param list<string> $command @return array{exit_code:int, stdout:string, stderr:string} */
    private function run(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repositoryDir);
        if (!is_resource($process)) {
            return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Unable to start process'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
        ];
    }

    /** @param array{exit_code:int, stdout:string, stderr:string} $result */
    private function requireSuccess(array $result, string $message): void
    {
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($message . ': ' . $this->commandError($result));
        }
    }

    /** @param array{exit_code:int, stdout:string, stderr:string} $result */
    private function commandError(array $result): string
    {
        $detail = trim($result['stderr']) ?: trim($result['stdout']);

        return $detail !== '' ? $detail : 'exit ' . $result['exit_code'];
    }
}
