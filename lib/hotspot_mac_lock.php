<?php

function mikhmonHotspotMacLockLegacyScript() {
  return '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]';
}

function mikhmonHotspotMacLockRebindScript() {
  return '; :local lockUserId [/ip hotspot user find where name=$user]; :if ([:len $lockUserId] > 0) do={ :local currentLock [/ip hotspot user get $lockUserId mac-address]; :if ([:len $currentLock] = 0 or $currentLock = "00:00:00:00:00:00") do={ /ip hotspot user set mac-address=$"mac-address" $lockUserId; } }';
}

function mikhmonHotspotMacIsUnlocked($macAddress) {
  $macAddress = strtoupper(trim((string) $macAddress));
  return $macAddress === '' || $macAddress === '00:00:00:00:00:00';
}

function mikhmonHotspotProfileUsesMacLock($profile) {
  $parts = explode(',', (string) ($profile['on-login'] ?? ''));
  return isset($parts[6]) && trim((string) $parts[6]) === 'Enable';
}

function mikhmonHotspotMacLockApiError($response) {
  if (!is_array($response)) return 'Respons router tidak valid.';
  foreach (array('!trap', '!fatal') as $type) {
    if (isset($response[$type][0]['message'])) return (string) $response[$type][0]['message'];
  }
  return '';
}

/**
 * Move the legacy first-login-only lock outside the voucher activation block.
 * This lets a used voucher bind itself again after an operator resets its MAC.
 */
function mikhmonEnsureHotspotProfileMacRebind($API, $profileName) {
  $profileName = trim((string) $profileName);
  if (!is_object($API) || !method_exists($API, 'comm') || $profileName === '') {
    return array('success' => false, 'message' => 'Profil pengguna tidak valid.');
  }

  $profileRows = $API->comm('/ip/hotspot/user/profile/print', array(
    '?name' => $profileName,
    '.proplist' => '.id,name,on-login',
  ));
  $profileError = mikhmonHotspotMacLockApiError($profileRows);
  if ($profileError !== '') return array('success' => false, 'message' => 'Gagal membaca profil: ' . $profileError);
  if (empty($profileRows[0])) return array('success' => false, 'message' => 'Profil pengguna tidak ditemukan.');

  $profile = $profileRows[0];
  if (!mikhmonHotspotProfileUsesMacLock($profile)) {
    return array('success' => false, 'message' => 'Profil ini tidak mengaktifkan Kunci Pengguna.');
  }

  $onLogin = (string) ($profile['on-login'] ?? '');
  $rebindScript = mikhmonHotspotMacLockRebindScript();
  if (strpos($onLogin, $rebindScript) !== false) {
    return array('success' => true, 'updated' => false);
  }

  $onLogin = str_replace(mikhmonHotspotMacLockLegacyScript(), '', $onLogin) . $rebindScript;
  $setResponse = $API->comm('/ip/hotspot/user/profile/set', array(
    '.id' => (string) ($profile['.id'] ?? $profileName),
    'on-login' => $onLogin,
  ));
  $setError = mikhmonHotspotMacLockApiError($setResponse);
  if ($setError !== '') return array('success' => false, 'message' => 'Gagal memperbarui penguncian profil: ' . $setError);

  return array('success' => true, 'updated' => true);
}

/**
 * Clear a user's MAC lock and stale authentication state so another device can
 * claim the voucher on its next successful login.
 */
function mikhmonResetHotspotMacLock($API, $user) {
  $userId = isset($user['.id']) ? trim((string) $user['.id']) : '';
  $username = isset($user['name']) ? trim((string) $user['name']) : '';
  if (!is_object($API) || !method_exists($API, 'comm') || $userId === '' || $username === '') {
    return array('success' => false, 'message' => 'Data pengguna tidak valid.');
  }

  $setResponse = $API->comm('/ip/hotspot/user/set', array(
    '.id' => $userId,
    'mac-address' => '00:00:00:00:00:00',
  ));
  $setError = mikhmonHotspotMacLockApiError($setResponse);
  if ($setError !== '') {
    return array('success' => false, 'message' => 'Gagal mengosongkan MAC: ' . $setError);
  }

  $activeRemoved = 0;
  $cookieRemoved = 0;
  $cleanupErrors = array();

  // Remove cookies before disconnecting the session. This prevents the old
  // device from immediately authenticating again and claiming the empty lock.
  $cookieRows = $API->comm('/ip/hotspot/cookie/print', array('?user' => $username));
  $cookieError = mikhmonHotspotMacLockApiError($cookieRows);
  if ($cookieError !== '') {
    $cleanupErrors[] = 'cookie: ' . $cookieError;
  } else {
    foreach ((array) $cookieRows as $cookieRow) {
      if (empty($cookieRow['.id'])) continue;
      $removeResponse = $API->comm('/ip/hotspot/cookie/remove', array('.id' => $cookieRow['.id']));
      $removeError = mikhmonHotspotMacLockApiError($removeResponse);
      if ($removeError === '') $cookieRemoved++;
      else $cleanupErrors[] = 'cookie: ' . $removeError;
    }
  }

  $activeRows = $API->comm('/ip/hotspot/active/print', array('?user' => $username));
  $activeError = mikhmonHotspotMacLockApiError($activeRows);
  if ($activeError !== '') {
    $cleanupErrors[] = 'sesi aktif: ' . $activeError;
  } else {
    foreach ((array) $activeRows as $activeRow) {
      if (empty($activeRow['.id'])) continue;
      $removeResponse = $API->comm('/ip/hotspot/active/remove', array('.id' => $activeRow['.id']));
      $removeError = mikhmonHotspotMacLockApiError($removeResponse);
      if ($removeError === '') $activeRemoved++;
      else $cleanupErrors[] = 'sesi aktif: ' . $removeError;
    }
  }

  return array(
    'success' => true,
    'message' => 'Kunci MAC berhasil direset.',
    'username' => $username,
    'active_removed' => $activeRemoved,
    'cookie_removed' => $cookieRemoved,
    'cleanup_errors' => array_values(array_unique($cleanupErrors)),
  );
}
