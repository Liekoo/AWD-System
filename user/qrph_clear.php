<?php
require '../config.php';
require_once '../includes/auth_check.php';
require_login();
unset($_SESSION['qrph_data']);
echo 'ok';