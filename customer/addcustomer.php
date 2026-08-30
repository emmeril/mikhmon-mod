<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/database.php');
$isEdit = isset($customer) && ($customer === 'edit' || ($customer === 'list' && $customerid !== ''));
$customerError = '';
$editCustomer = $isEdit ? mikhmonFindCustomer($session, $customerid) : array();
if ($isEdit && !$editCustomer) $customerError = 'Data pelanggan tidak ditemukan.';
if ($isEdit && $editCustomer && !mikhmonCanManageCustomer($editCustomer)) { $customerError = 'Anda tidak berhak mengelola pelanggan ini.'; $editCustomer = array(); }
$mitras = mikhmonIsAdmin() ? mikhmonGetUsers('mitra', $session) : array();
$hotspotProfiles = $hotspotServers = $pppoeProfiles = array();
function customerApiError($response) { if (!is_array($response)) return ''; foreach (array('!trap','!fatal') as $type) if (isset($response[$type][0]['message'])) return $response[$type][0]['message']; return ''; }
function customerFormValue($field, $default = '') { return isset($_POST[$field]) ? (string) $_POST[$field] : (string) $default; }
function customerSelected($field, $value, $default = '') { return customerFormValue($field, $default) === (string) $value ? ' selected' : ''; }
if (!empty($routerConnected)) {
  $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print'); $hotspotServers = $API->comm('/ip/hotspot/print'); $pppoeProfiles = $API->comm('/ppp/profile/print');
  if (!is_array($hotspotProfiles) || customerApiError($hotspotProfiles) !== '') $hotspotProfiles = array();
  if (!is_array($hotspotServers) || customerApiError($hotspotServers) !== '') $hotspotServers = array();
  if (!is_array($pppoeProfiles) || customerApiError($pppoeProfiles) !== '') $pppoeProfiles = array();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_action']) && ($isEdit || $_POST['customer_action'] === 'create')) {
  $creating = $_POST['customer_action'] === 'create' && !$isEdit; $name = trim(customerFormValue('customer_name')); $phone = trim(customerFormValue('customer_phone')); $address = trim(customerFormValue('customer_address'));
  $mitraId = mikhmonIsAdmin() ? trim(customerFormValue('mitra_id', $editCustomer['mitra_id'] ?? '')) : (mikhmonIsMitra() ? mikhmonUserId() : ($editCustomer['mitra_id'] ?? ''));
  $postedServices = array(); $types = (array)($_POST['service_type'] ?? array()); $ids = (array)($_POST['service_id'] ?? array()); $users = (array)($_POST['service_username'] ?? array()); $passwords = (array)($_POST['service_password'] ?? array()); $profiles = (array)($_POST['service_profile'] ?? array()); $servers = (array)($_POST['service_server'] ?? array());
  foreach ($users as $i => $value) $postedServices[] = array('id'=>trim((string)($ids[$i] ?? '')), 'service'=>($types[$i] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot', 'username'=>trim((string)$value), 'password'=>(string)($passwords[$i] ?? ''), 'profile'=>trim((string)($profiles[$i] ?? '')), 'server'=>trim((string)($servers[$i] ?? 'all')));
  $selectedMitra = $mitraId !== '' ? mikhmonFindUser($mitraId) : false;
  if ($name === '' || !$postedServices) $customerError = 'Nama pelanggan dan minimal satu layanan wajib diisi.';
  elseif ($mitraId !== '' && (!$selectedMitra || $selectedMitra['role'] !== 'mitra' || $selectedMitra['session'] !== $session)) $customerError = 'Mitra yang dipilih tidak valid untuk router ini.';
  elseif (empty($routerConnected)) $customerError = 'Router MikroTik tidak terhubung.';
  else {
    $oldServices = mikhmonCustomerServices($editCustomer); $processed = array(); $failed = '';
    foreach ($postedServices as $row) {
      if ($row['username'] === '' || $row['profile'] === '' || (($creating || $row['id'] === '') && $row['password'] === '')) { $failed = 'Username dan profile wajib diisi; password wajib untuk layanan baru.'; break; }
      $command = $row['service'] === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user'; $old = array(); foreach ($oldServices as $candidate) if ($row['id'] !== '' && $candidate['id'] === $row['id']) { $old = $candidate; break; }
      $oldUsername = $old['username'] ?? ''; $oldCommand = ($old['service'] ?? '') === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user'; $changingType = $old && $oldCommand !== $command; $target = $oldUsername !== '' ? $API->comm($oldCommand . '/print', array('?name'=>$oldUsername)) : array();
      if (customerApiError($target) !== '' || ($old && !isset($target[0]['.id']))) { $failed = 'User MikroTik untuk layanan ' . $oldUsername . ' tidak ditemukan.'; break; }
      $existing = $API->comm($command . '/print', array('?name'=>$row['username'])); $sameName = $oldUsername !== '' && $oldUsername === $row['username'];
      if (customerApiError($existing) !== '' || ((!$old || $changingType) && count($existing) > 0) || ($old && !$changingType && !$sameName && count($existing) > 0)) { $failed = 'Username ' . $row['username'] . ' sudah digunakan di MikroTik.'; break; }
      $args = $row['service'] === 'pppoe' ? array('name'=>$row['username'],'service'=>'pppoe','profile'=>$row['profile'],'comment'=>$name) : array('server'=>$row['server'] ?: 'all','name'=>$row['username'],'profile'=>$row['profile'],'comment'=>'up-'.$name); if (mikhmonIsMitra()) $args['comment'] .= ' ' . mikhmonOwnerTag(); if ($row['password'] !== '') $args['password'] = $row['password']; if ($old && !$changingType) $args['.id'] = $target[0]['.id']; else $args['disabled'] = 'no';
      $response = $API->comm($command . ($old && !$changingType ? '/set' : '/add'), $args); if (customerApiError($response) !== '') { $failed = 'MikroTik menolak layanan ' . $row['username'] . ': ' . customerApiError($response); break; }
      if ($changingType) $API->comm($oldCommand . '/remove', array('.id'=>$target[0]['.id']));
      $processed[] = array('id'=>$row['id'], 'service'=>$row['service'], 'username'=>$row['username'], 'profile'=>$row['profile'], 'server'=>$row['server']);
    }
    if ($failed === '' && !$creating) {
      $keptIds = array(); foreach ($processed as $row) if ($row['id'] !== '') $keptIds[$row['id']] = true;
      foreach ($oldServices as $old) if (!isset($keptIds[$old['id']])) {
        $oldCommand = $old['service'] === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
        $oldRows = $API->comm($oldCommand . '/print', array('?name'=>$old['username']));
        if (isset($oldRows[0]['.id'])) $API->comm($oldCommand . '/remove', array('.id'=>$oldRows[0]['.id']));
      }
    }
    if ($failed !== '') $customerError = $failed;
    elseif (mikhmonSaveCustomerWithServices($session, $isEdit ? $editCustomer['id'] : '', $name, $phone, $address, $processed, $mitraId) === false) $customerError = 'Data pelanggan lokal gagal disimpan.';
    else { $result = $creating ? 'created=1' : 'updated=1'; echo "<script>window.location='./?customer=list&session=" . rawurlencode($session) . '&' . $result . "'</script>"; exit; }
  }
}
$defaultServices = !$isEdit ? array(array('service'=>isset($_GET['service']) && $_GET['service']==='pppoe' ? 'pppoe' : 'hotspot','username'=>'','profile'=>'','server'=>'all','id'=>'')) : mikhmonCustomerServices($editCustomer);
$defaultName = $editCustomer['name'] ?? ''; $defaultPhone = $editCustomer['phone'] ?? ''; $defaultAddress = $editCustomer['address'] ?? '';
?>
<div class="row"><div class="col-12"><div class="card box-bordered"><div class="card-header"><h3><i class="fa <?= $isEdit ? 'fa-edit' : 'fa-user-plus'; ?>"></i> <?= $isEdit ? 'Edit Pelanggan' : 'Tambah Pelanggan'; ?></h3></div><div class="card-body">
<?php if ($customerError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($customerError, ENT_QUOTES); ?></div><?php endif; ?>
<?php if (!$isEdit || $editCustomer): ?><form method="post" autocomplete="off"><input type="hidden" name="customer_action" value="<?= $isEdit ? 'update' : 'create'; ?>"><table class="table"><tr><td>Nama Pelanggan</td><td><input class="form-control" name="customer_name" maxlength="100" required value="<?= htmlspecialchars(customerFormValue('customer_name',$defaultName),ENT_QUOTES); ?>"></td></tr><tr><td>Nomor HP</td><td><input class="form-control" name="customer_phone" maxlength="30" value="<?= htmlspecialchars(customerFormValue('customer_phone',$defaultPhone),ENT_QUOTES); ?>"></td></tr><tr><td>Alamat</td><td><textarea class="form-control" name="customer_address" maxlength="255"><?= htmlspecialchars(customerFormValue('customer_address',$defaultAddress),ENT_QUOTES); ?></textarea></td></tr><?php if (mikhmonIsAdmin()): ?><tr><td>Mitra</td><td><select class="form-control" name="mitra_id"><option value="">Belum ditetapkan</option><?php foreach ($mitras as $mitra): ?><option value="<?= htmlspecialchars($mitra['id'],ENT_QUOTES); ?>"<?= customerSelected('mitra_id',$mitra['id'],$editCustomer['mitra_id'] ?? ''); ?>><?= htmlspecialchars($mitra['name'],ENT_QUOTES); ?></option><?php endforeach; ?></select></td></tr><?php endif; ?></table>
<h4>Layanan <button type="button" class="btn bg-secondary" onclick="addServiceRow()"><i class="fa fa-plus"></i> Tambah Layanan</button></h4><div id="serviceRows"><?php foreach ($defaultServices as $service): ?><div class="service-row box-bordered" style="padding:10px;margin-bottom:8px"><input type="hidden" name="service_id[]" value="<?= htmlspecialchars($service['id'] ?? '',ENT_QUOTES); ?>"><div class="row"><div class="col-2"><select class="form-control service-type" name="service_type[]" onchange="toggleServiceRow(this)"><option value="hotspot"<?= ($service['service'] ?? '')==='hotspot'?' selected':''; ?>>Hotspot</option><option value="pppoe"<?= ($service['service'] ?? '')==='pppoe'?' selected':''; ?>>PPPoE</option></select></div><div class="col-3"><input class="form-control" name="service_username[]" placeholder="Username" required value="<?= htmlspecialchars($service['username'] ?? '',ENT_QUOTES); ?>"></div><div class="col-3"><input class="form-control" name="service_password[]" type="password" placeholder="<?= $isEdit?'Password baru (opsional)':'Password'; ?>"<?= $isEdit?'':' required'; ?>></div><div class="col-3"><select class="form-control service-profile" name="service_profile[]" required><option value="">Pilih profile</option><?php foreach (($service['service'] ?? 'hotspot')==='pppoe'?$pppoeProfiles:$hotspotProfiles as $profile): ?><?php if(isset($profile['name'])): ?><option value="<?= htmlspecialchars($profile['name'],ENT_QUOTES); ?>"<?= ($service['profile'] ?? '')===$profile['name']?' selected':''; ?>><?= htmlspecialchars($profile['name'],ENT_QUOTES); ?></option><?php endif; ?><?php endforeach; ?></select></div><div class="col-1"><button type="button" class="btn bg-danger" onclick="this.closest('.service-row').remove()"><i class="fa fa-trash"></i></button></div></div><div class="service-server" style="margin-top:8px;<?= ($service['service'] ?? 'hotspot')==='pppoe'?'display:none':''; ?>"><select class="form-control" name="service_server[]"><option value="all">all</option><?php foreach($hotspotServers as $server): ?><?php if(isset($server['name'])): ?><option value="<?= htmlspecialchars($server['name'],ENT_QUOTES); ?>"<?= ($service['server'] ?? 'all')===$server['name']?' selected':''; ?>><?= htmlspecialchars($server['name'],ENT_QUOTES); ?></option><?php endif; ?><?php endforeach; ?></select></div></div><?php endforeach; ?></div><a class="btn bg-warning" href="./?customer=list&session=<?= rawurlencode($session); ?>"><i class="fa fa-close"></i> Batal</a> <button class="btn bg-primary" type="submit" onclick="loader()"><i class="fa fa-save"></i> Simpan</button></form><?php endif; ?></div></div></div></div>
<script>
var customerProfiles={hotspot:[<?php foreach($hotspotProfiles as $p) if(isset($p['name'])) echo "'" . addslashes($p['name']) . "',"; ?>],pppoe:[<?php foreach($pppoeProfiles as $p) if(isset($p['name'])) echo "'" . addslashes($p['name']) . "',"; ?>]};
function toggleServiceRow(select){var row=select.closest('.service-row'), type=select.value, profile=$(row).find('.service-profile'), current=profile.val(); profile.empty().append('<option value="">Pilih profile</option>'); $.each(customerProfiles[type],function(_,name){profile.append($('<option>').val(name).text(name));}); if($.inArray(current,customerProfiles[type])>=0) profile.val(current); $(row).find('.service-server').toggle(type!=='pppoe');}
function addServiceRow(){var first=$('.service-row:first').clone();first.find('input').val('');first.find('select').each(function(){this.selectedIndex=0;});$('#serviceRows').append(first);toggleServiceRow(first.find('.service-type')[0]);}
</script>
