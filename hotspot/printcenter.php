<?php
error_reporting(0);

if (!isset($_SESSION['mikhmon'])) {
  header('Location:../admin.php?id=login');
  exit;
}

$getuser = (array) $API->comm('/ip/hotspot/user/print');
if (function_exists('mikhmonIsMitra') && mikhmonIsMitra()) {
  $assignedUsernames = function_exists('mikhmonMitraUsernames') ? mikhmonMitraUsernames($session) : array();
  $getuser = array_values(array_filter($getuser, function ($user) use ($assignedUsernames) {
    if (!is_array($user)) return false;
    if (mikhmonRowBelongsToCurrentMitra($user)) return true;
    return isset($user['name']) && isset($assignedUsernames[(string) $user['name']]);
  }));
}
$profiles = array();
$comments = array();
foreach ($getuser as $user) {
  if (!empty($user['profile'])) $profiles[(string) $user['profile']] = true;
  if (!empty($user['comment'])) {
    $comment = (string) $user['comment'];
    $comments[$comment] = isset($comments[$comment]) ? $comments[$comment] + 1 : 1;
  }
}
ksort($profiles);
ksort($comments);
?>
<style>
  .print-center-toolbar {
    display: grid;
    grid-template-columns: auto repeat(3, minmax(0, 1fr));
    align-items: center;
    gap: 6px;
  }
  .print-center-toolbar > span { white-space: nowrap; }
  .print-center-toolbar .btn { width: 100%; white-space: nowrap; }
  @media (max-width: 600px) {
    .print-center-toolbar { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .print-center-toolbar > span { grid-column: 1 / -1; text-align: center; }
  }
</style>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-print"></i> Print Center</h3>
      </div>
      <div class="card-body">
        <div class="row">
          <div class="col-4 pd-t-5 pd-b-5">
            <input id="printCenterSearch" type="text" class="form-control" placeholder="Cari username, profile, atau komentar">
          </div>
          <div class="col-4 pd-t-5 pd-b-5">
            <select id="printCenterProfile" class="form-control">
              <option value="">Semua profile</option>
              <?php foreach (array_keys($profiles) as $profile): ?>
                <option value="<?= htmlspecialchars($profile, ENT_QUOTES); ?>"><?= htmlspecialchars($profile, ENT_QUOTES); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-4 pd-t-5 pd-b-5">
            <select id="printCenterComment" class="form-control">
              <option value="">Semua comment</option>
              <?php foreach ($comments as $comment => $commentCount): ?>
                <option value="<?= htmlspecialchars($comment, ENT_QUOTES); ?>"><?= htmlspecialchars($comment, ENT_QUOTES); ?> [<?= $commentCount; ?>]</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="box bg-secondary print-center-toolbar">
          <span><i class="fa fa-check-square-o"></i> <span id="printCenterCount">0</span> dipilih</span>
          <button type="button" class="btn bg-primary" onclick="printCenterSubmit('default')"><i class="fa fa-print"></i> <?= $_print_default; ?></button>
          <button type="button" class="btn bg-primary" onclick="printCenterSubmit('qr')"><i class="fa fa-qrcode"></i> <?= $_print_qr; ?></button>
          <button type="button" class="btn bg-primary" onclick="printCenterSubmit('small')"><i class="fa fa-print"></i> <?= $_print_small; ?></button>
        </div>
        <div class="overflow mr-t-10 box-bordered" style="max-height:70vh">
          <table id="printCenterTable" class="table table-bordered table-hover text-nowrap">
            <thead><tr><th style="width:42px" class="text-center"><input type="checkbox" id="printCenterHeader"></th><th>Username</th><th>Profile</th><th>Server</th><th>Comment</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($getuser as $user):
              $name = isset($user['name']) ? (string) $user['name'] : '';
              $profile = isset($user['profile']) ? (string) $user['profile'] : '';
              $server = isset($user['server']) ? (string) $user['server'] : '';
              $comment = isset($user['comment']) ? (string) $user['comment'] : '';
              $disabled = isset($user['disabled']) && $user['disabled'] === 'true';
            ?>
              <tr data-profile="<?= htmlspecialchars($profile, ENT_QUOTES); ?>" data-comment="<?= htmlspecialchars($comment, ENT_QUOTES); ?>">
                <td class="text-center"><input type="checkbox" class="print-center-user" value="<?= htmlspecialchars($name, ENT_QUOTES); ?>" aria-label="Pilih voucher <?= htmlspecialchars($name, ENT_QUOTES); ?>"></td>
                <td><?= htmlspecialchars($name, ENT_QUOTES); ?></td>
                <td><?= htmlspecialchars($profile, ENT_QUOTES); ?></td>
                <td><?= htmlspecialchars($server, ENT_QUOTES); ?></td>
                <td><?= htmlspecialchars($comment, ENT_QUOTES); ?></td>
                <td class="<?= $disabled ? 'text-danger' : 'text-success'; ?>"><?= $disabled ? 'Disabled' : 'Aktif'; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  function rows() { return Array.prototype.slice.call(document.querySelectorAll('#printCenterTable tbody tr')); }
  function visibleRows() { return rows().filter(function (row) { return row.style.display !== 'none'; }); }
  function updateCount() { document.getElementById('printCenterCount').textContent = document.querySelectorAll('.print-center-user:checked').length; }
  function filterRows() {
    var search = document.getElementById('printCenterSearch').value.toLowerCase();
    var profile = document.getElementById('printCenterProfile').value;
    var comment = document.getElementById('printCenterComment').value;
    rows().forEach(function (row) {
      var matchSearch = row.textContent.toLowerCase().indexOf(search) !== -1;
      var matchProfile = !profile || row.getAttribute('data-profile') === profile;
      var matchComment = !comment || row.getAttribute('data-comment') === comment;
      row.style.display = matchSearch && matchProfile && matchComment ? '' : 'none';
    });
  }
  window.printCenterSubmit = function (format) {
    var selected = Array.prototype.slice.call(document.querySelectorAll('.print-center-user:checked'));
    if (!selected.length) { alert('Silakan pilih voucher terlebih dahulu.'); return; }
    var form = document.createElement('form');
    form.method = 'post'; form.action = './voucher/print.php'; form.target = '_blank';
    [['session', <?= json_encode($session); ?>], ['qr', format === 'qr' ? 'yes' : 'no'], ['small', format === 'small' ? 'yes' : 'no'], ['users_json', JSON.stringify(selected.map(function (input) { return input.value; }))]].forEach(function (field) {
      var input = document.createElement('input'); input.type = 'hidden'; input.name = field[0]; input.value = field[1]; form.appendChild(input);
    });
    document.body.appendChild(form); form.submit(); form.remove();
  };
  document.getElementById('printCenterSearch').addEventListener('input', filterRows);
  document.getElementById('printCenterProfile').addEventListener('change', filterRows);
  document.getElementById('printCenterComment').addEventListener('change', filterRows);
  function toggleVisibleRows() {
    visibleRows().forEach(function (row) { var input = row.querySelector('.print-center-user'); if (input) input.checked = this.checked; }, this);
    updateCount();
  }
  document.getElementById('printCenterHeader').addEventListener('change', toggleVisibleRows);
  document.querySelectorAll('.print-center-user').forEach(function (input) { input.addEventListener('change', updateCount); });
})();
</script>
