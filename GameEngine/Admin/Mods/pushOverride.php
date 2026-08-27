<?php

#################################################################################
##              -= YOU MAY NOT REMOVE OR CHANGE THIS NOTICE =-                 ##
## --------------------------------------------------------------------------- ##
##  Filename       pushOverride.php                                            ##
##  Type           BACKEND (Push Protection override)                         ##
##  Developed by:  Shadow                                                      ##
##  License:       TravianZ Project                                           ##
##  Copyright:     TravianZ (c) 2010-2026. All rights reserved.               ##
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

// #299 style: load CSRF helpers + admin_deny() before the access check.
require_once(__DIR__ . '/../csrf.php');
if ($_SESSION['access'] < MULTIHUNTER) {
    admin_deny('You must be signed in as an administrator or multihunter to do this. '
        . 'Your session may have expired - please return to the admin panel and sign in again.');
}

// This Mod is POSTed to directly, so verify the CSRF token itself.
csrf_verify();

include_once(__DIR__ . '/../../Database.php');
include_once(__DIR__ . '/../../PushProtection.php');

$admid = (int)($_SESSION['id'] ?? 0);
$uid   = (int)($_POST['uid'] ?? 0);
$mode  = (int)($_POST['mode'] ?? 0);
$limit = (int)($_POST['custom_limit'] ?? 0);
$note  = trim((string)($_POST['note'] ?? ''));

// Verify the acting user really is admin/MH in the DB (defence in depth).
$check = mysqli_query($GLOBALS['link'],
    "SELECT access, username FROM " . TB_PREFIX . "users WHERE id = " . $admid);
$acc = $check ? mysqli_fetch_assoc($check) : null;
if (!$acc || (int)$acc['access'] < MULTIHUNTER) {
    admin_deny('Your session may have expired - please sign in again.');
}

if ($uid > 3) {
    // Normalise mode to the allowed set.
    if (!in_array($mode, [
        PushProtection::OV_NONE,
        PushProtection::OV_UNLIMITED,
        PushProtection::OV_CUSTOM,
    ], true)) {
        $mode = PushProtection::OV_NONE;
    }
    if ($limit < 0) {
        $limit = 0;
    }

    PushProtection::setOverride($uid, $mode, $limit, $note, $admid);

    $modeLabel = $mode === PushProtection::OV_UNLIMITED ? 'exempt'
        : ($mode === PushProtection::OV_CUSTOM ? ('custom cap ' . $limit) : 'cleared');
    $logMsg = 'Push protection override for uid ' . $uid . ' set to <b>' . $modeLabel . '</b>';
    $logMsg = mysqli_real_escape_string($GLOBALS['link'], $logMsg);
    mysqli_query($GLOBALS['link'],
        "INSERT INTO " . TB_PREFIX . "admin_log VALUES (0, " . $admid . ", '" . $logMsg . "', " . time() . ")");
}

header("Location: ../../../Admin/admin.php?p=pushprot");
exit;
?>

