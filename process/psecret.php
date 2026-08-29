<?php
error_reporting(0);
if ($removesecr != '') $API->comm('/ppp/secret/remove', array('.id'=>$removesecr));
if ($enablesecr != '') $API->comm('/ppp/secret/set', array('.id'=>$enablesecr,'disabled'=>'no'));
if ($disablesecr != '') $API->comm('/ppp/secret/set', array('.id'=>$disablesecr,'disabled'=>'yes'));
echo "<script>window.location='./?ppp=secrets&session=".$session."'</script>";
?>
