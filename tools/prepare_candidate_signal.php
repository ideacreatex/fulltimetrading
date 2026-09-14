#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Paper\CandidateDataSnapshot;
use FulltimeTrading\Paper\CandidateOrder;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Paper\CandidateSession;
use FulltimeTrading\Paper\CandidateSignalArtifact;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Trading\AlpacaPaperClient;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $options = ['end' => '', 'refresh' => 'true', 'output' => $root . '/var/reports/daily/candidate_signal.json'];
foreach (array_slice($argv, 1) as $arg) { if (str_starts_with($arg, '--') && str_contains($arg, '=')) { [$k, $v] = explode('=', substr($arg, 2), 2); $options[$k] = $v; } }
$config = Config::fromFile($root . '/config/config.php'); $candidate = require $root . '/config/paper_candidate.php';
$client = new AlpacaPaperClient(new HttpClient(), getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url'));
$now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$calendar = $client->calendar($now->modify('-15 days')->format('Y-m-d'), $now->modify('+15 days')->format('Y-m-d'));
$session = CandidateSession::resolve($calendar, $client->clock(), $now);
$date = $options['end'] === '' ? $session['signal_date'] : $options['end']; CandidateOrder::date($date);
if ($date > $session['signal_date']) { throw new RuntimeException('Cannot prepare an incomplete/future candidate session.'); }
$next = null;
foreach ($client->calendar($date, (new DateTimeImmutable($date))->modify('+15 days')->format('Y-m-d')) as $row) {
    if ($row['date'] > $date) { $next = $row['date']; break; }
}
if ($next === null) { throw new RuntimeException('Missing official next market session.'); }
if ($options['refresh'] === 'true') {
    foreach (['fetch_candidate_execution_data', 'fetch_candidate_external_data'] as $tool) {
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', $root . '/tools/' . $tool . '.php', $date], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root);
        if (!is_resource($p)) { throw new RuntimeException('Cannot refresh candidate inputs.'); }
        fclose($pipes[0]); if (proc_close($p) !== 0) { throw new RuntimeException('Candidate input refresh failed: ' . $tool); }
    }
}
$inputs = CandidateDataSnapshot::load($root, $date, $candidate);
$artifact = CandidateSignalArtifact::build($inputs, $candidate, CandidateRelease::hash($root), $next);
CandidateSignalArtifact::validate($artifact, $candidate, CandidateRelease::hash($root), require $root . '/config/tactical_rotation.php');
CandidateSignalArtifact::write($options['output'], $artifact);
echo json_encode(['as_of' => $date, 'scheduled_session' => $next, 'content_sha256' => $artifact['content_sha256'],
    'peak_memory_bytes' => memory_get_peak_usage(true), 'orders_submitted' => 0], JSON_THROW_ON_ERROR), "\n";
