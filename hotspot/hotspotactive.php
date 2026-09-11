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
	} else {
		$gethotspotactive = $API->comm("/ip/hotspot/active/print");
		$TotalReg = count($gethotspotactive);
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
	}

	// Build the client-side filter options from rows the current user may see.
	$activeServerOptions = array();
	$activeLoginByOptions = array();
	foreach ((array) $gethotspotactive as $activeRow) {
		$activeServerName = trim((string) ($activeRow['server'] ?? ''));
		$activeLoginBy = trim((string) ($activeRow['login-by'] ?? ''));
		if ($activeServerName !== '') $activeServerOptions[$activeServerName] = true;
		if ($activeLoginBy !== '') $activeLoginByOptions[$activeLoginBy] = true;
	}
	$activeServerOptions = array_keys($activeServerOptions);
	$activeLoginByOptions = array_keys($activeLoginByOptions);
	natcasesort($activeServerOptions);
	natcasesort($activeLoginByOptions);
	$activeTotal = count((array) $gethotspotactive);
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
					echo htmlspecialchars($serveractive, ENT_QUOTES) . " ";
				}
				echo '(<span id="hotspotActiveVisibleCount">' . $activeTotal . '</span> / ' . $activeTotal . ')';
				if ($serveractive == "") {
				} else {
					echo " | <a href='./?hotspot=active&amp;session=" . rawurlencode($session) . "'> <i class='fa fa-search'></i> " . htmlspecialchars($_show_all, ENT_QUOTES) . "</a>";
				}
				?>			</h3>
        </div>
         <div class="card-body">
<style>
  .hotspot-active-toolbar { display:flex; align-items:stretch; gap:8px; margin-bottom:10px; }
  .hotspot-active-toolbar .form-control { height:34px; min-height:34px; margin:0; box-sizing:border-box; }
  #hotspotActiveSearch { flex:1; min-width:220px; }
  #hotspotActiveServerFilter, #hotspotActiveLoginFilter { width:180px; }
  .hotspot-active-toolbar .btn { display:inline-flex; align-items:center; justify-content:center; gap:5px; min-height:34px; margin:0; white-space:nowrap; }
  @media(max-width:700px) {
    .hotspot-active-toolbar { flex-direction:column; }
    #hotspotActiveSearch, #hotspotActiveServerFilter, #hotspotActiveLoginFilter { width:100%; min-width:0; }
  }
</style>
<div class="hotspot-active-toolbar" role="search" aria-label="Pencarian dan filter hotspot aktif">
  <input id="hotspotActiveSearch" type="search" class="form-control" placeholder="<?= htmlspecialchars($_search, ENT_QUOTES); ?> user, IP, MAC, <?= strtolower(htmlspecialchars($_comment, ENT_QUOTES)); ?>..." aria-label="<?= htmlspecialchars($_search, ENT_QUOTES); ?> hotspot aktif" autocomplete="off">
  <select id="hotspotActiveServerFilter" class="form-control" aria-label="Filter server">
    <option value="all"><?= htmlspecialchars($_all, ENT_QUOTES); ?> Server</option>
    <?php foreach ($activeServerOptions as $activeServerOption): ?>
      <option value="<?= htmlspecialchars($activeServerOption, ENT_QUOTES); ?>"><?= htmlspecialchars($activeServerOption, ENT_QUOTES); ?></option>
    <?php endforeach; ?>
  </select>
  <select id="hotspotActiveLoginFilter" class="form-control" aria-label="Filter login by">
    <option value="all"><?= htmlspecialchars($_all, ENT_QUOTES); ?> Login By</option>
    <?php foreach ($activeLoginByOptions as $activeLoginOption): ?>
      <option value="<?= htmlspecialchars($activeLoginOption, ENT_QUOTES); ?>"><?= htmlspecialchars($activeLoginOption, ENT_QUOTES); ?></option>
    <?php endforeach; ?>
  </select>
  <button id="hotspotActiveResetFilter" type="button" class="btn bg-secondary" title="Reset filter"><i class="fa fa-refresh"></i> <?= htmlspecialchars($_show_all, ENT_QUOTES); ?></button>
</div>
<div class="overflow box-bordered" style="max-height:75vh">
<table id="hotspotActiveTable" class="table table-bordered table-hover text-nowrap">
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
	echo '<tr class="hotspot-active-row" data-server="' . htmlspecialchars($server, ENT_QUOTES) . '" data-login-by="' . htmlspecialchars($loginby, ENT_QUOTES) . '">';
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
  <tr id="hotspotActiveNoResults" style="display:none"><td colspan="11" class="text-center">Data hotspot aktif tidak ditemukan.</td></tr>
  </tbody>
</table>
</div>
</div>
</div>
</div>
</div>
</div>
<script>
(function($) {
  if (!$) return;

  var state = window.mikhmonHotspotActiveFilters || { search: '', server: 'all', loginBy: 'all' };
  window.mikhmonHotspotActiveFilters = state;

  var search = $('#hotspotActiveSearch');
  var serverFilter = $('#hotspotActiveServerFilter');
  var loginFilter = $('#hotspotActiveLoginFilter');

  search.val(state.search || '');
  serverFilter.val(state.server || 'all');
  loginFilter.val(state.loginBy || 'all');
  if (serverFilter.val() === null) serverFilter.val('all');
  if (loginFilter.val() === null) loginFilter.val('all');

  function applyHotspotActiveFilters() {
    state.search = String(search.val() || '').toLowerCase().trim();
    state.server = serverFilter.val() || 'all';
    state.loginBy = loginFilter.val() || 'all';

    var visible = 0;
    $('#hotspotActiveTable .hotspot-active-row').each(function() {
      var row = $(this);
      var matchesSearch = state.search === '' || row.text().toLowerCase().indexOf(state.search) !== -1;
      var matchesServer = state.server === 'all' || row.attr('data-server') === state.server;
      var matchesLogin = state.loginBy === 'all' || row.attr('data-login-by') === state.loginBy;
      var show = matchesSearch && matchesServer && matchesLogin;
      row.toggle(show);
      if (show) visible++;
    });

    $('#hotspotActiveVisibleCount').text(visible);
    $('#hotspotActiveNoResults').toggle(visible === 0);
  }

  search.off('.hotspotActive').on('input.hotspotActive', applyHotspotActiveFilters);
  serverFilter.off('.hotspotActive').on('change.hotspotActive', applyHotspotActiveFilters);
  loginFilter.off('.hotspotActive').on('change.hotspotActive', applyHotspotActiveFilters);
  $('#hotspotActiveResetFilter').off('.hotspotActive').on('click.hotspotActive', function() {
    search.val('');
    serverFilter.val('all');
    loginFilter.val('all');
    applyHotspotActiveFilters();
    search.focus();
  });

  applyHotspotActiveFilters();
})(window.jQuery);
</script>
