<?php
class TravianAIVillageLogic
{
    private const RESOURCE_SLOTS = [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18];
    private const CONSTRUCT_SLOTS = [19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38];
    private const BUILDING_SLOTS = [19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40];
    private const WAREHOUSE = 10;
    private const GRANARY = 11;
    private const MAIN_BUILDING = 15;
    private const RALLY_POINT = 16;
    private const BARRACKS = 19;
    private const FIELD_NAMES = [1=>'Woodcutter',2=>'Clay Pit',3=>'Iron Mine',4=>'Cropland'];
    private const STORAGE_FULL = 0.90;
    private const CROP_CRITICAL = 0.10;

    public function __construct(private TravianAIDb $db, private TravianAIGame $game, private TravianAIRaid $raids)
    {
    }

    public function run(TravianAI $ai): array
    {
        $village = $this->db->one(
            'SELECT wref, wood, clay, iron, crop, maxstore, maxcrop FROM {p}vdata WHERE owner = ? ORDER BY capital DESC LIMIT 1',
            [$ai->uid]
        );
        if ($village === null) {
            $this->game->debug($ai->username . ' | VILLAGE ERROR | no village');
            return ["{$ai->username} has no village"];
        }

        $wref = (int) $village['wref'];
        $fields = $this->db->one('SELECT * FROM {p}fdata WHERE vref = ?', [$wref]);
        if ($fields === null) {
            $this->game->debug($ai->username . ' | VILLAGE ERROR | no field data | wref=' . $wref);
            return ["Village {$wref} has no field data"];
        }

        $lines = [];

        $buildQueue = $this->queueLength('{p}bdata', 'wid', $wref);
        $this->game->debug($ai->username . ' | VILLAGE STATE | wref=' . $wref . ' | buildQueue=' . $buildQueue . ' | trainingQueue=' . $this->queueLength('{p}training', 'vref', $wref) . ' | fields=' . $this->totalFieldLevels($fields));

        if ($buildQueue === 0) {
            $line = $this->build($ai, $village, $fields, $wref);
            if ($line !== null) $lines[] = $line;
            else $this->game->debug($ai->username . ' | BUILD RESULT | no completed build action');
        } else {
            $this->game->debug($ai->username . ' | BUILD SKIP | queue already active');
        }

        $line = $this->train($ai, $fields, $wref);
        if ($line !== null) $lines[] = $line;
        else $this->game->debug($ai->username . ' | TRAIN RESULT | no training action');

        if ($ai->canRaid()) {
            $line = $this->raids->run($ai, $wref);
            if ($line !== null) $lines[] = $line;
            else $this->game->debug($ai->username . ' | RAID RESULT | no raid action');
        } else {
            $this->game->debug($ai->username . ' | RAID SKIP | behavior=' . $ai->behavior . ' does not allow raids');
        }

        return $lines;
    }

    private function build(TravianAI $ai, array $village, array $fields, int $wref): ?string
    {
        $action = $this->chooseBuild($ai, $village, $fields);
        if ($action === null) {
            $this->game->debug($ai->username . ' | BUILD DECISION | none');
            return null;
        }
        [$description, $perform] = $action;
        $this->game->debug($ai->username . ' | BUILD DECISION | ' . $description);
        $performed = $perform();
        $queue = $this->queueLength('{p}bdata', 'wid', $wref);
        $this->game->debug($ai->username . ' | BUILD VERIFY | performed=' . ($performed ? 'YES' : 'NO') . ' | queue=' . $queue);
        if (!$performed) {
            return null;
        }

        if ($queue === 0) {
            $this->game->debug($ai->username . ' | BUILD FAILED | request accepted but no bdata row created | ' . $description);
            return "Village {$wref} waiting for resources";
        }
        $this->game->debug($ai->username . ' | BUILD SUCCESS | ' . $description);
        return "Village {$wref} {$description}";
    }

    private function chooseBuild(TravianAI $ai, array $village, array $fields): ?array
    {
        $user = $ai->username;

        if ((int)$village['maxcrop'] > 0 && (int)$village['crop'] / (int)$village['maxcrop'] < self::CROP_CRITICAL) {
            $slot = $this->lowestFieldOfType($fields, 4);
            if ($slot !== null) {
                $level = (int)$fields['f'.$slot] + 1;
                $this->game->debug($user . ' | BUILD CHOICE | crop critical | slot=' . $slot . ' | level=' . $level);
                return ["upgraded Cropland to level {$level} (crop low)", fn() => $this->game->upgradeField($user, $slot)];
            }
        }

        $store = max((int)$village['wood'], (int)$village['clay'], (int)$village['iron']);
        if ((int)$village['maxstore'] > 0 && $store / (int)$village['maxstore'] >= self::STORAGE_FULL) {
            $action = $this->buildOrUpgrade($ai, $fields, self::WAREHOUSE, 'Warehouse');
            if ($action !== null) { $this->game->debug($user . ' | BUILD CHOICE | storage | ' . $action[0]); return $action; }
        }

        if ((int)$village['maxcrop'] > 0 && (int)$village['crop'] / (int)$village['maxcrop'] >= self::STORAGE_FULL) {
            $action = $this->buildOrUpgrade($ai, $fields, self::GRANARY, 'Granary');
            if ($action !== null) { $this->game->debug($user . ' | BUILD CHOICE | granary | ' . $action[0]); return $action; }
        }

        if ($this->totalFieldLevels($fields) >= $ai->militaryThreshold()) {
            $action = $this->nextMilitaryStep($ai, $fields);
            if ($action !== null) { $this->game->debug($user . ' | BUILD CHOICE | military | ' . $action[0]); return $action; }
        }

        $slot = $this->lowestField($ai, $fields);
        if ($slot === null) return null;
        $type = (int)$fields['f'.$slot.'t'];
        $name = self::FIELD_NAMES[$type] ?? "Field {$type}";
        $level = (int)$fields['f'.$slot] + 1;
        $this->game->debug($user . ' | BUILD CHOICE | lowest field | slot=' . $slot . ' | type=' . $type . ' | level=' . $level);
        return ["upgraded {$name} to level {$level}", fn() => $this->game->upgradeField($user, $slot)];
    }

    private function nextMilitaryStep(TravianAI $ai, array $fields): ?array
    {
        $user = $ai->username;
        if ($this->buildingSlot($fields, self::BARRACKS) !== null) return null;

        if ($this->buildingSlot($fields, self::RALLY_POINT) === null) {
            $empty = $this->emptySlot($fields);
            if ($empty !== null) return ['built a Rally Point', fn() => $this->game->constructBuilding($user, $empty, self::RALLY_POINT)];
            return null;
        }

        $main = $this->buildingSlot($fields, self::MAIN_BUILDING);
        if ($main !== null && (int)$fields['f'.$main] < 3) {
            $level = (int)$fields['f'.$main] + 1;
            return ["upgraded Main Building to level {$level}", fn() => $this->game->upgradeBuilding($user, $main)];
        }

        $empty = $this->emptySlot($fields);
        return $empty === null ? null : ['built a Barracks', fn() => $this->game->constructBuilding($user, $empty, self::BARRACKS)];
    }

    private function train(TravianAI $ai, array $fields, int $wref): ?string
    {
        $barracks = $this->buildingSlot($fields, self::BARRACKS);
        if ($barracks === null) {
            $this->game->debug($ai->username . ' | TRAIN DECISION | no barracks');
            return null;
        }
        if ($this->queueLength('{p}training', 'vref', $wref) > 0) {
            $this->game->debug($ai->username . ' | TRAIN SKIP | training queue active');
            return null;
        }

        $amount = max(1, (int)round((2 + ($ai->aggression() * 8)) * $ai->trainFactor()));
        $this->game->debug($ai->username . ' | TRAIN DECISION | barracks=' . $barracks . ' | unit=' . $ai->barracksUnit() . ' | amount=' . $amount);
        if (!$this->game->trainTroops($ai->username, $barracks, $ai->barracksUnit(), $amount)) {
            $this->game->debug($ai->username . ' | TRAIN FAILED | HTTP/action rejected');
            return null;
        }
        $trainingQueue = $this->queueLength('{p}training', 'vref', $wref);
        if ($trainingQueue === 0) {
            $this->game->debug($ai->username . ' | TRAIN FAILED | request accepted but training row not created');
            return null;
        }
        return "Village {$wref} trained {$amount} troops";
    }

    private function buildOrUpgrade(TravianAI $ai, array $fields, int $type, string $name): ?array
    {
        $user = $ai->username;
        $existing = $this->buildingSlot($fields, $type);
        if ($existing !== null) {
            $level = (int)$fields['f'.$existing] + 1;
            return ["upgraded {$name} to level {$level}", fn() => $this->game->upgradeBuilding($user, $existing)];
        }
        $empty = $this->emptySlot($fields);
        return $empty === null ? null : ["built a {$name}", fn() => $this->game->constructBuilding($user, $empty, $type)];
    }

    private function lowestField(TravianAI $ai, array $fields): ?int
    {
        $best = null; $bestScore = PHP_INT_MAX;
        foreach (self::RESOURCE_SLOTS as $slot) {
            $level = (int)$fields['f'.$slot]; $type = (int)$fields['f'.$slot.'t'];
            $score = $level * 10;
            if ($type === $ai->buildBias) $score--;
            if ($score < $bestScore) { $bestScore = $score; $best = $slot; }
        }
        return $best;
    }

    private function lowestFieldOfType(array $fields, int $type): ?int
    {
        $best = null; $bestLevel = PHP_INT_MAX;
        foreach (self::RESOURCE_SLOTS as $slot) {
            if ((int)$fields['f'.$slot.'t'] !== $type) continue;
            $level = (int)$fields['f'.$slot];
            if ($level < $bestLevel) { $bestLevel = $level; $best = $slot; }
        }
        return $best;
    }

    private function totalFieldLevels(array $fields): int
    {
        $total = 0;
        foreach (self::RESOURCE_SLOTS as $slot) $total += (int)$fields['f'.$slot];
        return $total;
    }

    private function buildingSlot(array $fields, int $type): ?int
    {
        foreach (self::BUILDING_SLOTS as $slot) {
            if ((int)$fields['f'.$slot.'t'] === $type && (int)$fields['f'.$slot] > 0) return $slot;
        }
        return null;
    }

    private function emptySlot(array $fields): ?int
    {
        foreach (self::CONSTRUCT_SLOTS as $slot) {
            if ((int)$fields['f'.$slot.'t'] === 0) return $slot;
        }
        return null;
    }

    private function queueLength(string $table, string $column, int $value): int
    {
        $row = $this->db->one("SELECT COUNT(*) AS n FROM {$table} WHERE {$column} = ?", [$value]);
        return (int)($row['n'] ?? 0);
    }
}
