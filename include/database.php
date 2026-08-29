<?php
// Local JSON database used for router user snapshots. The file is kept outside
// the web root in deployments that support it and is written atomically.

function mikhmonBackupPath() {
  $directory = dirname(__DIR__) . '/data';
  if (!is_dir($directory)) {
    @mkdir($directory, 0700, true);
  }
  @chmod($directory, 0700);
  return $directory . '/mikhmon-backup.json';
}

function mikhmonReadDatabase() {
  $path = mikhmonBackupPath();
  if (!is_file($path)) {
    return array('version' => 1, 'routers' => array());
  }
  $data = json_decode((string) @file_get_contents($path), true);
  if (!is_array($data)) {
    return array('version' => 1, 'routers' => array());
  }
  if (!isset($data['routers']) || !is_array($data['routers'])) {
    $data['routers'] = array();
  }
  return $data;
}

function mikhmonWriteDatabase($data) {
  $path = mikhmonBackupPath();
  $tmp = $path . '.tmp.' . getmypid();
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
    return false;
  }
  @chmod($tmp, 0600);
  return @rename($tmp, $path);
}

function mikhmonSnapshotRows($rows) {
  $result = array();
  foreach ((array) $rows as $row) {
    if (is_array($row) && isset($row['name']) && (!isset($row['dynamic']) || $row['dynamic'] !== 'true')) {
      unset($row['.id'], $row['dynamic']);
      $result[] = $row;
    }
  }
  return $result;
}

function mikhmonBackupRouterData($API, $session, $force = false) {
  $database = mikhmonReadDatabase();
  $current = isset($database['routers'][$session]) ? $database['routers'][$session] : array();
  if (!$force && !empty($current['updated_at']) && (time() - (int) $current['updated_at']) < 60) {
    return $current;
  }

  $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print');
  $hotspotUsers = $API->comm('/ip/hotspot/user/print');
  $pppProfiles = $API->comm('/ppp/profile/print');
  $pppSecrets = $API->comm('/ppp/secret/print');
  foreach (array($hotspotProfiles, $hotspotUsers, $pppProfiles, $pppSecrets) as $rows) {
    if (!is_array($rows) || isset($rows['!trap'])) {
      return $current;
    }
  }
  $snapshot = array(
    'session' => (string) $session,
    'updated_at' => time(),
    'hotspot_profiles' => mikhmonSnapshotRows($hotspotProfiles),
    'hotspot_users' => mikhmonSnapshotRows($hotspotUsers),
    'ppp_profiles' => mikhmonSnapshotRows($pppProfiles),
    'ppp_secrets' => mikhmonSnapshotRows($pppSecrets),
  );
  $database['version'] = 1;
  $database['routers'][$session] = $snapshot;
  mikhmonWriteDatabase($database);
  return $snapshot;
}

function mikhmonRestoreFields($row, $allowed) {
  $fields = array();
  foreach ($allowed as $field) {
    if (isset($row[$field]) && $row[$field] !== '') {
      $fields[$field] = $row[$field];
    }
  }
  return $fields;
}

function mikhmonRestoreRows($API, $command, $rows, $allowed) {
  $existing = array();
  foreach ((array) $API->comm($command . '/print') as $row) {
    if (is_array($row) && isset($row['name'])) {
      $existing[(string) $row['name']] = true;
    }
  }
  $restored = 0;
  foreach ((array) $rows as $row) {
    if (!is_array($row) || empty($row['name']) || isset($existing[(string) $row['name']])) {
      continue;
    }
    $fields = mikhmonRestoreFields($row, $allowed);
    $response = !empty($fields) ? $API->comm($command . '/add', $fields) : false;
    if ($response !== false && !(is_array($response) && isset($response['!trap']))) {
      $restored++;
    }
  }
  return $restored;
}

function mikhmonRestoreRouterData($API, $session, $type = 'all') {
  $database = mikhmonReadDatabase();
  $snapshot = isset($database['routers'][$session]) ? $database['routers'][$session] : array();
  if (empty($snapshot)) {
    return array('error' => 'No backup found for this router session.');
  }
  $result = array('profiles' => 0, 'users' => 0);
  if ($type === 'all' || $type === 'hotspot') {
    $result['profiles'] += mikhmonRestoreRows($API, '/ip/hotspot/user/profile', isset($snapshot['hotspot_profiles']) ? $snapshot['hotspot_profiles'] : array(), array('name', 'idle-timeout', 'keepalive-timeout', 'status-autorefresh', 'shared-users', 'rate-limit', 'session-timeout', 'address-pool', 'transparent-proxy', 'use-radius', 'on-login', 'on-logout', 'comment'));
    $result['users'] += mikhmonRestoreRows($API, '/ip/hotspot/user', isset($snapshot['hotspot_users']) ? $snapshot['hotspot_users'] : array(), array('server', 'name', 'password', 'profile', 'mac-address', 'limit-uptime', 'limit-bytes-total', 'comment', 'disabled', 'email', 'phone', 'rate-limit', 'shared-users'));
  }
  if ($type === 'all' || $type === 'pppoe') {
    $result['profiles'] += mikhmonRestoreRows($API, '/ppp/profile', isset($snapshot['ppp_profiles']) ? $snapshot['ppp_profiles'] : array(), array('name', 'local-address', 'remote-address', 'rate-limit', 'session-timeout', 'idle-timeout', 'only-one', 'change-tcp-mss', 'use-encryption', 'use-compression', 'on-up', 'on-down', 'comment'));
    $result['users'] += mikhmonRestoreRows($API, '/ppp/secret', isset($snapshot['ppp_secrets']) ? $snapshot['ppp_secrets'] : array(), array('name', 'password', 'service', 'profile', 'local-address', 'remote-address', 'caller-id', 'comment', 'disabled', 'limit-uptime', 'rate-limit'));
  }
  return $result;
}
