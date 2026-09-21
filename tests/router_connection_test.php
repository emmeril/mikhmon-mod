<?php

$statePath = sys_get_temp_dir() . '/mikhmon-router-state-' . bin2hex(random_bytes(6)) . '.json';
putenv('MIKHMON_ROUTER_STATE_PATH=' . $statePath);
putenv('MIKHMON_ROUTER_OFFLINE_COOLDOWN=30');
require dirname(__DIR__) . '/lib/routeros_api.class.php';

function routerConnectionTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$first = new RouterosAPI();
$first->web_mode = true;
$first->port = 9;
$firstStartedAt = microtime(true);
$firstResult = $first->connect('127.0.0.1', 'test', 'test');
$firstDuration = microtime(true) - $firstStartedAt;
routerConnectionTestAssert($firstResult === false, 'closed test port is reported offline');
routerConnectionTestAssert($first->connection_attempts_made === 1, 'web request attempts an offline router only once');
routerConnectionTestAssert($firstDuration < 3, 'web failure returns within the short timeout budget');

$second = new RouterosAPI();
$second->web_mode = true;
$second->port = 9;
$secondStartedAt = microtime(true);
$secondResult = $second->connect('127.0.0.1', 'test', 'test');
$secondDuration = microtime(true) - $secondStartedAt;
routerConnectionTestAssert($secondResult === false && $second->circuit_skipped, 'cooldown skips a repeated web connection');
routerConnectionTestAssert($second->connection_attempts_made === 0 && $secondDuration < 0.2, 'cooldown returns immediately without opening a socket');
routerConnectionTestAssert($second->comm('/system/resource/print') === array(), 'commands on a disconnected API return an empty safe result');

$cli = new RouterosAPI();
$cli->web_mode = false;
$cli->port = 9;
$cli->attempts = 2;
$cli->delay = 0;
$cli->timeout = 1;
$cli->connect('127.0.0.1', 'test', 'test');
routerConnectionTestAssert($cli->connection_attempts_made === 2 && !$cli->circuit_skipped, 'CLI workers keep their configured retry policy');

if (is_file($statePath)) @unlink($statePath);
putenv('MIKHMON_ROUTER_STATE_PATH');
putenv('MIKHMON_ROUTER_OFFLINE_COOLDOWN');

echo 'router-connection-tests: OK' . PHP_EOL;
