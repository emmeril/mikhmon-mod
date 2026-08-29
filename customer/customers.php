<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

include_once('./include/database.php');

$customerMessage = '';
$customerError = '';
$editCustomer = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['customer_action']) ? $_POST['customer_action'] : '';
  if ($action === 'save') {
    $savedId = mikhmonSaveCustomer(
      $session,
      isset($_POST['customer_id']) ? $_POST['customer_id'] : '',
      isset($_POST['customer_name']) ? $_POST['customer_name'] : '',
      isset($_POST['customer_phone']) ? $_POST['customer_phone'] : '',
      isset($_POST['customer_address']) ? $_POST['customer_address'] : '',
      isset($_POST['customer_service']) ? $_POST['customer_service'] : 'hotspot'
    );
    if ($savedId === false) {
      $customerError = 'Nama pelanggan wajib diisi.';
    } else {
      $customerMessage = 'Data pelanggan berhasil disimpan.';
    }
  } elseif ($action === 'delete') {
    if (mikhmonDeleteCustomer($session, isset($_POST['customer_id']) ? $_POST['customer_id'] : '')) {
      $customerMessage = 'Data pelanggan berhasil dihapus.';
    } else {
      $customerError = 'Data pelanggan tidak ditemukan.';
    }
  }
}

if ($customerid !== '') {
  foreach (mikhmonGetCustomers($session) as $customerRow) {
    if (isset($customerRow['id']) && (string) $customerRow['id'] === (string) $customerid) {
      $editCustomer = $customerRow;
      break;
    }
  }
}
$customers = mikhmonGetCustomers($session);
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-address-card"></i> Pelanggan <span style="font-size:14px">&nbsp;|&nbsp; <?= count($customers); ?> pelanggan</span></h3></div>
  <div class="card-body">
    <?php if ($customerMessage !== ''): ?><div class="box bg-success"><?= htmlspecialchars($customerMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($customerError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($customerError, ENT_QUOTES); ?></div><?php endif; ?>
    <form method="post" autocomplete="off" style="margin-bottom:20px">
      <input type="hidden" name="customer_action" value="save">
      <input type="hidden" name="customer_id" value="<?= htmlspecialchars(isset($editCustomer['id']) ? $editCustomer['id'] : '', ENT_QUOTES); ?>">
      <div class="row">
        <div class="col-3"><label>Nama Pelanggan</label><input class="form-control" type="text" name="customer_name" maxlength="100" required value="<?= htmlspecialchars(isset($editCustomer['name']) ? $editCustomer['name'] : '', ENT_QUOTES); ?>"></div>
        <div class="col-3"><label>Nomor HP</label><input class="form-control" type="text" name="customer_phone" maxlength="30" value="<?= htmlspecialchars(isset($editCustomer['phone']) ? $editCustomer['phone'] : '', ENT_QUOTES); ?>"></div>
        <div class="col-4"><label>Alamat</label><input class="form-control" type="text" name="customer_address" maxlength="255" value="<?= htmlspecialchars(isset($editCustomer['address']) ? $editCustomer['address'] : '', ENT_QUOTES); ?>"></div>
        <div class="col-2"><label>Layanan</label><select class="form-control" name="customer_service"><option value="hotspot" <?= (!isset($editCustomer['service']) || $editCustomer['service'] === 'hotspot') ? 'selected' : ''; ?>>Hotspot</option><option value="pppoe" <?= (isset($editCustomer['service']) && $editCustomer['service'] === 'pppoe') ? 'selected' : ''; ?>>PPPoE</option></select></div>
      </div>
      <button class="btn bg-primary" type="submit" style="margin-top:12px"><i class="fa fa-save"></i> <?= $editCustomer ? 'Update' : 'Simpan'; ?></button>
      <?php if ($editCustomer): ?><a class="btn" href="./?customer=list&session=<?= rawurlencode($session); ?>" style="margin-top:12px">Batal</a><?php endif; ?>
    </form>
    <div class="overflow box-bordered" style="max-height:65vh"><table id="dataTable" class="table table-bordered table-hover text-nowrap">
      <thead><tr><th>No</th><th>Nama Pelanggan</th><th>Nomor HP</th><th>Alamat</th><th>Layanan</th><th>Aksi</th></tr></thead><tbody>
      <?php foreach ($customers as $customerIndex => $customerRow): ?><tr>
        <td><?= $customerIndex + 1; ?></td><td><?= htmlspecialchars(isset($customerRow['name']) ? $customerRow['name'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['phone']) ? $customerRow['phone'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['address']) ? $customerRow['address'] : '', ENT_QUOTES); ?></td><td><?= strtoupper(htmlspecialchars(isset($customerRow['service']) ? $customerRow['service'] : 'hotspot', ENT_QUOTES)); ?></td>
        <td><a class="btn bg-primary" href="./?customer=list&customer-id=<?= rawurlencode($customerRow['id']); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-edit"></i> Edit</a> <form method="post" style="display:inline" onsubmit="return confirm('Hapus pelanggan ini?');"><input type="hidden" name="customer_action" value="delete"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customerRow['id'], ENT_QUOTES); ?>"><button class="btn bg-danger" type="submit"><i class="fa fa-trash"></i> Hapus</button></form></td>
      </tr><?php endforeach; ?>
      <?php if (!$customers): ?><tr><td colspan="6" class="text-center">Belum ada data pelanggan.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div>
</div></div></div>
