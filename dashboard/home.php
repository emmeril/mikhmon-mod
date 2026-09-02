<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
session_start();
include_once(__DIR__ . '/../lib/billing_profile.php');
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {


// get MikroTik system clock
  $getclock = $API->comm("/system/clock/print");
  $clock = $getclock[0];
  $timezone = $getclock[0]['time-zone-name'];
  $_SESSION['timezone'] = $timezone;
  date_default_timezone_set($timezone);

// get system resource MikroTik
  $getresource = $API->comm("/system/resource/print");
  $resource = $getresource[0];

// get routeboard info
  $getrouterboard = $API->comm("/system/routerboard/print");
  $routerboard = $getrouterboard[0];
/*
// move hotspot log to disk *
  $getlogging = $API->comm("/system/logging/print", array("?prefix" => "->", ));
  $logging = $getlogging[0];
  if ($logging['prefix'] == "->") {
  } else {
    $API->comm("/system/logging/add", array("action" => "disk", "prefix" => "->", "topics" => "hotspot,info,debug", ));
  }

// get hotspot log
  $getlog = $API->comm("/log/print", array("?topics" => "hotspot,info,debug", ));
  $log = array_reverse($getlog);
  $THotspotLog = count($getlog);
*/
// Count only voucher users; Billing-managed profiles (Expired Mode = None) are excluded.
  $voucherProfiles = array();
  foreach ((array) $API->comm("/ip/hotspot/user/profile/print", array('.proplist' => 'name,on-login')) as $profileRow) {
    if (isset($profileRow['name']) && mikhmonBillingProfileExpiredMode('hotspot', $profileRow) !== 'none') {
      $voucherProfiles[(string) $profileRow['name']] = true;
    }
  }
  $countallusers = 0;
  foreach ((array) $API->comm("/ip/hotspot/user/print", array('.proplist' => 'profile')) as $hotspotUser) {
    if (isset($hotspotUser['profile']) && isset($voucherProfiles[(string) $hotspotUser['profile']])) $countallusers++;
  }
  if ($countallusers < 2) {
    $uunit = "item";
  } elseif ($countallusers > 1) {
    $uunit = "items";
  }

// get & counting hotspot active
  $counthotspotactive = $API->comm("/ip/hotspot/active/print", array("count-only" => ""));
  if ($counthotspotactive < 2) {
    $hunit = "item";
  } elseif ($counthotspotactive > 1) {
    $hunit = "items";
  }

// get & counting PPPoE users and active sessions
  $countpppusers = $API->comm("/ppp/secret/print", array("count-only" => ""));
  $countpppactive = $API->comm("/ppp/active/print", array("count-only" => ""));
  $pppuserunit = ($countpppusers == 1) ? "item" : "items";
  $pppactiveunit = ($countpppactive == 1) ? "item" : "items";

  if ($livereport == "disable") {
    $logh = "457px";
    $lreport = "style='display:none;'";
  } else {
    $logh = "350px";
    $lreport = "style='display:block;'";
  }
/*
// get selling report
    $thisD = date("d");
    $thisM = strtolower(date("M"));
    $thisY = date("Y");

    if (strlen($thisD) == 1) {
      $thisD = "0" . $thisD;
    } else {
      $thisD = $thisD;
    }

    $idhr = $thisM . "/" . $thisD . "/" . $thisY;
    $idbl = $thisM . $thisY;

    $getSRHr = $API->comm("/system/script/print", array(
      "?source" => "$idhr",
    ));
    $TotalRHr = count($getSRHr);
    $getSRBl = $API->comm("/system/script/print", array(
      "?owner" => "$idbl",
    ));
    $TotalRBl = count($getSRBl);

    for ($i = 0; $i < $TotalRHr; $i++) {

      $tHr += explode("-|-", $getSRHr[$i]['name'])[3];

    }
    for ($i = 0; $i < $TotalRBl; $i++) {

      $tBl += explode("-|-", $getSRBl[$i]['name'])[3];
    }
  }*/
}
?>

<style>
@media screen and (min-width: 751px) {
  .dashboard-main-row {
    display: flex;
    align-items: stretch;
  }
  .dashboard-main-row > .dashboard-main-column {
    display: flex;
    flex-direction: column;
  }
  .dashboard-main-right #r_3,
  .dashboard-main-right #r_3 .card {
    display: flex;
    flex: 1;
    flex-direction: column;
  }
  .dashboard-main-right #r_3 .card-body {
    display: flex;
    flex: 1;
    min-height: 0;
  }
  .dashboard-main-right #r_3 .overflow {
    flex: 1;
    height: auto !important;
    width: 100%;
  }
}
</style>

<div id="reloadHome">

    <div id="r_1" class="row">
      <div class="col-4">
        <div class="box bmh-75 box-bordered">
          <div class="box-group">
            <div class="box-group-icon"><i class="fa fa-calendar"></i></div>
              <div class="box-group-area">
                <span ><?= $_system_date_time ?><br>
                    <?php 
                    echo ucfirst($clock['date']) . " " . $clock['time'] . "<br>
                    ".$_uptime." : " . formatDTM($resource['uptime']);
                    $_SESSION[$session.'sdate'] = $clock['date'];
                    ?>
                </span>
              </div>
            </div>
          </div>
        </div>
      <div class="col-4">
        <div class="box bmh-75 box-bordered">
          <div class="box-group">
          <div class="box-group-icon"><i class="fa fa-info-circle"></i></div>
              <div class="box-group-area">
                <span >
                    <?php
                    echo $_board_name." : " . $resource['board-name'] . "<br/>
                    ".$_model." : " . $routerboard['model'] . "<br/>
                    Router OS : " . $resource['version'];
                    ?>
                </span>
              </div>
            </div>
          </div>
        </div>
    <div class="col-4">
      <div class="box bmh-75 box-bordered">
        <div class="box-group">
          <div class="box-group-icon"><i class="fa fa-server"></i></div>
              <div class="box-group-area">
                <span >
                    <?php
                    echo $_cpu_load." : " . $resource['cpu-load'] . "%<br/>
                    ".$_free_memory." : " . formatBytes($resource['free-memory'], 2) . "<br/>
                    ".$_free_hdd." : " . formatBytes($resource['free-hdd-space'], 2)
                    ?>
                </span>
                </div>
              </div>
            </div>
          </div> 
      </div>

        <div class="row dashboard-main-row">
          <div class="col-8 dashboard-main-column">
            <div id="r_2"class="row">
            <div class="card">
              <div class="card-header"><h3><i class="fa fa-wifi"></i> Hotspot</h3></div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-3 col-box-6">
                      <div class="box bg-blue bmh-75">
                        <a onclick="cancelPage()" href="./?hotspot=active&session=<?= $session; ?>">
                          <h1><?= $counthotspotactive; ?>
                              <span style="font-size: 15px;"><?= $hunit; ?></span>
                            </h1>
                          <div>
                            <i class="fa fa-laptop"></i> <?= $_hotspot_active ?>
                          </div>
                        </a>
                      </div>
                    </div>
                    <div class="col-3 col-box-6">
                    <div class="box bg-green bmh-75">
                      <a onclick="cancelPage()" href="./?hotspot=users&profile=all&session=<?= $session; ?>">
                            <h1><?= $countallusers; ?>
                              <span style="font-size: 15px;"><?= $uunit; ?></span>
                            </h1>
                      <div>
                            <i class="fa fa-ticket"></i> Vouchers
                          </div>
                      </a>
                    </div>
                  </div>
                  <div class="col-3 col-box-6">
                    <div class="box bg-yellow bmh-75">
                      <a onclick="cancelPage()" href="./?hotspot-user=add&session=<?= $session; ?>">
                        <div>
                          <h1><i class="fa fa-user-plus"></i>
                              <span style="font-size: 15px;"><?= $_add ?></span>
                          </h1>
                        </div>
                        <div>
                            <i class="fa fa-ticket"></i> Vouchers
                        </div>
                      </a>
                    </div>
                  </div>
                  <div class="col-3 col-box-6">
                    <div class="box bg-red bmh-75">
                      <a onclick="cancelPage()" href="./?hotspot-user=generate&session=<?= $session; ?>">
                        <div>
                          <h1><i class="fa fa-user-plus"></i>
                              <span style="font-size: 15px;"><?= $_generate ?></span>
                          </h1>
                        </div>
                        <div>
                            <i class="fa fa-ticket"></i> Vouchers
                        </div>
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
          </div>
          <div id="r_ppp" class="row">
            <div class="card">
              <div class="card-header"><h3><i class="fa fa-exchange"></i> PPPoE</h3></div>
              <div class="card-body">
                <div class="row">
                  <div class="col-4 col-box-6">
                    <div class="box bg-blue bmh-75">
                      <a onclick="cancelPage()" href="./?ppp=active&session=<?= $session; ?>">
                        <h1><?= $countpppactive; ?> <span style="font-size:15px;"><?= $pppactiveunit; ?></span></h1>
                        <div><i class="fa fa-wifi"></i> <?= $_ppp_active ?></div>
                      </a>
                    </div>
                  </div>
                  <div class="col-4 col-box-6">
                    <div class="box bg-green bmh-75">
                      <a onclick="cancelPage()" href="./?ppp=secrets&session=<?= $session; ?>">
                        <h1><?= $countpppusers; ?> <span style="font-size:15px;"><?= $pppuserunit; ?></span></h1>
                        <div><i class="fa fa-users"></i> <?= $_ppp_secrets ?></div>
                      </a>
                    </div>
                  </div>
                  <div class="col-4 col-box-6">
                    <div class="box bg-yellow bmh-75">
                      <a onclick="cancelPage()" href="./?ppp=addsecret&session=<?= $session; ?>">
                        <h1><i class="fa fa-user-plus"></i> <span style="font-size:15px;"><?= $_add; ?></span></h1>
                        <div><i class="fa fa-user-plus"></i> <?= $_ppp_secrets ?></div>
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
            <div class="card">
              <div class="card-header"><h3><i class="fa fa-area-chart"></i> <?= $_traffic ?> </h3></div>

              <div class="card-body">
  
                  <?php $getinterface = $API->comm("/interface/print");
                  $interface = $getinterface[$iface - 1]['name']; 
                  /*$TotalReg = count($getinterface);
                  for ($i = 0; $i < $TotalReg; $i++) {
                    echo $getinterface[$i]['name'].'<br>';
                  }*/
                  ?>
                  
                  <script type="text/javascript"> 
                    var chart;
                    var sessiondata = <?= json_encode((string) $session); ?>;
                    var interface = <?= json_encode((string) $interface); ?>;
                    var trafficTooltipTitle = <?= json_encode(htmlspecialchars($brandname . ' - ' . $_traffic, ENT_QUOTES, 'UTF-8')); ?>;
                    var trafficTimeLabel = <?= json_encode(htmlspecialchars($_time, ENT_QUOTES, 'UTF-8')); ?>;
                    var n = 3000;

                    function formatDashboardTrafficRate(value) {
                      var rate = Number(value) || 0;
                      var sizes = ['bps', 'kbps', 'Mbps', 'Gbps', 'Tbps'];
                      if (rate <= 0) return '0 bps';

                      var index = Math.min(Math.floor(Math.log(rate) / Math.log(1024)), sizes.length - 1);
                      return parseFloat((rate / Math.pow(1024, index)).toFixed(2)) + ' ' + sizes[index];
                    }

                    function requestDatta(session,iface) {
                      $.ajax({
                        url: './traffic/traffic.php?session='+session+'&iface='+iface,
                        datatype: "json",
                        success: function(data) {
                          var midata = JSON.parse(data);
                          if( midata.length > 0 ) {
                            var TX=parseInt(midata[0].data);
                            var RX=parseInt(midata[1].data);
                            var x = (new Date()).getTime(); 
                            shift=chart.series[0].data.length > 19;
                            chart.series[0].addPoint([x, TX], true, shift);
                            chart.series[1].addPoint([x, RX], true, shift);
                          }
                        },
                        error: function(XMLHttpRequest, textStatus, errorThrown) { 
                          console.error("Status: " + textStatus + " request: " + XMLHttpRequest); console.error("Error: " + errorThrown); 
                        }       
                      });
                    }	

                    $(document).ready(function() {
                        Highcharts.setOptions({
                          global: {
                            useUTC: false
                          }
                        });

                        Highcharts.addEvent(Highcharts.Series, 'afterInit', function () {
	                        this.symbolUnicode = {
    	                    circle: '●',
                          diamond: '♦',
                          square: '■',
                          triangle: '▲',
                          'triangle-down': '▼'
                          }[this.symbol] || '●';
                        });

                          chart = new Highcharts.Chart({
                          chart: {
                          renderTo: 'trafficMonitor',
                          animation: Highcharts.svg,
                          type: 'areaspline',
                          events: {
                            load: function () {
                              setInterval(function () {
                                requestDatta(sessiondata,interface);
                              }, 8000);
                            }				
                          }
                        },
                        title: {
                          text: '<?= $_interface ?> ' + interface
                        },
                        
                        xAxis: {
                          type: 'datetime',
                          tickPixelInterval: 150,
                          maxZoom: 20 * 1000,
                        },
                        yAxis: {
                            minPadding: 0.2,
                            maxPadding: 0.2,
                            title: {
                              text: null
                            },
                            labels: {
                              formatter: function () {
                                return formatDashboardTrafficRate(this.value);
                              },
                            },       
                        },
                        
                        series: [{
                          name: 'TX',
                          data: [],
                          marker: {
                            symbol: 'circle'
                          }
                        }, {
                          name: 'RX',
                          data: [],
                          marker: {
                            symbol: 'circle'
                          }
                        }],

                        tooltip: {
                          formatter: function () {
                            var lines = [
                              '<b>' + trafficTooltipTitle + '</b>',
                              '<b>' + trafficTimeLabel + ':</b> ' + Highcharts.dateFormat('%H:%M:%S', new Date(this.x))
                            ];

                            $.each(this.points, function (_, point) {
                              lines.push(
                                '<span style="color:' + point.series.color + ';font-size:1.5em;">●</span>' +
                                '<b>' + point.series.name + ':</b> ' + formatDashboardTrafficRate(point.y)
                              );
                            });

                            return lines.join('<br>');
                          },
                          shared: true                                                      
                        },
                      });
                    });
                  </script>
                  <div id="trafficMonitor"></div>
                </div>
              </div>
            </div>
            <div class="col-4 dashboard-main-column dashboard-main-right">
            <div id="r_4" class="row">
              <div <?= $lreport; ?> class="box bmh-75 box-bordered">
                <div class="box-group">
                  <div class="box-group-icon"><i class="fa fa-money"></i></div>
                    <div class="box-group-area">
                      <span >
                        <div id="reloadLreport">
                          <?php 
                          if ($_SESSION[$session.'sdate'] == $_SESSION[$session.'idhr']){
                            echo "<b>" . $_income . "</b><br/>" . "
                          ".$_today." " . $_SESSION[$session.'totalHr'] . " trx : " . $currency . " " . $_SESSION[$session.'dincome']. "<br/>
                          ".$_this_month." " . $_SESSION[$session.'totalBl'] . " trx : " . $currency . " " . $_SESSION[$session.'mincome'] . "<hr style='margin:5px 0;border:0;border-top:1px solid currentColor;opacity:.35'><b>" . $_net_profit . "</b><br/>" . $_today . ": " . $currency . " " . (isset($_SESSION[$session.'dprofit']) ? $_SESSION[$session.'dprofit'] : '0') . "<br/>" . $_this_month . ": " . $currency . " " . (isset($_SESSION[$session.'mprofit']) ? $_SESSION[$session.'mprofit'] : '0');
                          }else{
                            echo "<div id='loader' ><i><span> <i class='fa fa-circle-o-notch fa-spin'></i> ". $_processing." </i></div>";
                          }
                          ?>                       
                        </div>
                    </span>
                </div>
              </div>
            </div>
            </div>
            <div id="r_3" class="row">
            <div class="card">
              <div class="card-header">
                <h3><a onclick="cancelPage()" href="./?hotspot=log&session=<?= $session; ?>" title="Open Hotspot Log" ><i class="fa fa-align-justify"></i> <?= $_hotspot_log ?></a></h3></div>
                  <div class="card-body">
                    <div style="padding: 5px; max-height: 320px;" class="mr-t-10 overflow">
                      <table class="table table-sm table-bordered table-hover" style="font-size: 12px; td.padding:2px;">
                        <thead>
                          <tr>
                            <th><?= $_time ?></th>
                            <th><?= $_users ?> (IP)</th>
                            <th><?= $_messages ?></th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr>
                            <td colspan="3" class="text-center">
                            <div id="loader" ><i><i class='fa fa-circle-o-notch fa-spin'></i> <?= $_processing ?> </i></div>
                            </td>
                          </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
              <?php include(__DIR__ . '/systemlogs.php'); ?>
              </div>
            </div>
</div>
</div>
