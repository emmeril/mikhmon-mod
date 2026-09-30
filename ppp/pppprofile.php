<?php
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

require_once __DIR__ . "/profilemeta.php";
$profiles = $API->comm("/ppp/profile/print");
if (!is_array($profiles)) {
  $profiles = array();
}
?>
<style>
  .ppp-profile-header{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:52px}
  .ppp-profile-header h3{margin:0}
  .ppp-profile-header .btn,.ppp-profile-actions .btn{margin:0}
  .ppp-profile-actions{display:flex;align-items:center;gap:6px}
  @media(max-width:767px){
    .ppp-profile-header{align-items:stretch;flex-direction:column;padding-top:10px;padding-bottom:10px}
    .ppp-profile-header .btn,.ppp-profile-actions .btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;box-sizing:border-box}
    .ppp-profile-header .btn{width:100%}
  }
</style>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header ppp-profile-header">
        <h3><i class="fa fa-pie-chart"></i> <?= $_ppp_profiles ?></h3>
        <a class="btn bg-primary" href="./?ppp=add-profile&session=<?= $session ?>"><i class="fa fa-plus"></i> <?= $_add ?></a>
      </div>
      <div class="card-body">
        <div class="overflow box-bordered">
          <table class="table table-bordered table-hover text-nowrap">
            <thead>
              <tr>
                <th><?= count($profiles) ?></th>
                <th><?= $_name ?></th>
                <th>Local Address</th>
                <th>Remote Address</th>
                <th>Rate Limit</th>
                <th class="text-right"><?= $_price . ' ' . $currency ?></th>
                  <th class="text-right"><?= $_selling_price . ' ' . $currency ?></th>
                  <th>Expired</th>
                  <th>Validity</th>
                  <th><?= $_comment ?></th>
                <th><?= $_action ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($profiles as $profile) {
                $meta = pppProfileMetaDecode(isset($profile['comment']) ? $profile['comment'] : '');
              ?>
                <tr>
                  <td><i class="fa fa-minus-square text-danger pointer" onclick="if(confirm('Delete profile?'))loadpage('./?remove-pprofile=<?= rawurlencode($profile['.id']) ?>&session=<?= $session ?>')"></i></td>
                  <td><?= htmlspecialchars($profile['name']) ?></td>
                  <td><?= htmlspecialchars(isset($profile['local-address']) ? $profile['local-address'] : '') ?></td>
                  <td><?= htmlspecialchars(isset($profile['remote-address']) ? $profile['remote-address'] : '') ?></td>
                  <td><?= htmlspecialchars(isset($profile['rate-limit']) ? $profile['rate-limit'] : '') ?></td>
                  <td class="text-right"><?= htmlspecialchars(pppProfilePriceFormat($meta['price'], $currency, $cekindo['indo'])) ?></td>
                  <td class="text-right"><?= htmlspecialchars(pppProfilePriceFormat($meta['selling-price'], $currency, $cekindo['indo'])) ?></td>
                  <td><?= htmlspecialchars($meta['expmode']) ?></td>
                  <td><?= htmlspecialchars($meta['validity']) ?></td>
                  <td><?= htmlspecialchars($meta['comment']) ?></td>
                  <td><div class="ppp-profile-actions"><a class="btn bg-primary" href="./?ppp=edit-profile&profile=<?= rawurlencode($profile['name']) ?>&session=<?= $session ?>"><i class="fa fa-edit"></i> <?= $_edit ?></a></div></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
