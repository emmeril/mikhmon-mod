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
require_once dirname(__DIR__) . '/include/systemlog.php';

// Apply retention even when none of the routers can be reached.
mikhmonReadRouterDatabase();

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
    mikhmonSystemLog('error', 'Backup Router', 'Konfigurasi router tidak valid.', array('session' => $session));
    continue;
  }
  $api = new RouterosAPI();
  $api->debug = false;
  if (!$api->connect($iphost, $userhost, decrypt($passwdhost))) {
    echo $session . ": connection failed\n";
    mikhmonSystemLog('error', 'Backup Router', 'Koneksi router gagal saat proses backup.', array('session' => $session));
    continue;
  }
  $snapshot = mikhmonBackupRouterData($api, $session, true);
  echo $session . ': ' . count($snapshot['hotspot_users']) . ' hotspot, ' . count($snapshot['ppp_secrets']) . " PPPoE\n";
  mikhmonSystemLog('success', 'Backup Router', 'Backup selesai: ' . count($snapshot['hotspot_users']) . ' Hotspot dan ' . count($snapshot['ppp_secrets']) . ' PPPoE.', array('session' => $session));
  $total++;
  $api->disconnect();
}
echo 'Completed: ' . $total . " session(s)\n";
