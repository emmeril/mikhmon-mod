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
} elseif (isset($_SESSION['mikhmon_role']) && $_SESSION['mikhmon_role'] !== 'admin') {
  http_response_code(403);
  exit;
} else {
// load session MikroTik
  $session = $_GET['session'];
  $load = $_GET['load'];

// lang
include('../include/lang.php');
include('../lang/'.$langid.'.php');

// load config
  include('../include/config.php');
  include('../include/readcfg.php');

// routeros api
  include_once('../lib/routeros_api.class.php');
  include_once('../lib/formatbytesbites.php');
  include_once('../include/database.php');
  $API = new RouterosAPI();
  $API->debug = false;



  if ($load == "sysresource") {

    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
      // Dashboard polling keeps versioned backups current while Mikhmon is open.
      mikhmonSynchronizeRouterData($API, $session);
    }

// get MikroTik system clock
    $getclock = $API->comm("/system/clock/print");
    $clock = $getclock[0];
    $timezone = $getclock[0]['time-zone-name'];
    date_default_timezone_set($timezone);

// get system resource MikroTik
    $getresource = $API->comm("/system/resource/print");
    $resource = $getresource[0];

// get routeboard info
    $getrouterboard = $API->comm("/system/routerboard/print");
    $routerboard = $getrouterboard[0];
    ?>
    
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

<?php
} else if ($load == "hotspot") {
  $cache = isset($_SESSION['dashboard_hotspot_cache'][$session]) ? $_SESSION['dashboard_hotspot_cache'][$session] : array();
  $cacheFresh = is_array($cache) && !empty($cache['at']) && (time() - (int) $cache['at']) < 30;
  if ($cacheFresh) {
    $countallusers = (int) ($cache['users'] ?? 0);
    $counthotspotactive = (int) ($cache['active'] ?? 0);
  } else {
    $API->connect($iphost, $userhost, decrypt($passwdhost));
// Count only voucher users so periodic dashboard refresh matches the initial view.
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
// get & counting hotspot active
  $counthotspotactive = $API->comm("/ip/hotspot/active/print", array("count-only" => ""));
    $_SESSION['dashboard_hotspot_cache'][$session] = array('at' => time(), 'users' => $countallusers, 'active' => (int) $counthotspotactive);
  }
  $uunit = ($countallusers == 1) ? "item" : "items";
  if ($counthotspotactive < 2) {
    $hunit = "item";
  } elseif ($counthotspotactive > 1) {
    $hunit = "items";
  }

  ?>
    
            <div id="r_2" class="card">
              <div class="card-header"><h3><i class="fa fa-wifi"></i> Hotspot</h3></div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-3 col-box-6">
                      <div class="box bg-blue bmh-75">
                        <a href="./?hotspot=active&session=<?= $session; ?>">
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
                      <a href="./?hotspot=users&profile=all&session=<?= $session; ?>">
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
                      <a href="./?hotspot-user=add&session=<?= $session; ?>">
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
                      <a href="./?hotspot-user=generate&session=<?= $session; ?>">
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

<?php 
} else if ($load == "logs") {
  $cache = isset($_SESSION['dashboard_logs_cache'][$session]) ? $_SESSION['dashboard_logs_cache'][$session] : array();
  $cacheFresh = is_array($cache) && !empty($cache['at']) && (time() - (int) $cache['at']) < 30;
  if ($cacheFresh) {
    $log = isset($cache['log']) && is_array($cache['log']) ? $cache['log'] : array();
  } else {
    $API->connect($iphost, $userhost, decrypt($passwdhost));

  // Configure persistent hotspot logging once per login session.
  if (empty($_SESSION['dashboard_logging_checked'][$session])) {
    $getlogging = $API->comm("/system/logging/print", array("?prefix" => "->", '.proplist' => 'prefix'));
    if (!isset($getlogging[0]['prefix']) || $getlogging[0]['prefix'] !== "->") {
      $API->comm("/system/logging/add", array("action" => "disk", "prefix" => "->", "topics" => "hotspot,info,debug"));
    }
    $_SESSION['dashboard_logging_checked'][$session] = true;
  }
  
  // get hotspot log
  $getlog = $API->comm("/log/print", array("?topics" => "hotspot,info,debug", '.proplist' => 'time,message'));
  $log = array_slice(array_reverse((array) $getlog), 0, 20);
    $_SESSION['dashboard_logs_cache'][$session] = array('at' => time(), 'log' => $log);
  }
  //$THotspotLog = count($getlog);

  if ($livereport == "disable") {
    $logh = "457px";
    $lreport = "style='display:none;'";
  } else {
    $logh = "350px";
    $lreport = "style='display:block;'";
  }



  ?>
  
              <div id="r_3" class="row">
              <div class="card">
                <div class="card-header">
                  <h3><a href="./?hotspot=log&session=<?= $session; ?>" title="Open Hotspot Log" ><i class="fa fa-align-justify"></i> <?= $_hotspot_log ?></a></h3></div>
                    <div class="card-body">
                      <div style="padding: 5px; height: <?= $logh; ?> ;" class="mr-t-10 overflow">
                        <table class="table table-sm table-bordered table-hover" style="font-size: 12px; td.padding:2px;">
                          <thead>
                            <tr>
                            <th><?= $_time .$THotspotLog; ?></th>
                            <th><?= $_users ?> (IP)</th>
                            <th><?= $_messages ?></th>
                            </tr>
                          </thead>
                          <tbody>
                      
  <?php


  for ($i = 0, $logCount = count($log); $i < $logCount; $i++) {
    $mess = explode(":", $log[$i]['message']);
    $time = $log[$i]['time'];
    echo "<tr>";
    if (substr($log[$i]['message'], 0, 2) == "->") {
      echo "<td>" . $time . "</td>";
    //echo substr($mess[1], 0,2);
      echo "<td>";
      if (count($mess) > 6) {
        echo $mess[1] . ":" . $mess[2] . ":" . $mess[3] . ":" . $mess[4] . ":" . $mess[5] . ":" . $mess[6];
      } else {
        echo $mess[1];
      }
      echo "</td>";
      echo "<td>";
      if (count($mess) > 6) {
        echo str_replace("trying to", "", $mess[7] . " " . $mess[8] . " " . $mess[9] . " " . $mess[10]);
      } else {
        echo str_replace("trying to", "", $mess[2] . " " . $mess[3] . " " . $mess[4] . " " . $mess[5]);
      }
      echo "</td>";
    } else {
    }
    echo "</tr>";
  }
  ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
                </div>

<?php 
}

}

?>
