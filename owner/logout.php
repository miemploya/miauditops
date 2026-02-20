<?php
/**
 * MIAUDITOPS — Owner Logout
 */
session_start();
unset($_SESSION['owner_id'], $_SESSION['owner_name'], $_SESSION['is_platform_owner']);
session_destroy();
header('Location: login.php');
exit;
