<?php

require dirname(__DIR__) . '/include/crudmodal.php';

function crudModalAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

ob_start();
mikhmonCrudModalStart('./?customer=list&session=router-a', 'Add customer');
echo '<form><input name="name"></form>';
mikhmonCrudModalEnd();
$html = ob_get_clean();

crudModalAssert(strpos($html, 'role="dialog"') !== false, 'modal exposes dialog semantics');
crudModalAssert(strpos($html, 'aria-modal="true"') !== false, 'modal is announced as modal');
crudModalAssert(strpos($html, 'data-close-url="./?customer=list&amp;session=router-a"') !== false, 'close target is escaped');
crudModalAssert(strpos($html, 'event.key==="Escape"') !== false, 'modal closes with Escape');
crudModalAssert(strpos($html, 'event.key!=="Tab"') !== false, 'modal traps keyboard focus');
crudModalAssert(strpos($html, 'min-height:44px') !== false, 'mobile controls keep a minimum tap height');
crudModalAssert(strpos($html, '.crud-modal-content .card-header{min-height:52px') !== false, 'header contains the close button');
crudModalAssert(strpos($html, 'top:4px;right:5px;width:44px;height:44px') !== false, 'close button stays inside the dialog edge');
crudModalAssert(strpos($html, 'max-height:calc(100dvh - 24px)') !== false, 'mobile modal stays inside the viewport');

$routerSource = file_get_contents(dirname(__DIR__) . '/index.php');
$voucherSource = file_get_contents(dirname(__DIR__) . '/hotspot/users.php');
$menuSource = file_get_contents(dirname(__DIR__) . '/include/menu.php');
$profileSource = file_get_contents(dirname(__DIR__) . '/hotspot/userprofile.php');
$pppSecretSource = file_get_contents(dirname(__DIR__) . '/ppp/pppsecrets.php');
$pppProfileSource = file_get_contents(dirname(__DIR__) . '/ppp/pppprofile.php');
$identitySource = file_get_contents(dirname(__DIR__) . '/customer/identities.php');
$customerSource = file_get_contents(dirname(__DIR__) . '/customer/customers.php');
$sessionSource = file_get_contents(dirname(__DIR__) . '/settings/sessions.php');
crudModalAssert(strpos($routerSource, "'Generate hotspot vouchers'") !== false, 'voucher generator route uses the modal shell');
crudModalAssert(strpos($voucherSource, 'class="btn bg-green" href="./?hotspot-user=generate') !== false, 'voucher list shows the generator button');
crudModalAssert(strpos($voucherSource, 'class="btn bg-primary" href="./?hotspot-user=add') !== false, 'voucher list shows the add-user button');
crudModalAssert(strpos($menuSource, 'href="./?hotspot-user=generate') === false, 'voucher submenu does not show the generator action');
crudModalAssert(strpos($menuSource, 'href="./?hotspot-user=add') === false, 'voucher submenu does not show the add-user action');
crudModalAssert(strpos($menuSource, 'href="./?user-profile=add') === false, 'hotspot submenu does not show the add-profile action');
crudModalAssert(strpos($profileSource, "class='hotspot-profile-actions'") !== false, 'hotspot profile table has a dedicated action column');
crudModalAssert(strpos($profileSource, "<td><a class='btn bg-primary' title='Open User Profile") === false, 'hotspot profile name is not used as the edit button');
$lockHeaderPosition = strpos($profileSource, '<th class="align-middle"><?= $_lock_user ?></th>');
$actionHeaderPosition = strpos($profileSource, '<th class="text-center align-middle"><?= $_action ?></th>');
$lockCellPosition = strpos($profileSource, 'echo $getgracep[6];');
$actionCellPosition = strpos($profileSource, "echo \"<td class='hotspot-profile-actions'>");
crudModalAssert($lockHeaderPosition !== false && $actionHeaderPosition > $lockHeaderPosition, 'hotspot profile action header follows the lock-user column');
crudModalAssert($lockCellPosition !== false && $actionCellPosition > $lockCellPosition, 'hotspot profile action cell follows the lock-user value');
crudModalAssert(strpos($menuSource, 'href="./?ppp=addsecret') === false, 'PPPoE submenu does not show the add-user action');
crudModalAssert(strpos($menuSource, 'href="./?customer=identity-add') === false, 'customer submenu does not show the add-identity action');
crudModalAssert(strpos($menuSource, 'href="./?customer=service-add') === false, 'customer and PPPoE submenus do not show the add-service action');
crudModalAssert(strpos($menuSource, 'href="./?admin=router-add') === false, 'settings submenu does not show the add-router action');
crudModalAssert(strpos($menuSource, 'href="./?admin=session-settings') === false, 'settings submenu does not duplicate the router edit action');
crudModalAssert(strpos($menuSource, 'id=settings&router=new-') === false, 'standalone settings sidebar does not show the add-router action');
crudModalAssert(strpos($pppSecretSource, '<td><?= htmlspecialchars($name) ?></td>') !== false, 'PPPoE secret name is plain text');
crudModalAssert(strpos($pppSecretSource, 'class="ppp-secret-actions"') !== false, 'PPPoE secret edit control is in the action column');
crudModalAssert(strpos($pppSecretSource, 'href="./?ppp=addsecret') !== false, 'PPPoE secret list keeps its add button');
crudModalAssert(strpos($pppProfileSource, '<td><?= htmlspecialchars($profile[\'name\']) ?></td>') !== false, 'PPPoE profile name is plain text');
crudModalAssert(strpos($pppProfileSource, 'class="ppp-profile-actions"') !== false, 'PPPoE profile edit control is in the action column');
crudModalAssert(strpos($pppProfileSource, 'href="./?ppp=add-profile') !== false, 'PPPoE profile list keeps its add button');
crudModalAssert(strpos($identitySource, 'href="./?customer=identity-add') !== false, 'identity list keeps its add button');
crudModalAssert(strpos($customerSource, 'href="./?customer=service-add') !== false, 'customer list keeps its add-service button');
crudModalAssert(strpos($sessionSource, 'id=settings&amp;router=new-') !== false, 'router list keeps its add button');

echo 'crud-modal-tests: OK' . PHP_EOL;
