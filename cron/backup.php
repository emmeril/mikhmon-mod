<?php
// Run from system cron to keep all configured router sessions backed up.
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$_SERVER['REQUEST_URI'] = '/cron/backup.php';
error_reporting(E_ALL);
require_once dirname(__DIR__) . '/include/config.php';
require_once dirname(__DIR__) . '/lib/routeros_api.class.php';
require_once dirname(__DIR__) . '/include/database.php';

$total = 0;
foreach ((array) $data as $session => $config) {
  if ($session === 'mikhmon' || !is_array($config) || count($config) < 4) {
    continue;
  }
  $iphost = explode('!', $config[1])[1] ?? '';
  $userhost = explode('@|@', $config[2])[1] ?? '';
  $passwdhost = explode('#|#', $config[3])[1] ?? '';
  if ($iphost === '' || $userhost === '' || $passwdhost === '') {
    echo $session . ": invalid configuration\n";
    continue;
  }
  $api = new RouterosAPI();
  $api->debug = false;
  if (!$api->connect($iphost, $userhost, decrypt($passwdhost))) {
    echo $session . ": connection failed\n";
    continue;
  }
  $snapshot = mikhmonBackupRouterData($api, $session, true);
  echo $session . ': ' . count($snapshot['hotspot_users']) . ' hotspot, ' . count($snapshot['ppp_secrets']) . " PPPoE\n";
  $total++;
  $api->disconnect();
}
echo 'Completed: ' . $total . " session(s)\n";
