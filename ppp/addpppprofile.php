<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) { header("Location:../admin.php?id=login"); exit; }
require_once __DIR__ . "/profilemeta.php";
$pools = $API->comm("/ip/pool/print");
if (!is_array($pools)) {
  $pools = array();
}
if (isset($_POST['save'])) {
  $profileComment = pppProfileMetaEncode($_POST['price'], $_POST['selling-price'], $_POST['comment'], $_POST['expmode'], $_POST['validity']);
  $API->comm("/ppp/profile/add", array(
    "name" => trim($_POST['name']), "local-address" => $_POST['local-address'],
    "remote-address" => $_POST['remote-address'], "rate-limit" => $_POST['rate-limit'],
    "dns-server" => $_POST['dns-server'], "comment" => $profileComment,
    "on-up" => pppProfileOnUpScript($_POST['expmode'], $_POST['validity'], trim($_POST['name']), $_POST['price'], $_POST['selling-price'])
  ));
  echo "<script>window.location='./?ppp=profiles&session=" . $session . "'</script>"; exit;
}
?>
<div class="row"><div class="col-8"><div class="card box-bordered"><div class="card-header"><h3><i class="fa fa-plus"></i> <?= $_ppp_profiles ?></h3></div><div class="card-body"><form method="post"><a class="btn bg-warning" href="./?ppp=profiles&session=<?= $session ?>"><?= $_close ?></a> <button class="btn bg-primary" name="save"><?= $_save ?></button><table class="table">
<tr><td><?= $_name ?></td><td><input class="form-control" name="name" required autofocus></td></tr>
<tr><td>Local Address</td><td><select class="form-control" name="local-address"><option value="">none</option><?php foreach ($pools as $pool) { if (!isset($pool['name'])) continue; $label=$pool['name'] . (isset($pool['ranges']) && $pool['ranges'] !== '' ? ' - '.$pool['ranges'] : ''); echo '<option value="'.htmlspecialchars($pool['name'],ENT_QUOTES).'">'.htmlspecialchars($label).'</option>'; } ?><?php if (count($pools) === 0) echo '<option disabled>Tidak ada IP pool</option>'; ?></select></td></tr>
<tr><td>Remote Address</td><td><select class="form-control" name="remote-address"><option value="">none</option><?php foreach ($pools as $pool) { if (!isset($pool['name'])) continue; $label=$pool['name'] . (isset($pool['ranges']) && $pool['ranges'] !== '' ? ' - '.$pool['ranges'] : ''); echo '<option value="'.htmlspecialchars($pool['name'],ENT_QUOTES).'">'.htmlspecialchars($label).'</option>'; } ?><?php if (count($pools) === 0) echo '<option disabled>Tidak ada IP pool</option>'; ?></select></td></tr>
<tr><td>Rate Limit</td><td><input class="form-control" name="rate-limit" placeholder="10M/10M"></td></tr><tr><td>DNS Server</td><td><input class="form-control" name="dns-server"></td></tr>
<tr><td><?= $_price . ' ' . $currency ?></td><td><input class="form-control" type="number" min="0" step="any" name="price"></td></tr>
<tr><td><?= $_selling_price . ' ' . $currency ?></td><td><input class="form-control" type="number" min="0" step="any" name="selling-price"></td></tr>
<tr><td>Expired Mode</td><td><select class="form-control" name="expmode"><option value="none">Tidak ada</option><option value="remove">Remove user</option><option value="disable">Disable user</option></select></td></tr>
<tr><td>Validity</td><td><input class="form-control" name="validity" placeholder="Contoh: 30d, 12h"></td></tr>
<tr><td><?= $_comment ?></td><td><input class="form-control" name="comment"></td></tr></table></form></div></div></div></div>
