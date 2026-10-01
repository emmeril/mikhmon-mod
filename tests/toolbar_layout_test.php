<?php

function toolbarAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$root = dirname(__DIR__);
$headSource = file_get_contents($root . '/include/headhtml.php');
$toolbarCss = file_get_contents($root . '/css/mikhmon-toolbar.css');

toolbarAssert(strpos($headSource, 'css/mikhmon-toolbar.css') !== false, 'shared toolbar stylesheet is loaded');
toolbarAssert(strpos($toolbarCss, '.data-toolbar__filters') !== false, 'shared toolbar defines filter groups');
toolbarAssert(strpos($toolbarCss, '.data-toolbar__actions') !== false, 'shared toolbar defines action groups');
toolbarAssert(strpos($toolbarCss, 'min-height: 44px') !== false, 'mobile toolbar controls keep a 44px tap target');
toolbarAssert(strpos($toolbarCss, 'flex-direction: column') !== false, 'mobile toolbar controls stack vertically');

$toolbarPages = array(
  'PPPoE secrets' => 'ppp/pppsecrets.php',
  'customer identities' => 'customer/identities.php',
  'customer services' => 'customer/customers.php',
  'hotspot active' => 'hotspot/hotspotactive.php',
  'MAC locks' => 'hotspot/maclocks.php',
  'users and partners' => 'settings/users.php',
  'commission' => 'customer/commission.php',
  'print center' => 'hotspot/printcenter.php',
  'selling report' => 'report/selling.php',
  'user log' => 'report/userlog.php',
);

foreach ($toolbarPages as $pageName => $relativePath) {
  $source = file_get_contents($root . '/' . $relativePath);
  toolbarAssert(strpos($source, 'data-toolbar') !== false, $pageName . ' uses the shared toolbar layout');
}

$pppSource = file_get_contents($root . '/ppp/pppsecrets.php');
toolbarAssert(strpos($pppSource, 'data-toolbar__filters') !== false, 'PPPoE filters are grouped');
toolbarAssert(strpos($pppSource, 'data-toolbar__actions') !== false, 'PPPoE reset action is separated from filters');

echo 'toolbar-layout-tests: OK' . PHP_EOL;
