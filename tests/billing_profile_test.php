<?php

require dirname(__DIR__) . '/ppp/profilemeta.php';
require dirname(__DIR__) . '/hotspot/profileprice.php';
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

$pppoeMeta = pppProfileMetaDecode(pppProfileMetaEncode(50000, 10000, 75000, 'Paket rumah', 'none', '30d'));
billingProfileTestAssert($pppoeMeta['price'] === '50000' && $pppoeMeta['commission'] === '10000' && $pppoeMeta['selling-price'] === '75000', 'PPPoE profile keeps HPP, commission, and customer price separate');
$pppoeReportScript = pppProfileOnUpScript('none', '30d', 'Paket rumah', 50000, 75000, 10000);
billingProfileTestAssert(strpos($pppoeReportScript, '-|-50000-|-10000') !== false, 'PPPoE sale record stores HPP and partner commission snapshots');
$hotspotWithCommission = mikhmonHotspotProfileStoreCommission($hotspotExpired['on-login'], 2500);
$hotspotPrices = mikhmonHotspotProfilePrices(array('on-login' => $hotspotWithCommission));
billingProfileTestAssert($hotspotPrices['hpp'] === 10000.0 && $hotspotPrices['commission'] === 2500.0 && $hotspotPrices['harga'] === 12000.0, 'hotspot profile keeps HPP, commission, and customer price separate');

echo 'billing-profile-tests: OK' . PHP_EOL;
