<?php

function dashboardCardOrderAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$dashboardSources = array(
  'admin dashboard' => file_get_contents(dirname(__DIR__) . '/dashboard/home.php'),
  'partner dashboard' => file_get_contents(dirname(__DIR__) . '/dashboard/rolehome.php'),
);

foreach ($dashboardSources as $dashboardName => $source) {
  $pppoePosition = strpos($source, 'id="r_ppp"');
  $customerPosition = strpos($source, 'fa fa-address-card');
  $trafficPosition = strpos($source, 'fa fa-area-chart');

  dashboardCardOrderAssert($pppoePosition !== false, $dashboardName . ' contains the PPPoE card');
  dashboardCardOrderAssert($customerPosition > $pppoePosition, $dashboardName . ' places the customer card after PPPoE');
  dashboardCardOrderAssert($trafficPosition > $customerPosition, $dashboardName . ' places the customer card before traffic');
}

echo 'dashboard-card-order-tests: OK' . PHP_EOL;
