<?php
error_reporting(0);

if (!isset($_SESSION['mikhmon'])) {
  header('Location:../admin.php?id=login');
  exit;
}

if (isset($_POST['save'])) {
  $suseradm = trim((string) $_POST['useradm']);
  $spassadm = encrypt((string) $_POST['passadm']);
  $sbrandname = trim(strip_tags((string) $_POST['brandname']));
  $sbrandname = $sbrandname !== '' ? $sbrandname : 'MIKHMON';
  $qrbt = isset($_POST['qrbt']) && $_POST['qrbt'] === 'enable' ? 'enable' : 'disable';

  $content = file_get_contents('./include/config.php');
  $content = str_replace("mikhmon<|<$useradm", "mikhmon<|<$suseradm", $content);
  $content = str_replace("mikhmon>|>$passadm", "mikhmon>|>$spassadm", $content);
  file_put_contents('./include/config.php', $content);

  file_put_contents('./include/quickbt.php', '<?php $qrbt=' . var_export($qrbt, true) . ';?>');
  file_put_contents('./include/brand.php', "<?php\n\$brandname = " . var_export($sbrandname, true) . ";\n?>\n");

  $target = !empty($session)
    ? './?admin=settings&session=' . rawurlencode($session) . '&saved=1'
    : './admin.php?id=admin-settings&saved=1';
  echo '<script>window.location=' . json_encode($target) . '</script>';
  exit;
}
?>
<script>
function toggleAdminPassword() {
  var field = document.getElementById('passadm');
  field.type = field.type === 'password' ? 'text' : 'password';
}
</script>

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
        <p class="mr-b-10">Atur identitas aplikasi dan akun administrator. Pengelolaan MikroTik sekarang tersedia melalui menu <b>Router</b>.</p>
        <form autocomplete="off" method="post" action="">
          <div class="row">
            <div class="col-6">
              <table class="table">
                <tr>
                  <td class="align-middle">Brand Name</td>
                  <td><input class="form-control" type="text" maxlength="30" name="brandname" value="<?= htmlspecialchars($brandname, ENT_QUOTES); ?>" required></td>
                </tr>
                <tr>
                  <td class="align-middle"><?= $_user_name; ?></td>
                  <td><input class="form-control" type="text" name="useradm" value="<?= htmlspecialchars($useradm, ENT_QUOTES); ?>" required></td>
                </tr>
                <tr>
                  <td class="align-middle"><?= $_password; ?></td>
                  <td>
                    <div class="input-group">
                      <div class="input-group-11 col-box-10">
                        <input class="group-item group-item-l" id="passadm" type="password" name="passadm" value="<?= htmlspecialchars(decrypt($passadm), ENT_QUOTES); ?>" required>
                      </div>
                      <div class="input-group-1 col-box-2">
                        <div class="group-item group-item-r pd-2p5 text-center align-middle">
                          <input title="Tampilkan/sembunyikan password" type="checkbox" onclick="toggleAdminPassword()">
                        </div>
                      </div>
                    </div>
                  </td>
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
