<?php

require dirname(__DIR__) . '/ppp/profilemeta.php';
require dirname(__DIR__) . '/lib/billing_profile.php';

function billingProfileTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$hotspotNone = array('name' => 'monthly', 'on-login' => ':put (",,100000,30d,120000,noexp,Disable,")');
$hotspotExpired = array('name' => 'voucher', 'on-login' => ':put (",ntfc,10000,1d,12000,,Disable,")');
$hotspotEmpty = array('name' => 'manual', 'on-login' => '');
$pppoeNone = array('name' => 'ppp-monthly', 'comment' => '[MIKHMON-PPPOE price=50000 selling=75000 expmode=none validity=30d]');
$pppoeExpired = array('name' => 'ppp-expired', 'comment' => '[MIKHMON-PPPOE price=50000 selling=75000 expmode=disable validity=30d]');

billingProfileTestAssert(mikhmonBillingProfileExpiredMode('hotspot', $hotspotNone) === 'none', 'hotspot none mode is allowed');
billingProfileTestAssert(mikhmonBillingProfileExpiredMode('hotspot', $hotspotEmpty) === 'none', 'empty hotspot script is treated as no expiry');
billingProfileTestAssert(!mikhmonBillingProfileCanManage('hotspot', $hotspotExpired), 'hotspot expired mode is rejected');
billingProfileTestAssert(mikhmonBillingProfileCanManage('pppoe', $pppoeNone), 'pppoe none mode is allowed');
billingProfileTestAssert(!mikhmonBillingProfileCanManage('pppoe', $pppoeExpired), 'pppoe expired mode is rejected');

echo 'billing-profile-tests: OK' . PHP_EOL;
