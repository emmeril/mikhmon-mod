<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

include_once('./include/database.php');

$customerMessage = '';
$customerError = '';
if (isset($_GET['created']) && $_GET['created'] === '1') {
  $customerMessage = 'Pelanggan dan user MikroTik berhasil dibuat.';
} elseif (isset($_GET['updated']) && $_GET['updated'] === '1') {
  $customerMessage = 'Data pelanggan dan user MikroTik berhasil diperbarui.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['customer_action']) ? $_POST['customer_action'] : '';
  if ($action === 'delete') {
    if (mikhmonDeleteCustomer($session, isset($_POST['customer_id']) ? $_POST['customer_id'] : '')) {
      $customerMessage = 'Data pelanggan berhasil dihapus.';
    } else {
      $customerError = 'Data pelanggan tidak ditemukan.';
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
    <p><a class="btn bg-primary" href="./?customer=add&session=<?= rawurlencode($session); ?>"><i class="fa fa-user-plus"></i> Tambah Pelanggan</a></p>
    <p><small>Menghapus data pelanggan tidak menghapus user Hotspot/PPPoE di MikroTik.</small></p>
    <div class="overflow box-bordered" style="max-height:65vh"><table id="dataTable" class="table table-bordered table-hover text-nowrap">
      <thead><tr><th>No</th><th>Nama Pelanggan</th><th>Nomor HP</th><th>Alamat</th><th>Layanan</th><th>Username</th><th>Profile</th><th>Aksi</th></tr></thead><tbody>
      <?php foreach ($customers as $customerIndex => $customerRow): ?><tr>
        <td><?= $customerIndex + 1; ?></td><td><?= htmlspecialchars(isset($customerRow['name']) ? $customerRow['name'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['phone']) ? $customerRow['phone'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['address']) ? $customerRow['address'] : '', ENT_QUOTES); ?></td><td><?= strtoupper(htmlspecialchars(isset($customerRow['service']) ? $customerRow['service'] : 'hotspot', ENT_QUOTES)); ?></td><td><?= htmlspecialchars(isset($customerRow['username']) ? $customerRow['username'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['profile']) ? $customerRow['profile'] : '', ENT_QUOTES); ?></td>
        <td><a class="btn bg-primary" href="./?customer=edit&customer-id=<?= rawurlencode($customerRow['id']); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-edit"></i> Edit</a> <form method="post" style="display:inline" onsubmit="return confirm('Hapus data pelanggan ini? User MikroTik tidak akan dihapus.');"><input type="hidden" name="customer_action" value="delete"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customerRow['id'], ENT_QUOTES); ?>"><button class="btn bg-danger" type="submit"><i class="fa fa-trash"></i> Hapus Data</button></form></td>
      </tr><?php endforeach; ?>
      <?php if (!$customers): ?><tr><td colspan="8" class="text-center">Belum ada data pelanggan.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div>
</div></div></div>
