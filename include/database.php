<?php
// Versioned local snapshots provide automatic backup and guarded recovery.

function mikhmonBackupPath() {
  $directory = dirname(__DIR__) . '/data';
  if (!is_dir($directory)) {
    @mkdir($directory, 0700, true);
  }
  @chmod($directory, 0700);
  return $directory . '/mikhmon-backup.json';
}

function mikhmonDefaultSyncSettings() {
  return array(
    'auto_sync' => true,
    'interval' => 60,
    'recovery_percent' => 80,
    'minimum_missing' => 5,
  );
}

function mikhmonReadDatabase() {
  $path = mikhmonBackupPath();
  if (!is_file($path)) {
    return array('version' => 2, 'routers' => array());
  }
  $data = json_decode((string) @file_get_contents($path), true);
  if (!is_array($data)) {
    return array('version' => 2, 'routers' => array());
  }
  if (!isset($data['routers']) || !is_array($data['routers'])) {
    $data['routers'] = array();
  }
  return $data;
}

function mikhmonWriteDatabase($data) {
  $path = mikhmonBackupPath();
  $tmp = $path . '.tmp.' . getmypid();
  $data['version'] = 2;
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

function mikhmonSnapshotCore($snapshot) {
  return array(
    'session' => isset($snapshot['session']) ? $snapshot['session'] : '',
    'updated_at' => isset($snapshot['updated_at']) ? (int) $snapshot['updated_at'] : 0,
    'hotspot_profiles' => isset($snapshot['hotspot_profiles']) ? $snapshot['hotspot_profiles'] : array(),
    'hotspot_users' => isset($snapshot['hotspot_users']) ? $snapshot['hotspot_users'] : array(),
    'ppp_profiles' => isset($snapshot['ppp_profiles']) ? $snapshot['ppp_profiles'] : array(),
    'ppp_secrets' => isset($snapshot['ppp_secrets']) ? $snapshot['ppp_secrets'] : array(),
  );
}

function mikhmonNormalizeRouterRecord($record, $session) {
  $settings = mikhmonDefaultSyncSettings();
  if (!is_array($record)) {
    $record = array();
  }
  // Upgrade the first backup format without discarding its snapshot.
  if (!isset($record['latest']) && isset($record['updated_at'])) {
    $record = array(
      'latest' => mikhmonSnapshotCore($record),
      'history' => array(),
      'settings' => $settings,
      'last_checked_at' => isset($record['updated_at']) ? (int) $record['updated_at'] : 0,
      'last_recovery' => array(),
    );
  }
  $record['latest'] = isset($record['latest']) && is_array($record['latest']) ? mikhmonSnapshotCore($record['latest']) : mikhmonSnapshotCore(array());
  $record['history'] = isset($record['history']) && is_array($record['history']) ? $record['history'] : array();
  $record['settings'] = array_merge($settings, isset($record['settings']) && is_array($record['settings']) ? $record['settings'] : array());
  $record['last_checked_at'] = isset($record['last_checked_at']) ? (int) $record['last_checked_at'] : 0;
  $record['last_recovery'] = isset($record['last_recovery']) && is_array($record['last_recovery']) ? $record['last_recovery'] : array();
  if (!empty($record['latest'])) {
    $record['latest']['session'] = (string) $session;
  }
  return $record;
}

function mikhmonGetRouterRecord($database, $session) {
  $record = isset($database['routers'][$session]) ? $database['routers'][$session] : array();
  return mikhmonNormalizeRouterRecord($record, $session);
}

function mikhmonCaptureRouterData($API, $session) {
  $commands = array(
    'hotspot_profiles' => '/ip/hotspot/user/profile/print',
    'hotspot_users' => '/ip/hotspot/user/print',
    'ppp_profiles' => '/ppp/profile/print',
    'ppp_secrets' => '/ppp/secret/print',
  );
  $snapshot = array('session' => (string) $session, 'updated_at' => time());
  foreach ($commands as $key => $command) {
    $rows = $API->comm($command);
    if (!is_array($rows) || isset($rows['!trap']) || isset($rows['!fatal'])) {
      return false;
    }
    $snapshot[$key] = mikhmonSnapshotRows($rows);
  }
  return $snapshot;
}

function mikhmonSnapshotFingerprint($snapshot) {
  $copy = mikhmonSnapshotCore($snapshot);
  unset($copy['updated_at']);
  return sha1(json_encode($copy));
}

function mikhmonStoreSnapshot(&$record, $snapshot) {
  $snapshot = mikhmonSnapshotCore($snapshot);
  if (!empty($record['latest']) && mikhmonSnapshotFingerprint($record['latest']) !== mikhmonSnapshotFingerprint($snapshot)) {
    array_unshift($record['history'], mikhmonSnapshotCore($record['latest']));
    $record['history'] = array_slice($record['history'], 0, 10);
  }
  $record['latest'] = $snapshot;
  $record['last_checked_at'] = time();
}

function mikhmonBackupRouterData($API, $session, $force = false) {
  $database = mikhmonReadDatabase();
  $record = mikhmonGetRouterRecord($database, $session);
  $interval = max(30, (int) $record['settings']['interval']);
  if (!$force && $record['last_checked_at'] > 0 && (time() - $record['last_checked_at']) < $interval) {
    return $record['latest'];
  }
  $snapshot = mikhmonCaptureRouterData($API, $session);
  if ($snapshot === false) {
    return $record['latest'];
  }
  mikhmonStoreSnapshot($record, $snapshot);
  $database['routers'][$session] = $record;
  mikhmonWriteDatabase($database);
  return $record['latest'];
}

function mikhmonRowsByName($rows) {
  $named = array();
  foreach ((array) $rows as $row) {
    if (is_array($row) && isset($row['name'])) {
      $named[(string) $row['name']] = $row;
    }
  }
  return $named;
}

function mikhmonShouldAutoRecover($backupRows, $currentRows, $settings) {
  $backupCount = count((array) $backupRows);
  $currentNames = mikhmonRowsByName($currentRows);
  if ($backupCount === 0) {
    return false;
  }
  $missing = 0;
  foreach (mikhmonRowsByName($backupRows) as $name => $row) {
    if (!isset($currentNames[$name])) {
      $missing++;
    }
  }
  if ($missing === 0) {
    return false;
  }
  if (count($currentNames) === 0) {
    return $backupCount >= 3;
  }
  $percent = ($missing / $backupCount) * 100;
  return $missing >= (int) $settings['minimum_missing'] && $percent >= (int) $settings['recovery_percent'];
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
  $existing = mikhmonRowsByName($API->comm($command . '/print'));
  $restored = 0;
  foreach ((array) $rows as $row) {
    if (!is_array($row) || empty($row['name']) || isset($existing[(string) $row['name']])) {
      continue;
    }
    $fields = mikhmonRestoreFields($row, $allowed);
    $response = !empty($fields) ? $API->comm($command . '/add', $fields) : false;
    if ($response !== false && !(is_array($response) && (isset($response['!trap']) || isset($response['!fatal'])))) {
      $restored++;
    }
  }
  return $restored;
}

function mikhmonRestoreSnapshot($API, $snapshot, $type = 'all') {
  $result = array('profiles' => 0, 'users' => 0);
  if ($type === 'all' || $type === 'hotspot') {
    $result['profiles'] += mikhmonRestoreRows($API, '/ip/hotspot/user/profile', $snapshot['hotspot_profiles'], array('name', 'idle-timeout', 'keepalive-timeout', 'status-autorefresh', 'shared-users', 'rate-limit', 'session-timeout', 'address-pool', 'transparent-proxy', 'use-radius', 'on-login', 'on-logout', 'comment'));
    $result['users'] += mikhmonRestoreRows($API, '/ip/hotspot/user', $snapshot['hotspot_users'], array('server', 'name', 'password', 'profile', 'mac-address', 'limit-uptime', 'limit-bytes-total', 'comment', 'disabled', 'email', 'phone', 'rate-limit', 'shared-users'));
  }
  if ($type === 'all' || $type === 'pppoe') {
    $result['profiles'] += mikhmonRestoreRows($API, '/ppp/profile', $snapshot['ppp_profiles'], array('name', 'local-address', 'remote-address', 'rate-limit', 'session-timeout', 'idle-timeout', 'only-one', 'change-tcp-mss', 'use-encryption', 'use-compression', 'on-up', 'on-down', 'comment'));
    $result['users'] += mikhmonRestoreRows($API, '/ppp/secret', $snapshot['ppp_secrets'], array('name', 'password', 'service', 'profile', 'local-address', 'remote-address', 'caller-id', 'comment', 'disabled', 'limit-uptime', 'rate-limit'));
  }
  return $result;
}

function mikhmonSynchronizeRouterData($API, $session, $force = false) {
  $database = mikhmonReadDatabase();
  $record = mikhmonGetRouterRecord($database, $session);
  $interval = max(30, (int) $record['settings']['interval']);
  if (!$force && $record['last_checked_at'] > 0 && (time() - $record['last_checked_at']) < $interval) {
    return array('status' => 'throttled', 'record' => $record);
  }
  $current = mikhmonCaptureRouterData($API, $session);
  if ($current === false) {
    return array('status' => 'router-error', 'record' => $record);
  }
  if (empty($record['latest'])) {
    mikhmonStoreSnapshot($record, $current);
    $database['routers'][$session] = $record;
    mikhmonWriteDatabase($database);
    return array('status' => 'initialized', 'record' => $record);
  }

  $recoverHotspot = !empty($record['settings']['auto_sync']) && mikhmonShouldAutoRecover($record['latest']['hotspot_users'], $current['hotspot_users'], $record['settings']);
  $recoverPppoe = !empty($record['settings']['auto_sync']) && mikhmonShouldAutoRecover($record['latest']['ppp_secrets'], $current['ppp_secrets'], $record['settings']);
  if ($recoverHotspot || $recoverPppoe) {
    $type = $recoverHotspot && $recoverPppoe ? 'all' : ($recoverHotspot ? 'hotspot' : 'pppoe');
    $restored = mikhmonRestoreSnapshot($API, $record['latest'], $type);
    $record['last_checked_at'] = time();
    $record['last_recovery'] = array(
      'time' => time(),
      'type' => $type,
      'users' => $restored['users'],
      'profiles' => $restored['profiles'],
    );
    $database['routers'][$session] = $record;
    mikhmonWriteDatabase($database);
    return array('status' => 'recovered', 'record' => $record, 'result' => $restored);
  }

  mikhmonStoreSnapshot($record, $current);
  $database['routers'][$session] = $record;
  mikhmonWriteDatabase($database);
  return array('status' => 'backed-up', 'record' => $record);
}

function mikhmonRestoreRouterData($API, $session, $type = 'all', $version = 'latest') {
  $database = mikhmonReadDatabase();
  $record = mikhmonGetRouterRecord($database, $session);
  $snapshot = $record['latest'];
  if (strpos($version, 'history-') === 0) {
    $index = (int) substr($version, 8);
    if (isset($record['history'][$index])) {
      $snapshot = mikhmonSnapshotCore($record['history'][$index]);
    }
  }
  if (empty($snapshot)) {
    return array('error' => 'No backup found for this router session.');
  }
  return mikhmonRestoreSnapshot($API, $snapshot, $type);
}

function mikhmonSetAutoSync($session, $enabled) {
  $database = mikhmonReadDatabase();
  $record = mikhmonGetRouterRecord($database, $session);
  $record['settings']['auto_sync'] = (bool) $enabled;
  $database['routers'][$session] = $record;
  return mikhmonWriteDatabase($database);
}
