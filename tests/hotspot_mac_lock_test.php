<?php

require dirname(__DIR__) . '/lib/hotspot_mac_lock.php';

function macLockTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

class HotspotMacLockFakeApi {
  public $calls = array();

  public function comm($command, $arguments = array()) {
    $this->calls[] = array($command, $arguments);
    if ($command === '/ip/hotspot/active/print') return array(array('.id' => '*A1', 'user' => 'voucher-1'));
    if ($command === '/ip/hotspot/cookie/print') return array(array('.id' => '*C1', 'user' => 'voucher-1'));
    return array();
  }
}

class HotspotMacProfileFakeApi {
  public $calls = array();

  public function comm($command, $arguments = array()) {
    $this->calls[] = array($command, $arguments);
    if ($command === '/ip/hotspot/user/profile/print') {
      return array(array(
        '.id' => '*P1',
        'name' => 'voucher-profile',
        'on-login' => ':put (",rem,1000,1d,1500,,Enable,"); {:if (true) do={' . mikhmonHotspotMacLockLegacyScript() . '}}',
      ));
    }
    return array();
  }
}

$profileApi = new HotspotMacProfileFakeApi();
$profileResult = mikhmonEnsureHotspotProfileMacRebind($profileApi, 'voucher-profile');
macLockTestAssert($profileResult['success'] === true && $profileResult['updated'] === true, 'legacy profile is upgraded during reset');
$updatedOnLogin = $profileApi->calls[1][1]['on-login'];
macLockTestAssert(strpos($updatedOnLogin, mikhmonHotspotMacLockLegacyScript()) === false, 'legacy first-login lock is removed');
macLockTestAssert(strpos($updatedOnLogin, mikhmonHotspotMacLockRebindScript()) !== false, 'rebind-aware lock is installed');

$api = new HotspotMacLockFakeApi();
$result = mikhmonResetHotspotMacLock($api, array('.id' => '*1', 'name' => 'voucher-1', 'mac-address' => 'AA:BB:CC:DD:EE:FF'));

macLockTestAssert($result['success'] === true, 'reset succeeds when RouterOS accepts every command');
macLockTestAssert($result['active_removed'] === 1, 'active session is removed');
macLockTestAssert($result['cookie_removed'] === 1, 'hotspot cookie is removed');
macLockTestAssert($api->calls[0][0] === '/ip/hotspot/user/set', 'user MAC is cleared first');
macLockTestAssert(array_key_exists('mac-address', $api->calls[0][1]) && $api->calls[0][1]['mac-address'] === '00:00:00:00:00:00', 'MAC field is reset to the RouterOS unrestricted value');
macLockTestAssert(mikhmonHotspotMacIsUnlocked('') && mikhmonHotspotMacIsUnlocked('00:00:00:00:00:00'), 'empty and zero MAC values are treated as unlocked');
macLockTestAssert($api->calls[2][0] === '/ip/hotspot/cookie/remove', 'cookie cleanup is requested before disconnecting the device');
macLockTestAssert($api->calls[4][0] === '/ip/hotspot/active/remove', 'active session cleanup is requested');

echo 'hotspot-mac-lock-tests: OK' . PHP_EOL;
