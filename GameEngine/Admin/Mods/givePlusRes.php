<?php
#################################################################################
##              -= YOU MAY NOT REMOVE OR CHANGE THIS NOTICE =-                 ##
## --------------------------------------------------------------------------- ##
##  Filename       givePlusRes.php                                             ##
##  Type           BACKEND                                                     ##
##  Developed by:  aggenkeech                                                  ##
##  License:       TravianZ Project                                            ##
##  Copyright:     TravianZ (c) 2010-2025. All rights reserved.                ##
##                                                                             ##
#################################################################################

// Multi-instance bootstrap: resolve the instance and bind the correct session/config.
// config.php must remain at the beginning, as it initializes the transition to the resolver.
// Load the generated instance configuration and language before using the admin session.
//$autoprefix is ​​no longer needed if we normalize deterministic paths.

include_once(__DIR__ . '/../../config.php');

if (file_exists(__DIR__ . '/../../Lang/loader.php')) {
    require_once(__DIR__ . '/../../Lang/loader.php');
    if (defined('LANG') && function_exists('tz_load_language')) {
        tz_load_language(LANG);
    }
}

// #299: load CSRF helpers + admin_deny() before the access check below.
require_once(__DIR__ . '/../csrf.php');
if (empty($_SESSION['access']) || $_SESSION['access'] < 9) {
    admin_deny('You must be signed in as an administrator to view this page. Your session may have expired — please return to the admin panel and sign in again.');
}

// Issue #139: this Mod is POSTed to directly, so it must verify the CSRF token
// itself (it does not go through admin.php's central csrf_verify()).
csrf_verify();

include_once(__DIR__ . '/../../Database.php');

// ---------------------------------------------------------------------------
// Input
// ---------------------------------------------------------------------------
$session = (int)($_POST['admid'] ?? 0);
$admin = $database->getUserArray($session, 1);
if (!$admin || (int)$admin['access'] !== 9) {
    admin_deny('You must be signed in as an administrator to view this page. Your session may have expired — please return to the admin panel and sign in again.');
}

$wood = (int)($_POST['wood'] ?? 0) * 86400;
$clay = (int)($_POST['clay'] ?? 0) * 86400;
$iron = (int)($_POST['iron'] ?? 0) * 86400;
$crop = (int)($_POST['crop'] ?? 0) * 86400;

if ($wood + $clay + $iron + $crop == 0) {
    header("Location: ../../../Admin/admin.php?p=givePlusRes&e=0");
    exit;
}

$time = time();

// ---------------------------------------------------------------------------
// Update în masă
// ---------------------------------------------------------------------------
if ($wood > 0) {
    $database->query("UPDATE " . TB_PREFIX . "users SET b1 = IF(b1 < $time, $time + $wood, b1 + $wood) WHERE id > 3");
}
if ($clay > 0) {
    $database->query("UPDATE " . TB_PREFIX . "users SET b2 = IF(b2 < $time, $time + $clay, b2 + $clay) WHERE id > 3");
}
if ($iron > 0) {
    $database->query("UPDATE " . TB_PREFIX . "users SET b3 = IF(b3 < $time, $time + $iron, b3 + $iron) WHERE id > 3");
}
if ($crop > 0) {
    $database->query("UPDATE " . TB_PREFIX . "users SET b4 = IF(b4 < $time, $time + $crop, b4 + $crop) WHERE id > 3");
}

// ---------------------------------------------------------------------------
// Log admin
// ---------------------------------------------------------------------------
$adminId = (int)$_SESSION['id'];
$logText = "Gave res bonuses to all: wood=" . ($_POST['wood'] ?? 0) . "d, clay=" . ($_POST['clay'] ?? 0) . "d, iron=" . ($_POST['iron'] ?? 0) . "d, crop=" . ($_POST['crop'] ?? 0) . "d";
$logEsc = $database->escape($logText);

$database->query(
    "INSERT INTO " . TB_PREFIX . "admin_log (`id`, `user`, `log`, `time`) " .
    "VALUES (0, '$adminId', '$logEsc', $time)"
);

header("Location: ../../../Admin/admin.php?p=givePlusRes&g=1");
exit;
?>