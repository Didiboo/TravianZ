<?php
/**
 * TravianZ NPC player definition.
 *
 * A behaviour is the personality of the NPC. Difficulty is kept as an internal
 * pacing value; the ACP exposes the behaviour because that is what changes how
 * the NPC actually plays.
 */
class TravianAI
{
    private const BEHAVIORS = [
        'balanced' => [
            'label' => 'Équilibré',
            'think_min' => 180, 'think_max' => 300, 'action_chance' => 0.70,
            'military_threshold' => 8, 'aggression' => 0.55,
            'raid' => true, 'train_factor' => 1.00,
        ],
        'builder' => [
            'label' => 'Développeur',
            'think_min' => 210, 'think_max' => 330, 'action_chance' => 0.75,
            'military_threshold' => 14, 'aggression' => 0.15,
            'raid' => false, 'train_factor' => 0.75,
        ],
        'raider' => [
            'label' => 'Raideur',
            'think_min' => 120, 'think_max' => 210, 'action_chance' => 0.85,
            'military_threshold' => 6, 'aggression' => 0.90,
            'raid' => true, 'train_factor' => 1.25,
        ],
        'military' => [
            'label' => 'Militaire',
            'think_min' => 150, 'think_max' => 240, 'action_chance' => 0.80,
            'military_threshold' => 8, 'aggression' => 0.75,
            'raid' => true, 'train_factor' => 1.10,
        ],
        'pacifist' => [
            'label' => 'Pacifique',
            'think_min' => 240, 'think_max' => 360, 'action_chance' => 0.70,
            'military_threshold' => 18, 'aggression' => 0.05,
            'raid' => false, 'train_factor' => 0.35,
        ],
    ];

    public int $id;
    public int $uid;
    public string $username;
    public string $password;
    public string $behavior;
    public int $buildBias;
    public int $tribe;
    public int $nextThink;

    public function __construct(array $row)
    {
        $this->id = (int) $row['id'];
        $this->uid = (int) $row['uid'];
        $this->username = (string) $row['username'];
        $this->password = (string) $row['password'];
        $this->behavior = self::isBehavior((string) ($row['behavior'] ?? 'balanced'))
            ? (string) $row['behavior']
            : 'balanced';
        $this->buildBias = (int) ($row['build_bias'] ?? 4);
        $this->tribe = (int) ($row['tribe'] ?? 1);
        $this->nextThink = (int) ($row['next_think'] ?? 0);
    }

    public static function behaviors(): array
    {
        return self::BEHAVIORS;
    }

    public static function isBehavior(string $behavior): bool
    {
        return isset(self::BEHAVIORS[$behavior]);
    }

    private function profile(): array
    {
        return self::BEHAVIORS[$this->behavior];
    }

    public function behaviorLabel(): string
    {
        return $this->profile()['label'];
    }

    public function militaryThreshold(): int
    {
        return (int) $this->profile()['military_threshold'];
    }

    public function aggression(): float
    {
        return (float) $this->profile()['aggression'];
    }

    public function canRaid(): bool
    {
        return (bool) $this->profile()['raid'];
    }

    public function trainFactor(): float
    {
        return (float) $this->profile()['train_factor'];
    }

    public function barracksUnit(): int
    {
        return (($this->tribe - 1) * 10) + 1;
    }

    public function shouldThink(int $now): bool
    {
        return $now >= $this->nextThink;
    }

    public function willAct(): bool
    {
        $p = $this->profile();
        return (mt_rand(0, 1000) / 1000) < $p['action_chance'];
    }

    public function nextInterval(): int
    {
        $p = $this->profile();
        return max(60, mt_rand((int) $p['think_min'], (int) $p['think_max']));
    }
}
