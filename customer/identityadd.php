<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/database.php');

$identityEdit = isset($customer) && (in_array($customer, array('identity-edit', 'edit'), true) || ($customer === 'list' && $customerid !== ''));
$identityError = '';
$identityCustomer = $identityEdit ? mikhmonFindCustomer($session, $customerid) : array();
if ($identityEdit && !$identityCustomer) $identityError = 'Identitas pelanggan tidak ditemukan.';
if ($identityEdit && $identityCustomer && !mikhmonCanManageCustomer($identityCustomer)) { $identityError = 'Anda tidak berhak mengelola identitas ini.'; $identityCustomer = array(); }
$identityMitras = mikhmonIsAdmin() ? mikhmonGetUsers('mitra', $session) : array();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['identity_action'])) {
  $name = trim(strip_tags((string) ($_POST['identity_name'] ?? '')));
  $phone = trim(strip_tags((string) ($_POST['identity_phone'] ?? '')));
  $address = trim(strip_tags((string) ($_POST['identity_address'] ?? '')));
  $mitraId = mikhmonIsAdmin() ? trim((string) ($_POST['mitra_id'] ?? '')) : (mikhmonIsMitra() ? mikhmonUserId() : ($identityCustomer['mitra_id'] ?? ''));
  $mitra = $mitraId !== '' ? mikhmonFindUser($mitraId) : false;
  $sameNameCustomer = false;
  foreach (mikhmonGetCustomers($session) as $candidate) if (mikhmonCustomerNameKey($candidate['name'] ?? '') === mikhmonCustomerNameKey($name)) { $sameNameCustomer = $candidate; break; }
  if ($name === '') $identityError = 'Nama pelanggan wajib diisi.';
  elseif ($sameNameCustomer && (!$identityEdit || (string) $sameNameCustomer['id'] !== (string) $identityCustomer['id']) && !mikhmonCanManageCustomer($sameNameCustomer)) $identityError = 'Nama pelanggan sudah digunakan oleh identitas lain yang tidak dapat Anda kelola.';
  elseif ($identityEdit && $sameNameCustomer && (string) $sameNameCustomer['id'] !== (string) $identityCustomer['id']) $identityError = 'Nama pelanggan sudah digunakan oleh identitas lain.';
  elseif ($mitraId !== '' && (!$mitra || $mitra['role'] !== 'mitra' || $mitra['session'] !== $session)) $identityError = 'Mitra yang dipilih tidak valid untuk router ini.';
  else {
    $savedId = mikhmonSaveCustomerIdentity($session, $identityEdit ? $identityCustomer['id'] : '', $name, $phone, $address, $mitraId);
    if ($savedId === false) $identityError = 'Identitas pelanggan gagal disimpan.';
    else {
      $query = './?customer=identity-list&session=' . rawurlencode($session) . '&saved=1';
      echo "<script>window.location=" . json_encode($query) . "</script>"; exit;
    }
  }
}
$identityName = $identityCustomer['name'] ?? '';
$identityPhone = $identityCustomer['phone'] ?? '';
$identityAddress = $identityCustomer['address'] ?? '';
?>
<style>
  .identity-card{max-width:760px;margin:0 auto}
  .identity-fields{display:grid;grid-template-columns:1fr;gap:14px}
  .identity-fields .wide{grid-column:1/-1}
  .identity-fields label{display:block;margin-bottom:6px;font-size:12px;font-weight:bold;color:#d7dbe0}
  .identity-fields textarea{height:90px;resize:vertical}
  .identity-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
  .identity-actions .btn,.identity-actions a{box-sizing:border-box;margin:0}
  @media(max-width:767px){.identity-fields{grid-template-columns:1fr}.identity-fields .wide{grid-column:auto}.identity-actions{flex-direction:column}.identity-actions .btn,.identity-actions a{width:100%;text-align:center}}
</style>
<div class="row"><div class="col-12"><div class="card box-bordered identity-card">
  <div class="card-header"><h3><i class="fa <?= $identityEdit ? 'fa-edit' : 'fa-user-plus'; ?>"></i> <?= $identityEdit ? 'Edit Identitas Pelanggan' : 'Tambah Identitas Pelanggan'; ?></h3></div>
  <div class="card-body">
    <?php if ($identityError !== ''): ?><div class="box bg-danger"><i class="fa fa-warning"></i> <?= htmlspecialchars($identityError, ENT_QUOTES); ?></div><?php endif; ?>
    <form method="post" autocomplete="off"><input type="hidden" name="identity_action" value="save">
      <div class="identity-fields">
        <div><label>Nama Pelanggan *</label><input class="form-control" name="identity_name" maxlength="100" required value="<?= htmlspecialchars(isset($_POST['identity_name']) ? $_POST['identity_name'] : $identityName, ENT_QUOTES); ?>" placeholder="Nama lengkap pelanggan"></div>
        <div><label>Nomor HP</label><input class="form-control" name="identity_phone" maxlength="30" value="<?= htmlspecialchars(isset($_POST['identity_phone']) ? $_POST['identity_phone'] : $identityPhone, ENT_QUOTES); ?>" placeholder="Contoh: 0812xxxxxxxx"></div>
        <div class="wide"><label>Alamat</label><textarea class="form-control" name="identity_address" maxlength="255" placeholder="Alamat pemasangan atau keterangan lokasi"><?= htmlspecialchars(isset($_POST['identity_address']) ? $_POST['identity_address'] : $identityAddress, ENT_QUOTES); ?></textarea></div>
        <?php if (mikhmonIsAdmin()): ?><div><label>Mitra</label><select class="form-control" name="mitra_id"><option value="">Belum ditetapkan</option><?php foreach ($identityMitras as $mitra): ?><option value="<?= htmlspecialchars($mitra['id'], ENT_QUOTES); ?>"<?= ((string) ($_POST['mitra_id'] ?? ($identityCustomer['mitra_id'] ?? '')) === (string) $mitra['id']) ? ' selected' : ''; ?>><?= htmlspecialchars($mitra['name'], ENT_QUOTES); ?></option><?php endforeach; ?></select></div><?php endif; ?>
      </div>
      <div class="identity-actions"><button class="btn bg-primary" type="submit" onclick="loader()"><i class="fa fa-save"></i> <?= $identityEdit ? 'Simpan Perubahan' : 'Simpan Identitas'; ?></button><a class="btn bg-warning" href="./?customer=identity-list&session=<?= rawurlencode($session); ?>"><i class="fa fa-close"></i> Batal</a></div>
    </form>
  </div>
</div></div></div>
