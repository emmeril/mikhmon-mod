<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) { header("Location:../admin.php?id=login"); exit; }
$profiles = $API->comm('/ppp/profile/print');
$error = '';
if (isset($_POST['save'])) {
  $name = trim($_POST['name']); $password = (string)$_POST['password'];
  if ($name === '' || $password === '') $error = 'Username dan password wajib diisi.';
  else {
    $API->comm('/ppp/secret/add', array('name'=>$name,'password'=>$password,'service'=>$_POST['service'],'profile'=>$_POST['profile'],'local-address'=>$_POST['local-address'],'remote-address'=>$_POST['remote-address'],'caller-id'=>$_POST['caller-id'],'comment'=>$_POST['comment'],'disabled'=>'no'));
    echo "<script>window.location='./?ppp=secrets&session=".$session."'</script>"; exit;
  }
}
?>
<div class="row"><div class="col-8"><div class="card box-bordered"><div class="card-header"><h3><i class="fa fa-user-plus"></i> <?= $_add_user ?></h3></div><div class="card-body">
<?php if ($error) echo '<div class="bg-danger pd-5">'.htmlspecialchars($error).'</div>'; ?><form method="post" autocomplete="off">
<table class="table"><tr><td><?= $_user_name ?></td><td><input class="form-control" name="name" required autofocus></td></tr><tr><td><?= $_password ?></td><td><input class="form-control" type="password" name="password" required></td></tr><tr><td>Service</td><td><select class="form-control" name="service"><option>pppoe</option><option>any</option><option>pptp</option><option>l2tp</option><option>sstp</option><option>ovpn</option></select></td></tr><tr><td><?= $_profile ?></td><td><select class="form-control" name="profile"><option></option><?php foreach($profiles as $p) echo '<option>'.htmlspecialchars($p['name']).'</option>'; ?></select></td></tr><tr><td>Local Address</td><td><input class="form-control" name="local-address"></td></tr><tr><td>Remote Address</td><td><input class="form-control" name="remote-address"></td></tr><tr><td>Caller ID</td><td><input class="form-control" name="caller-id"></td></tr><tr><td><?= $_comment ?></td><td><input class="form-control" name="comment"></td></tr></table>
<div class="text-right"><button class="btn bg-primary" name="save" onclick="loader()"><i class="fa fa-save"></i> <?= $_save ?></button> <a class="btn bg-warning" href="./?ppp=secrets&session=<?= $session ?>"><i class="fa fa-close"></i> <?= $_close ?></a></div>
</form>
</div></div></div><div class="col-4"><div class="card"><div class="card-header"><h3><i class="fa fa-info-circle"></i> PPPoE</h3></div><div class="card-body">Buat user PPPoE baru dan pilih profil PPP yang sesuai.</div></div></div></div>
