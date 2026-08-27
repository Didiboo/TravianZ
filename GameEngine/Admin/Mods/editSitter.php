<?php
#################################################################################
##              -= YOU MAY NOT REMOVE OR CHANGE THIS NOTICE =-                 ##
## --------------------------------------------------------------------------- ##
##  Filename       editSitter.php                                              ##
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
$id      = (int)($_POST['id'] ?? 0);
$sit1    = (int)($_POST['sitter1'] ?? 0);
$sit2    = (int)($_POST['sitter2'] ?? 0);

if ($id <= 0 || $session <= 0) {
    header("Location: ../../../Admin/admin.php?p=player&uid=$id&e=bad");
    exit;
}

// ---------------------------------------------------------------------------
// Verificare admin
// ---------------------------------------------------------------------------
$admin = $database->getUserArray($session, 1);
if (!$admin || (int)$admin['access'] !== 9) {
    admin_deny('You must be signed in as an administrator to view this page. Your session may have expired — please return to the admin panel and sign in again.');
}

// ---------------------------------------------------------------------------
// Update
// ---------------------------------------------------------------------------
$database->query("UPDATE " . TB_PREFIX . "users SET sit1 = $sit1, sit2 = $sit2 WHERE id = $id");

// ---------------------------------------------------------------------------
// Log admin
// ---------------------------------------------------------------------------
$adminId = (int)$_SESSION['id'];
$time = time();

// FIX: username pentru target + sitteri
$targetName = $database->getUserField($id, 'username', 0) ?: 'UID '.$id;
$targetNameSafe = htmlspecialchars($targetName, ENT_QUOTES, 'UTF-8');

$sit1Name = $sit1 > 0 ? ($database->getUserField($sit1, 'username', 0) ?: $sit1) : 'none';
$sit2Name = $sit2 > 0 ? ($database->getUserField($sit2, 'username', 0) ?: $sit2) : 'none';

$logText = "Changed sitters for user <a href='admin.php?p=player&uid=$id'>$targetNameSafe</a> (sit1=$sit1Name, sit2=$sit2Name)";
$logEsc = $database->escape($logText);

$database->query(
    "INSERT INTO " . TB_PREFIX . "admin_log (`id`, `user`, `log`, `time`) " .
    "VALUES (0, '$adminId', '$logEsc', $time)"
);

header("Location: ../../../Admin/admin.php?p=player&uid=" . $id);
exit;
?>