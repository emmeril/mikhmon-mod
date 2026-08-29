<?php
error_reporting(0);
if ($removesecr != '') {
  $rows = $API->comm('/ppp/secret/print', array('?.id'=>$removesecr));
  $name = isset($rows[0]['name']) ? $rows[0]['name'] : '';
  $API->comm('/ppp/secret/remove', array('.id'=>$removesecr));
  if ($name != '') {
    $schedulers = $API->comm('/system/scheduler/print', array('?name'=>'mikhmon-pppoe-'.$name));
    if (isset($schedulers[0]['.id'])) $API->comm('/system/scheduler/remove', array('.id'=>$schedulers[0]['.id']));
    $markers = $API->comm('/system/script/print', array('?name'=>'mikhmon-pppoe-sale-'.$name));
    if (isset($markers[0]['.id'])) $API->comm('/system/script/remove', array('.id'=>$markers[0]['.id']));
  }
}
if ($enablesecr != '') {
  $rows = $API->comm('/ppp/secret/print', array('?.id'=>$enablesecr));
  $name = isset($rows[0]['name']) ? $rows[0]['name'] : '';
  if ($name != '') {
    $schedulers = $API->comm('/system/scheduler/print', array('?name'=>'mikhmon-pppoe-'.$name));
    if (count($schedulers) == 0) {
      $markers = $API->comm('/system/script/print', array('?name'=>'mikhmon-pppoe-sale-'.$name));
      if (isset($markers[0]['.id'])) $API->comm('/system/script/remove', array('.id'=>$markers[0]['.id']));
    }
  }
  $API->comm('/ppp/secret/set', array('.id'=>$enablesecr,'disabled'=>'no'));
}
if ($disablesecr != '') $API->comm('/ppp/secret/set', array('.id'=>$disablesecr,'disabled'=>'yes'));
echo "<script>window.location='./?ppp=secrets&session=".$session."'</script>";
?>
