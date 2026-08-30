<?php

include_once('./report/reportrecord.php');

$clock = array('date' => date('M/d/Y'), 'time' => date('H:i:s'));
$resource = array();
$routerboard = array();
$mitraCustomers = mikhmonVisibleCustomers($session);
$hotspotCustomerNames = mikhmonMitraUsernamesByService($session, 'hotspot');
$pppoeCustomerNames = mikhmonMitraUsernamesByService($session, 'pppoe');
$mitraHotspotUsers = array();
$mitraVoucherUsers = array();
$mitraHotspotActive = array();
$mitraPppSecrets = array();
$mitraPppActive = array();
$monthKey = strtolower(date('M')) . date('Y');
$todayKey = strtolower(date('M/d/Y'));
$monthReports = array();
$hotspotProfiles = array();
$pppProfiles = array();

if (!empty($routerConnected)) {
  $clockRows = $API->comm('/system/clock/print');
  $resourceRows = $API->comm('/system/resource/print');
  $routerboardRows = $API->comm('/system/routerboard/print');
  if (isset($clockRows[0])) $clock = $clockRows[0];
  if (isset($resourceRows[0])) $resource = $resourceRows[0];
  if (isset($routerboardRows[0])) $routerboard = $routerboardRows[0];
  if (!empty($clock['time-zone-name'])) {
    $_SESSION['timezone'] = $clock['time-zone-name'];
    date_default_timezone_set($clock['time-zone-name']);
    $monthKey = strtolower(date('M')) . date('Y');
    $todayKey = strtolower(date('M/d/Y'));
  }

  foreach ((array) $API->comm('/ip/hotspot/user/print') as $hotspotUser) {
    $isVoucherOwner = mikhmonRowBelongsToCurrentMitra($hotspotUser);
    $isAssignedCustomer = isset($hotspotUser['name']) && isset($hotspotCustomerNames[(string) $hotspotUser['name']]);
    if ($isVoucherOwner) $mitraVoucherUsers[] = $hotspotUser;
    if ($isVoucherOwner || $isAssignedCustomer) $mitraHotspotUsers[] = $hotspotUser;
  }
  $mitraHotspotNames = array();
  foreach ($mitraHotspotUsers as $hotspotUser) if (isset($hotspotUser['name'])) $mitraHotspotNames[(string) $hotspotUser['name']] = true;
  foreach ((array) $API->comm('/ip/hotspot/active/print') as $activeUser) {
    if (isset($activeUser['user']) && isset($mitraHotspotNames[(string) $activeUser['user']])) $mitraHotspotActive[] = $activeUser;
  }

  foreach ((array) $API->comm('/ppp/secret/print') as $secret) {
    if (isset($secret['name']) && isset($pppoeCustomerNames[(string) $secret['name']])) $mitraPppSecrets[] = $secret;
  }
  foreach ((array) $API->comm('/ppp/active/print') as $activePpp) {
    if (isset($activePpp['name']) && isset($pppoeCustomerNames[(string) $activePpp['name']])) $mitraPppActive[] = $activePpp;
  }

  $monthReports = mikhmonFilterReportRecords($API->comm('/system/script/print', array('?owner' => $monthKey)));
  $mitraAllUsernames = mikhmonMitraUsernames($session);
  $monthReports = array_values(array_filter($monthReports, function ($row) use ($mitraAllUsernames) {
    $parts = mikhmonReportParts($row);
    return mikhmonRowBelongsToCurrentMitra($row) || (isset($parts[2]) && isset($mitraAllUsernames[trim($parts[2])]));
  }));
  $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print');
  $pppProfiles = $API->comm('/ppp/profile/print');
}

$profileCosts = mikhmonReportProfileCosts($hotspotProfiles, $pppProfiles);
$profileSellingPrices = mikhmonReportProfileSellingPrices($hotspotProfiles, $pppProfiles);
$reportTotals = array(
  'all' => array('count' => 0, 'income' => 0, 'profit' => 0),
  'hotspot' => array('count' => 0, 'income' => 0, 'profit' => 0),
  'pppoe' => array('count' => 0, 'income' => 0, 'profit' => 0),
  'today' => array('count' => 0, 'income' => 0, 'profit' => 0),
);
foreach ($monthReports as $reportRow) {
  $parts = mikhmonReportParts($reportRow);
  $service = (isset($parts[9]) && strtolower(trim($parts[9])) === 'pppoe') || (isset($parts[5]) && strtolower(trim($parts[5])) === 'pppoe') ? 'pppoe' : 'hotspot';
  $income = mikhmonReportSellingPrice($reportRow, $profileSellingPrices);
  $profit = mikhmonReportNetProfit($reportRow, $profileCosts, $profileSellingPrices);
  foreach (array('all', $service) as $key) {
    $reportTotals[$key]['count']++;
    $reportTotals[$key]['income'] += $income;
    $reportTotals[$key]['profit'] += $profit;
  }
  if (isset($parts[0]) && strtolower(trim($parts[0])) === $todayKey) {
    $reportTotals['today']['count']++;
    $reportTotals['today']['income'] += $income;
    $reportTotals['today']['profit'] += $profit;
  }
}

$paidInvoices = 0;
$unpaidInvoices = 0;
$customerIds = array();
foreach ($mitraCustomers as $customer) if (isset($customer['id'])) $customerIds[(string) $customer['id']] = true;
foreach (mikhmonGetInvoices($session) as $invoice) {
  if (!isset($invoice['customer_id']) || !isset($customerIds[(string) $invoice['customer_id']])) continue;
  if (isset($invoice['status']) && $invoice['status'] === 'paid') $paidInvoices++;
  elseif (isset($invoice['status']) && $invoice['status'] === 'unpaid') $unpaidInvoices++;
}

function mitraDashboardMoney($value, $currency) {
  return $currency . ' ' . number_format((float) $value, 0, ',', '.');
}

$hotspotCustomers = count($hotspotCustomerNames);
$pppoeCustomers = count($pppoeCustomerNames);
?>
<div id="reloadHome">
  <div id="r_1" class="row">
    <div class="col-4"><div class="box bmh-75 box-bordered"><div class="box-group"><div class="box-group-icon"><i class="fa fa-calendar"></i></div><div class="box-group-area"><span><?= $_system_date_time ?><br><?= htmlspecialchars(ucfirst(isset($clock['date']) ? $clock['date'] : date('M/d/Y')) . ' ' . (isset($clock['time']) ? $clock['time'] : date('H:i:s')), ENT_QUOTES); ?><br><?= $_uptime ?> : <?= htmlspecialchars(isset($resource['uptime']) ? formatDTM($resource['uptime']) : '-', ENT_QUOTES); ?></span></div></div></div></div>
    <div class="col-4"><div class="box bmh-75 box-bordered"><div class="box-group"><div class="box-group-icon"><i class="fa fa-info-circle"></i></div><div class="box-group-area"><span><?= $_board_name ?> : <?= htmlspecialchars(isset($resource['board-name']) ? $resource['board-name'] : '-', ENT_QUOTES); ?><br><?= $_model ?> : <?= htmlspecialchars(isset($routerboard['model']) ? $routerboard['model'] : '-', ENT_QUOTES); ?><br>Router OS : <?= htmlspecialchars(isset($resource['version']) ? $resource['version'] : '-', ENT_QUOTES); ?></span></div></div></div></div>
    <div class="col-4"><div class="box bmh-75 box-bordered"><div class="box-group"><div class="box-group-icon"><i class="fa fa-server"></i></div><div class="box-group-area"><span><?= $_cpu_load ?> : <?= htmlspecialchars(isset($resource['cpu-load']) ? $resource['cpu-load'] : '0', ENT_QUOTES); ?>%<br><?= $_free_memory ?> : <?= htmlspecialchars(isset($resource['free-memory']) ? formatBytes($resource['free-memory'], 2) : '-', ENT_QUOTES); ?><br><?= $_free_hdd ?> : <?= htmlspecialchars(isset($resource['free-hdd-space']) ? formatBytes($resource['free-hdd-space'], 2) : '-', ENT_QUOTES); ?></span></div></div></div></div>
  </div>

  <?php if (empty($routerConnected)): ?><div class="box bg-warning">Router MikroTik tidak terhubung. Data layanan dan report belum dapat diperbarui.</div><?php endif; ?>

  <div class="row">
    <div class="col-8">
      <div id="r_2" class="row"><div class="card"><div class="card-header"><h3><i class="fa fa-wifi"></i> Hotspot</h3></div><div class="card-body"><div class="row">
        <div class="col-3 col-box-6"><div class="box bg-blue bmh-75"><a href="./?hotspot=active&session=<?= $session; ?>"><h1><?= count($mitraHotspotActive); ?> <span style="font-size:15px">items</span></h1><div><i class="fa fa-laptop"></i> <?= $_hotspot_active ?></div></a></div></div>
        <div class="col-3 col-box-6"><div class="box bg-green bmh-75"><a href="./?hotspot=users&profile=all&session=<?= $session; ?>"><h1><?= count($mitraHotspotUsers); ?> <span style="font-size:15px">items</span></h1><div><i class="fa fa-users"></i> User Hotspot Saya</div></a></div></div>
        <div class="col-3 col-box-6"><div class="box bg-yellow bmh-75"><a href="./?hotspot=users-by-profile&session=<?= $session; ?>"><h1><?= count($mitraVoucherUsers); ?> <span style="font-size:15px">items</span></h1><div><i class="fa fa-ticket"></i> Voucher Saya</div></a></div></div>
        <div class="col-3 col-box-6"><div class="box bg-red bmh-75"><a href="./?hotspot-user=generate&session=<?= $session; ?>"><h1><i class="fa fa-user-plus"></i> <span style="font-size:15px"><?= $_generate ?></span></h1><div><i class="fa fa-ticket"></i> Voucher</div></a></div></div>
      </div></div></div></div>

      <div id="r_ppp" class="row"><div class="card"><div class="card-header"><h3><i class="fa fa-exchange"></i> PPPoE</h3></div><div class="card-body"><div class="row">
        <div class="col-4 col-box-6"><div class="box bg-blue bmh-75"><a href="./?ppp=active&session=<?= $session; ?>"><h1><?= count($mitraPppActive); ?> <span style="font-size:15px">items</span></h1><div><i class="fa fa-wifi"></i> <?= $_ppp_active ?></div></a></div></div>
        <div class="col-4 col-box-6"><div class="box bg-green bmh-75"><a href="./?ppp=secrets&session=<?= $session; ?>"><h1><?= count($mitraPppSecrets); ?> <span style="font-size:15px">items</span></h1><div><i class="fa fa-users"></i> Pelanggan PPPoE</div></a></div></div>
        <div class="col-4 col-box-6"><div class="box bg-yellow bmh-75"><a href="./?customer=add&service=pppoe&session=<?= $session; ?>"><h1><i class="fa fa-user-plus"></i> <span style="font-size:15px"><?= $_add ?></span></h1><div><i class="fa fa-user-plus"></i> Pelanggan PPPoE</div></a></div></div>
      </div></div></div></div>

      <div class="row"><div class="card"><div class="card-header"><h3><i class="fa fa-address-card"></i> Pelanggan</h3></div><div class="card-body"><div class="row">
        <div class="col-4"><div class="box bg-blue bmh-75"><a href="./?customer=list&session=<?= $session; ?>"><h1><?= count($mitraCustomers); ?></h1><div><i class="fa fa-users"></i> Semua Pelanggan</div></a></div></div>
        <div class="col-4"><div class="box bg-green bmh-75"><a href="./?customer=list&session=<?= $session; ?>"><h1><?= $hotspotCustomers; ?></h1><div><i class="fa fa-wifi"></i> Pelanggan Hotspot</div></a></div></div>
        <div class="col-4"><div class="box bg-yellow bmh-75"><a href="./?customer=list&session=<?= $session; ?>"><h1><?= $pppoeCustomers; ?></h1><div><i class="fa fa-exchange"></i> Pelanggan PPPoE</div></a></div></div>
      </div></div></div></div>
    </div>

    <div class="col-4">
      <div id="r_4" class="row"><div class="box bmh-75 box-bordered"><div class="box-group"><div class="box-group-icon"><i class="fa fa-money"></i></div><div class="box-group-area"><span><b><?= $_income ?></b><br><?= $_today ?> <?= $reportTotals['today']['count']; ?> trx : <?= htmlspecialchars(mitraDashboardMoney($reportTotals['today']['income'], $currency), ENT_QUOTES); ?><br><?= $_this_month ?> <?= $reportTotals['all']['count']; ?> trx : <?= htmlspecialchars(mitraDashboardMoney($reportTotals['all']['income'], $currency), ENT_QUOTES); ?><hr style="margin:5px 0;border:0;border-top:1px solid currentColor;opacity:.35"><b>Net Profit Mitra</b><br><?= $_today ?>: <?= htmlspecialchars(mitraDashboardMoney($reportTotals['today']['profit'], $currency), ENT_QUOTES); ?><br><?= $_this_month ?>: <?= htmlspecialchars(mitraDashboardMoney($reportTotals['all']['profit'], $currency), ENT_QUOTES); ?></span></div></div></div></div>

      <div class="row"><div class="card"><div class="card-header"><h3><i class="fa fa-area-chart"></i> Report Layanan</h3></div><div class="card-body">
        <a href="./?report=selling&idbl=<?= $monthKey; ?>&service=hotspot&session=<?= $session; ?>"><div class="box bg-blue"><strong>Hotspot</strong><br><?= $reportTotals['hotspot']['count']; ?> transaksi<br>Net Profit: <?= htmlspecialchars(mitraDashboardMoney($reportTotals['hotspot']['profit'], $currency), ENT_QUOTES); ?></div></a>
        <a href="./?report=selling&idbl=<?= $monthKey; ?>&service=pppoe&session=<?= $session; ?>"><div class="box bg-green"><strong>PPPoE</strong><br><?= $reportTotals['pppoe']['count']; ?> transaksi<br>Net Profit: <?= htmlspecialchars(mitraDashboardMoney($reportTotals['pppoe']['profit'], $currency), ENT_QUOTES); ?></div></a>
      </div></div></div>

      <div class="row"><div class="card"><div class="card-header"><h3><i class="fa fa-file-text"></i> Invoice Pelanggan</h3></div><div class="card-body"><div class="row"><div class="col-6"><div class="box bg-green text-center"><h2><?= $paidInvoices; ?></h2>Lunas</div></div><div class="col-6"><div class="box bg-red text-center"><h2><?= $unpaidInvoices; ?></h2>Belum Bayar</div></div></div></div></div></div>
    </div>
  </div>
</div>
