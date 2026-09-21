<?php

$testDatabase = sys_get_temp_dir() . '/mikhmon-performance-' . bin2hex(random_bytes(6)) . '.json';
putenv('MIKHMON_DATABASE_PATH=' . $testDatabase);
require dirname(__DIR__) . '/include/database.php';

function performanceTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

class PerformanceMaintenanceFakeApi {
  public $commands = array();

  public function comm($command, $arguments = array()) {
    $this->commands[] = array($command, $arguments);
    if ($command === '/ip/hotspot/user/profile/print') return array(array('name' => 'default'));
    if ($command === '/ip/hotspot/user/print') return array(array('name' => 'voucher-a', 'profile' => 'default'));
    if ($command === '/ppp/profile/print' || $command === '/ppp/secret/print' || $command === '/system/script/print') return array();
    return array();
  }
}

$api = new PerformanceMaintenanceFakeApi();
$first = mikhmonSynchronizeRouterData($api, 'router-a');
$firstCommandCount = count($api->commands);
performanceTestAssert($first['status'] === 'backed-up' && $firstCommandCount >= 5, 'first connected request performs maintenance and the due snapshot');

$second = mikhmonSynchronizeRouterData($api, 'router-a');
performanceTestAssert($second['status'] === 'throttled', 'second request in the maintenance window is throttled');
performanceTestAssert(count($api->commands) === $firstCommandCount, 'throttled request performs no RouterOS queries');

$forced = mikhmonSynchronizeRouterData($api, 'router-a', true);
performanceTestAssert($forced['status'] === 'backed-up' && count($api->commands) > $firstCommandCount, 'manual synchronization bypasses the maintenance throttle');

foreach (array(
  $testDatabase,
  $testDatabase . '.routers',
  $testDatabase . '.routers.index',
  $testDatabase . '.legacy',
  $testDatabase . '.maintenance.json',
) as $testFile) {
  if (is_file($testFile)) @unlink($testFile);
}

echo 'performance-regression-tests: OK' . PHP_EOL;
