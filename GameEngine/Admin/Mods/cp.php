<?php
#################################################################################
##              -= YOU MAY NOT REMOVE OR CHANGE THIS NOTICE =-                 ##
## --------------------------------------------------------------------------- ##
##  Filename       cp.php                                                      ##
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
if($_SESSION['access'] < 9) admin_deny('You must be signed in as an administrator to view this page. Your session may have expired — please return to the admin panel and sign in again.');

// Issue #139: this Mod is POSTed to directly, so it must verify the CSRF token
// itself (it does not go through admin.php's central csrf_verify()).
csrf_verify();

include_once(__DIR__ . '/../../Database.php');

$id = (int) $_POST['id'];
$admid = (int) $_POST['admid'];

$sql = mysqli_query($GLOBALS["link"], "SELECT * FROM ".TB_PREFIX."users WHERE id = ".$admid."");
$access = mysqli_fetch_array($sql);
$sessionaccess = $access['access'];

if($sessionaccess != 9) admin_deny('You must be signed in as an administrator to view this page. Your session may have expired — please return to the admin panel and sign in again.');

mysqli_query($GLOBALS["link"], "UPDATE ".TB_PREFIX."users SET cp = cp + ".(int) $_POST['cp']." WHERE id = ".$id."");

header("Location: ../../../Admin/admin.php?p=player&uid=".$id."");
?>