<?php
require_once 'session_init.php';
session_unset();
session_destroy();
header('Location: /index.php');
exit;