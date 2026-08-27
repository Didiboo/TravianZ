<?php
#################################################################################
##              -= YOU MAY NOT REMOVE OR CHANGE THIS NOTICE =-                 ##
## --------------------------------------------------------------------------- ##
##  Filename       editAli.php                                                 ##
##  Type           BACKEND                                                     ##
##  Developed by:  Shadow (după model editUser)                                ##
##  License:       TravianZ Project                                            ##
##  Copyright:     TravianZ (c) 2010-2025. All rights reserved.                 ##
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
$admid = (int)($_POST['admid'] ?? 0);
$aid   = (int)($_POST['aid'] ?? 0);

if ($aid <= 0 || $admid <= 0) {
    header("Location: ../../../Admin/admin.php?p=alliance&aid=$aid&e=bad");
    exit;
}

// ---------------------------------------------------------------------------
// Verificare admin - la fel ca editUser
// ---------------------------------------------------------------------------
$admin = $database->getUserArray($admid, 1);
if (!$admin || (int)$admin['access'] !== 9) {
    admin_deny('You must be signed in as an administrator to view this page. Your session may have expired — please return to the admin panel and sign in again.');
}

// ---------------------------------------------------------------------------
// Câmpuri
// ---------------------------------------------------------------------------
$tag    = $database->escape(substr(trim($_POST['tag'] ?? ''), 0, 8));
$name   = $database->escape(substr(trim($_POST['name'] ?? ''), 0, 25));
$leader = (int)($_POST['leader'] ?? 0);
$max    = (int)($_POST['max'] ?? 0);
$max    = max(3, min(60, $max));
$notice = $database->escape($_POST['notice'] ?? '');
$desc   = $database->escape($_POST['desc'] ?? '');

// ---------------------------------------------------------------------------
// Update
// ---------------------------------------------------------------------------
$database->query(
    "UPDATE " . TB_PREFIX . "alidata SET 
        tag = '$tag',
        name = '$name',
        leader = $leader,
        `max` = $max,
        notice = '$notice',
        `desc` = '$desc'
     WHERE id = $aid"
);

// ---------------------------------------------------------------------------
// Log admin - aceeași structură ca editUser
// ---------------------------------------------------------------------------
$time = time();
$logText = "Edited alliance <a href='admin.php?p=alliance&aid=$aid'>$tag</a>";
$logEsc = $database->escape($logText);

$database->query(
    "INSERT INTO " . TB_PREFIX . "admin_log (`id`, `user`, `log`, `time`) " .
    "VALUES (0, '$admid', '$logEsc', $time)"
);

header("Location: ../../../Admin/admin.php?p=alliance&aid=$aid&edited=1");
exit;
?>