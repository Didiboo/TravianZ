<?php

// ============================================================
// TRAVIANZ MI INSTANCE / SESSION BOOTSTRAP
// ============================================================
require_once(__DIR__ . '/../../Instance/Resolver.php');

$travianInstance = InstanceResolver::resolve(false);
InstanceResolver::startInstanceSession($travianInstance);

include_once(__DIR__ . '/../../config.php');

if (file_exists(__DIR__ . '/../../Lang/loader.php')) {
    require_once(__DIR__ . '/../../Lang/loader.php');

    if (defined('LANG') && function_exists('tz_load_language')) {
        tz_load_language(LANG);
    }
}

#################################################################################
##              -= YOU MAY NOT REMOVE OR CHANGE THIS NOTICE =-                 ##
## --------------------------------------------------------------------------- ##
##  Filename       editBuildings.php                                           ##
##  Type        BACKEND                                                        ##
##  Developed by:  aggenkeech                                                  ##
##  Fix by:        ronix + Shadow 2026 (WW lvl 100 + auto pop)                ##
##  License:       TravianZ Project                                            ##
##  Copyright:     TravianZ (c) 2011-2026. All rights reserved.                ##
##                                                                             ##
#################################################################################

// ============================================================
// CSRF + ADMIN ACCESS
// #299: load CSRF helpers + admin_deny() before the access check below
// Issue #139: this Mod is POSTed to directly, so it must verify the CSRF token
// itself (it does not go through admin.php's central csrf_verify()).
//============================================================
require_once(__DIR__ . '/../csrf.php');

if (empty($_SESSION['access']) || (int)$_SESSION['access'] < 9) {
     die(ACCESS_DENIED_ADMIN);
}

// This file is POSTed to directly, so verify CSRF here.
csrf_verify();

// ============================================================
// DATABASE / AUTOMATION
// ============================================================
include_once(__DIR__ . '/../../Database.php');
include_once(__DIR__ . '/../../Automation.php');

// ============================================================
// Input
// ============================================================
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    header("Location: ../../../Admin/admin.php?p=admin");
    exit;
}

// ============================================================
// Construim SET-ul dinamic pentru f1-f40 și f99
// ============================================================
$sets = [];

for ($i = 1; $i <= 40; $i++) {
    $level = (int)($_POST["id{$i}level"] ?? 0);
    $gid   = (int)($_POST["id{$i}gid"] ?? 0);

    // limităm la valori rezonabile Travian
    $level = max(0, min(20, $level));

    // 50 = last supported building ID.
    $gid = max(0, min(50, $gid));

    $sets[] = "f{$i} = {$level}";
    $sets[] = "f{$i}t = {$gid}";
}

// ============================================================
// câmpurile speciale f99
// ============================================================
$level99 = (int)($_POST['id99level'] ?? 0);
$gid99   = (int)($_POST['id99gid'] ?? 0);

// World Wonder = gid 40, maximum level 100.
if ($gid99 === 40) {
    $level99 = max(0, min(100, $level99));
} else {
    $level99 = max(0, min(20, $level99)); // capcană, etc.
}

$gid99 = max(0, min(50, $gid99));

$sets[] = "f99 = {$level99}";
$sets[] = "f99t = {$gid99}";

$setSql = implode(', ', $sets);

// ============================================================
// UPDATE BUILDINGS
// ============================================================
$database->query(
    "UPDATE " . TB_PREFIX . "fdata SET {$setSql} WHERE vref = {$id}"
);

// ============================================================
// recalculăm populația după editare
// ============================================================
$automation = new Automation();

$pop = $automation->recountPop($id);

// ============================================================
// WORLD WONDER POPULATION FIX
// --- FIX: recountPop original nu include f99 (WW), îl adăugăm ---
// ============================================================
$fdata = $database->getResourceLevel($id);

if ((int)$fdata['f99t'] === 40) {
    $wwLevel = (int)$fdata['f99'];

    if ($wwLevel > 0) {
        // buildingPOP există în Automation
        $wwPop = $automation->buildingPOP(40, $wwLevel);
        $pop += $wwPop;

        $database->query(
            "UPDATE " . TB_PREFIX . "vdata SET pop = {$pop} WHERE wref = {$id}"
        );
    }
}

// ============================================================
// ADMIN LOG
// ============================================================
$adminId = (int)$_SESSION['id'];
$time = time();

// FIX: nume sat + ID formatat
$village = $database->getVillage($id);// dacă nu e deja încărcat sus

$villageName = $village['name'] ?? 'Village';

$villageNameSafe = htmlspecialchars(    $villageName,    ENT_QUOTES,'UTF-8');

$log = $database->escape("Edited buildings for village <a href='admin.php?p=village&did={$id}'>$villageNameSafe</a>");

$database->query("INSERT INTO " . TB_PREFIX .
    "admin_log (`id`,`user`,`log`,`time`)  VALUES (0,'{$adminId}','{$log}',{$time})");

// ============================================================
// REDIRECT
// ============================================================
header("Location: ../../../Admin/admin.php?p=village&did=" . $id);

exit;
?>
