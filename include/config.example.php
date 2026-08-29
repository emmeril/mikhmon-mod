<?php
if (substr($_SERVER["REQUEST_URI"], -10) == "config.php") {
  header("Location:./");
}

/*
 * Copy this file to config.php, then configure the administrator and router
 * through Mikhmon. Never commit config.php because it contains credentials.
 */
$data = array();

