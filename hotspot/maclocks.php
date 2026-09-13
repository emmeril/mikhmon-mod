<?php

include_once(__DIR__ . '/../lib/hotspot_mac_lock.php');

if (!isset($_SESSION['mikhmon'])) {
  header('Location:../admin.php?id=login');
  exit;
}

$macLockNotice = '';
$macLockError = '';

if (!$routerConnected) {
  $macLockError = 'Router MikroTik tidak terhubung.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_mac_user'])) {
  $targetId = trim((string) $_POST['reset_mac_user']);
  $targetRows = $targetId === '' ? array() : $API->comm('/ip/hotspot/user/print', array(
    '?.id' => $targetId,
    '.proplist' => '.id,name,profile,mac-address,comment',
  ));
  $targetError = mikhmonHotspotMacLockApiError($targetRows);

  if ($targetError !== '') {
    $macLockError = 'Gagal membaca pengguna: ' . $targetError;
  } elseif (empty($targetRows[0])) {
    $macLockError = 'Pengguna Hotspot tidak ditemukan.';
  } elseif (!mikhmonCanManageHotspotUser($session, $targetRows[0])) {
    http_response_code(403);
    exit('Akses voucher ditolak.');
  } elseif (mikhmonHotspotMacIsUnlocked($targetRows[0]['mac-address'] ?? '')) {
    $macLockNotice = 'Kunci MAC pengguna tersebut sudah kosong.';
  } else {
    $profileResult = mikhmonEnsureHotspotProfileMacRebind($API, (string) ($targetRows[0]['profile'] ?? ''));
    if (!$profileResult['success']) {
      $macLockError = $profileResult['message'];
      mikhmonSystemLog('error', 'Kunci MAC', 'Gagal mereset kunci MAC pengguna ' . ($targetRows[0]['name'] ?? $targetId) . '.', mikhmonSystemLogCurrentUser());
    } else {
      $resetResult = mikhmonResetHotspotMacLock($API, $targetRows[0]);
      if (!$resetResult['success']) {
        $macLockError = $resetResult['message'];
        mikhmonSystemLog('error', 'Kunci MAC', 'Gagal mereset kunci MAC pengguna ' . ($targetRows[0]['name'] ?? $targetId) . '.', mikhmonSystemLogCurrentUser());
      } else {
        $macLockNotice = 'Kunci MAC ' . $resetResult['username'] . ' berhasil direset. Sesi terputus: ' . $resetResult['active_removed'] . ', cookie dihapus: ' . $resetResult['cookie_removed'] . '.';
        if (!empty($profileResult['updated'])) $macLockNotice .= ' Skrip profil juga diperbarui agar MAC baru terkunci otomatis.';
        if (!empty($resetResult['cleanup_errors'])) {
          $macLockNotice .= ' Catatan pembersihan: ' . implode('; ', $resetResult['cleanup_errors']) . '.';
        }
        mikhmonSystemLog('warning', 'Kunci MAC', 'Mereset kunci MAC pengguna ' . $resetResult['username'] . '.', mikhmonSystemLogCurrentUser());
      }
    }
  }
}

$lockedUsers = array();
$activeUsers = array();
if ($routerConnected) {
  $lockProfiles = array();
  $profileRows = $API->comm('/ip/hotspot/user/profile/print', array('.proplist' => 'name,on-login'));
  $profileError = mikhmonHotspotMacLockApiError($profileRows);
  if ($profileError !== '') {
    $macLockError = 'Gagal mengambil profil Kunci Pengguna: ' . $profileError;
  } else {
    foreach ((array) $profileRows as $profileRow) {
      if (isset($profileRow['name']) && mikhmonHotspotProfileUsesMacLock($profileRow)) {
        $lockProfiles[(string) $profileRow['name']] = true;
      }
    }
  }

  $userRows = $API->comm('/ip/hotspot/user/print', array(
    '.proplist' => '.id,name,server,profile,mac-address,comment,disabled',
  ));
  $userError = mikhmonHotspotMacLockApiError($userRows);
  if ($userError !== '') {
    $macLockError = 'Gagal mengambil daftar kunci MAC: ' . $userError;
  } else {
    foreach ((array) $userRows as $userRow) {
      if (mikhmonHotspotMacIsUnlocked($userRow['mac-address'] ?? '')) continue;
      if (!isset($lockProfiles[(string) ($userRow['profile'] ?? '')])) continue;
      if (!mikhmonCanManageHotspotUser($session, $userRow)) continue;
      $lockedUsers[] = $userRow;
    }
    usort($lockedUsers, function ($left, $right) {
      return strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });
  }

  $activeRows = $API->comm('/ip/hotspot/active/print', array('.proplist' => 'user'));
  if (mikhmonHotspotMacLockApiError($activeRows) === '') {
    foreach ((array) $activeRows as $activeRow) {
      if (isset($activeRow['user'])) $activeUsers[(string) $activeRow['user']] = true;
    }
  }
}
?>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-unlock-alt"></i> Reset Kunci MAC <small>(<?= count($lockedUsers); ?> pengguna terkunci)</small></h3>
      </div>
      <div class="card-body">
        <?php if ($macLockNotice !== ''): ?>
          <div class="bg-success pd-10 radius-3 mr-b-10"><i class="fa fa-check"></i> <?= htmlspecialchars($macLockNotice, ENT_QUOTES); ?></div>
        <?php endif; ?>
        <?php if ($macLockError !== ''): ?>
          <div class="bg-danger pd-10 radius-3 mr-b-10"><i class="fa fa-ban"></i> <?= htmlspecialchars($macLockError, ENT_QUOTES); ?></div>
        <?php endif; ?>

        <div class="bg-warning pd-10 radius-3 mr-b-10">
          <i class="fa fa-info-circle"></i>
          Reset akan mengosongkan MAC voucher, memutus sesi aktif, dan menghapus cookie lama. Perangkat yang login berikutnya akan menjadi perangkat yang terkunci.
        </div>

        <div class="w-6">
          <input id="filterTable" type="search" class="form-control" placeholder="Cari username, profil, atau MAC..." autocomplete="off">
        </div>

        <div class="overflow box-bordered mr-t-10" style="max-height:75vh">
          <table id="dataTable" class="table table-bordered table-hover text-nowrap">
            <thead>
              <tr>
                <th class="text-center">No.</th>
                <th class="pointer" title="Klik untuk mengurutkan"><i class="fa fa-sort"></i> Username</th>
                <th class="pointer" title="Klik untuk mengurutkan"><i class="fa fa-sort"></i> Profil</th>
                <th class="pointer" title="Klik untuk mengurutkan"><i class="fa fa-sort"></i> Server</th>
                <th class="pointer" title="Klik untuk mengurutkan"><i class="fa fa-sort"></i> MAC Terkunci</th>
                <th class="text-center">Status</th>
                <th class="text-center">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lockedUsers as $index => $lockedUser): ?>
                <?php
                  $lockedId = (string) ($lockedUser['.id'] ?? '');
                  $lockedName = (string) ($lockedUser['name'] ?? '');
                  $isActive = isset($activeUsers[$lockedName]);
                  $confirmMessage = 'Reset kunci MAC ' . $lockedName . '? Sesi aktif perangkat lama akan diputus.';
                ?>
                <tr>
                  <td class="text-center"><?= $index + 1; ?></td>
                  <td><a href="./?hotspot-user=<?= rawurlencode($lockedId); ?>&amp;session=<?= rawurlencode($session); ?>"><i class="fa fa-edit"></i> <?= htmlspecialchars($lockedName, ENT_QUOTES); ?></a></td>
                  <td><?= htmlspecialchars((string) ($lockedUser['profile'] ?? ''), ENT_QUOTES); ?></td>
                  <td><?= htmlspecialchars((string) ($lockedUser['server'] ?? ''), ENT_QUOTES); ?></td>
                  <td><strong><?= htmlspecialchars((string) ($lockedUser['mac-address'] ?? ''), ENT_QUOTES); ?></strong></td>
                  <td class="text-center"><?php if ($isActive): ?><span class="text-success"><i class="fa fa-circle"></i> Aktif</span><?php else: ?><span class="text-muted">Tidak aktif</span><?php endif; ?></td>
                  <td class="text-center">
                    <form method="post" action="./?hotspot=mac-locks&amp;session=<?= rawurlencode($session); ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode($confirmMessage), ENT_QUOTES); ?>);" style="margin:0;">
                      <?= mikhmonCsrfField(); ?>
                      <button type="submit" class="btn bg-warning" name="reset_mac_user" value="<?= htmlspecialchars($lockedId, ENT_QUOTES); ?>"><i class="fa fa-unlock-alt"></i> Reset MAC</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($lockedUsers)): ?>
                <tr><td colspan="7" class="text-center">Belum ada pengguna dengan kunci MAC.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
