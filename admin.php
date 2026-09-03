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
if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params(array('lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax'));
  ini_set('session.use_strict_mode', '1');
}
session_start();
// hide all error
error_reporting(0);

ob_start("ob_gzhandler");

// check url
$url = $_SERVER['REQUEST_URI'];

// load session MikroTik
$session = $_GET['session'];
$id = $_GET['id'];
$c = $_GET['c'];
$router = $_GET['router'];
$logo = $_GET['logo'];

$ids = array(
  "editor",
  "uplogo",
  "database",
  "settings",
  "admin-settings",
  "fonnte",
  "payment-gateway",
);

// lang
include('./lang/isocodelang.php');
include('./include/lang.php');
include('./lang/'.$langid.'.php');

// quick bt
include('./include/quickbt.php');

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


// load config
include('./include/config.php');
include('./include/readcfg.php');
include_once('./include/access.php');
include_once('./include/systemlog.php');
include_once('./include/headhtml.php');

include_once('./lib/routeros_api.class.php');
include_once('./lib/formatbytesbites.php');
?>
    
<?php
if ($id == "login" || substr($url, -1) == "p") {

  if (isset($_POST['login'])) {
    $user = trim((string) $_POST['user']);
    $pass = (string) $_POST['pass'];
    if ($user == $useradm && $pass == decrypt($passadm)) {
      session_regenerate_id(true);
      mikhmonSetLoginSession(array('username' => $user, 'name' => 'Administrator'), 'admin');
      $target = mikhmonAdminLandingUrl($data);
      mikhmonSystemLog('success', 'Autentikasi', 'Administrator berhasil login.', mikhmonSystemLogCurrentUser(array('session' => mikhmonDefaultRouterSession($data))));
      echo "<script>window.location=" . json_encode($target) . "</script>";
    } else {
      $staff = mikhmonLoginStaff($user, $pass);
      if ($staff) {
        session_regenerate_id(true);
        mikhmonSetLoginSession($staff);
        if ($staff['role'] === 'admin') {
          $target = mikhmonAdminLandingUrl($data);
          $loginSession = mikhmonDefaultRouterSession($data);
        } else {
          $staffSession = rawurlencode($staff['session']);
          $target = $staff['role'] === 'biller' ? './?billing=1&session=' . $staffSession : './?session=' . $staffSession;
          $loginSession = $staff['session'];
        }
        mikhmonSystemLog('success', 'Autentikasi', 'Pengguna berhasil login.', mikhmonSystemLogCurrentUser(array('session' => $loginSession)));
        echo "<script>window.location=" . json_encode($target) . "</script>";
      } else {
        mikhmonSystemLog('warning', 'Autentikasi', 'Percobaan login gagal untuk username ' . $user . '.', array(
          'user' => $user !== '' ? $user : 'Tidak diketahui',
          'role' => 'guest',
          'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ));
        $error = '<div style="width: 100%; padding:5px 0px 5px 0px; border-radius:5px;" class="bg-danger"><i class="fa fa-ban"></i> Alert!<br>Invalid username or password.</div>';
      }
    }
  }
  

  include_once('./include/login.php');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !mikhmonValidCsrf($_POST['_csrf'] ?? '')) {
  http_response_code(403);
  exit('Invalid request.');
} elseif (!isset($_SESSION["mikhmon"])) {
  echo "<script>window.location='./admin.php?id=login'</script>";
} elseif (!mikhmonRefreshStaffSession()) {
  session_destroy();
  echo "<script>window.location='./admin.php?id=login'</script>";
} elseif (!mikhmonIsAdmin() && $id !== 'logout') {
  $staffSession = rawurlencode(mikhmonAssignedSession());
  $target = mikhmonIsBiller() ? './?billing=1&session=' . $staffSession : './?session=' . $staffSession;
  echo "<script>window.location=" . json_encode($target) . "</script>";
} elseif (substr($url, -1) == "/" || substr($url, -4) == ".php") {
  echo "<script>window.location=" . json_encode(mikhmonAdminLandingUrl($data)) . "</script>";

} elseif ($id == "admin-settings" && !empty($session)) {
  $legacyTarget = './?admin=settings&session=' . rawurlencode($session);
  echo '<script>window.location=' . json_encode($legacyTarget) . '</script>';
} elseif ($id == "admin-settings") {
  include_once('./include/menu.php');
  include_once('./settings/adminsettings.php');
} elseif ($id == "fonnte") {
  // Keep gateway settings in the router dashboard when a router session is selected.
  if (!empty($session)) {
    $fonnteTarget = './?admin=fonnte&session=' . rawurlencode($session);
    echo '<script>window.location=' . json_encode($fonnteTarget) . '</script>';
  } else {
    include_once('./include/menu.php');
    include_once('./settings/fonnte.php');
  }
} elseif ($id == "payment-gateway") {
  // Keep gateway settings in the router dashboard when a router session is selected.
  if (!empty($session)) {
    $paymentGatewayTarget = './?admin=payment-gateway&session=' . rawurlencode($session);
    echo '<script>window.location=' . json_encode($paymentGatewayTarget) . '</script>';
  } else {
    include_once('./include/menu.php');
    include_once('./settings/paymentgateway.php');
  }
} elseif ($id == "database" && !empty($session)) {
  $legacyTarget = './?admin=database&session=' . rawurlencode($session);
  echo '<script>window.location=' . json_encode($legacyTarget) . '</script>';
} elseif ($id == "settings" && !empty($session)) {
  $legacyTarget = './?admin=session-settings&session=' . rawurlencode($session);
  echo '<script>window.location=' . json_encode($legacyTarget) . '</script>';
} elseif ($id == "uplogo" && !empty($session)) {
  $legacyTarget = './?hotspot=uplogo&session=' . rawurlencode($session);
  echo '<script>window.location=' . json_encode($legacyTarget) . '</script>';
} elseif ($id == "editor" && !empty($session)) {
  $template = isset($_GET['template']) ? (string) $_GET['template'] : 'default';
  $legacyTarget = './?hotspot=template-editor&template=' . rawurlencode($template) . '&session=' . rawurlencode($session);
  echo '<script>window.location=' . json_encode($legacyTarget) . '</script>';
} elseif ($id == "sessions") {
  $_SESSION["connect"] = "";
  include_once('./include/menu.php');
  include_once('./settings/sessions.php');
  /*echo '
  <script type="text/javascript">
    document.getElementById("sessname").onkeypress = function(e) {
    var chr = String.fromCharCode(e.which);
    if (" _!@#$%^&*()+=;|?,~".indexOf(chr) >= 0)
        return false;
    };
    </script>';*/
} elseif ($id == "users") {
  include_once('./include/menu.php');
  include_once('./settings/users.php');
} elseif ($id == "settings" && !empty($session) || $id == "settings" && !empty($router)) {
  include_once('./include/menu.php');
  include_once('./settings/settings.php');
  echo '
  <script type="text/javascript">
    document.getElementById("sessname").onkeypress = function(e) {
    var chr = String.fromCharCode(e.which);
    if (" _!@#$%^&*()+=;|?,~".indexOf(chr) >= 0)
        return false;
    };
    </script>';
} elseif ($id == "connect"  && !empty($session)) {
  ini_set("max_execution_time",5);  
  include_once('./include/menu.php');
  $API = new RouterosAPI();
  $API->debug = false;
  if ($API->connect($iphost, $userhost, decrypt($passwdhost))){
    $_SESSION["connect"] = "<b class='text-green'>Connected</b>";
    echo "<script>window.location='./?session=" . $session . "'</script>";
  } else {
    $_SESSION["connect"] = "<b class='text-red'>Not Connected</b>";
    $nl = '\n';
    if ($currency == in_array($currency, $cekindo['indo'])) {
      echo "<script>alert('Mikhmon not connected!".$nl."Silakan periksa kembali IP, User, Password dan port API harus enable.".$nl."Jika menggunakan koneksi VPN, pastikan VPN tersebut terkoneksi.')</script>";
    }else{
      echo "<script>alert('Mikhmon not connected!".$nl."Please check the IP, User, Password and port API must be enabled.')</script>";
    }
    if($c == "settings"){
      echo "<script>window.location='./admin.php?id=settings&session=" . $session . "'</script>";
    }else{
      echo "<script>window.location='./admin.php?id=sessions'</script>";
    }
  }
} elseif ($id == "uplogo"  && !empty($session)) {
  include_once('./include/menu.php');
  include_once('./settings/uplogo.php');
} elseif ($id == "database"  && !empty($session)) {
  $API = new RouterosAPI();
  $API->debug = false;
  $API->connect($iphost, $userhost, decrypt($passwdhost));
  include_once('./include/menu.php');
  include_once('./settings/database.php');
} elseif ($id == "reboot"  && !empty($session)) {
  include_once('./process/reboot.php');
} elseif ($id == "shutdown"  && !empty($session)) {
  include_once('./process/shutdown.php');
} elseif ($id == "remove-session" && $session != "") {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mikhmonValidCsrf($_POST['_csrf'] ?? '')) { http_response_code(403); exit('Invalid request.'); }
  include_once('./include/menu.php');
  $fc = file("./include/config.php" );
  $f = fopen("./include/config.php", "w");
  $q = "'";
  $rem = '$data['.$q.$session.$q.']';
  foreach ($fc as $line) {
    if (!strstr($line, $rem))
      fputs($f, $line);
  }
  fclose($f);
  echo "<script>window.location='./admin.php?id=sessions'</script>";
} elseif ($id == "about") {
  echo "<script>window.location='./admin.php?id=sessions'</script>";
} elseif ($id == "logout") {
  include_once('./include/menu.php');
  echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Logout...</b>";
  mikhmonSystemLog('info', 'Autentikasi', 'Pengguna keluar dari aplikasi.', mikhmonSystemLogCurrentUser());
  session_destroy();
  echo "<script>window.location='./admin.php?id=login'</script>";
} elseif ($id == "remove-logo" && $logo != ""  && !empty($session)) {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mikhmonValidCsrf($_POST['_csrf'] ?? '')) { http_response_code(403); exit('Invalid request.'); }
  include_once('./include/menu.php');
  $logo = basename((string) $logo);
  if (preg_match('/^logo-[A-Za-z0-9_-]+\.png$/', $logo)) @unlink(__DIR__ . '/img/' . $logo);
  echo "<script>window.location='./admin.php?id=uplogo&session=" . $session . "'</script>";
} elseif ($id == "editor"  && !empty($session)) {
  include_once('./include/menu.php');
  include_once('./settings/vouchereditor.php');
} elseif (empty($id)) {
  echo "<script>window.location=" . json_encode(mikhmonAdminLandingUrl($data)) . "</script>";
} elseif(in_array($id, $ids) && empty($session)){
	echo "<script>window.location='./admin.php?id=sessions'</script>";
}
?>
<script src="js/mikhmon-ui.<?= $theme; ?>.min.js"></script>
<script src="js/mikhmon.js?t=<?= str_replace(" ","_",date("Y-m-d H:i:s")); ?>"></script>
<?php include('./include/info.php'); ?>
</body>
</html>
