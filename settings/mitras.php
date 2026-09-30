<?php

error_reporting(0);
include_once(__DIR__ . '/../include/systemlog.php');
if (!isset($_SESSION['mikhmon']) || !mikhmonIsAdmin()) {
  header('Location:../admin.php?id=login');
  exit;
}

$mitraMessage = '';
$mitraError = '';
$editMitra = false;
$mitraBaseUrl = './?mitra=list&session=' . rawurlencode($session);
$mitraT = function ($text) {
  return function_exists('mikhmonTranslateText') ? mikhmonTranslateText($text) : $text;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mitra_action'])) {
  if (!mikhmonValidCsrf($_POST['_csrf'] ?? '')) {
    $mitraError = $mitraT('The form session is invalid. Reload the page and try again.');
  } elseif ($_POST['mitra_action'] === 'save') {
    $mitraId = trim((string) ($_POST['mitra_id'] ?? ''));
    $existingMitra = $mitraId !== '' ? mikhmonFindPartner($mitraId) : false;
    $category = strtolower(trim((string) ($_POST['category'] ?? '')));
    $userId = trim((string) ($_POST['user_id'] ?? ''));
    $user = $userId !== '' ? mikhmonFindUser($userId) : false;
    $expectedRole = $category === 'biller' ? 'biller' : 'mitra';
    $partner = array(
      'id' => $mitraId,
      'user_id' => $userId,
      'session' => $session,
      'name' => (string) ($_POST['name'] ?? ''),
      'category' => $category,
      'voucher_stock' => (int) ($_POST['voucher_stock'] ?? 0),
      'phone' => (string) ($_POST['phone'] ?? ''),
      'email' => (string) ($_POST['email'] ?? ''),
      'address' => (string) ($_POST['address'] ?? ''),
      'commission' => (float) ($_POST['commission'] ?? 0),
      'active' => isset($_POST['active']),
      'updated_at' => time(),
    );
    if (!in_array($category, array('reseller', 'biller', 'sales'), true)) {
      $mitraError = $mitraT('Partner category is invalid.');
    } elseif ($userId !== '' && (!$user || ($user['role'] ?? '') !== $expectedRole || (string) ($user['session'] ?? '') !== (string) $session)) {
      $mitraError = $mitraT('The login account does not match the Partner category or router.');
    } else {
      $savedId = mikhmonSavePartner($partner);
      if ($savedId === false) {
        $mitraError = $mitraT('Partner could not be saved. Check the name, email, and login account.');
      } else {
        mikhmonSystemLog('success', 'Mitra', ($existingMitra ? 'Memperbarui' : 'Membuat') . ' Mitra ' . trim((string) $partner['name']) . '.', mikhmonSystemLogCurrentUser(array('session' => $session)));
        echo '<script>window.location.replace(' . json_encode($mitraBaseUrl . '&saved=1') . ')</script>';
        exit;
      }
    }
    $editMitra = mikhmonNormalizePartner($partner);
  } elseif ($_POST['mitra_action'] === 'delete') {
    $mitraId = trim((string) ($_POST['mitra_id'] ?? ''));
    $partner = mikhmonFindPartner($mitraId);
    if (!$partner || $partner['session'] !== $session) {
      $mitraError = $mitraT('Partner not found.');
    } elseif ($partner['user_id'] !== '') {
      $mitraError = $mitraT('Unlink the login account before deleting this Partner.');
    } elseif (!mikhmonDeletePartner($mitraId)) {
      $mitraError = $mitraT('Partner could not be deleted.');
    } else {
      mikhmonSystemLog('warning', 'Mitra', 'Menghapus Mitra ' . $partner['name'] . '.', mikhmonSystemLogCurrentUser(array('session' => $session)));
      echo '<script>window.location.replace(' . json_encode($mitraBaseUrl . '&deleted=1') . ')</script>';
      exit;
    }
  }
}

if (!$editMitra && !empty($_GET['mitra-id'])) {
  $candidate = mikhmonFindPartner((string) $_GET['mitra-id']);
  if ($candidate && $candidate['session'] === $session) $editMitra = $candidate;
  else $mitraError = $mitraT('Partner not found.');
}
if (isset($_GET['saved'])) $mitraMessage = $mitraT('Partner saved successfully.');
if (isset($_GET['deleted'])) $mitraMessage = $mitraT('Partner deleted successfully.');

$mitras = mikhmonGetPartners($session);
usort($mitras, function ($left, $right) { return strcasecmp($left['name'], $right['name']); });
$loginUsers = array_values(array_filter(mikhmonGetUsers('', $session), function ($user) {
  return in_array($user['role'] ?? '', array('mitra', 'biller'), true);
}));
$linkedUserIds = array();
foreach ($mitras as $partner) if ($partner['user_id'] !== '') $linkedUserIds[$partner['user_id']] = $partner['id'];
$formMitra = $editMitra ?: mikhmonNormalizePartner(array('id' => '', 'session' => $session, 'active' => true));
$isEditingMitra = $editMitra && mikhmonFindPartner($formMitra['id']);
?>
<style>
  .mitra-form{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .mitra-form .wide{grid-column:1/-1}
  .mitra-form label{display:block;margin-bottom:5px;font-weight:600}
  .mitra-form textarea{min-height:76px;resize:vertical}
  .mitra-form-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px;justify-content:flex-end;margin-top:16px;padding-top:12px;border-top:1px solid rgba(127,127,127,.25)}
  .mitra-form-actions .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:38px;margin:0;box-sizing:border-box}
  .mitra-table td,.mitra-table th{vertical-align:middle}
  .mitra-action-cell{min-width:176px;text-align:center}
  .mitra-actions{display:grid;grid-template-columns:repeat(2,minmax(82px,1fr));align-items:stretch;gap:6px}
  .mitra-actions form{display:block;margin:0}
  .mitra-actions .btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;width:100%;min-height:38px;margin:0;box-sizing:border-box}
  .mitra-status{font-weight:700}
  .mitra-empty{padding:28px;text-align:center}
  .mitra-modal{position:fixed;inset:0;z-index:1000;display:none;align-items:flex-start;justify-content:center;padding:32px 16px;background:rgba(0,0,0,.55);overflow-y:auto}
  .mitra-modal.is-open{display:flex}
  .mitra-dialog{width:min(720px,100%);margin:0;max-height:calc(100vh - 64px);overflow-y:auto}
  .mitra-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:52px;box-sizing:border-box}
  .mitra-dialog-header h3{min-width:0}
  .mitra-dialog-close{flex:0 0 44px;width:44px;height:44px;margin:0;padding:0;font-size:24px;line-height:44px;color:#2f353a}
  .mitra-dialog-cancel{color:#2f353a}
  body.mitra-modal-open{overflow:hidden}
  .mitra-page :focus-visible{outline:2px solid #f5a623;outline-offset:2px}
  @media(max-width:750px){.mitra-modal{padding:16px 10px}.mitra-dialog{max-height:calc(100vh - 32px)}}
  @media(max-width:620px){.mitra-form{grid-template-columns:1fr}.mitra-form .wide{grid-column:auto}.mitra-form-actions{align-items:stretch;flex-direction:column}.mitra-form-actions .btn{width:100%;min-height:44px;margin:0}.mitra-actions .btn{min-height:44px}}
</style>
<div class="row mitra-page"><div class="col-12">
  <?php if ($mitraMessage !== ''): ?><div class="box bg-success" role="status"><i class="fa fa-check"></i> <?= htmlspecialchars($mitraMessage, ENT_QUOTES); ?></div><?php endif; ?>
  <?php if ($mitraError !== ''): ?><div class="box bg-danger" role="alert"><i class="fa fa-warning"></i> <?= htmlspecialchars($mitraError, ENT_QUOTES); ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header"><h3><i class="fa fa-handshake-o"></i> Partner List <span style="font-size:14px">| <span id="mitraVisibleCount"><?= count($mitras); ?></span> <?= htmlspecialchars($mitraT('records'), ENT_QUOTES); ?></span></h3></div>
      <div class="card-body">
        <div class="mitra-toolbar data-toolbar" role="search" aria-label="<?= htmlspecialchars($mitraT('Filter by category'), ENT_QUOTES); ?>"><div class="data-toolbar__filters"><input id="mitraSearch" class="form-control data-toolbar__search" type="search" placeholder="<?= htmlspecialchars($mitraT('Search by name, phone, email, or address'), ENT_QUOTES); ?>" aria-label="<?= htmlspecialchars($mitraT('Search by name, phone, email, or address'), ENT_QUOTES); ?>"><select id="mitraCategoryFilter" class="form-control data-toolbar__select" aria-label="<?= htmlspecialchars($mitraT('Filter by category'), ENT_QUOTES); ?>"><option value="all">All Categories</option><option value="reseller">Reseller</option><option value="biller">Biller</option><option value="sales">Sales</option></select></div><div class="data-toolbar__actions"><button id="openMitraModal" class="btn bg-primary mitra-add-button" type="button"><i class="fa fa-user-plus"></i> Add Partner</button></div></div>
        <div class="overflow box-bordered"><table class="table table-bordered table-hover text-nowrap mitra-table"><thead><tr><th>ID</th><th>Status</th><th>Name</th><th>Category</th><th>Voucher Stock</th><th>Phone</th><th>Email</th><th>Address</th><th>Commission</th><th>Login Account</th><th class="mitra-action-cell">Action</th></tr></thead><tbody>
          <?php foreach ($mitras as $partner): $account = $partner['user_id'] !== '' ? mikhmonFindUser($partner['user_id']) : false; ?><tr class="mitra-row" data-category="<?= htmlspecialchars($partner['category'], ENT_QUOTES); ?>"><td><?= htmlspecialchars($partner['id'], ENT_QUOTES); ?></td><td class="mitra-status <?= $partner['active'] ? 'text-success' : 'text-danger'; ?>"><?= $partner['active'] ? 'Active' : 'Inactive'; ?></td><td><?= htmlspecialchars($partner['name'], ENT_QUOTES); ?></td><td><?= strtoupper(htmlspecialchars($partner['category'], ENT_QUOTES)); ?></td><td class="text-right"><?= (int) $partner['voucher_stock']; ?></td><td><?= htmlspecialchars($partner['phone'] ?: '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($partner['email'] ?: '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($partner['address'] ?: '-', ENT_QUOTES); ?></td><td class="text-right"><?= htmlspecialchars($currency . ' ' . number_format($partner['commission'], 0, ',', '.'), ENT_QUOTES); ?></td><td><?= htmlspecialchars($account ? $account['username'] : '-', ENT_QUOTES); ?></td><td class="mitra-action-cell"><div class="mitra-actions"><a class="btn bg-primary" href="<?= htmlspecialchars($mitraBaseUrl, ENT_QUOTES); ?>&amp;mitra-id=<?= rawurlencode($partner['id']); ?>"><i class="fa fa-edit"></i> Edit</a><form method="post" onsubmit="return confirm(<?= htmlspecialchars(json_encode($mitraT('Delete this Partner?')), ENT_QUOTES); ?>);"><?= mikhmonCsrfField(); ?><input type="hidden" name="mitra_action" value="delete"><input type="hidden" name="mitra_id" value="<?= htmlspecialchars($partner['id'], ENT_QUOTES); ?>"><button class="btn bg-danger" type="submit"<?= $partner['user_id'] !== '' ? ' disabled title="' . htmlspecialchars($mitraT('Unlink the login account before deleting'), ENT_QUOTES) . '"' : ''; ?>><i class="fa fa-trash"></i> Delete</button></form></div></td></tr><?php endforeach; ?>
          <?php if (!$mitras): ?><tr><td colspan="11" class="mitra-empty">No Partners yet. Select Add Partner to create the first record.</td></tr><?php endif; ?><tr id="mitraNoResults" style="display:none"><td colspan="11" class="mitra-empty">No Partners match the filter.</td></tr>
        </tbody></table></div>
      </div>
    </div>
</div></div>
<div id="mitraModal" class="mitra-modal<?= $editMitra ? ' is-open' : ''; ?>" role="dialog" aria-modal="true" aria-labelledby="mitraModalTitle" aria-hidden="<?= $editMitra ? 'false' : 'true'; ?>">
  <div class="card box-bordered mitra-dialog" role="document">
    <div class="card-header mitra-dialog-header"><h3 id="mitraModalTitle"><i class="fa <?= $isEditingMitra ? 'fa-edit' : 'fa-user-plus'; ?>"></i> <?= $isEditingMitra ? 'Edit Partner' : 'Add Partner'; ?></h3><button class="btn bg-danger mitra-dialog-close" type="button" aria-label="<?= htmlspecialchars($mitraT('Close modal'), ENT_QUOTES); ?>">&times;</button></div>
    <div class="card-body">
      <form method="post" autocomplete="off">
        <?= mikhmonCsrfField(); ?>
        <input type="hidden" name="mitra_action" value="save">
        <input type="hidden" name="mitra_id" value="<?= htmlspecialchars($formMitra['id'], ENT_QUOTES); ?>">
        <div class="mitra-form">
          <?php if ($isEditingMitra): ?><div class="wide"><label>ID</label><input class="form-control" value="<?= htmlspecialchars($formMitra['id'], ENT_QUOTES); ?>" readonly></div><?php endif; ?>
          <div class="wide"><label for="mitra-name">Name *</label><input id="mitra-name" class="form-control" name="name" maxlength="100" required value="<?= htmlspecialchars($formMitra['name'], ENT_QUOTES); ?>"></div>
          <div><label for="mitra-category">Category *</label><select id="mitra-category" class="form-control" name="category" required><option value="reseller"<?= $formMitra['category'] === 'reseller' ? ' selected' : ''; ?>>Reseller</option><option value="biller"<?= $formMitra['category'] === 'biller' ? ' selected' : ''; ?>>Biller</option><option value="sales"<?= $formMitra['category'] === 'sales' ? ' selected' : ''; ?>>Sales</option></select></div>
          <div><label for="mitra-stock">Voucher Stock</label><input id="mitra-stock" class="form-control" type="number" min="0" step="1" name="voucher_stock" value="<?= (int) $formMitra['voucher_stock']; ?>"></div>
          <div><label for="mitra-phone">Phone</label><input id="mitra-phone" class="form-control" name="phone" maxlength="30" value="<?= htmlspecialchars($formMitra['phone'], ENT_QUOTES); ?>"></div>
          <div><label for="mitra-email">Email</label><input id="mitra-email" class="form-control" type="email" name="email" maxlength="120" value="<?= htmlspecialchars($formMitra['email'], ENT_QUOTES); ?>"></div>
          <div class="wide"><label for="mitra-address">Address</label><textarea id="mitra-address" class="form-control" name="address" maxlength="255"><?= htmlspecialchars($formMitra['address'], ENT_QUOTES); ?></textarea></div>
          <div><label for="mitra-commission">Commission</label><input id="mitra-commission" class="form-control" type="number" min="0" step="1" name="commission" value="<?= (float) $formMitra['commission']; ?>"></div>
          <div><label for="mitra-user">Login Account</label><select id="mitra-user" class="form-control" name="user_id"><option value="">No login account</option><?php foreach ($loginUsers as $loginUser): $usedByOther = isset($linkedUserIds[$loginUser['id']]) && $linkedUserIds[$loginUser['id']] !== $formMitra['id']; ?><option value="<?= htmlspecialchars($loginUser['id'], ENT_QUOTES); ?>" data-role="<?= htmlspecialchars($loginUser['role'], ENT_QUOTES); ?>"<?= $formMitra['user_id'] === $loginUser['id'] ? ' selected' : ''; ?><?= $usedByOther ? ' disabled' : ''; ?>><?= htmlspecialchars($loginUser['name'] . ' (' . strtoupper($loginUser['role']) . ')', ENT_QUOTES); ?></option><?php endforeach; ?></select></div>
          <div class="wide"><label><input type="checkbox" name="active" value="1"<?= !empty($formMitra['active']) ? ' checked' : ''; ?>> Active status</label></div>
        </div>
        <div class="mitra-form-actions"><button class="btn bg-primary" type="submit"><i class="fa fa-save"></i> Save Partner</button><button class="btn bg-warning mitra-dialog-cancel" type="button"><i class="fa fa-close"></i> Cancel</button></div>
      </form>
    </div>
  </div>
</div>
<script>
$(function(){
  var modal=$('#mitraModal'),openButton=$('#openMitraModal'),form=modal.find('form'),lastFocus=null,addPartnerLabel=<?= json_encode($mitraT('Add Partner')); ?>;
  function prepareAdd(){form[0].reset();form.find('[name="mitra_id"]').val('');form.find('[name="name"],[name="phone"],[name="email"],[name="address"]').val('');form.find('[name="voucher_stock"],[name="commission"]').val('0');form.find('[name="category"]').val('reseller');form.find('[name="user_id"]').val('');form.find('[name="active"]').prop('checked',true);$('#mitraModalTitle').html('<i class="fa fa-user-plus"></i> '+addPartnerLabel);updateAccountOptions();}
  function openModal(){lastFocus=document.activeElement;modal.addClass('is-open').attr('aria-hidden','false');$('body').addClass('mitra-modal-open');window.setTimeout(function(){$('#mitra-name').trigger('focus');},0);}
  function closeModal(){modal.removeClass('is-open').attr('aria-hidden','true');$('body').removeClass('mitra-modal-open');if(lastFocus)$(lastFocus).trigger('focus');else openButton.trigger('focus');}
  function updateAccountOptions(){var category=$('#mitra-category').val(),requiredRole=category==='biller'?'biller':'mitra',select=$('#mitra-user');select.find('option[data-role]').each(function(){var option=$(this),matches=option.data('role')===requiredRole;option.prop('hidden',!matches);if(!matches&&option.prop('selected'))select.val('');});}
  function filterMitras(){var query=$('#mitraSearch').val().toLowerCase(),category=$('#mitraCategoryFilter').val(),visible=0;$('.mitra-row').each(function(){var row=$(this),show=row.text().toLowerCase().indexOf(query)>-1&&(category==='all'||row.data('category')===category);row.toggle(show);if(show)visible++;});$('#mitraVisibleCount').text(visible);$('#mitraNoResults').toggle(visible===0&&$('.mitra-row').length>0);}
  openButton.on('click',function(){prepareAdd();openModal();});$('.mitra-dialog-close,.mitra-dialog-cancel').on('click',closeModal);modal.on('click',function(event){if(event.target===this)closeModal();});$(document).on('keydown',function(event){if(!modal.hasClass('is-open'))return;if(event.key==='Escape'){event.preventDefault();closeModal();return;}if(event.key==='Tab'){var focusable=modal.find('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),a[href]').filter(':visible'),first=focusable.first()[0],last=focusable.last()[0];if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}}});
  $('#mitra-category').on('change',updateAccountOptions);$('#mitraSearch').on('input',filterMitras);$('#mitraCategoryFilter').on('change',filterMitras);updateAccountOptions();filterMitras();if(modal.hasClass('is-open')){lastFocus=openButton[0];$('body').addClass('mitra-modal-open');window.setTimeout(function(){$('#mitra-name').trigger('focus');},0);}
});
</script>
