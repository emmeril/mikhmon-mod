<?php

include_once(__DIR__ . '/../lib/billing_profile.php');
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
$mitraHotspotLogs = array();
$interfaceName = '';
$monthKey = strtolower(date('M')) . date('Y');
$todayKey = strtolower(date('M/d/Y'));
$monthReports = array();
$hotspotProfiles = array();
$pppProfiles = array();

if (!empty($routerConnected)) {
  $clockRows = $API->comm('/system/clock/print');
  $resourceRows = $API->comm('/system/resource/print');
  $routerboardRows = $API->comm('/system/routerboard/print');
  $interfaceRows = (array) $API->comm('/interface/print');
  if (isset($clockRows[0])) $clock = $clockRows[0];
  if (isset($resourceRows[0])) $resource = $resourceRows[0];
  if (isset($routerboardRows[0])) $routerboard = $routerboardRows[0];
  $interfaceIndex = max(0, (int) $iface - 1);
  if (isset($interfaceRows[$interfaceIndex]['name'])) {
    $interfaceName = (string) $interfaceRows[$interfaceIndex]['name'];
  } elseif (isset($interfaceRows[0]['name'])) {
    $interfaceName = (string) $interfaceRows[0]['name'];
  }
  if (!empty($clock['time-zone-name'])) {
    $_SESSION['timezone'] = $clock['time-zone-name'];
    date_default_timezone_set($clock['time-zone-name']);
    $monthKey = strtolower(date('M')) . date('Y');
    $todayKey = strtolower(date('M/d/Y'));
  }

  $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print');
  $voucherProfiles = array();
  foreach ((array) $hotspotProfiles as $profileRow) {
    if (isset($profileRow['name']) && mikhmonBillingProfileExpiredMode('hotspot', $profileRow) !== 'none') {
      $voucherProfiles[(string) $profileRow['name']] = true;
    }
  }

  foreach ((array) $API->comm('/ip/hotspot/user/print') as $hotspotUser) {
    $isVoucherOwner = mikhmonRowBelongsToCurrentMitra($hotspotUser);
    $isAssignedCustomer = isset($hotspotUser['name']) && isset($hotspotCustomerNames[(string) $hotspotUser['name']]);
    if ($isVoucherOwner && isset($hotspotUser['profile']) && isset($voucherProfiles[(string) $hotspotUser['profile']])) $mitraVoucherUsers[] = $hotspotUser;
    if ($isVoucherOwner || $isAssignedCustomer) $mitraHotspotUsers[] = $hotspotUser;
  }
  $mitraHotspotNames = array();
  foreach ($mitraHotspotUsers as $hotspotUser) if (isset($hotspotUser['name'])) $mitraHotspotNames[(string) $hotspotUser['name']] = true;

  // Keep the dashboard log limited to hotspot users managed by this mitra.
  $mitraLogUsernames = array_merge(array_keys($hotspotCustomerNames), array_keys($mitraHotspotNames));
  $hotspotLogs = array_reverse((array) $API->comm('/log/print', array('?topics' => 'hotspot,info,debug')));
  foreach ($hotspotLogs as $hotspotLog) {
    $message = isset($hotspotLog['message']) ? (string) $hotspotLog['message'] : '';
    if (substr($message, 0, 2) !== '->') continue;
    $parts = explode(':', $message);
    $userCell = isset($parts[1]) ? $parts[1] : '';
    $belongsToMitra = false;
    foreach ($mitraLogUsernames as $username) {
      if ($username !== '' && stripos($userCell, (string) $username) !== false) {
        $belongsToMitra = true;
        break;
      }
    }
    if (!$belongsToMitra) continue;
    $messageCell = count($parts) > 6
      ? implode(' ', array_slice($parts, 7, 4))
      : implode(' ', array_slice($parts, 2, 4));
    $mitraHotspotLogs[] = array(
      'time' => isset($hotspotLog['time']) ? (string) $hotspotLog['time'] : '',
      'user' => count($parts) > 6 ? implode(':', array_slice($parts, 1, 6)) : $userCell,
      'message' => str_replace('trying to', '', $messageCell),
    );
    if (count($mitraHotspotLogs) >= 20) break;
  }
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

function mitraDashboardMoney($value, $currency) {
  return $currency . ' ' . number_format((float) $value, 0, ',', '.');
}

$hotspotCustomers = count($hotspotCustomerNames);
$pppoeCustomers = count($pppoeCustomerNames);
?>
<style>
@media screen and (min-width: 751px) {
  .mitra-dashboard-main-row {
    display: flex;
    align-items: stretch;
  }
  .mitra-dashboard-main-row > .mitra-dashboard-column {
    display: flex;
    flex-direction: column;
  }
  .mitra-dashboard-right .mitra-hotspot-log,
  .mitra-dashboard-right .mitra-hotspot-log .card {
    display: flex;
    flex: 1;
    flex-direction: column;
  }
  .mitra-dashboard-right .mitra-hotspot-log .card-body {
    display: flex;
    flex: 1;
    min-height: 0;
  }
  .mitra-dashboard-right .mitra-hotspot-log .overflow {
    flex: 1;
    max-height: none !important;
    width: 100%;
  }
}
</style>
<div id="reloadHome">
  <div id="r_1" class="row">
    <div class="col-4"><div class="box bmh-75 box-bordered"><div class="box-group"><div class="box-group-icon"><i class="fa fa-calendar"></i></div><div class="box-group-area"><span><?= $_system_date_time ?><br><?= htmlspecialchars(ucfirst(isset($clock['date']) ? $clock['date'] : date('M/d/Y')) . ' ' . (isset($clock['time']) ? $clock['time'] : date('H:i:s')), ENT_QUOTES); ?><br><?= $_uptime ?> : <?= htmlspecialchars(isset($resource['uptime']) ? formatDTM($resource['uptime']) : '-', ENT_QUOTES); ?></span></div></div></div></div>
    <div class="col-4"><div class="box bmh-75 box-bordered"><div class="box-group"><div class="box-group-icon"><i class="fa fa-info-circle"></i></div><div class="box-group-area"><span><?= $_board_name ?> : <?= htmlspecialchars(isset($resource['board-name']) ? $resource['board-name'] : '-', ENT_QUOTES); ?><br><?= $_model ?> : <?= htmlspecialchars(isset($routerboard['model']) ? $routerboard['model'] : '-', ENT_QUOTES); ?><br>Router OS : <?= htmlspecialchars(isset($resource['version']) ? $resource['version'] : '-', ENT_QUOTES); ?></span></div></div></div></div>
    <div class="col-4"><div class="box bmh-75 box-bordered"><div class="box-group"><div class="box-group-icon"><i class="fa fa-server"></i></div><div class="box-group-area"><span><?= $_cpu_load ?> : <?= htmlspecialchars(isset($resource['cpu-load']) ? $resource['cpu-load'] : '0', ENT_QUOTES); ?>%<br><?= $_free_memory ?> : <?= htmlspecialchars(isset($resource['free-memory']) ? formatBytes($resource['free-memory'], 2) : '-', ENT_QUOTES); ?><br><?= $_free_hdd ?> : <?= htmlspecialchars(isset($resource['free-hdd-space']) ? formatBytes($resource['free-hdd-space'], 2) : '-', ENT_QUOTES); ?></span></div></div></div></div>
  </div>

  <?php if (empty($routerConnected)): ?><div class="box bg-warning">Router MikroTik tidak terhubung. Data layanan dan report belum dapat diperbarui.</div><?php endif; ?>

  <div class="row mitra-dashboard-main-row">
    <div class="col-8 mitra-dashboard-column">
      <div id="r_2" class="row"><div class="card"><div class="card-header"><h3><i class="fa fa-wifi"></i> Hotspot</h3></div><div class="card-body"><div class="row">
        <div class="col-3 col-box-6"><div class="box bg-blue bmh-75"><a href="./?hotspot=active&session=<?= $session; ?>"><h1><?= count($mitraHotspotActive); ?> <span style="font-size:15px">items</span></h1><div><i class="fa fa-laptop"></i> <?= $_hotspot_active ?></div></a></div></div>
        <div class="col-3 col-box-6"><div class="box bg-green bmh-75"><a href="./?hotspot=users&profile=all&session=<?= $session; ?>"><h1><?= count($mitraHotspotUsers); ?> <span style="font-size:15px">items</span></h1><div><i class="fa fa-users"></i> <?= $_hotspot_users ?></div></a></div></div>
        <div class="col-3 col-box-6"><div class="box bg-yellow bmh-75"><a href="./?hotspot=users-by-profile&session=<?= $session; ?>"><h1><?= count($mitraVoucherUsers); ?> <span style="font-size:15px">items</span></h1><div><i class="fa fa-ticket"></i> Vouchers</div></a></div></div>
        <div class="col-3 col-box-6"><div class="box bg-red bmh-75"><a href="./?hotspot-user=generate&session=<?= $session; ?>"><h1><i class="fa fa-user-plus"></i> <span style="font-size:15px"><?= $_generate ?></span></h1><div><i class="fa fa-ticket"></i> Voucher</div></a></div></div>
      </div></div></div></div>

      <div id="r_ppp" class="row"><div class="card"><div class="card-header"><h3><i class="fa fa-exchange"></i> PPPoE</h3></div><div class="card-body"><div class="row">
        <div class="col-4 col-box-6"><div class="box bg-blue bmh-75"><a href="./?ppp=active&session=<?= $session; ?>"><h1><?= count($mitraPppActive); ?> <span style="font-size:15px">items</span></h1><div><i class="fa fa-wifi"></i> <?= $_ppp_active ?></div></a></div></div>
        <div class="col-4 col-box-6"><div class="box bg-green bmh-75"><a href="./?ppp=secrets&session=<?= $session; ?>"><h1><?= count($mitraPppSecrets); ?> <span style="font-size:15px">items</span></h1><div><i class="fa fa-users"></i> <?= $_ppp_secrets ?></div></a></div></div>
        <div class="col-4 col-box-6"><div class="box bg-yellow bmh-75"><a href="./?customer=service-add&service=pppoe&session=<?= $session; ?>"><h1><i class="fa fa-user-plus"></i> <span style="font-size:15px"><?= $_add ?></span></h1><div><i class="fa fa-user-plus"></i> <?= $_ppp_secrets ?></div></a></div></div>
      </div></div></div></div>

      <div class="row"><div class="card"><div class="card-header"><h3><i class="fa fa-area-chart"></i> <?= $_traffic ?></h3></div><div class="card-body">
        <div id="mitraTrafficMonitor"></div>
      </div></div></div>
      <script>
      (function () {
        var mitraTrafficChart;
        var mitraTrafficSession = <?= json_encode((string) $session); ?>;
        var mitraTrafficInterface = <?= json_encode($interfaceName); ?>;

        function requestMitraTraffic() {
          if (!mitraTrafficChart || !mitraTrafficInterface) return;
          $.ajax({
            url: './traffic/traffic.php?session=' + encodeURIComponent(mitraTrafficSession) + '&iface=' + encodeURIComponent(mitraTrafficInterface),
            dataType: 'json',
            success: function (data) {
              if (!Array.isArray(data) || data.length < 2) return;
              var tx = parseInt(data[0].data, 10) || 0;
              var rx = parseInt(data[1].data, 10) || 0;
              var now = (new Date()).getTime();
              var shift = mitraTrafficChart.series[0].data.length > 19;
              mitraTrafficChart.series[0].addPoint([now, tx], true, shift);
              mitraTrafficChart.series[1].addPoint([now, rx], true, shift);
            }
          });
        }

        $(function () {
          if (!window.Highcharts || !document.getElementById('mitraTrafficMonitor')) return;
          mitraTrafficChart = new Highcharts.Chart({
            chart: { renderTo: 'mitraTrafficMonitor', type: 'areaspline', height: 250 },
            title: { text: <?= json_encode($_interface); ?> + ' ' + mitraTrafficInterface },
            xAxis: { type: 'datetime', tickPixelInterval: 150 },
            yAxis: {
              minPadding: 0.2,
              maxPadding: 0.2,
              title: { text: null },
              labels: { formatter: function () {
                var bytes = this.value;
                var sizes = ['bps', 'kbps', 'Mbps', 'Gbps', 'Tbps'];
                if (!bytes) return '0 bps';
                var index = Math.floor(Math.log(bytes) / Math.log(1024));
                return parseFloat((bytes / Math.pow(1024, index)).toFixed(2)) + ' ' + sizes[index];
              } }
            },
            series: [
              { name: 'Tx', data: [], marker: { symbol: 'circle' } },
              { name: 'Rx', data: [], marker: { symbol: 'circle' } }
            ],
            tooltip: { shared: true }
          });
          requestMitraTraffic();
          setInterval(requestMitraTraffic, 8000);
        });
      })();
      </script>

      <div class="row"><div class="card"><div class="card-header"><h3><i class="fa fa-address-card"></i> Pelanggan</h3></div><div class="card-body"><div class="row">
        <div class="col-4"><div class="box bg-blue bmh-75"><a href="./?customer=list&session=<?= $session; ?>"><h1><?= count($mitraCustomers); ?></h1><div><i class="fa fa-users"></i> Pelanggan</div></a></div></div>
        <div class="col-4"><div class="box bg-green bmh-75"><a href="./?customer=list&session=<?= $session; ?>"><h1><?= $hotspotCustomers; ?></h1><div><i class="fa fa-wifi"></i> Hotspot</div></a></div></div>
        <div class="col-4"><div class="box bg-yellow bmh-75"><a href="./?customer=list&session=<?= $session; ?>"><h1><?= $pppoeCustomers; ?></h1><div><i class="fa fa-exchange"></i> PPPoE</div></a></div></div>
      </div></div></div></div>
    </div>

    <div class="col-4 mitra-dashboard-column mitra-dashboard-right">
      <div id="r_4" class="row"><div class="box bmh-75 box-bordered"><div class="box-group"><div class="box-group-icon"><i class="fa fa-money"></i></div><div class="box-group-area"><span><b><?= $_income ?></b><br><?= $_today ?> <?= $reportTotals['today']['count']; ?> trx : <?= htmlspecialchars(mitraDashboardMoney($reportTotals['today']['income'], $currency), ENT_QUOTES); ?><br><?= $_this_month ?> <?= $reportTotals['all']['count']; ?> trx : <?= htmlspecialchars(mitraDashboardMoney($reportTotals['all']['income'], $currency), ENT_QUOTES); ?><hr style="margin:5px 0;border:0;border-top:1px solid currentColor;opacity:.35"><b>Net Profit Mitra</b><br><?= $_today ?>: <?= htmlspecialchars(mitraDashboardMoney($reportTotals['today']['profit'], $currency), ENT_QUOTES); ?><br><?= $_this_month ?>: <?= htmlspecialchars(mitraDashboardMoney($reportTotals['all']['profit'], $currency), ENT_QUOTES); ?></span></div></div></div></div>

      <div class="row mitra-hotspot-log"><div class="card"><div class="card-header"><h3><i class="fa fa-align-justify"></i> <?= $_hotspot_log ?></h3></div><div class="card-body"><div style="padding: 5px; max-height: 430px;" class="mr-t-10 overflow">
        <table class="table table-sm table-bordered table-hover" style="font-size: 12px;">
          <thead><tr><th><?= $_time ?></th><th><?= $_users ?> (IP)</th><th><?= $_messages ?></th></tr></thead>
          <tbody>
          <?php if (empty($mitraHotspotLogs)): ?>
            <tr><td colspan="3" class="text-center">Belum ada log hotspot.</td></tr>
          <?php else: foreach ($mitraHotspotLogs as $hotspotLog): ?>
            <tr><td><?= htmlspecialchars($hotspotLog['time'], ENT_QUOTES); ?></td><td><?= htmlspecialchars($hotspotLog['user'], ENT_QUOTES); ?></td><td><?= htmlspecialchars($hotspotLog['message'], ENT_QUOTES); ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div></div></div></div>
    </div>
  </div>
</div>
