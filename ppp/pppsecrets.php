<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) { header("Location:../admin.php?id=login"); exit; }

$secrets = $API->comm('/ppp/secret/print');
$profiles = $API->comm('/ppp/profile/print');
$filter = isset($_GET['profile']) ? $_GET['profile'] : '';
if ($filter !== '') {
  $secrets = $API->comm('/ppp/secret/print', array('?profile' => $filter));
}
$count = count($secrets);
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-users"></i> <?= $_ppp_secrets ?>
    <span style="font-size:14px"> &nbsp;|&nbsp; <a href="./?ppp=addsecret&session=<?= $session ?>"><i class="fa fa-user-plus"></i> <?= $_add ?></a></span>
    <small id="loader" style="display:none"><i class="fa fa-circle-o-notch fa-spin"></i> <?= $_processing ?></small>
  </h3></div>
  <div class="card-body">
    <div class="row"><div class="col-6 pd-t-5 pd-b-5"><div class="input-group">
      <input id="filterTable" type="text" style="padding:5.8px" class="group-item group-item-l" placeholder="<?= $_search ?>">
      <select class="group-item group-item-r" onchange="location=this.value;loader()"><option><?= $_profile ?></option><option value="./?ppp=secrets&session=<?= $session ?>"> <?= $_show_all ?></option>
      <?php foreach ($profiles as $profile) echo '<option value="./?ppp=secrets&profile='.rawurlencode($profile['name']).'&session='.$session.'">'.htmlspecialchars($profile['name']).'</option>'; ?>
      </select>
    </div></div></div>
    <div class="overflow mr-t-10 box-bordered" style="max-height:75vh"><table id="dataTable" class="table table-bordered table-hover text-nowrap">
      <thead><tr><th class="text-center"><?= $count ?></th><th><?= $_name ?></th><th><?= $_password ?></th><th><?= $_profile ?></th><th>Service</th><th>Caller ID</th><th>Status</th><th><?= $_action ?></th></tr></thead><tbody>
      <?php foreach ($secrets as $secret): $id = $secret['.id']; $name = $secret['name']; $disabled = ($secret['disabled'] === 'true' || $secret['disabled'] === 'yes'); ?>
      <tr><td class="text-center"><i class="fa fa-minus-square text-danger pointer" title="<?= $_delete ?>" onclick="if(confirm('Delete <?= htmlspecialchars(addslashes($name)) ?>?')){loadpage('./?remove-pppsecret=<?= rawurlencode($id) ?>&session=<?= $session ?>');loader()}"></i></td>
        <td><a href="./?secret=<?= rawurlencode($name) ?>&session=<?= $session ?>"><i class="fa fa-edit"></i> <?= htmlspecialchars($name) ?></a></td>
        <td><?= htmlspecialchars(isset($secret['password']) ? $secret['password'] : '') ?></td><td><?= htmlspecialchars(isset($secret['profile']) ? $secret['profile'] : '') ?></td><td><?= htmlspecialchars(isset($secret['service']) ? $secret['service'] : 'any') ?></td><td><?= htmlspecialchars(isset($secret['caller-id']) ? $secret['caller-id'] : '') ?></td>
        <td><?php if ($disabled): ?><span class="text-red">Disabled</span><?php else: ?><span class="text-green">Enabled</span><?php endif; ?></td>
        <td><?php if ($disabled): ?><a href="./?enable-pppsecret=<?= rawurlencode($id) ?>&session=<?= $session ?>"><i class="fa fa-unlock text-green"></i></a><?php else: ?><a href="./?disable-pppsecret=<?= rawurlencode($id) ?>&session=<?= $session ?>"><i class="fa fa-lock text-orange"></i></a><?php endif; ?></td>
      </tr><?php endforeach; ?>
      </tbody></table></div>
  </div>
</div></div></div>
<script>$(function(){ $('#filterTable').on('keyup',function(){var v=this.value.toLowerCase();$('#dataTable tbody tr').each(function(){$(this).toggle($(this).text().toLowerCase().indexOf(v)>-1);});});});</script>
