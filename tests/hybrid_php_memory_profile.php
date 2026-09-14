<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$environment = getenv();
$environment['PHP_INI_SCAN_DIR'] = ':' . $root . '/config/php-paper.d';
$code = <<<'PHP'
$child = proc_open([PHP_BINARY, '-r', 'echo ini_get("memory_limit"),"|",(int)extension_loaded("pdo_sqlite");'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
fclose($pipes[0]); $text = stream_get_contents($pipes[1]); fclose($pipes[1]);
if (proc_close($child) !== 0) { exit(1); }
echo ini_get('memory_limit'), '|', (int) extension_loaded('pdo_sqlite'), '|', $text;
PHP;
$process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root, $environment);
if (!is_resource($process)) { throw new RuntimeException('Unable to run PHP profile test.'); }
fclose($pipes[0]); $text = stream_get_contents($pipes[1]); fclose($pipes[1]);
if (proc_close($process) !== 0 || $text !== '512M|1|512M|1') { throw new RuntimeException('Paper parent/child memory or default extensions not preserved: ' . $text); }
$profile = parse_ini_file($root . '/config/php-paper.d/90-hybrid-memory.ini');
if ($profile !== ['memory_limit' => '512M']) { throw new RuntimeException('Paper resource profile must not alter other PHP settings.'); }
$installer = file_get_contents($root . '/bin/install-hybrid-launchd');
if (!str_contains($installer, '<key>PHP_INI_SCAN_DIR</key><string>:$PROJECT_XML/config/php-paper.d</string>')) { throw new RuntimeException('Installer does not preserve the scoped PHP profile.'); }
echo "Paper-only parent/child 512M cap, default extensions and installer profile PASS\n";
