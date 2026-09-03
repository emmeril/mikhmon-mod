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
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
	header("Location:../admin.php?id=login");
} else {

// load session MikroTik
	$session = $_GET['session'];
	$serveractive = $_GET['server'];

// load config
	include('../include/config.php');
	include_once('../include/access.php');
	include('../include/readcfg.php');
	
// lang
  include('../include/lang.php');
  include('../lang/'.$langid.'.php');

// routeros api
	include_once('../lib/routeros_api.class.php');
	include_once('../lib/formatbytesbites.php');
	$API = new RouterosAPI();
	$API->debug = false;
	$API->connect($iphost, $userhost, decrypt($passwdhost));

	if ($serveractive != "") {
		$gethotspotactive = $API->comm("/ip/hotspot/active/print", array("?server" => "" . $serveractive . ""));
		$TotalReg = count($gethotspotactive);

		$counthotspotactive = $API->comm("/ip/hotspot/active/print", array(
			"count-only" => "", "?server" => "" . $serveractive . ""
		));

	} else {
		$gethotspotactive = $API->comm("/ip/hotspot/active/print");
		$TotalReg = count($gethotspotactive);

		$counthotspotactive = $API->comm("/ip/hotspot/active/print", array(
			"count-only" => "",
		));
	}
	if (mikhmonIsMitra()) {
		$mitraUsers = array();
		foreach (mikhmonMitraUsernames($session) as $assignedUsername => $unused) $mitraUsers[(string) $assignedUsername] = true;
		foreach ((array) $API->comm('/ip/hotspot/user/print') as $mitraUser) {
			if (isset($mitraUser['name']) && mikhmonRowBelongsToCurrentMitra($mitraUser)) $mitraUsers[(string) $mitraUser['name']] = true;
		}
		$gethotspotactive = array_values(array_filter((array) $gethotspotactive, function ($active) use ($mitraUsers) {
			return isset($active['user']) && isset($mitraUsers[(string) $active['user']]);
		}));
		$TotalReg = count($gethotspotactive);
		$counthotspotactive = $TotalReg;
	}
}
?>
<div class="row">
<div id="reloadHotspotActive">
<div class="col-12">
	<div class="card">
		<div class="card-header">
    		<h3><i class="fa fa-wifi"></i> <?= $_hotspot_active ?> <?php
				if ($serveractive == "") {
				} else {
					echo $serveractive . " ";
				}
				if ($counthotspotactive < 2) {
					echo "$counthotspotactive item";
				} elseif ($counthotspotactive > 1) {
					echo "$counthotspotactive items";
				};
				if ($serveractive == "") {
				} else {
					echo " | <a href='./?hotspot=active&session=" . $session . "'> <i class='fa fa-search'></i> Show all</a>";
				}
				?>			</h3>
        </div>
         <div class="card-body overflow">
<table id="tFilter" class="table table-bordered table-hover text-nowrap">
  <thead>
  <tr>
    <th></th>
    <th>Server</th>
    <th>User</th>
    <th>Address</th>
    <th>Mac Address</th>
    <th class="text-right">Uptime</th>
    <th class="text-right">Bytes In</th>
    <th class="text-right">Bytes Out</th>
    <th class="text-right">Time Left</th>
    <th>Login By</th>
    <th><?= $_comment ?></th>
  </tr>
  </thead>
  <tbody>
<?php
for ($i = 0; $i < $TotalReg; $i++) {
	$hotspotactive = $gethotspotactive[$i];
	$id = $hotspotactive['.id'];
	$server = $hotspotactive['server'];
	$user = $hotspotactive['user'];
	$address = $hotspotactive['address'];
	$mac = $hotspotactive['mac-address'];
	$uptime = formatDTM($hotspotactive['uptime']);
	$usesstime = formatDTM($hotspotactive['session-time-left']);
	$bytesi = formatBytes($hotspotactive['bytes-in'], 2);
	$byteso = formatBytes($hotspotactive['bytes-out'], 2);
	$loginby = $hotspotactive['login-by'];
	$comment = $hotspotactive['comment'];
	$uriprocess = "'./?remove-user-active=" . $id . "&session=" . $session . "'";
	echo "<tr>";
	echo "<td style='text-align:center;'><span class='pointer' title='Remove " . htmlspecialchars($user, ENT_QUOTES) . "' onclick=loadpage(".$uriprocess.")><i class='fa fa-minus-square text-danger'></i></span></td>";
	echo "<td><a title='filter " . htmlspecialchars($server, ENT_QUOTES) . "' href='./?hotspot=active&server=" . rawurlencode($server) . "&session=" . rawurlencode($session) . "'><i class='fa fa-server'></i> " . htmlspecialchars($server, ENT_QUOTES) . "</a></td>";
	echo "<td><a title='Open User " . htmlspecialchars($user, ENT_QUOTES) . "' href='./?hotspot-user=" . rawurlencode($user) . "&session=" . rawurlencode($session) . "'><i class='fa fa-edit'></i> " . htmlspecialchars($user, ENT_QUOTES) . "</a></td>";
	echo "<td>" . htmlspecialchars($address, ENT_QUOTES) . "</td>";
	echo "<td>" . htmlspecialchars($mac, ENT_QUOTES) . "</td>";
	echo "<td style='text-align:right;'>" . $uptime . "</td>";
	echo "<td style='text-align:right;'>" . $bytesi . "</td>";
	echo "<td style='text-align:right;'>" . $byteso . "</td>";
	echo "<td style='text-align:right;'>" . $usesstime . "</td>";
	echo "<td>" . htmlspecialchars($loginby, ENT_QUOTES) . "</td>";
	echo "<td>" . htmlspecialchars($comment, ENT_QUOTES) . "</td>";
	echo "</tr>";
}
?>
  </tbody>
</table>
</div>
</div>
</div>
</div>
</div>
