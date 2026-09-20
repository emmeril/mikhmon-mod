<?php
if (substr($_SERVER["REQUEST_URI"], -10) == "config.php") {
  header("Location:./");
}

/*
 * Copy this file to config.php before first use. The initial administrator is:
 * username: admin
 * password: admin@123
 *
 * Never commit config.php because it contains administrator and router
 * credentials.
 */
$data = array();
$data['mikhmon'] = array(
  '1' => 'mikhmon<|<admin',
  '2' => 'mikhmon>|>mZWfoZ9yaWNl',
);
