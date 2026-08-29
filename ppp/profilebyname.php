<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) { header("Location:../admin.php?id=login"); exit; }
$name=$_GET['profile']; $rows=$API->comm("/ppp/profile/print",array("?name"=>$name)); $profile=isset($rows[0])?$rows[0]:array();
if (!$profile) { echo '<div class="card"><div class="card-body">Profil tidak ditemukan.</div></div>'; return; }
$pools=$API->comm("/ip/pool/print");
if (!is_array($pools)) {
  $pools = array();
}
if (isset($_POST['save'])) {
  $API->comm("/ppp/profile/set",array(".id"=>$profile['.id'],"name"=>trim($_POST['name']),"local-address"=>$_POST['local-address'],"remote-address"=>$_POST['remote-address'],"rate-limit"=>$_POST['rate-limit'],"dns-server"=>$_POST['dns-server'],"comment"=>$_POST['comment']));
  echo "<script>window.location='./?ppp=profiles&session=" . $session . "'</script>"; exit;
}
function pv($key,$row){return htmlspecialchars(isset($row[$key])?$row[$key]:'',ENT_QUOTES);}
function poolOptions($pools,$selected){$found=false; foreach($pools as $pool){if(!isset($pool['name']))continue; $poolName=$pool['name']; $isSelected=$poolName===$selected; $found=$found||$isSelected; $label=$poolName.(isset($pool['ranges'])&&$pool['ranges']!==''?' - '.$pool['ranges']:''); echo '<option value="'.htmlspecialchars($poolName,ENT_QUOTES).'"'.($isSelected?' selected':'').'>'.htmlspecialchars($label).'</option>';} if($selected!==''&&!$found)echo '<option value="'.htmlspecialchars($selected,ENT_QUOTES).'" selected>'.htmlspecialchars($selected).' (nilai saat ini)</option>';}
$local=isset($profile['local-address'])?$profile['local-address']:''; $remote=isset($profile['remote-address'])?$profile['remote-address']:'';
?>
<div class="row"><div class="col-8"><div class="card box-bordered"><div class="card-header"><h3><i class="fa fa-edit"></i> <?= $_ppp_profiles ?></h3></div><div class="card-body"><form method="post"><a class="btn bg-warning" href="./?ppp=profiles&session=<?= $session ?>"><?= $_close ?></a> <button class="btn bg-primary" name="save"><?= $_save ?></button><table class="table">
<tr><td><?= $_name ?></td><td><input class="form-control" name="name" value="<?= pv('name',$profile) ?>" required></td></tr>
<tr><td>Local Address</td><td><select class="form-control" name="local-address"><option value=""<?= $local===''?' selected':'' ?>>none</option><?php poolOptions($pools,$local); ?></select></td></tr>
<tr><td>Remote Address</td><td><select class="form-control" name="remote-address"><option value=""<?= $remote===''?' selected':'' ?>>none</option><?php poolOptions($pools,$remote); ?></select></td></tr>
<tr><td>Rate Limit</td><td><input class="form-control" name="rate-limit" value="<?= pv('rate-limit',$profile) ?>"></td></tr><tr><td>DNS Server</td><td><input class="form-control" name="dns-server" value="<?= pv('dns-server',$profile) ?>"></td></tr><tr><td><?= $_comment ?></td><td><input class="form-control" name="comment" value="<?= pv('comment',$profile) ?>"></td></tr></table></form></div></div></div></div>
