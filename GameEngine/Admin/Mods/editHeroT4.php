<?php
#################################################################################
##              -= YOU MAY NOT REMOVE OR CHANGE THIS NOTICE =-                 ##
## --------------------------------------------------------------------------- ##
##  Filename       : editHeroT4.php                                            ##
##  Type           : BACKEND (Admin Mod) - T4 hero port admin controls         ##
## --------------------------------------------------------------------------- ##
##  Refactored by  : Shadow                                                    ##
##  Project        : TravianZ                                                  ##
##  GitHub         : https://github.com/Shadowss/TravianZ                      ##
## --------------------------------------------------------------------------- ##
##  License        : TravianZ Project                                          ##
##  Copyright      : TravianZ (c) 2010-2026. All rights reserved.              ##
## --------------------------------------------------------------------------- ##
#################################################################################
##                                                                             ##
##  POST actions (all CSRF-verified, admin access >= 9, same conventions as    ##
##  editHero.php):                                                             ##
##    t4admin=setsilver     uid, silver            -> absolute silver balance  ##
##    t4admin=giveitem      uid, itemid, qty       -> grant catalog item       ##
##    t4admin=delitem       uid, rowid             -> delete inventory row     ##
##    t4admin=cancelauction aucid                  -> refund + return item     ##
##    t4admin=deladventure  advid                  -> remove an offer          ##
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

// Load CSRF helpers + admin_deny() before the access check (same as editHero.php #299).
require_once(__DIR__ . '/../csrf.php');
if (empty($_SESSION['access']) || $_SESSION['access'] < 9) {
    admin_deny('You must be signed in as an administrator to view this page. Your session may have expired — please return to the admin panel and sign in again.');
}

// POSTed to directly, so it verifies the CSRF token itself (#139).
csrf_verify();

include_once(__DIR__ . '/../../Database.php');
include_once(__DIR__ . '/../../Data/hero_items.php');
include_once(__DIR__ . '/../../HeroItems.php');
include_once(__DIR__ . '/../../HeroAuction.php');

$uid    = (int) ($_POST['uid'] ?? 0);
$action = (string) ($_POST['t4admin'] ?? '');
$status = '&e=1'; // error by default; flipped to &ok=1 on success

$heroItems = new HeroItems();

switch ($action) {

    case 'setsilver':
        // Absolute set (not delta) - simplest to reason about as an admin.
        $silver = max(0, (int) ($_POST['silver'] ?? 0));
        if ($uid > 0) {
            $stmt = $database->dblink->prepare(
                "UPDATE " . TB_PREFIX . "hero SET silver = ? WHERE uid = ? LIMIT 1"
            );
            $stmt->bind_param('ii', $silver, $uid);
            $stmt->execute();
            if ($stmt->affected_rows > 0 || $heroItems->getSilver($uid) === $silver) {
                $status = '&ok=1';
            }
            $stmt->close();
        }
        break;

    case 'giveitem':
        $itemid = (int) ($_POST['itemid'] ?? 0);
        $qty    = max(1, (int) ($_POST['qty'] ?? 1));
        if ($uid > 0 && $heroItems->addItem($uid, $itemid, $qty) > 0) {
            $status = '&ok=1';
        }
        break;

    case 'delitem':
        // Hard delete of an inventory row (equipped or not) - admin override.
        $rowid = (int) ($_POST['rowid'] ?? 0);
        if ($uid > 0 && $rowid > 0) {
            $stmt = $database->dblink->prepare(
                "DELETE FROM " . TB_PREFIX . "hero_items WHERE id = ? AND uid = ? LIMIT 1"
            );
            $stmt->bind_param('ii', $rowid, $uid);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $status = '&ok=1';
            }
            $stmt->close();
        }
        break;

    case 'cancelauction':
        $aucid   = (int) ($_POST['aucid'] ?? 0);
        $auction = new HeroAuction();
        if ($aucid > 0 && $auction->adminCancel($aucid)) {
            $status = '&ok=1';
        }
        break;

    case 'deladventure':
        // Remove an available offer (status 0 only - never a running one,
        // that would strand the hero mid-movement).
        $advid = (int) ($_POST['advid'] ?? 0);
        if ($advid > 0) {
            $stmt = $database->dblink->prepare(
                "DELETE FROM " . TB_PREFIX . "hero_adventure WHERE id = ? AND status = 0 LIMIT 1"
            );
            $stmt->bind_param('i', $advid);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $status = '&ok=1';
            }
            $stmt->close();
        }
        break;
}

header("Location: ../../../Admin/admin.php?p=editHeroT4&uid=$uid$status");
exit;
