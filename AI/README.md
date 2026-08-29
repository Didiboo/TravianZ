# TravianZ NPC / PNJ integration

This build adds the NPC system from the supplied `travianz-ha` AI implementation to the supplied TravianZ master archive.

## ACP

In `Créer des utilisateurs`:
- `PNJ actif`
- `Comportement`: Équilibré, Développeur, Raideur, Militaire, Pacifique

The selected behaviour is stored per NPC account.

## Autonomous play

The existing `cron.php` now runs the NPC scheduler after each TravianZ automation tick. No separate AI process is required.

The AI:
- develops resource fields and buildings;
- manages warehouse/granary pressure;
- builds a Rally Point and Barracks;
- trains troops;
- raids other playable tribes according to behaviour;
- remembers raid results;
- keeps its own HTTP session/cookie jar per NPC and per instance.

The game remains authoritative: NPC actions use the normal TravianZ HTTP endpoints rather than directly modifying game state.

## Multi-instance

The implementation uses the current TravianZ instance resolver and generated table prefix. NPC data and runtime files are isolated per instance.

## Behaviours

- Équilibré
- Développeur
- Raideur
- Militaire
- Pacifique

The AI tables are created/migrated automatically when the cron runs or when the first NPC is created.

## Cron installation

The NPC scheduler uses the existing `cron.php`; it does not run as a separate AI process. The installer automatically registers `cron.php` with the operating-system scheduler when the installer account has the required permission: Windows uses Task Scheduler and Linux uses `/etc/cron.d` or the current user's crontab.
