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

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$dashboardAjax = file_get_contents($root . '/dashboard/aload.php');
$maintenance = file_get_contents($root . '/cron/maintenance.php');
$billing = file_get_contents($root . '/lib/billing_automation.php');

performanceTestAssert(strpos($index, 'mikhmonSynchronizeRouterData(') === false, 'normal navigation does not run router maintenance');
performanceTestAssert(strpos($dashboardAjax, 'mikhmonSynchronizeRouterData(') === false, 'dashboard polling does not run router maintenance');
performanceTestAssert(strpos($maintenance, 'mikhmonSynchronizeRouterData($api, $session)') !== false, 'the maintenance cron owns background router synchronization');
performanceTestAssert(strpos($index, '@filemtime(__DIR__') !== false, 'static JavaScript uses stable file-version cache keys');
performanceTestAssert(strpos($index, 'dashboardRefreshInFlight') !== false, 'dashboard section refreshes cannot overlap');
performanceTestAssert(strpos($index, 'loadDashboardSection("#r_1"') < strpos($index, 'loadDashboardSection("#r_2"') && strpos($index, 'loadDashboardSection("#r_2"') < strpos($index, 'loadDashboardSection("#r_3"'), 'dashboard sections refresh sequentially');
performanceTestAssert(strpos($billing, 'mikhmonBillingAutomationRemoveSchedulers($api, $customers)') !== false, 'billing removes legacy schedulers in bulk');
performanceTestAssert(strpos($billing, 'mikhmonBillingAutomationLoadServiceState($api)') !== false, 'billing loads service status in bulk');

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
