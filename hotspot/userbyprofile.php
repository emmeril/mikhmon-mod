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
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU General Public License for more details.
 */

/* Voucher counts grouped by hotspot profile. */
error_reporting(0);
include_once(__DIR__ . '/../lib/voucher_summary.php');

if (!isset($_SESSION['mikhmon'])) {
  header('Location:../admin.php?id=login');
  exit;
}

$profileRows = $API->comm('/ip/hotspot/user/profile/print');
$userRows = $API->comm('/ip/hotspot/user/print');
$profileRows = is_array($profileRows) ? $profileRows : array();
$userRows = is_array($userRows) ? $userRows : array();
$recordRows = array();
foreach ($profileRows as $profile) {
  if (isset($profile['name']) && in_array(mikhmonBillingProfileExpiredMode('hotspot', $profile), array('remc', 'ntfc'), true)) {
    $recordRows = $API->comm('/system/script/print');
    break;
  }
}
$recordRows = is_array($recordRows) ? $recordRows : array();

if (function_exists('mikhmonIsMitra') && mikhmonIsMitra()) {
  $assignedUsernames = function_exists('mikhmonMitraUsernames') ? mikhmonMitraUsernames($session) : array();
  $userRows = array_values(array_filter($userRows, function ($user) use ($assignedUsernames) {
    return (function_exists('mikhmonRowBelongsToCurrentMitra') && mikhmonRowBelongsToCurrentMitra($user))
      || (isset($user['name']) && isset($assignedUsernames[(string) $user['name']]));
  }));
  $recordRows = array_values(array_filter($recordRows, function ($record) use ($assignedUsernames) {
    if (function_exists('mikhmonRowBelongsToCurrentMitra') && mikhmonRowBelongsToCurrentMitra($record)) return true;
    $parts = mikhmonReportParts($record);
    return isset($parts[2]) && isset($assignedUsernames[trim((string) $parts[2])]);
  }));
}

$stats = mikhmonVoucherSummary($profileRows, $userRows, $recordRows);
if (function_exists('mikhmonIsMitra') && mikhmonIsMitra()) $stats = array_filter($stats, function ($row) { return $row['total'] > 0; });

?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-bar-chart"></i> Jumlah Voucher <small>&nbsp;|&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer" title="Reload data"></i></small></h3></div>
  <div class="card-body">
    <div class="overflow box-bordered" style="max-height:75vh"><table id="voucherProfileSummary" class="table table-bordered table-hover text-nowrap">
      <thead><tr><th>No</th><th>Profile</th><th>Mode</th><th>Total Voucher</th><th>Belum Dipakai</th><th>Sedang Dipakai</th><th>Expired / Terhapus</th><th>Aksi</th></tr></thead><tbody>
      <?php $index = 0; foreach ($stats as $profileName => $row): $index++; $profile = $row['profile']; $profileMode = mikhmonBillingProfileExpiredMode('hotspot', $profile); ?>
        <tr><td class="text-center"><?= $index; ?></td><td><?= htmlspecialchars($profileName, ENT_QUOTES); ?></td><td><?= htmlspecialchars(mikhmonVoucherProfileModeLabel($row['mode']), ENT_QUOTES); ?></td><td class="text-center"><?= $row['total']; ?></td><td class="text-center text-success"><strong><?= $row['unused']; ?></strong></td><td class="text-center"><?= $row['used']; ?></td><td class="text-center text-danger"><?= $row['expired_known'] ? $row['expired'] : '-'; ?></td><td><?php if ($profileMode !== 'none'): ?><a class="btn bg-primary" href="./?hotspot-user=generate&genprof=<?= rawurlencode($profileName); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-ticket"></i> Generate</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$stats): ?><tr><td colspan="8" class="text-center">Belum ada voucher yang dapat ditampilkan.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div>
</div></div></div>
<style>
  #voucherProfileSummary th,#voucherProfileSummary td{text-align:center;vertical-align:middle}
  #voucherProfileSummary th:nth-child(2),#voucherProfileSummary td:nth-child(2){text-align:left}
  @media(max-width:750px){.col-3{width:50%}}
</style>
