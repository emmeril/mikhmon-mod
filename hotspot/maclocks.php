<?php

include_once(__DIR__ . '/../lib/hotspot_mac_lock.php');

if (!isset($_SESSION['mikhmon'])) {
  header('Location:../admin.php?id=login');
  exit;
}

$macLockNotice = '';
$macLockError = '';
$macLockResultMessage = function ($result) use ($_mac_lock_invalid_user, $_mac_lock_clear_failed, $_mac_lock_invalid_profile, $_mac_lock_read_profile_failed, $_mac_lock_profile_not_found, $_mac_lock_profile_disabled, $_mac_lock_profile_update_failed) {
  $messages = array(
    'invalid_user' => $_mac_lock_invalid_user,
    'clear_failed' => $_mac_lock_clear_failed,
    'invalid_profile' => $_mac_lock_invalid_profile,
    'read_profile_failed' => $_mac_lock_read_profile_failed,
    'profile_not_found' => $_mac_lock_profile_not_found,
    'profile_lock_disabled' => $_mac_lock_profile_disabled,
    'profile_update_failed' => $_mac_lock_profile_update_failed,
  );
  $message = $messages[(string) ($result['code'] ?? '')] ?? $_mac_lock_invalid_user;
  if (!empty($result['detail'])) $message .= ': ' . $result['detail'];
  return $message;
};

if (!$routerConnected) {
  $macLockError = $_mac_lock_router_offline;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_mac_user'])) {
  $targetId = trim((string) $_POST['reset_mac_user']);
  $targetRows = $targetId === '' ? array() : $API->comm('/ip/hotspot/user/print', array(
    '?.id' => $targetId,
    '.proplist' => '.id,name,profile,mac-address,comment',
  ));
  $targetError = mikhmonHotspotMacLockApiError($targetRows);

  if ($targetError !== '') {
    $macLockError = $_mac_lock_read_user_failed . ': ' . $targetError;
  } elseif (empty($targetRows[0])) {
    $macLockError = $_mac_lock_user_not_found;
  } elseif (!mikhmonCanManageHotspotUser($session, $targetRows[0])) {
    http_response_code(403);
    exit('Akses voucher ditolak.');
  } elseif (mikhmonHotspotMacIsUnlocked($targetRows[0]['mac-address'] ?? '')) {
    $macLockNotice = $_mac_lock_already_empty;
  } else {
    $profileResult = mikhmonEnsureHotspotProfileMacRebind($API, (string) ($targetRows[0]['profile'] ?? ''));
    if (!$profileResult['success']) {
      $macLockError = $macLockResultMessage($profileResult);
      mikhmonSystemLog('error', 'Kunci MAC', 'Gagal mereset kunci MAC pengguna ' . ($targetRows[0]['name'] ?? $targetId) . '.', mikhmonSystemLogCurrentUser());
    } else {
      $resetResult = mikhmonResetHotspotMacLock($API, $targetRows[0]);
      if (!$resetResult['success']) {
        $macLockError = $macLockResultMessage($resetResult);
        mikhmonSystemLog('error', 'Kunci MAC', 'Gagal mereset kunci MAC pengguna ' . ($targetRows[0]['name'] ?? $targetId) . '.', mikhmonSystemLogCurrentUser());
      } else {
        $macLockNotice = sprintf($_mac_lock_reset_success, $resetResult['username'], $resetResult['active_removed'], $resetResult['cookie_removed']);
        if (!empty($profileResult['updated'])) $macLockNotice .= ' ' . $_mac_lock_profile_updated;
        if (!empty($resetResult['cleanup_errors'])) {
          $cleanupMessages = array();
          foreach ($resetResult['cleanup_errors'] as $cleanupError) {
            $cleanupScope = ($cleanupError['scope'] ?? '') === 'cookie' ? $_mac_lock_cookie : $_mac_lock_active_session;
            $cleanupMessages[] = $cleanupScope . ': ' . ($cleanupError['detail'] ?? '');
          }
          $macLockNotice .= ' ' . $_mac_lock_cleanup_note . ': ' . implode('; ', $cleanupMessages) . '.';
        }
        mikhmonSystemLog('warning', 'Kunci MAC', 'Mereset kunci MAC pengguna ' . $resetResult['username'] . '.', mikhmonSystemLogCurrentUser());
      }
    }
  }
}

$lockedUsers = array();
$activeUsers = array();
$lockedProfileOptions = array();
if ($routerConnected) {
  $lockProfiles = array();
  $profileRows = $API->comm('/ip/hotspot/user/profile/print', array('.proplist' => 'name,on-login'));
  $profileError = mikhmonHotspotMacLockApiError($profileRows);
  if ($profileError !== '') {
    $macLockError = $_mac_lock_load_profiles_failed . ': ' . $profileError;
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
    $macLockError = $_mac_lock_load_users_failed . ': ' . $userError;
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
    foreach ($lockedUsers as $lockedUser) {
      $lockedProfileName = trim((string) ($lockedUser['profile'] ?? ''));
      if ($lockedProfileName !== '') $lockedProfileOptions[$lockedProfileName] = true;
    }
    $lockedProfileOptions = array_keys($lockedProfileOptions);
    natcasesort($lockedProfileOptions);
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
        <h3><i class="fa fa-unlock-alt"></i> <?= htmlspecialchars($_reset_mac_lock, ENT_QUOTES); ?> <small>(<?= sprintf($_mac_lock_count, '<span id="macLockVisibleCount">' . count($lockedUsers) . '</span>', count($lockedUsers)); ?>)</small></h3>
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
          <?= htmlspecialchars($_mac_lock_description, ENT_QUOTES); ?>
        </div>

        <style>
          .mac-lock-toolbar { display:flex; align-items:stretch; gap:8px; }
          .mac-lock-toolbar .form-control { height:34px; min-height:34px; margin:0; box-sizing:border-box; }
          #macLockSearch { flex:1; min-width:220px; }
          #macLockProfileFilter, #macLockStatusFilter { width:190px; }
          .mac-lock-toolbar .btn { display:inline-flex; align-items:center; justify-content:center; gap:5px; min-height:34px; margin:0; white-space:nowrap; }
          @media(max-width:700px) {
            .mac-lock-toolbar { flex-direction:column; }
            #macLockSearch, #macLockProfileFilter, #macLockStatusFilter { width:100%; min-width:0; }
          }
        </style>
        <div class="mac-lock-toolbar" role="search" aria-label="<?= htmlspecialchars($_search . ' ' . $_reset_mac_lock, ENT_QUOTES); ?>">
          <input id="macLockSearch" type="search" class="form-control" placeholder="<?= htmlspecialchars($_mac_lock_search_placeholder, ENT_QUOTES); ?>" aria-label="<?= htmlspecialchars($_search . ' ' . $_reset_mac_lock, ENT_QUOTES); ?>" autocomplete="off">
          <select id="macLockProfileFilter" class="form-control" aria-label="<?= htmlspecialchars($_profile, ENT_QUOTES); ?>">
            <option value="all"><?= htmlspecialchars($_mac_lock_all_profiles, ENT_QUOTES); ?></option>
            <?php foreach ($lockedProfileOptions as $lockedProfileOption): ?>
              <option value="<?= htmlspecialchars($lockedProfileOption, ENT_QUOTES); ?>"><?= htmlspecialchars($lockedProfileOption, ENT_QUOTES); ?></option>
            <?php endforeach; ?>
          </select>
          <select id="macLockStatusFilter" class="form-control" aria-label="<?= htmlspecialchars($_mac_lock_status, ENT_QUOTES); ?>">
            <option value="all"><?= htmlspecialchars($_mac_lock_all_statuses, ENT_QUOTES); ?></option>
            <option value="active"><?= htmlspecialchars($_mac_lock_active, ENT_QUOTES); ?></option>
            <option value="inactive"><?= htmlspecialchars($_mac_lock_inactive, ENT_QUOTES); ?></option>
          </select>
          <button id="macLockResetFilter" type="button" class="btn bg-secondary" title="<?= htmlspecialchars($_show_all, ENT_QUOTES); ?>"><i class="fa fa-refresh"></i> <?= htmlspecialchars($_show_all, ENT_QUOTES); ?></button>
        </div>

        <div class="overflow box-bordered mr-t-10" style="max-height:75vh">
          <table id="dataTable" class="table table-bordered table-hover text-nowrap">
            <thead>
              <tr>
                <th class="text-center">No.</th>
                <th class="pointer"><i class="fa fa-sort"></i> <?= htmlspecialchars($_user_name, ENT_QUOTES); ?></th>
                <th class="pointer"><i class="fa fa-sort"></i> <?= htmlspecialchars($_profile, ENT_QUOTES); ?></th>
                <th class="pointer"><i class="fa fa-sort"></i> <?= htmlspecialchars($_mac_lock_server, ENT_QUOTES); ?></th>
                <th class="pointer"><i class="fa fa-sort"></i> <?= htmlspecialchars($_mac_lock_locked_mac, ENT_QUOTES); ?></th>
                <th class="text-center"><?= htmlspecialchars($_mac_lock_status, ENT_QUOTES); ?></th>
                <th class="text-center"><?= htmlspecialchars($_action, ENT_QUOTES); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lockedUsers as $index => $lockedUser): ?>
                <?php
                  $lockedId = (string) ($lockedUser['.id'] ?? '');
                  $lockedName = (string) ($lockedUser['name'] ?? '');
                  $isActive = isset($activeUsers[$lockedName]);
                  $confirmMessage = sprintf($_mac_lock_reset_confirm, $lockedName);
                ?>
                <tr class="mac-lock-row" data-profile="<?= htmlspecialchars((string) ($lockedUser['profile'] ?? ''), ENT_QUOTES); ?>" data-status="<?= $isActive ? 'active' : 'inactive'; ?>">
                  <td class="text-center"><?= $index + 1; ?></td>
                  <td><a href="./?hotspot-user=<?= rawurlencode($lockedId); ?>&amp;session=<?= rawurlencode($session); ?>"><i class="fa fa-edit"></i> <?= htmlspecialchars($lockedName, ENT_QUOTES); ?></a></td>
                  <td><?= htmlspecialchars((string) ($lockedUser['profile'] ?? ''), ENT_QUOTES); ?></td>
                  <td><?= htmlspecialchars((string) ($lockedUser['server'] ?? ''), ENT_QUOTES); ?></td>
                  <td><strong><?= htmlspecialchars((string) ($lockedUser['mac-address'] ?? ''), ENT_QUOTES); ?></strong></td>
                  <td class="text-center"><?php if ($isActive): ?><span class="text-success"><i class="fa fa-circle"></i> <?= htmlspecialchars($_mac_lock_active, ENT_QUOTES); ?></span><?php else: ?><span class="text-muted"><?= htmlspecialchars($_mac_lock_inactive, ENT_QUOTES); ?></span><?php endif; ?></td>
                  <td class="text-center">
                    <form method="post" action="./?hotspot=mac-locks&amp;session=<?= rawurlencode($session); ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode($confirmMessage), ENT_QUOTES); ?>);" style="margin:0;">
                      <?= mikhmonCsrfField(); ?>
                      <button type="submit" class="btn bg-warning" name="reset_mac_user" value="<?= htmlspecialchars($lockedId, ENT_QUOTES); ?>"><i class="fa fa-unlock-alt"></i> <?= htmlspecialchars($_mac_lock_reset_button, ENT_QUOTES); ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($lockedUsers)): ?>
                <tr><td colspan="7" class="text-center"><?= htmlspecialchars($_mac_lock_empty, ENT_QUOTES); ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($lockedUsers)): ?>
                <tr id="macLockNoResults" style="display:none"><td colspan="7" class="text-center"><?= htmlspecialchars($_mac_lock_no_results, ENT_QUOTES); ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function($) {
  if (!$) return;

  var search = $('#macLockSearch');
  var profile = $('#macLockProfileFilter');
  var status = $('#macLockStatusFilter');

  function applyMacLockFilters() {
    var query = String(search.val() || '').toLowerCase().trim();
    var selectedProfile = profile.val() || 'all';
    var selectedStatus = status.val() || 'all';
    var visible = 0;

    $('#dataTable .mac-lock-row').each(function() {
      var row = $(this);
      var matchesQuery = query === '' || row.text().toLowerCase().indexOf(query) !== -1;
      var matchesProfile = selectedProfile === 'all' || row.attr('data-profile') === selectedProfile;
      var matchesStatus = selectedStatus === 'all' || row.attr('data-status') === selectedStatus;
      var show = matchesQuery && matchesProfile && matchesStatus;
      row.toggle(show);
      if (show) visible++;
    });

    $('#macLockVisibleCount').text(visible);
    $('#macLockNoResults').toggle(visible === 0 && $('#dataTable .mac-lock-row').length > 0);
  }

  search.on('input', applyMacLockFilters);
  profile.add(status).on('change', applyMacLockFilters);
  $('#macLockResetFilter').on('click', function() {
    search.val('');
    profile.val('all');
    status.val('all');
    applyMacLockFilters();
    search.trigger('focus');
  });

  applyMacLockFilters();
})(window.jQuery);
</script>
