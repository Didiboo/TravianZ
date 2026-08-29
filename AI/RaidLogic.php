<?php
class TravianAIRaid
{
    private const MIN_ARMY = 10;
    private const MIN_PROFIT = 50;

    public function __construct(private TravianAIDb $db, private TravianAIGame $game)
    {
    }

    public function run(TravianAI $ai, int $wref): ?string
    {
        $this->game->debug($ai->username . ' | RAID START | village=' . $wref);
        $this->recordReturns($ai, $wref);
        if ($this->troopsAway($wref)) {
            $this->game->debug($ai->username . ' | RAID SKIP | troops already away');
            return null;
        }

        $army = $this->armySize($ai, $wref);
        $this->game->debug($ai->username . ' | RAID ARMY | size=' . $army . ' | minimum=' . self::MIN_ARMY);
        if ($army < self::MIN_ARMY) {
            $this->game->debug($ai->username . ' | RAID SKIP | army below minimum');
            return null;
        }

        $target = $this->chooseTarget($ai, $wref);
        if ($target === null) {
            $this->game->debug($ai->username . ' | RAID SKIP | no target found');
            return null;
        }
        $this->game->debug($ai->username . ' | RAID TARGET | wref=' . (int)$target['wref'] . ' | coords=' . $target['x'] . '|' . $target['y']);

        $send = (int)floor($army * (0.5 + ($ai->aggression() * 0.4)));
        $this->game->debug($ai->username . ' | RAID DECISION | send=' . $send . ' | aggression=' . $ai->aggression());
        if ($send < self::MIN_ARMY) {
            $this->game->debug($ai->username . ' | RAID SKIP | calculated send below minimum');
            return null;
        }

        $ok = $this->game->raid($ai->username, (int)$target['x'], (int)$target['y'], 1, $send);
        if (!$ok) {
            $this->game->debug($ai->username . ' | RAID FAILED | game action rejected');
            return null;
        }
        if (!$this->troopsAway($wref)) {
            $this->game->debug($ai->username . ' | RAID FAILED | request accepted but no movement detected');
            return null;
        }

        $this->remember((int)$target['wref'], $ai->id);
        $this->game->debug($ai->username . ' | RAID SUCCESS | target=' . $target['x'] . '|' . $target['y'] . ' | send=' . $send);
        return "Village {$wref} raided ({$target['x']}|{$target['y']}) with {$send} troops";
    }

    private function chooseTarget(TravianAI $ai, int $wref): ?array
    {
        $known = $this->db->one(
            'SELECT w.id AS wref, w.x, w.y
               FROM {p}raid_memory r
               JOIN {p}vdata v ON v.wref = r.target_wref
               JOIN {p}wdata w ON w.id = v.wref
               JOIN {p}users u ON u.id = v.owner
              WHERE r.ai_id = ? AND r.last_success = 1 AND r.last_loot >= ?
                AND u.protect < UNIX_TIMESTAMP()
              ORDER BY r.last_loot DESC LIMIT 1',
            [$ai->id, self::MIN_PROFIT]
        );
        if ($known !== null) {
            $this->game->debug($ai->username . ' | RAID TARGET SOURCE | remembered target=' . $known['x'] . '|' . $known['y']);
            return $known;
        }

        $home = $this->db->one('SELECT x, y FROM {p}wdata WHERE id = ?', [$wref]);
        if ($home === null) {
            $this->game->debug($ai->username . ' | RAID TARGET FAILED | home village coordinates unavailable');
            return null;
        }

        $target = $this->db->one(
            'SELECT w.id AS wref, w.x, w.y
               FROM {p}wdata w
               JOIN {p}vdata v ON v.wref = w.id
               JOIN {p}users u ON u.id = v.owner
              WHERE u.tribe IN (1,2,3,6,7,8,9)
                AND u.protect < UNIX_TIMESTAMP()
                AND v.owner <> ?
                AND w.id NOT IN (SELECT target_wref FROM {p}raid_memory WHERE ai_id = ?)
              ORDER BY (POW(w.x - ?, 2) + POW(w.y - ?, 2)) ASC LIMIT 1',
            [$ai->uid, $ai->id, (int)$home['x'], (int)$home['y']]
        );
        if ($target !== null) {
            $this->game->debug($ai->username . ' | RAID TARGET SOURCE | nearest target=' . $target['x'] . '|' . $target['y']);
        }
        return $target;
    }

    private function recordReturns(TravianAI $ai, int $wref): void
    {
        $returns = $this->db->all(
            'SELECT m.`from` AS target, (m.wood + m.clay + m.iron + m.crop) AS loot
               FROM {p}movement m WHERE m.`to` = ? AND m.sort_type = 4',
            [$wref]
        );
        if (!empty($returns)) $this->game->debug($ai->username . ' | RAID RETURNS | count=' . count($returns));
        foreach ($returns as $return) {
            $loot = (int)$return['loot'];
            $this->db->exec(
                'UPDATE {p}raid_memory SET last_success = ?, last_loot = ?
                 WHERE ai_id = ? AND target_wref = ?',
                [$loot > 0 ? 1 : 0, $loot, $ai->id, (int)$return['target']]
            );
        }
    }

    private function remember(int $targetWref, int $aiId): void
    {
        $this->ensureRaidMemory();
        $this->db->exec(
            'INSERT INTO {p}raid_memory (ai_id, target_wref, last_success, last_loot, last_attack)
             VALUES (?, ?, 0, 0, UNIX_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_attack = UNIX_TIMESTAMP()',
            [$aiId, $targetWref]
        );
    }

    private function ensureRaidMemory(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS {p}raid_memory (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ai_id INT NOT NULL,
                target_wref INT NOT NULL,
                last_success TINYINT NOT NULL DEFAULT 0,
                last_loot INT NOT NULL DEFAULT 0,
                last_attack INT NOT NULL DEFAULT 0,
                UNIQUE KEY ai_target (ai_id, target_wref)
            )
        ');
    }

    private function troopsAway(int $wref): bool
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS n FROM {p}movement
             WHERE proc = 0 AND (`from` = ? OR (`to` = ? AND sort_type = 4))',
            [$wref, $wref]
        );
        return (int)($row['n'] ?? 0) > 0;
    }

    private function armySize(TravianAI $ai, int $wref): int
    {
        $column = 'u' . $ai->barracksUnit();
        $row = $this->db->one("SELECT {$column} AS n FROM {p}units WHERE vref = ?", [$wref]);
        return (int)($row['n'] ?? 0);
    }
}
