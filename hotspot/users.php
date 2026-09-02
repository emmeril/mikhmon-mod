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

include_once(__DIR__ . '/../lib/billing_profile.php');

// hide all error
error_reporting(0);
ini_set('max_execution_time', 300);

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  if ($prof == "all") {
    $getuser = $API->comm("/ip/hotspot/user/print", array('.proplist' => 'server,name,password,profile,mac-address,uptime,bytes-in,bytes-out,comment,disabled,limit-uptime,limit-bytes-total'));
    $TotalReg = count($getuser);

  } elseif ($prof != "all") {
    $getuser = $API->comm("/ip/hotspot/user/print", array(
      '.proplist' => 'server,name,password,profile,mac-address,uptime,bytes-in,bytes-out,comment,disabled,limit-uptime,limit-bytes-total',
      "?profile" => "$prof",
    ));
    $TotalReg = count($getuser);

  }
  if ($comm != "") {
    $getuser = $API->comm("/ip/hotspot/user/print", array(
      '.proplist' => 'server,name,password,profile,mac-address,uptime,bytes-in,bytes-out,comment,disabled,limit-uptime,limit-bytes-total',
      "?comment" => "$comm",
    //"?uptime" => "00:00:00"
    ));
    $TotalReg = count($getuser);

  }
  $exp = $_GET['exp'];
  if ($exp != "") {
    $getuser = $API->comm("/ip/hotspot/user/print", array(
      '.proplist' => 'server,name,password,profile,mac-address,uptime,bytes-in,bytes-out,comment,disabled,limit-uptime,limit-bytes-total',
      "?limit-uptime" => "1s",
    ));
    
  }
  $allProfiles = $API->comm("/ip/hotspot/user/profile/print");
  $getprofile = array_values(array_filter((array) $allProfiles, function ($profileRow) {
    return isset($profileRow['name']) && mikhmonBillingProfileExpiredMode('hotspot', $profileRow) !== 'none';
  }));
  $voucherProfiles = array();
  foreach ($getprofile as $profileRow) {
    $voucherProfiles[(string) $profileRow['name']] = true;
  }
  $getuser = array_values(array_filter((array) $getuser, function ($hotspotUser) use ($voucherProfiles) {
    return isset($hotspotUser['profile']) && isset($voucherProfiles[(string) $hotspotUser['profile']]);
  }));
  $TotalReg = count($getuser);
  $counttuser = $TotalReg;

  if (function_exists('mikhmonIsMitra') && mikhmonIsMitra()) {
    $assignedUsernames = function_exists('mikhmonMitraUsernames') ? mikhmonMitraUsernames($session) : array();
    $getuser = array_values(array_filter((array) $getuser, function ($hotspotUser) use ($assignedUsernames) {
      return mikhmonRowBelongsToCurrentMitra($hotspotUser) || (isset($hotspotUser['name']) && isset($assignedUsernames[(string) $hotspotUser['name']]));
    }));
    $TotalReg = count($getuser);
    $counttuser = $TotalReg;
  }
  $TotalReg2 = count($getprofile);
}
?>

<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header">
    <h3><i class="fa fa-ticket"></i> Vouchers
      <span style="font-size: 14px">
        <?php
        if ($counttuser == 0 && $prof != "all" && !(function_exists('mikhmonIsMitra') && mikhmonIsMitra())) {
          echo "<script>window.location='./?hotspot=users&profile=all&session=" . $session . "</script>";
        } ?>
         <?php if (!(function_exists('mikhmonIsMitra') && mikhmonIsMitra())): ?>&nbsp; | &nbsp; <a href="./?hotspot-user=add&session=<?= $session; ?>" title="Add User"><i class="fa fa-user-plus"></i> <?= $_add ?></a><?php endif; ?>
        &nbsp; | &nbsp; <a href="./?hotspot-user=generate&session=<?= $session; ?>" title="Generate User"><i class="fa fa-users"></i> <?= $_generate ?></a>
         &nbsp; | &nbsp; <a href="<?= str_replace("=users", "=export-users", $url); ?>&export=script" title="Download User List as Mikrotik Script"><i class="fa fa-download"></i> Script</a>&nbsp; | &nbsp; <a href="<?= str_replace("=users", "=export-users", $url); ?>&export=csv" title="Download User List as CSV"><i class="fa fa-download"></i> CSV</a>
        </span>  &nbsp;
        <small id="loader" style="display: none;" ><i><i class='fa fa-circle-o-notch fa-spin'></i> <?= $_processing ?> </i></small>
    </h3>
    
</div>
<div class="card-body">
  <div class="row">
   <div class="col-6 pd-t-5 pd-b-5">
  <div class="input-group">
    <div class="input-group-4 col-box-4">
      <input id="filterTable" type="text" style="padding:5.8px;" class="group-item group-item-l" placeholder="<?= $_search ?>">
    </div>
    <div class="input-group-4 col-box-4">
      <select style="padding:5px;" class="group-item group-item-m" onchange="location = this.value; loader()" title="Filter by Profile">
        <option><?= $_profile ?> </option>
        <option value="./?hotspot=users&profile=all&session=<?= $session; ?>"><?= $_show_all ?></option>
      <?php
      for ($i = 0; $i < $TotalReg2; $i++) {
        $profile = $getprofile[$i];
        echo "<option value='./?hotspot=users&profile=" . $profile['name'] . "&session=" . $session . "'>" . $profile['name'] . "</option>";
      }
      ?>
    </select>
  </div>
  <div class="input-group-4 col-box-4">
    <select style="padding:5px;" class="group-item group-item-r" id="comment" name="comment" onchange="location = './?hotspot=users&comment='+ this.value +'&session=<?= $session;?>';">
    <?php
    if ($comm != "") {
    } else {
      echo "<option value=''>".$_comment."</option>";
    }
    $TotalReg = count($getuser);
    for ($i = 0; $i < $TotalReg; $i++) {
      $ucomment = $getuser[$i]['comment'];
      $uprofile = $getuser[$i]['profile'];
      $acomment .= ",".$ucomment."#". $uprofile;
    }

    $ocomment=  explode(",",$acomment);
    
    $comments=array_count_values($ocomment) ;
    foreach ($comments as $tcomment=>$value) {

      if (is_numeric(substr($tcomment, 3, 3))) {
       
        echo "<option value='" . explode("#",$tcomment)[0] . "' >". explode("#",$tcomment)[0]." ".explode("#",$tcomment)[1]. " [".$value. "]</option>";
       }
 
    }

    ?>
    </select>
  </div>
  </div>
  </div>
 
  <div class="col-6">
    <?php if ($comm != "") { ?>
  <button class="btn bg-red" onclick="if(confirm('Are you sure to delete username by comment (<?= $comm; ?>)?')){loadpage('./?remove-hotspot-user-by-comment=<?= $comm; ?>&session=<?= $session; ?>');loader();}else{}" title="Remove user by comment <?= $comm; ?>">  <i class="fa fa-trash"></i> <?= $_by_comment ?></button>
    <?php ; }else if ($exp == "1"){ ?>
  <button class="btn bg-red" onclick="if(confirm('Are you sure to delete users?')){loadpage('./?remove-hotspot-user-expired=1&session=<?= $session; ?>');loader();}else{}" title="Remove user expired">  <i class="fa fa-trash"></i> Expired Users</button>
      <?php } ?>
  <span><i class="fa fa-print"></i> Cetak Semua:</span>
  <button type="button" class="btn bg-primary" onclick="voucherListPrint('default')" title="Cetak semua voucher - <?= htmlspecialchars($_print_default, ENT_QUOTES); ?>"><i class="fa fa-print"></i> <?= $_print_default; ?></button>
  <button type="button" class="btn bg-info" onclick="voucherListPrint('qr')" title="Cetak semua voucher - <?= htmlspecialchars($_print_qr, ENT_QUOTES); ?>"><i class="fa fa-qrcode"></i> <?= $_print_qr; ?></button>
  <button type="button" class="btn bg-warning" onclick="voucherListPrint('small')" title="Cetak semua voucher - <?= htmlspecialchars($_print_small, ENT_QUOTES); ?>"><i class="fa fa-print"></i> <?= $_print_small; ?></button>
</div>
  </div>
<div class="overflow mr-t-10 box-bordered" style="max-height: 75vh">
<style>
  #dataTable .voucher-password-cell { min-width:105px; text-align:center; font-weight:bold; cursor:pointer; user-select:none; }
  #dataTable .voucher-password-cell i { margin-left:5px; color:#888; }
</style>
<table id="dataTable" class="table table-bordered table-hover text-nowrap">
  <thead>
  <tr>
    <th style="min-width:50px;" class="align-middle text-center" id="cuser"><?= $counttuser; ?></th>
    <th style="min-width:50px;" class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Server</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_name ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_password ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_profile ?></th>
	  <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Mac Address</th>
    <th class="text-right align-middle pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_uptime_user ?></th>
    <th class="text-right align-middle pointer" title="Click to sort"><i class="fa fa-sort"></i> Bytes In</th>
    <th class="text-right align-middle pointer" title="Click to sort"><i class="fa fa-sort"></i> Bytes Out</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_comment ?></th>
    </tr>
  </thead>
  <tbody id="tbody">
<?php
for ($i = 0; $i < $TotalReg; $i++) {
  $userdetails = $getuser[$i];
  $uid = $userdetails['.id'];
  $userver = $userdetails['server'];
  $uname = $userdetails['name'];
  $upassword = isset($userdetails['password']) ? (string) $userdetails['password'] : '';
  $uprofile = $userdetails['profile'];
  $umacadd = $userdetails['mac-address'];
  $uuptime = formatDTM($userdetails['uptime']);
  $ubytesi = formatBytes($userdetails['bytes-in'], 2);
  $ubyteso = formatBytes($userdetails['bytes-out'], 2);

  $ucomment = $userdetails['comment'];
  $udisabled = $userdetails['disabled'];
  $utimelimit = $userdetails['limit-uptime'];
  if ($utimelimit == '1s') {
    $utimelimit = ' expired';
  } else {
    $utimelimit = ' ' . $utimelimit;
  }
  $udatalimit = $userdetails['limit-bytes-total'];
  if ($udatalimit == '') {
    $udatalimit = '';
  } else {
    $udatalimit = ' ' . formatBytes($udatalimit, 2);
  }

  echo "<tr data-voucher-name=\"" . htmlspecialchars($uname, ENT_QUOTES) . "\">";
  ?>
  <td style='text-align:center;'>  <i class='fa fa-minus-square text-danger pointer' onclick="if(confirm('Are you sure to delete username (<?= $uname; ?>)?')){loadpage('./?remove-hotspot-user=<?= $uid; ?>&session=<?= $session; ?>')}else{}" title='Remove <?= $uname; ?>'></i>&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp
  <?php
  if ($udisabled == "true") {
    $uriprocess = "'./?enable-hotspot-user=" . $uid . "&session=" . $session."'";
    echo '<span class="text-warning pointer" title="Enable User ' . $uname . '"  onclick="loadpage('.$uriprocess.')"><i class="fa fa-lock "></i></span></td>';
  } else {
    $uriprocess = "'./?disable-hotspot-user=" . $uid . "&session=" . $session."'";
    echo '<span class="pointer" title="Disable User ' . $uname . '"  onclick="loadpage('.$uriprocess.')"><i class="fa fa-unlock "></i></span></td>';
  }
  echo "<td>" . $userver . "</td>";
  echo "<td><a title='Open User " . $uname . "' href=./?hotspot-user=" . $uid . "&session=" . $session . "><i class='fa fa-edit'></i> " . $uname . " </a>";
  echo '</td>';
  ?>
  <td class="voucher-password-cell" data-password="<?= htmlspecialchars($upassword, ENT_QUOTES); ?>" data-pinned="false" role="button" tabindex="0" aria-label="Tampilkan password" aria-pressed="false" title="Arahkan kursor atau klik untuk melihat password"><span class="voucher-password-value">******</span><i class="fa fa-eye"></i></td>
  <?php
  echo "<td>" . $uprofile . "</td>";
  echo "<td style=' text-align:left'>" . $umacadd . "</td>";
  echo "<td style=' text-align:right'>" . $uuptime . "</td>";
  echo "<td style=' text-align:right'>" . $ubytesi . "</td>";
  echo "<td style=' text-align:right'>" . $ubyteso . "</td>";
  echo "<td>";
  if ($uname == "default-trial") {
  } else if (substr($ucomment,0,3) == "vc-" || substr($ucomment,0,3) == "up-") {
    echo "<a href=./?hotspot=users&comment=" . $ucomment . "&session=" . $session . " title='Filter by " . $ucomment . "'><i class='fa fa-search'></i> ". $ucomment." ". $udatalimit ." ".$utimelimit . "</a>";
  } else if ($utimelimit == ' expired') {
    echo "<a href=./?hotspot=users&profile=all&exp=1&session=" . $session . " title='Filter by expired'><i class='fa fa-search'></i> " . $ucomment." ". $udatalimit ." ".$utimelimit . "</a>";
  }else{
    echo $ucomment.' ';
  }
  echo  "</td>";
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

<script>
$(function() {
  // Print every voucher currently visible after the active filters.
  window.voucherListPrint = function(format) {
    var users = Array.prototype.slice.call(document.querySelectorAll('#dataTable tbody tr'))
      .filter(function(row) { return row.style.display !== 'none'; })
      .map(function(row) { return row.getAttribute('data-voucher-name') || ''; })
      .filter(function(name) { return name !== ''; });
    if (!users.length) {
      alert('Tidak ada voucher untuk dicetak.');
      return;
    }
    var form = document.createElement('form');
    form.method = 'post';
    form.action = './voucher/print.php';
    form.target = '_blank';
    [
      ['session', <?= json_encode($session); ?>],
      ['qr', format === 'qr' ? 'yes' : 'no'],
      ['small', format === 'small' ? 'yes' : 'no'],
      ['users_json', JSON.stringify(users)]
    ].forEach(function(field) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = field[0];
      input.value = field[1];
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
    form.remove();
  };

  function setVoucherPasswordVisibility(cell, visible) {
    var password = cell.attr('data-password') || '';
    cell.find('.voucher-password-value').text(visible ? (password || '-') : '******');
    cell.find('i').toggleClass('fa-eye', !visible).toggleClass('fa-eye-slash', visible);
  }

  $('.voucher-password-cell')
    .on('mouseenter focus', function() { setVoucherPasswordVisibility($(this), true); })
    .on('mouseleave', function() { if ($(this).attr('data-pinned') !== 'true') setVoucherPasswordVisibility($(this), false); })
    .on('blur', function() { $(this).attr('data-pinned', 'false').attr('aria-pressed', 'false'); setVoucherPasswordVisibility($(this), false); })
    .on('click', function() {
      var cell = $(this), pinned = cell.attr('data-pinned') !== 'true';
      cell.attr('data-pinned', pinned ? 'true' : 'false').attr('aria-pressed', pinned ? 'true' : 'false');
      setVoucherPasswordVisibility(cell, pinned);
    })
    .on('keydown', function(event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        $(this).trigger('click');
      }
    });
});
</script>
