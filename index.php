<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
session_start();
// hide all error
error_reporting(0);
// check url

ob_start("ob_gzhandler");


$url = $_SERVER['REQUEST_URI'];

// load session MikroTik

$session = $_GET['session'];

if (!isset($_SESSION["mikhmon"])) {
  header("Location:./admin.php?id=login");
} elseif (empty($session)) {
  include_once('./include/config.php');
  include_once('./include/access.php');
  if (!mikhmonRefreshStaffSession()) {
    session_destroy();
    header('Location:./admin.php?id=login');
    exit;
  }
  if (mikhmonIsAdmin()) {
    $landingTarget = mikhmonAdminLandingUrl($data);
  } else {
    $assignedSession = rawurlencode(mikhmonAssignedSession());
    $landingTarget = mikhmonIsBiller() ? './?billing=1&session=' . $assignedSession : './?session=' . $assignedSession;
  }
  echo "<script>window.location=" . json_encode($landingTarget) . "</script>";
} else {
  $_SESSION["$session"] = $session;
  $setsession = $_SESSION["$session"];

  $_SESSION["connect"] = "";

// time zone
  date_default_timezone_set($_SESSION['timezone']);

// lang
  include('./lang/isocodelang.php');
  include('./include/lang.php');
  include('./lang/'.$langid.'.php');

// quick bt
  include('./include/quickbt.php');

// load config
  include('./include/config.php');
  include_once('./include/access.php');
  include_once('./include/systemlog.php');
  if (!mikhmonRefreshStaffSession()) {
    session_destroy();
    header('Location:./admin.php?id=login');
    exit;
  }
  if (!mikhmonIsAdmin() && mikhmonAssignedSession() !== '' && (string) $session !== mikhmonAssignedSession()) {
    $assignedSession = rawurlencode(mikhmonAssignedSession());
    $assignedTarget = mikhmonIsBiller() ? './?billing=1&session=' . $assignedSession : './?session=' . $assignedSession;
    header('Location: ' . $assignedTarget);
    exit;
  }
  include('./include/readcfg.php');

// theme
  include('./include/theme.php');
  include('./settings/settheme.php');
  include('./settings/setlang.php');
  if ($_SESSION['theme'] == "") {
    $theme = $theme;
    $themecolor = $themecolor;
  } else {
    $theme = $_SESSION['theme'];
    $themecolor = $_SESSION['themecolor'];
  }

// Router access is initialized after routing so local admin pages stay usable
// when a configured MikroTik is offline.
  include_once('./lib/routeros_api.class.php');
  include_once('./lib/formatbytesbites.php');
  

// get variable
  $hotspot = $_GET['hotspot'];
  $hotspotuser = $_GET['hotspot-user'];
  $userbyname = $_GET['hotspot-user'];
  $removeuseractive = $_GET['remove-user-active'];
  $removehost = $_GET['remove-host'];
  $removecookie = $_GET['remove-cookie'];
  $removeipbinding = $_GET['remove-ip-binding'];
  $removehotspotuser = $_GET['remove-hotspot-user'];
  $removehotspotusers = $_GET['remove-hotspot-users'];
  $removeuserprofile = $_GET['remove-user-profile'];
  $resethotspotuser = $_GET['reset-hotspot-user'];
  $removehotspotuserbycomment = $_GET['remove-hotspot-user-by-comment'];
  $removeexpiredhotspotuser = $_GET['remove-hotspot-user-expired'];
  $enablehotspotuser = $_GET['enable-hotspot-user'];
  $disablehotspotuser = $_GET['disable-hotspot-user'];
  $enableipbinding = $_GET['enable-ip-binding'];
  $disableipbinding = $_GET['disable-ip-binding'];
  $userprofile = $_GET['user-profile'];
  $userprofilebyname = $_GET['user-profile'];
  $sys = $_GET['system'];
  $enablesch = $_GET['enable-scheduler'];
  $disablesch = $_GET['disable-scheduler'];
  $removesch = $_GET['remove-scheduler'];
  $macbinding = $_GET['mac'];
  $ipbinding = $_GET['addr'];
  $ppp = $_GET['ppp'];
  $secretbyname = $_GET['secret'];
  $enablesecr = $_GET['enable-pppsecret'];
  $disablesecr = $_GET['disable-pppsecret'];
  $removesecr = $_GET['remove-pppsecret'];
  $removepprofile = $_GET['remove-pprofile'];
  $removepactive = $_GET['remove-pactive'];
  $srv = $_GET['srv'];
  $prof = $_GET['profile'];
  $comm = $_GET['comment'];
  $serveractive = $_GET['server'];
  $report = $_GET['report'];
  $reportservice = $_GET['service'];
  $removereport = $_GET['remove-report'];
  $minterface = $_GET['interface'];
  $customer = $_GET['customer'];
  $customerid = $_GET['customer-id'];
  $billing = $_GET['billing'];
  $commission = $_GET['commission'];
  $admin = $_GET['admin'];
  $router = $_GET['router'];

  $requestedRoute = 'other';
  if ($hotspot == 'logout') $requestedRoute = 'logout';
  elseif ($billing == '1') $requestedRoute = 'billing';
  elseif ($commission == '1') $requestedRoute = 'commission';
  elseif ($customer == 'list' && $customerid != '') $requestedRoute = 'customer-identity-edit';
  elseif ($customer == 'list') $requestedRoute = 'customer-list';
  elseif ($customer == 'identity-list') $requestedRoute = 'customer-identity-list';
  elseif ($customer == 'identity-edit' || $customer == 'edit') $requestedRoute = 'customer-identity-edit';
  elseif ($customer == 'identity-add' || $customer == 'add') $requestedRoute = 'customer-identity-add';
  elseif ($customer == 'service-edit') $requestedRoute = 'customer-service-edit';
  elseif ($customer == 'service-add') $requestedRoute = 'customer-service-add';
  elseif ($report == 'selling') $requestedRoute = 'report-selling';
  elseif ($report == 'resume-report') $requestedRoute = 'report-resume';
  elseif ($report == 'systemlog') $requestedRoute = 'system-log';
  elseif ($admin == 'settings') $requestedRoute = 'admin-settings';
  elseif ($admin == 'routers') $requestedRoute = 'admin-routers';
  elseif ($admin == 'users') $requestedRoute = 'admin-users';
  elseif ($admin == 'fonnte') $requestedRoute = 'admin-fonnte';
  elseif ($admin == 'payment-gateway') $requestedRoute = 'admin-payment-gateway';
  elseif ($admin == 'router-add') $requestedRoute = 'admin-router-add';
  elseif ($admin == 'session-settings') $requestedRoute = 'admin-session-settings';
  elseif ($admin == 'database') $requestedRoute = 'admin-database';
  elseif ($hotspotuser == 'generate') $requestedRoute = 'hotspot-generate';
  elseif ($hotspot == 'print-center') $requestedRoute = 'hotspot-print-center';
  elseif ($hotspot == 'active') $requestedRoute = 'hotspot-active';
  elseif ($hotspot == 'users-by-profile') $requestedRoute = 'hotspot-vouchers';
  elseif ($hotspot == 'users') $requestedRoute = 'hotspot-users';
  elseif ($ppp == 'secrets') $requestedRoute = 'pppoe-users';
  elseif ($ppp == 'active') $requestedRoute = 'pppoe-active';
  elseif ($hotspot == 'dashboard' || substr(explode('=', $url)[0], -9) == '/?session') $requestedRoute = 'home';

  if (!mikhmonCanOpenMainRoute($requestedRoute)) {
    $roleTarget = mikhmonIsBiller()
      ? './?billing=1&session=' . rawurlencode($session)
      : './?session=' . rawurlencode($session);
    header('Location: ' . $roleTarget);
    exit;
  }

  $localRoutes = array('logout', 'system-log', 'admin-settings', 'admin-routers', 'admin-users', 'admin-fonnte', 'admin-payment-gateway', 'admin-router-add', 'admin-session-settings');
  $routerConnected = false;
  $API = null;
  $identity = isset($hotspotname) ? $hotspotname : $session;
  if (!in_array($requestedRoute, $localRoutes, true)) {
    $API = new RouterosAPI();
    $API->debug = false;
    $routerConnected = $API->connect($iphost, $userhost, decrypt($passwdhost));
    if ($routerConnected) {
      $getidentity = $API->comm("/system/identity/print");
      $identity = isset($getidentity[0]['name']) ? $getidentity[0]['name'] : '';
      mikhmonSynchronizeRouterData($API, $session);
    }
  }


  $pagehotspot = array('users','hosts','ipbinding','cookies','log','dhcp-leases');
  $pageppp = array('secrets','profiles','active',);
  $pagereport = array('userlog','selling','systemlog');

  include_once('./include/headhtml.php');

  include_once('./include/menu.php');

  $disable_sci = '<script>
  document.getElementById("comment").onkeypress = function(e) {
    var chr = String.fromCharCode(e.which);
    if (" _!@#$%^&*()+=;|?,.~".indexOf(chr) >= 0)
        return false;
};
</script>';


// logout
  if ($hotspot == "logout") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Logout...</b>";

    mikhmonSystemLog('info', 'Autentikasi', 'Pengguna keluar dari aplikasi.', mikhmonSystemLogCurrentUser(array('session' => $session)));
    session_destroy();
    echo "<script>sessionStorage.clear();</script>";
    echo "<script>window.location='./admin.php?id=login'</script>";
  }
// redirect to home
  elseif (substr(explode("=", $url)[0],-9) == "/?session") {
    if (mikhmonIsMitra()) include_once('./dashboard/rolehome.php');
    else include_once('./dashboard/home.php');
    $_SESSION['ubn'] = "";
  }

// redirect to home
  elseif ($hotspot == "dashboard") {
    if (mikhmonIsMitra()) include_once('./dashboard/rolehome.php');
    else include_once('./dashboard/home.php');
    $_SESSION['ubn'] = "";

  }

// hotspot log
  elseif ($hotspot == "log") {
    include_once('./hotspot/log.php');
  }

// admin settings inside the router dashboard
  elseif ($admin == "settings" && mikhmonIsAdmin()) {
    include_once('./settings/adminsettings.php');
  }

// router management inside the router dashboard
  elseif ($admin == "routers" && mikhmonIsAdmin()) {
    include_once('./settings/sessions.php');
  }

// user and role management inside the router dashboard
  elseif ($admin == "users" && mikhmonIsAdmin()) {
    include_once('./settings/users.php');
  }

// WhatsApp Gateway settings inside the router dashboard
  elseif ($admin == "fonnte" && mikhmonIsAdmin()) {
    include_once('./settings/fonnte.php');
  }

// Payment gateway settings inside the router dashboard
  elseif ($admin == "payment-gateway" && mikhmonIsAdmin()) {
    include_once('./settings/paymentgateway.php');
  }

// Add router inside the router dashboard
  elseif ($admin == "router-add" && mikhmonIsAdmin()) {
    $id = "settings";
    include_once('./settings/settings.php');
  }

// Router session settings inside the router dashboard
  elseif ($admin == "session-settings" && mikhmonIsAdmin()) {
    $id = "settings";
    include_once('./settings/settings.php');
  }

// Database backup inside the router dashboard
  elseif ($admin == "database" && mikhmonIsAdmin()) {
    include_once('./settings/database.php');
  }

// customer database
  elseif ($commission == "1") {
    include_once('./customer/commission.php');
  }
  elseif ($billing == "1") {
    include_once('./customer/billing.php');
  }
  elseif ($customer == "list" && $customerid != "") {
    include_once('./customer/identityadd.php');
  }
  elseif ($customer == "list") {
    include_once('./customer/customers.php');
  }
  elseif ($customer == "identity-list") {
    include_once('./customer/identities.php');
  }
  elseif ($customer == "identity-add" || $customer == "identity-edit" || $customer == "edit" || $customer == "add") {
    include_once('./customer/identityadd.php');
  }
  elseif ($customer == "service-add") {
    include_once('./customer/serviceadd.php');
  }
  elseif ($customer == "service-edit") {
    include_once('./customer/serviceedit.php');
  }

// hotspot log
  elseif ($report == "userlog") {
    include_once('./report/userlog.php');
  }

// application system log
  elseif ($report == "systemlog") {
    include_once('./dashboard/systemlogs.php');
  }

// about was removed from the navigation; redirect old bookmarks home
  elseif ($hotspot == "about") {
    echo "<script>window.location='./?session=" . $session . "'</script>";
  }

// bad request
  elseif (substr($url, -1) == "=") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Bad request! redirect to Home......</b>";

    echo "<script>window.location='./'</script>";
  }

// hotspot add users
  elseif ($hotspot == "add-user") {
    $_SESSION['hua'] = "";
    include_once('./hotspot/adduser.php');
  }

// hotspot users
  elseif ($hotspot == "users" && $prof == "all") {
    $_SESSION['ubp'] = "";
    $_SESSION['hua'] = "";
    $_SESSION['ubc'] = "";
    $_SESSION['vcr'] = "";
    include_once('./hotspot/users.php');
  }

// hotspot users filter by profile
  elseif ($hotspot == "users" && $prof != "") {
    $_SESSION['ubp'] = $prof;
    $_SESSION['hua'] = "";
    $_SESSION['ubc'] = "";
    $_SESSION['vcr'] = "";
    include_once('./hotspot/users.php');
  }

// hotspot users filter by comment
  elseif ($hotspot == "users" && $comm != "") {
    $_SESSION['ubc'] = $comm;
    $_SESSION['hua'] = "";
    $_SESSION['ubp'] = "";
    $_SESSION['vcr'] = "";
    include_once('./hotspot/users.php');
  }

// hotspot by profile
  elseif ($hotspot == "users-by-profile") {
    $_SESSION['ubp'] = "";
    $_SESSION['hua'] = "";
    $_SESSION['ubc'] = "";
    $_SESSION['vcr'] = "active";
    include_once('./hotspot/userbyprofile.php');
  }
// export hotspot users
  elseif ($hotspot == "export-users") {
    include_once('./hotspot/exportusers.php');
  }

// quick print
  elseif ($hotspot == "print-center") {
    include_once('./hotspot/printcenter.php');
  }

// quick print
  elseif ($hotspot == "quick-print") {
    include_once('./hotspot/quickprint.php');
  }

// quick print
elseif ($hotspot == "list-quick-print") {
  include_once('./hotspot/listquickprint.php');
}  

// add hotspot user
  elseif ($hotspotuser == "add") {
    include_once('./hotspot/adduser.php');
    echo $disable_sci;
  }

// add hotspot user
  elseif ($hotspotuser == "generate") {
    include_once('./hotspot/generateuser.php');
    echo $disable_sci;
  }

// hotspot users filter by name
  elseif (substr($hotspotuser, 0, 1) == "*") {
    $_SESSION['ubn'] = $hotspotuser;
    $_SESSION['hua'] = "";
    include_once('./hotspot/userbyname.php');
  } elseif ($hotspotuser != "") {
    $_SESSION['ubn'] = $hotspotuser;
    include_once('./hotspot/userbyname.php');
  }

// remove hotspot user
  elseif ($removehotspotuser != "" || $removehotspotusers != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removehotspotuser.php');
  }

// remove hotspot user by comment
  elseif ($removehotspotuserbycomment != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removehotspotuserbycomment.php');
  }

// remove expired hotspot user
elseif ($removeexpiredhotspotuser != "") {
  echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

  include_once('./process/removeexpiredhotspotuser.php');
}  

// reset hotspot user
  elseif ($resethotspotuser != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/resethotspotuser.php');
  }

// enable hotspot user
  elseif ($enablehotspotuser != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/enablehotspotuser.php');
  }

// disable hotspot user
  elseif ($disablehotspotuser != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/disablehotspotuser.php');
  }

// user profile
  elseif ($hotspot == "user-profiles") {
    include_once('./hotspot/userprofile.php');
  }

// add  user profile
  elseif ($userprofile == "add") {
    include_once('./hotspot/adduserprofile.php');
  }

// User profile by name
  elseif (substr($userprofile, 0, 1) == "*") {
    include_once('./hotspot/userprofilebyname.php');
  } elseif ($userprofile != "") {
    include_once('./hotspot/userprofilebyname.php');
  }


// remove user profile
  elseif ($removeuserprofile != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removeuserprofile.php');
  }

// hotspot active
  elseif ($hotspot == "active") {
    $_SESSION['ubp'] = "";
    $_SESSION['hua'] = "hotspotactive";
    $_SESSION['ubc'] = "";
    include_once('./hotspot/hotspotactive.php');
  }

// dhcp leases
  elseif ($hotspot == "dhcp-leases") {
    include_once('./dhcp/dhcpleases.php');
  }

// traffic monitor
  elseif ($minterface == "traffic-monitor") {
  include_once('./traffic/trafficmonitor.php');
}

// hotspot hosts
  elseif ($hotspot == "hosts" || $hotspot == "hostp" || $hotspot == "hosta") {
    include_once('./hotspot/hosts.php');
  }

// hotspot bindings
  elseif ($hotspot == "binding") {
    include_once('./hotspot/binding.php');
  }

// template editor
  elseif ($hotspot == "template-editor") {
    include_once('./settings/vouchereditor.php');
  }

// upload logo
  elseif ($hotspot == "uplogo") {
    include_once('./settings/uplogo.php');
  }

// hotspot Cookies
  elseif ($hotspot == "cookies") {
    include_once('./hotspot/cookies.php');
  }

// remove hotspot Cookies
  elseif ($removecookie != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removecookie.php');
  }

// hotspot Ip Bindings
  elseif ($hotspot == "ipbinding") {
    include_once('./hotspot/ipbinding.php');
  }

// remove enable disable ipbinding
  elseif ($removeipbinding != "" || $enableipbinding != "" || $disableipbinding != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/pipbinding.php');
  }


// remove user active
  elseif ($removeuseractive != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removeuseractive.php');
  }

// remove host
  elseif ($removehost != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removehost.php');
  }


// makebinding
  elseif ($macbinding != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/makebinding.php');
  }

// selling
  elseif ($report == "selling") {
    include_once('./report/selling.php');
  }

// selling
elseif ($report == "resume-report") {
  include_once('./report/resumereport.php');
}

// selling
elseif ($report == "export") {
  include_once('./report/export.php');
}

// selling
  elseif ($removereport != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removereport.php');
  }

// ppp secret
  elseif ($ppp == "secrets") {
    include_once('./ppp/pppsecrets.php');
  }

// ppp addsecret
  elseif ($ppp == "addsecret") {
    include_once('./ppp/addsecret.php');
  }

// ppp secretbyname
  elseif ($secretbyname != "") {
    include_once('./ppp/secretbyname.php');
  }

// remove enable disable secret
  elseif ($removesecr != "" || $enablesecr != "" || $disablesecr != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/psecret.php');
  }


// ppp profile
  elseif ($ppp == "profiles") {
    include_once('./ppp/pppprofile.php');
  }

// add ppp profile
  elseif ($ppp == "add-profile") {
    include_once('./ppp/addpppprofile.php');
  }

// add ppp profile
elseif ($ppp == "edit-profile") {
  include_once('./ppp/profilebyname.php');
}
// remove enable disable profile
  elseif ($removepprofile != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removepprofile.php');
  }

// ppp active connection
  elseif ($ppp == "active") {
    include_once('./ppp/pppactive.php');
  }

// remove ppp active connection
  elseif ($removepactive != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removepactive.php');
  }

// sys scheduler
  elseif ($sys == "scheduler") {
    include_once('./system/scheduler.php');
  }
// remove enable disable scheduler
  elseif ($removesch != "" || $enablesch != "" || $disablesch != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/pscheduler.php');
  }

  ?>

</div>
</div>
</div>
<script src="./js/highcharts/highcharts.js"></script>
<script src="./js/highcharts/themes/hc.<?= $theme; ?>.js"></script>
<script src="./js/mikhmon-ui.<?= $theme; ?>.min.js"></script>
<script src="./js/mikhmon.js?t=<?= str_replace(" ","_",date("Y-m-d H:i:s")); ?>"></script>

<?php
if (mikhmonIsAdmin() && ($hotspot == "dashboard" || substr(end(explode("/", $url)), 0, 8) == "?session")) {
  echo '<script>
    $("#r_3").load("./dashboard/aload.php?session=' . $session . '&load=logs #r_3");  
    var interval1 = Math.max(30000, ' . ((int) $areload * 1000) . ');
    var dashboard = setInterval(function() {
    if (document.hidden) return;
    $("#r_1").load("./dashboard/aload.php?session=' . $session . '&load=sysresource #r_1");
    $("#r_2").load("./dashboard/aload.php?session=' . $session . '&load=hotspot #r_2");
    $("#r_3").load("./dashboard/aload.php?session=' . $session . '&load=logs #r_3");
    
  }, interval1);

';
if ($livereport == "enable" || $livereport == "") {
  echo '$("#r_4").load("./report/livereport.php?session=' . $session . ' #r_4");';
  echo  '
    var interval2 = "65432";
    var livereport = setInterval(function() {
    $("#r_4").load("./report/livereport.php?session=' . $session . ' #r_4"); 
  }, interval2);
 ';}
  echo ' 
  function cancelPage(){
    window.stop();
    clearInterval(dashboard);';
    if ($livereport == "enable" || $livereport == "") {
    echo '
    clearInterval(livereport);';
    }
  echo '
    }
</script>';

} elseif ($hotspot == "active" && $serveractive != "") {
  echo '<script>
  $(document).ready(function(){
    var interval = "' . ($areload * 1000) . '";
    setInterval(function() {
    $("#reloadHotspotActive").load("./hotspot/hotspotactive.php?server=' . $serveractive . '&session=' . $session . '"); }, interval);})
</script>
';
} elseif ($hotspot == "active" && $serveractive == "") {
  echo '<script>
  $(document).ready(function(){
    var interval = "' . ($areload * 1000) . '";
    setInterval(function() {
    $("#reloadHotspotActive").load("./hotspot/hotspotactive.php?session=' . $session . '"); }, interval);})
</script>
';
} elseif ($userprofile == "add" || substr($userprofile, 0, 1) == "*" || $userprofile != "") {
  echo "<script>
  //enable disable input on ready
$(document).ready(function(){
    var exp = document.getElementById('expmode').value;
    var val = document.getElementById('validity').style;
    var vali = document.getElementById('validi');
    if (exp === 'rem' || exp === 'remc') {
      val.display= 'table-row';
      vali.type = 'text';
      $('#validi').focus();
    } else if (exp === 'ntf' || exp === 'ntfc') {
      val.display = 'table-row';
      vali.type = 'text';
      $('#validi').focus();
    } else {
      val.display = 'none';
      vali.type = 'hidden';
    }
});
</script>";

} elseif (in_array($hotspot, $pagehotspot) || in_array($ppp, $pageppp) || in_array($report, $pagereport) || $sys == "scheduler") {
echo '
<script>
$(document).ready(function(){
  makeAllSortable();
  $("#filterTable").on("keyup", function() {
    var value = $(this).val().toLowerCase();
    $("#dataTable tbody tr").filter(function() {
      $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
    });
  });
});

</script>
';
}
}
?>
</body>
</html>

