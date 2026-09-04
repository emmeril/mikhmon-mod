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
include_once(__DIR__ . '/reportrecord.php');
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
// load session MikroTik
  $session = $_GET['session'];
// set  timezone
date_default_timezone_set($_SESSION['timezone']);

// lang
include('../include/lang.php');
include('../lang/'.$langid.'.php');


// load config
  include('../include/config.php');
  include_once('../include/access.php');
  if (!mikhmonIsAdmin() && (!mikhmonIsMitra() || mikhmonAssignedSession() !== (string) $session)) {
    http_response_code(403);
    exit;
  }
  include('../include/readcfg.php');

// routeros api
  include_once('../lib/routeros_api.class.php');
  include_once('../lib/formatbytesbites.php');
  $API = new RouterosAPI();
  $API->debug = false;
  $API->connect($iphost, $userhost, decrypt($passwdhost));

  if ($livereport == "disable") {
    $logh = "457px";
    $lreport = "style='display:none;'";
  } else {
    $logh = "350px";
    $lreport = "style='display:block;'";
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

    $_SESSION[$session.'idhr'] = $idhr;

   /* $getSRHr = $API->comm("/system/script/print", array(
      "?source" => "$idhr",
    ));
    $TotalRHr = count($getSRHr);
    $_SESSION[$session.'totalHr'] = $TotalRHr;*/
	$getSRBl = mikhmonReportFetchRecords($API, $session, '', $idbl);
	$getSRBl = mikhmonReportMergeBillingRows($session, $getSRBl, '', $idbl);
    if (mikhmonIsMitra()) {
      $mitraUsernames = mikhmonMitraUsernames($session);
      $getSRBl = array_values(array_filter($getSRBl, function ($row) use ($mitraUsernames) {
        $parts = mikhmonReportParts($row);
        return mikhmonRowBelongsToCurrentMitra($row) || (isset($parts[2]) && isset($mitraUsernames[trim($parts[2])]));
      }));
    }
    $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print');
    $pppProfiles = $API->comm('/ppp/profile/print');
    $profileCosts = mikhmonReportProfileCosts($hotspotProfiles, $pppProfiles);
    $profileSellingPrices = mikhmonReportProfileSellingPrices($hotspotProfiles, $pppProfiles);
    $TotalRBl = count($getSRBl);
    $_SESSION[$session.'totalBl'] = $TotalRBl;
/*
    for ($i = 0; $i < $TotalRHr; $i++) {

      $tHr += explode("-|-", $getSRHr[$i]['name'])[3];

    }*/
    foreach($getSRBl as $row){
    
      if((explode("-|-", $row['name'])[0]) == $idhr){
         $tHr += mikhmonReportSellingPrice($row, $profileSellingPrices);
         $pHr += mikhmonReportNetProfit($row, $profileCosts, $profileSellingPrices);
         $TotalRHr += count((array)$row['source']); /*Modif line add (array) by github https://github.com/MasKawer*/
 
       }
       $tBl += mikhmonReportSellingPrice($row, $profileSellingPrices);
       $pBl += mikhmonReportNetProfit($row, $profileCosts, $profileSellingPrices);

      if($TotalRHr == ""){
        $TotalRHr = "0";
        $_SESSION[$session.'totalHr'] = "0";
      }else{
        $_SESSION[$session.'totalHr'] = $TotalRHr;
      }
      
    }
  }
}
?>

            <div id="r_4" class="row">
              <div <?= $lreport; ?> class="box bmh-75 box-bordered">
                <div class="box-group">
                  <div class="box-group-icon"><i class="fa fa-money"></i></div>
                    <div class="box-group-area">
                      <span >
                        <div id="reloadLreport">
                        <?php 
                          if ($currency == in_array($currency, $cekindo['indo'])) {
                            $dincome = number_format((float)$tHr, 0, ",", ".");
                            $mincome = number_format((float)$tBl, 0, ",", ".");
                            $dprofit = number_format((float)$pHr, 0, ",", ".");
                            $mprofit = number_format((float)$pBl, 0, ",", ".");
                            $_SESSION[$session.'dincome'] = $dincome;
                            $_SESSION[$session.'mincome'] = $mincome;
                            $_SESSION[$session.'dprofit'] = $dprofit;
                            $_SESSION[$session.'mprofit'] = $mprofit;
                          }else{
                            $dincome = number_format((float)$tHr, 2);
                            $mincome = number_format((float)$tBl, 2);
                            $dprofit = number_format((float)$pHr, 2);
                            $mprofit = number_format((float)$pBl, 2);
                            $_SESSION[$session.'dincome'] = $dincome;
                            $_SESSION[$session.'mincome'] = $mincome;
                            $_SESSION[$session.'dprofit'] = $dprofit;
                            $_SESSION[$session.'mprofit'] = $mprofit;
                          }
                            echo "<b>" . $_income . "</b><br/>" . "
                          ".$_today." " . $TotalRHr . " trx : " . $currency . " " . $dincome . "<br/>
                          ".$_this_month." " . $TotalRBl . " trx : " . $currency . " " . $mincome . "<hr style='margin:5px 0;border:0;border-top:1px solid currentColor;opacity:.35'><b>" . $_net_profit . "</b><br/>" . $_today . ": " . $currency . " " . $dprofit . "<br/>" . $_this_month . ": " . $currency . " " . $mprofit;
                          ?>
                        </div>
                    </span>
                </div>
              </div>
            </div>
            </div>
