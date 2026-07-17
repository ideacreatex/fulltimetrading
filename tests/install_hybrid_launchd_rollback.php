<?php

declare(strict_types=1);

function rollbackTestExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{exit_code:int,stdout:string,stderr:string,root:string,plist:string,state:string} */
function rollbackTestRun(string $scenario): array
{
    $root = sys_get_temp_dir() . '/ftt-hybrid-rollback-' . $scenario . '-' . bin2hex(random_bytes(5));
    $project = $root . '/project';
    $home = $root . '/home';
    $fakeBin = $root . '/fake-bin';
    $state = $root . '/state';
    foreach ([
        $project . '/var/db',
        $project . '/var/run',
        $project . '/var/reports/daily',
        $home . '/Library/LaunchAgents',
        $fakeBin,
        $state,
    ] as $directory) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create rollback fixture directory.');
        }
    }
    touch($project . '/var/db/trading.sqlite');
    file_put_contents($project . '/var/reports/daily/tactical_rotation_shadow.json', "{}\n");
    $plist = $home . '/Library/LaunchAgents/com.fulltimetrading.hybrid-v4-paper.plist';
    file_put_contents($plist, "OLD HYBRID PLIST\n");
    file_put_contents($home . '/Library/LaunchAgents/com.fulltimetrading.paper-daemon.plist', "LEGACY PLIST\n");
    file_put_contents($state . '/loaded', '1');
    file_put_contents($state . '/pid', '999997');
    file_put_contents($state . '/lockheld', '1');
    file_put_contents($state . '/bootout_count', '0');
    file_put_contents($state . '/lint_count', '0');
    file_put_contents($project . '/var/run/tactical_paper_daemon.lock', '999997');

    rollbackTestExecutable($fakeBin . '/git', <<<'SH'
#!/bin/sh
case "$*" in
  *"symbolic-ref --quiet --short HEAD"*) printf '%s\n' main ;;
  *"status --porcelain"*) : ;;
  *"remote get-url"*) printf '%s\n' fake://origin ;;
  *" fetch "*) : ;;
  *"rev-parse"*) printf '%s\n' aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ;;
  *) : ;;
esac
SH);
    rollbackTestExecutable($fakeBin . '/plutil', <<<'SH'
#!/bin/sh
if [ "${1:-}" = "-extract" ]; then
  printf '%s\n' "$FAKE_PROJECT"
  exit 0
fi
if [ "${1:-}" = "-lint" ]; then
  count=$(cat "$FAKE_STATE/lint_count")
  count=$((count + 1))
  printf '%s' "$count" >"$FAKE_STATE/lint_count"
  if [ "$FAKE_SCENARIO" = "prebootstrap_failure" ] && [ "$count" -eq 2 ]; then
    exit 7
  fi
fi
exit 0
SH);
    rollbackTestExecutable($fakeBin . '/launchctl', <<<'SH'
#!/bin/sh
command=${1:-}
loaded=$(cat "$FAKE_STATE/loaded")
case "$command" in
  print)
    [ "$loaded" = "1" ] || exit 3
    printf '    pid = %s\n' "$(cat "$FAKE_STATE/pid")"
    ;;
  bootout)
    count=$(cat "$FAKE_STATE/bootout_count")
    count=$((count + 1))
    printf '%s' "$count" >"$FAKE_STATE/bootout_count"
    if [ "$count" -ge 2 ] && [ "$FAKE_SCENARIO" = "stuck_new" ]; then
      printf '%s\n' 'simulated bootout failure' >&2
      exit 9
    fi
    printf '0' >"$FAKE_STATE/loaded"
    printf '0' >"$FAKE_STATE/lockheld"
    ;;
  bootstrap)
    plist=${3:-}
    if grep -q 'OLD HYBRID PLIST' "$plist"; then
      pid=999997
    else
      pid=999999
    fi
    printf '1' >"$FAKE_STATE/loaded"
    printf '%s' "$pid" >"$FAKE_STATE/pid"
    printf '1' >"$FAKE_STATE/lockheld"
    printf '%s' "$pid" >"$FAKE_PROJECT/var/run/tactical_paper_daemon.lock"
    ;;
  *) exit 2 ;;
esac
SH);
    rollbackTestExecutable($fakeBin . '/php', <<<'SH'
#!/bin/sh
if [ "${1:-}" = "-r" ]; then
  code=$2
  shift 2
  case "$code" in
    *'flock($lock'*)
      [ "$(cat "$FAKE_STATE/lockheld")" = "1" ] && exit 1
      exit 0
      ;;
    *'get("database_path")'*)
      printf '%s' "$FAKE_PROJECT/var/db/trading.sqlite"
      ;;
    *'$p=require'*)
      printf '%s' 'hybrid-v4-paper-2026-07-17'
      ;;
    *'$positions = $client->positions'*)
      printf '%s' flat
      ;;
    *'TelegramNotifier::fromEnv'*) exit 0 ;;
    *'last_executor_exit_code'*) exit 1 ;;
    *'Telegram outbox verification'*) exit 0 ;;
    *'TacticalNotificationHealthGuard::assess'*) exit 1 ;;
    *'json_decode'*) exit 0 ;;
    *) exit 0 ;;
  esac
  exit 0
fi

output=''
for argument in "$@"; do
  case "$argument" in --output=*) output=${argument#--output=} ;; esac
done
if [ -n "$output" ]; then
  mkdir -p "$(dirname "$output")"
  printf '%s\n' '{"run_id":"hybrid-v4-paper-2026-07-17","dry_run":true,"paper_only":true,"errors":[]}' >"$output"
fi
exit 0
SH);

    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment = array_replace($environment, [
        'PATH' => $fakeBin . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => $home,
        'PROJECT_DIR' => $project,
        'PHP_BIN' => $fakeBin . '/php',
        'FAKE_PROJECT' => $project,
        'FAKE_STATE' => $state,
        'FAKE_SCENARIO' => $scenario,
        'HYBRID_VERIFY_TIMEOUT_SECONDS' => '1',
        'HYBRID_ROLLBACK_STOP_TIMEOUT_SECONDS' => '1',
    ]);
    $installer = realpath(__DIR__ . '/../bin/install-hybrid-launchd');
    if ($installer === false) {
        throw new RuntimeException('Installer path is unavailable.');
    }
    $process = proc_open([$installer], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to run rollback fixture.');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'root' => $root,
        'plist' => $plist,
        'state' => $state,
    ];
}

function rollbackTestExecutable(string $path, string $content): void
{
    file_put_contents($path, $content . "\n");
    chmod($path, 0755);
}

function rollbackTestRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child) && !is_link($child)) {
            rollbackTestRemoveTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

$restored = rollbackTestRun('prebootstrap_failure');
try {
    rollbackTestExpect($restored['exit_code'] === 7, 'Cleanup must preserve the original transactional failure code.');
    rollbackTestExpect(
        str_contains((string) file_get_contents($restored['plist']), 'OLD HYBRID PLIST'),
        'A verified stopped process must allow restoration of the prior plist.',
    );
    rollbackTestExpect(trim((string) file_get_contents($restored['state'] . '/loaded')) === '1', 'Prior LaunchAgent must be reloaded.');
    rollbackTestExpect(!str_contains($restored['stderr'], 'INCOMPLETE HYBRID ROLLBACK'), 'A complete rollback must not claim manual recovery.');
} finally {
    rollbackTestRemoveTree($restored['root']);
}

$stuck = rollbackTestRun('stuck_new');
try {
    rollbackTestExpect($stuck['exit_code'] === 1, 'Verification timeout must remain the reported install failure.');
    rollbackTestExpect(str_contains($stuck['stderr'], 'launchctl bootout failed'), 'Rollback bootout failure must be loud.');
    rollbackTestExpect(str_contains($stuck['stderr'], 'INCOMPLETE HYBRID ROLLBACK'), 'A live daemon/held lock must be reported as incomplete rollback.');
    rollbackTestExpect(
        !str_contains((string) file_get_contents($stuck['plist']), 'OLD HYBRID PLIST'),
        'Previous plist must not be restored over a daemon that is still running.',
    );
    preg_match('/transaction artifacts retained at ([^\s]+)/', $stuck['stderr'], $matches);
    rollbackTestExpect(isset($matches[1]) && is_dir($matches[1]), 'Incomplete rollback artifacts must be retained for recovery.');
    if (isset($matches[1])) {
        rollbackTestRemoveTree($matches[1]);
    }
} finally {
    rollbackTestRemoveTree($stuck['root']);
}

echo "hybrid launchd rollback tests passed\n";
