<?php
error_reporting(0);

if (!isset($_SESSION['mikhmon'])) {
  header('Location:../admin.php?id=login');
  exit;
}

$routerSessions = array();
foreach ((array) $data as $routerSession => $routerSettings) {
  if ($routerSession === 'mikhmon' || $routerSession === '' || !is_array($routerSettings)) continue;
  $routerSessions[$routerSession] = $routerSettings;
}
$returnSession = !empty($session) ? $session : mikhmonDefaultRouterSession($data);
?>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <div class="row">
          <div class="col-6">
            <h3 class="card-title"><i class="fa fa-server"></i> <?= $_router_list; ?></h3>
          </div>
          <div class="col-6 text-right">
            <a class="btn bg-primary" href="./admin.php?id=settings&amp;router=new-<?= rand(1111, 9999); ?>&amp;return=routers"><i class="fa fa-plus"></i> <?= $_add_router; ?></a>
          </div>
        </div>
      </div>
      <div class="card-body">
        <p class="mr-b-10">Pilih router untuk membuka dashboard, atau gunakan tombol pengaturan untuk mengubah koneksi MikroTik.</p>
        <?php if (empty($routerSessions)): ?>
          <div class="text-center pd-20">
            <i class="fa fa-server" style="font-size:48px"></i>
            <h3>Belum ada router</h3>
            <p>Tambahkan router pertama agar dashboard MikroTik dapat digunakan.</p>
            <a class="btn bg-primary" href="./admin.php?id=settings&amp;router=new-<?= rand(1111, 9999); ?>&amp;return=routers"><i class="fa fa-plus"></i> <?= $_add_router; ?></a>
          </div>
        <?php else: ?>
          <div class="router-table-wrap">
            <style>
              .router-table-wrap { max-width:100%; overflow-x:auto; }
              .router-actions { display:flex; flex-wrap:wrap; gap:3px; justify-content:flex-end; }
              .router-actions .btn { margin:2px 0 2px 3px; }
              @media screen and (max-width:750px) {
                .router-table-wrap { overflow:visible; }
                .router-table thead { display:none; }
                .router-table, .router-table tbody, .router-table tr, .router-table td { display:block; width:100%; box-sizing:border-box; }
                .router-table tr { margin-bottom:12px; border:1px solid currentColor; border-radius:4px; overflow:hidden; }
                .router-table td { position:relative; min-height:36px; padding:8px 8px 8px 42%; text-align:left !important; border-width:0 0 1px 0 !important; overflow-wrap:anywhere; }
                .router-table td:last-child { border-bottom:0 !important; }
                .router-table td::before { content:attr(data-label); position:absolute; left:8px; top:8px; width:35%; font-weight:bold; }
                .router-table .router-number { display:none; }
                .router-table .router-action-cell { padding-left:8px; }
                .router-table .router-action-cell::before { position:static; display:block; width:auto; margin-bottom:6px; }
                .router-actions { width:100%; justify-content:flex-start; gap:5px; }
                .router-actions .btn { flex:1 1 100%; box-sizing:border-box; margin:0; }
              }
            </style>
            <table class="table table-bordered table-hover table-sm router-table">
              <thead>
                <tr>
                  <th class="text-center">No</th>
                  <th><?= $_session_name; ?></th>
                  <th><?= $_hotspot_name; ?></th>
                  <th>Alamat MikroTik</th>
                  <th class="text-center">Dipilih</th>
                  <th class="text-right">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php $routerNumber = 1; foreach ($routerSessions as $routerSession => $routerSettings): ?>
                  <?php
                  $routerName = isset($routerSettings[4]) ? explode('%', $routerSettings[4], 2) : array('', '');
                  $routerAddress = isset($routerSettings[1]) ? explode('!', $routerSettings[1], 2) : array('', '');
                  $isCurrent = (string) $routerSession === (string) $returnSession;
                  $deleteMessage = 'Hapus router ' . $routerSession . '?';
                  ?>
                  <tr>
                    <td class="align-middle text-center router-number" data-label="No"><?= $routerNumber++; ?></td>
                    <td class="align-middle" data-label="<?= htmlspecialchars($_session_name, ENT_QUOTES); ?>"><i class="fa fa-server"></i> <b><?= htmlspecialchars($routerSession, ENT_QUOTES); ?></b></td>
                    <td class="align-middle" data-label="<?= htmlspecialchars($_hotspot_name, ENT_QUOTES); ?>"><?= htmlspecialchars(isset($routerName[1]) ? $routerName[1] : '', ENT_QUOTES); ?></td>
                    <td class="align-middle" data-label="Alamat MikroTik"><?= htmlspecialchars(isset($routerAddress[1]) ? $routerAddress[1] : '', ENT_QUOTES); ?></td>
                    <td class="align-middle text-center text-nowrap" data-label="Dipilih"><?= $isCurrent ? '<span class="text-green"><i class="fa fa-check-circle"></i> Ya</span>' : '<span class="text-grey">-</span>'; ?></td>
                    <td class="text-right router-action-cell" data-label="Aksi">
                      <div class="router-actions">
                        <a class="btn bg-green" href="./admin.php?id=connect&amp;session=<?= rawurlencode($routerSession); ?>"><i class="fa fa-external-link"></i> <?= $_open; ?></a>
                        <a class="btn bg-primary" href="./admin.php?id=settings&amp;session=<?= rawurlencode($routerSession); ?>&amp;return=routers"><i class="fa fa-edit"></i> <?= $_session_settings; ?></a>
                        <a class="btn bg-danger" href="./admin.php?id=remove-session&amp;session=<?= rawurlencode($routerSession); ?>" onclick="return confirm(<?= htmlspecialchars(json_encode($deleteMessage), ENT_QUOTES); ?>);"><i class="fa fa-trash"></i> <?= $_delete; ?></a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
