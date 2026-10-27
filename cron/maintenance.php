<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

define('MIKHMON_ALLOW_PRODUCTION_CLI_WRITE', true);
$_SERVER['REQUEST_URI'] = '/cron/maintenance.php';
error_reporting(E_ALL);
date_default_timezone_set(getenv('MIKHMON_TIMEZONE') ?: 'Asia/Jakarta');
require_once dirname(__DIR__) . '/include/config.php';
require_once dirname(__DIR__) . '/include/database.php';
require_once dirname(__DIR__) . '/lib/routeros_api.class.php';
require_once dirname(__DIR__) . '/include/systemlog.php';

$lockPath = dirname(__DIR__) . '/data/maintenance-cron.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
  echo "Router maintenance is already running.\n";
  exit;
}
@chmod($lockPath, 0600);

$completed = 0;
foreach ((array) $data as $session => $routerConfig) {
  if ($session === 'mikhmon' || !is_array($routerConfig) || count($routerConfig) < 4) continue;
  $iphost = explode('!', (string) ($routerConfig[1] ?? ''), 2)[1] ?? '';
  $userhost = explode('@|@', (string) ($routerConfig[2] ?? ''), 2)[1] ?? '';
  $password = explode('#|#', (string) ($routerConfig[3] ?? ''), 2)[1] ?? '';
  if ($iphost === '' || $userhost === '' || $password === '') {
    echo $session . ": invalid configuration\n";
    continue;
  }
  $api = new RouterosAPI();
  $api->debug = false;
  if (!$api->connect($iphost, $userhost, decrypt($password))) {
    echo $session . ": connection failed\n";
    mikhmonSystemLog('error', 'Router Maintenance', 'Koneksi router gagal saat sinkronisasi.', array('session' => $session));
    continue;
  }
  $result = mikhmonSynchronizeRouterData($api, $session);
  echo $session . ': ' . ($result['status'] ?? 'unknown') . "\n";
  $api->disconnect();
  $completed++;
}

flock($lock, LOCK_UN);
fclose($lock);
echo 'Completed: ' . $completed . " session(s)\n";
