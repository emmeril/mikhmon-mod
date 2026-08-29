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
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-pie-chart"></i> <?= $_ppp_profiles ?> &nbsp;|&nbsp; <a href="./?ppp=add-profile&session=<?= $session ?>"><i class="fa fa-plus"></i> <?= $_add ?></a></h3>
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
                  <td><a href="./?ppp=edit-profile&profile=<?= rawurlencode($profile['name']) ?>&session=<?= $session ?>"><i class="fa fa-edit"></i> <?= htmlspecialchars($profile['name']) ?></a></td>
                  <td><?= htmlspecialchars(isset($profile['local-address']) ? $profile['local-address'] : '') ?></td>
                  <td><?= htmlspecialchars(isset($profile['remote-address']) ? $profile['remote-address'] : '') ?></td>
                  <td><?= htmlspecialchars(isset($profile['rate-limit']) ? $profile['rate-limit'] : '') ?></td>
                  <td class="text-right"><?= htmlspecialchars(pppProfilePriceFormat($meta['price'], $currency, $cekindo['indo'])) ?></td>
                  <td class="text-right"><?= htmlspecialchars(pppProfilePriceFormat($meta['selling-price'], $currency, $cekindo['indo'])) ?></td>
                  <td><?= htmlspecialchars($meta['expmode']) ?></td>
                  <td><?= htmlspecialchars($meta['validity']) ?></td>
                  <td><?= htmlspecialchars($meta['comment']) ?></td>
                  <td><a href="./?ppp=edit-profile&profile=<?= rawurlencode($profile['name']) ?>&session=<?= $session ?>"><i class="fa fa-edit"></i></a></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
