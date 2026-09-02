<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) { header("Location:../admin.php?id=login"); exit; }

$secrets = $API->comm('/ppp/secret/print');
$profiles = $API->comm('/ppp/profile/print');
$filter = isset($_GET['profile']) ? $_GET['profile'] : '';
if ($filter !== '') {
  $secrets = $API->comm('/ppp/secret/print', array('?profile' => $filter));
}
$secrets = is_array($secrets) ? $secrets : array();
$profiles = is_array($profiles) ? $profiles : array();
if (function_exists('mikhmonIsMitra') && mikhmonIsMitra()) {
  $mitraPppUsernames = mikhmonMitraUsernamesByService($session, 'pppoe');
  $secrets = array_values(array_filter($secrets, function ($secret) use ($mitraPppUsernames) {
    return isset($secret['name']) && isset($mitraPppUsernames[(string) $secret['name']]);
  }));
}
$count = count($secrets);
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-users"></i> <?= $_ppp_secrets ?>
    <?php if (!(function_exists('mikhmonIsMitra') && mikhmonIsMitra())): ?><span style="font-size:14px"> &nbsp;|&nbsp; <a href="./?ppp=addsecret&session=<?= $session ?>"><i class="fa fa-user-plus"></i> <?= $_add ?></a></span><?php endif; ?>
    <small id="loader" style="display:none"><i class="fa fa-circle-o-notch fa-spin"></i> <?= $_processing ?></small>
  </h3></div>
  <div class="card-body">
    <div class="row">
      <div class="col-6 pd-t-5 pd-b-5"><div class="input-group">
        <div class="input-group-4 col-box-4"><input id="pppSearch" type="text" style="padding:5.8px" class="group-item group-item-l" placeholder="<?= $_search ?>"></div>
        <div class="input-group-4 col-box-4"><select id="pppProfile" class="group-item group-item-m" onchange="location=this.value;loader()" title="Filter by Profile">
          <option value="./?ppp=secrets&session=<?= $session ?>"><?= $_profile ?>: <?= $filter !== '' ? htmlspecialchars($filter, ENT_QUOTES) : $_show_all; ?></option>
          <option value="./?ppp=secrets&session=<?= $session ?>"><?= $_show_all ?></option>
          <?php foreach ($profiles as $profile): ?><?php if (!isset($profile['name'])) continue; ?><option value="./?ppp=secrets&profile=<?= rawurlencode($profile['name']); ?>&session=<?= $session; ?>"<?= $filter === $profile['name'] ? ' selected' : ''; ?>><?= htmlspecialchars($profile['name']); ?></option><?php endforeach; ?>
        </select></div>
        <div class="input-group-4 col-box-4"><select id="pppStatus" class="group-item group-item-r" title="Filter Status"><option value="all">Status: Semua</option><option value="enabled">Enabled</option><option value="disabled">Disabled</option></select></div>
      </div></div>
      <div class="col-6 text-right"><button id="pppReset" type="button" class="btn bg-secondary"><i class="fa fa-refresh"></i> Reset Filter</button></div>
    </div>
    <style>
      #dataTable .ppp-password-cell { min-width:105px; text-align:center; font-weight:bold; cursor:pointer; user-select:none; }
      #dataTable .ppp-password-cell i { margin-left:5px; color:#888; }
    </style>
    <div class="overflow mr-t-10 box-bordered" style="max-height:75vh"><table id="dataTable" class="table table-bordered table-hover text-nowrap">
      <thead><tr><th id="pppVisibleCount" class="text-center"><?= $count ?></th><th><?= $_name ?></th><th><?= $_password ?></th><th><?= $_profile ?></th><th>Service</th><th>Caller ID</th><th>Status</th><th><?= $_action ?></th></tr></thead><tbody>
      <?php foreach ($secrets as $secret): $id = $secret['.id']; $name = $secret['name']; $disabled = ($secret['disabled'] === 'true' || $secret['disabled'] === 'yes'); ?>
      <tr class="ppp-secret-row" data-status="<?= $disabled ? 'disabled' : 'enabled'; ?>"><td class="text-center"><?php if (!(function_exists('mikhmonIsMitra') && mikhmonIsMitra())): ?><i class="fa fa-minus-square text-danger pointer" title="<?= $_delete ?>" onclick="if(confirm('Delete <?= htmlspecialchars(addslashes($name)) ?>?')){loadpage('./?remove-pppsecret=<?= rawurlencode($id) ?>&session=<?= $session ?>');loader()}"></i><?php else: ?><i class="fa fa-user"></i><?php endif; ?></td>
        <td><?php if (!(function_exists('mikhmonIsMitra') && mikhmonIsMitra())): ?><a href="./?secret=<?= rawurlencode($name) ?>&session=<?= $session ?>"><i class="fa fa-edit"></i> <?= htmlspecialchars($name) ?></a><?php else: ?><?= htmlspecialchars($name) ?><?php endif; ?></td>
        <td class="ppp-password-cell" data-password="<?= htmlspecialchars(isset($secret['password']) ? $secret['password'] : '', ENT_QUOTES); ?>" data-pinned="false" role="button" tabindex="0" aria-label="Tampilkan password" aria-pressed="false" title="Arahkan kursor atau klik untuk melihat password"><span class="ppp-password-value">******</span><i class="fa fa-eye"></i></td><td><?= htmlspecialchars(isset($secret['profile']) ? $secret['profile'] : '') ?></td><td><?= htmlspecialchars(isset($secret['service']) ? $secret['service'] : 'any') ?></td><td><?= htmlspecialchars(isset($secret['caller-id']) ? $secret['caller-id'] : '') ?></td>
        <td><?php if ($disabled): ?><span class="text-red">Disabled</span><?php else: ?><span class="text-green">Enabled</span><?php endif; ?></td>
        <td><?php if (function_exists('mikhmonIsMitra') && mikhmonIsMitra()): ?>- <?php elseif ($disabled): ?><a href="./?enable-pppsecret=<?= rawurlencode($id) ?>&session=<?= $session ?>"><i class="fa fa-unlock text-green"></i></a><?php else: ?><a href="./?disable-pppsecret=<?= rawurlencode($id) ?>&session=<?= $session ?>"><i class="fa fa-lock text-orange"></i></a><?php endif; ?></td>
      </tr><?php endforeach; ?>
      <tr id="pppNoResults" style="display:none"><td colspan="8" class="text-center">Data PPP Secret tidak ditemukan.</td></tr>
      </tbody></table></div>
  </div>
</div></div></div>
<script>
$(function() {
  function setPppPasswordVisibility(cell, visible) {
    var password = cell.attr('data-password') || '';
    cell.find('.ppp-password-value').text(visible ? (password || '-') : '******');
    cell.find('i').toggleClass('fa-eye', !visible).toggleClass('fa-eye-slash', visible);
  }

  $('.ppp-password-cell')
    .on('mouseenter focus', function() { setPppPasswordVisibility($(this), true); })
    .on('mouseleave', function() { if ($(this).attr('data-pinned') !== 'true') setPppPasswordVisibility($(this), false); })
    .on('blur', function() { $(this).attr('data-pinned', 'false').attr('aria-pressed', 'false'); setPppPasswordVisibility($(this), false); })
    .on('click', function() {
      var cell = $(this), pinned = cell.attr('data-pinned') !== 'true';
      cell.attr('data-pinned', pinned ? 'true' : 'false').attr('aria-pressed', pinned ? 'true' : 'false');
      setPppPasswordVisibility(cell, pinned);
    })
    .on('keydown', function(event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        $(this).trigger('click');
      }
    });

  function filterPppSecrets() {
    var search = $('#pppSearch').val().toLowerCase();
    var status = $('#pppStatus').val();
    var visible = 0;
    $('.ppp-secret-row').each(function() {
      var row = $(this);
      var searchableText = row.text().toLowerCase() + ' ' + String(row.find('.ppp-password-cell').attr('data-password') || '').toLowerCase();
      var matchesSearch = searchableText.indexOf(search) > -1;
      var matchesStatus = status === 'all' || row.data('status') === status;
      var show = matchesSearch && matchesStatus;
      row.toggle(show);
      if (show) visible++;
    });
    $('#pppVisibleCount').text(visible);
    $('#pppNoResults').toggle(visible === 0);
  }
  $('#pppSearch').on('input', filterPppSecrets);
  $('#pppStatus').on('change', filterPppSecrets);
  $('#pppReset').on('click', function() {
    if (<?= $filter !== '' ? 'true' : 'false'; ?>) {
      location = './?ppp=secrets&session=<?= $session; ?>';
      loader();
      return;
    }
    $('#pppSearch').val('');
    $('#pppStatus').val('all');
    filterPppSecrets();
  });
  filterPppSecrets();
});
</script>
