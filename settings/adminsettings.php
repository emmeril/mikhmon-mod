<?php
error_reporting(0);

if (!isset($_SESSION['mikhmon']) || !mikhmonIsAdmin()) {
  header('Location:../admin.php?id=login');
  exit;
}

if (isset($_POST['save'])) {
  $sbrandname = trim(strip_tags((string) $_POST['brandname']));
  $sbrandname = $sbrandname !== '' ? $sbrandname : 'MIKHMON';
  $qrbt = isset($_POST['qrbt']) && $_POST['qrbt'] === 'enable' ? 'enable' : 'disable';

  file_put_contents('./include/quickbt.php', '<?php $qrbt=' . var_export($qrbt, true) . ';?>');
  file_put_contents('./include/brand.php', "<?php\n\$brandname = " . var_export($sbrandname, true) . ";\n?>\n");

  $target = !empty($session)
    ? './?admin=settings&session=' . rawurlencode($session) . '&saved=1'
    : './admin.php?id=admin-settings&saved=1';
  echo '<script>window.location=' . json_encode($target) . '</script>';
  exit;
}
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fa fa-user-circle"></i> <?= $_admin_settings; ?></h3>
      </div>
      <div class="card-body">
        <?php if (isset($_GET['saved'])): ?>
          <div class="bg-success pd-10 radius-3 mr-b-10"><i class="fa fa-check"></i> Pengaturan admin berhasil disimpan.</div>
        <?php endif; ?>
        <p class="mr-b-10">Atur identitas aplikasi. Akun administrator dikelola melalui menu <b>Manajemen User</b>.</p>
        <form autocomplete="off" method="post" action="">
          <div class="row">
            <div class="col-6">
              <table class="table">
                <tr>
                  <td class="align-middle">Brand Name</td>
                  <td><input class="form-control" type="text" maxlength="30" name="brandname" value="<?= htmlspecialchars($brandname, ENT_QUOTES); ?>" required></td>
                </tr>
                <tr>
                  <td class="align-middle"><?= $_quick_print; ?> QR</td>
                  <td>
                    <select class="form-control" name="qrbt">
                      <option value="disable" <?= $qrbt === 'disable' ? 'selected' : ''; ?>>Disable</option>
                      <option value="enable" <?= $qrbt === 'enable' ? 'selected' : ''; ?>>Enable</option>
                    </select>
                  </td>
                </tr>
              </table>
            </div>
          </div>
          <div class="text-right">
            <button class="btn bg-primary" type="submit" name="save"><i class="fa fa-save"></i> <?= $_save; ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
