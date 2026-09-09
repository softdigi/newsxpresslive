<?php
/**
 * helpers/reward_config.php
 *
 * RewardConfig — admin-configurable reward settings helper.
 *
 * 3-layer caching:
 *   in-memory (process lifetime) → Redis (5 min) → MySQL
 *
 * Usage:
 *   require_once __DIR__ . '/reward_config.php';
 *
 *   $rate = RewardConfig::get('reward_7day_inr', 2.00);  // float
 *   RewardConfig::set('reward_7day_inr', '3.00', $adminUid, 'Holi promo');
 *   $ok  = RewardConfig::checkBudget(5.00);
 *   RewardConfig::trackBudgetSpend(5.00);
 */

declare(strict_types=1);

class RewardConfig
{
    // ── Cache constants ───────────────────────────────────────────────────────
    private const REDIS_TTL   = 300;  // 5 minutes
    private const KEY_PREFIX  = 'rconfig:';

    // ── In-memory store (per-request) ─────────────────────────────────────────
    private static array $mem = [];

    // ── Dependencies injected once ────────────────────────────────────────────
    private static ?PDO    $pdo   = null;
    private static ?Redis  $redis = null;

    // ─────────────────────────────────────────────────────────────────────────
    // Dependency injection — call at bootstrap time
    // ─────────────────────────────────────────────────────────────────────────
    public static function init(PDO $pdo, ?Redis $redis = null): void
    {
        self::$pdo   = $pdo;
        self::$redis = $redis;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // get() — 3-layer cache fetch with type casting
    // ─────────────────────────────────────────────────────────────────────────
    public static function get(string $key, mixed $default = null): mixed
    {
        // Layer 1: in-memory
        if (isset(self::$mem[$key])) {
            return self::$mem[$key];
        }

        // Layer 2: Redis
        if (self::$redis !== null) {
            try {
                $raw = self::$redis->get(self::KEY_PREFIX . $key);
                if ($raw !== false && $raw !== null) {
                    // Redis mein sirf string hota hai — value_type chahiye cast ke liye
                    // Type info bhi cache karte hain ek JSON object mein
                    $data = json_decode($raw, true);
                    if (is_array($data) && isset($data['v'], $data['t'])) {
                        $value = self::castValue($data['v'], $data['t']);
                        self::$mem[$key] = $value;
                        return $value;
                    }
                }
            } catch (Throwable) {
                // Redis fail hone par DB pe fall through
            }
        }

        // Layer 3: DB
        if (self::$pdo === null) {
            return $default;
        }

        try {
            $stmt = self::$pdo->prepare(
                'SELECT config_value, value_type
                 FROM reward_config
                 WHERE config_key = ? AND is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return $default;
            }

            $value = self::castValue($row['config_value'], $row['value_type']);

            // Redis mein store karo
            if (self::$redis !== null) {
                try {
                    self::$redis->setex(
                        self::KEY_PREFIX . $key,
                        self::REDIS_TTL,
                        json_encode(['v' => $row['config_value'], 't' => $row['value_type']])
                    );
                } catch (Throwable) {
                    // Cache fail — koi baat nahi
                }
            }

            self::$mem[$key] = $value;
            return $value;

        } catch (PDOException $e) {
            error_log('RewardConfig::get PDO error: ' . $e->getMessage());
            return $default;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // set() — DB update + audit log + cache invalidate
    // ─────────────────────────────────────────────────────────────────────────
    public static function set(
        string $key,
        string $value,
        string $adminUid,
        string $reason = ''
    ): bool {
        if (self::$pdo === null) {
            return false;
        }

        try {
            // Pehle old value fetch karo
            $stmt = self::$pdo->prepare(
                'SELECT config_value FROM reward_config WHERE config_key = ? LIMIT 1'
            );
            $stmt->execute([$key]);
            $old = $stmt->fetchColumn();
            if ($old === false) {
                return false; // Key exist nahi karti
            }

            // DB update
            $upd = self::$pdo->prepare(
                'UPDATE reward_config SET config_value = ? WHERE config_key = ?'
            );
            $upd->execute([$value, $key]);

            if ($upd->rowCount() === 0) {
                return false;
            }

            // Audit log
            $audit = self::$pdo->prepare(
                'INSERT INTO reward_config_audit
                 (config_key, old_value, new_value, changed_by, reason)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $audit->execute([$key, (string)$old, $value, $adminUid, $reason]);

            // Redis cache delete
            if (self::$redis !== null) {
                try {
                    self::$redis->del(self::KEY_PREFIX . $key);
                } catch (Throwable) {}
            }

            // In-memory clear
            unset(self::$mem[$key]);

            return true;

        } catch (PDOException $e) {
            error_log('RewardConfig::set PDO error: ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getAll() — all active configs, optional group filter
    // ─────────────────────────────────────────────────────────────────────────
    public static function getAll(?string $group = null): array
    {
        if (self::$pdo === null) {
            return [];
        }

        try {
            if ($group !== null) {
                $stmt = self::$pdo->prepare(
                    'SELECT config_key, config_value, value_type, config_group, description
                     FROM reward_config
                     WHERE is_active = 1 AND config_group = ?
                     ORDER BY config_group, config_key'
                );
                $stmt->execute([$group]);
            } else {
                $stmt = self::$pdo->query(
                    'SELECT config_key, config_value, value_type, config_group, description
                     FROM reward_config
                     WHERE is_active = 1
                     ORDER BY config_group, config_key'
                );
            }

            $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($rows as $row) {
                $result[$row['config_key']] = [
                    'value'       => self::castValue($row['config_value'], $row['value_type']),
                    'value_type'  => $row['value_type'],
                    'group'       => $row['config_group'],
                    'description' => $row['description'],
                ];
            }
            return $result;

        } catch (PDOException $e) {
            error_log('RewardConfig::getAll PDO error: ' . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // checkBudget() — Redis counters se daily+monthly limits check karo
    // ─────────────────────────────────────────────────────────────────────────
    public static function checkBudget(float $amount): bool
    {
        $dailyLimit   = (float)self::get('daily_reward_budget',   2000.00);
        $monthlyLimit = (float)self::get('monthly_reward_budget', 20000.00);

        $today = date('Y-m-d');
        $month = date('Y-m');

        $dailyKey   = "reward_budget:daily:{$today}";
        $monthlyKey = "reward_budget:monthly:{$month}";

        // Redis se fetch karo
        if (self::$redis !== null) {
            try {
                $dailySpent   = (float)(self::$redis->get($dailyKey)   ?: 0);
                $monthlySpent = (float)(self::$redis->get($monthlyKey) ?: 0);

                if (($dailySpent + $amount) > $dailyLimit) {
                    return false;
                }
                if (($monthlySpent + $amount) > $monthlyLimit) {
                    return false;
                }
                return true;
            } catch (Throwable) {
                // Redis fail — DB se fallback
            }
        }

        // Redis nahi hai toh DB se check karo
        if (self::$pdo === null) {
            return true; // No DB — allow by default
        }

        try {
            $stmt = self::$pdo->prepare(
                'SELECT SUM(amount_spent) FROM reward_budget_tracking
                 WHERE period_type = ? AND period_key = ?'
            );

            $stmt->execute(['daily', $today]);
            $dailySpent = (float)($stmt->fetchColumn() ?: 0);

            $stmt->execute(['monthly', $month]);
            $monthlySpent = (float)($stmt->fetchColumn() ?: 0);

            return ($dailySpent + $amount) <= $dailyLimit
                && ($monthlySpent + $amount) <= $monthlyLimit;

        } catch (PDOException $e) {
            error_log('RewardConfig::checkBudget PDO error: ' . $e->getMessage());
            return true; // Fail-open
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // trackBudgetSpend() — Redis + DB mein spend record karo
    // ─────────────────────────────────────────────────────────────────────────
    public static function trackBudgetSpend(float $amount): void
    {
        $today = date('Y-m-d');
        $month = date('Y-m');

        // Redis update
        if (self::$redis !== null) {
            try {
                $dailyKey   = "reward_budget:daily:{$today}";
                $monthlyKey = "reward_budget:monthly:{$month}";

                // INCRBYFLOAT — atomic float increment
                self::$redis->incrByFloat($dailyKey,   $amount);
                self::$redis->expire($dailyKey, 2 * 86400);   // 2 days

                self::$redis->incrByFloat($monthlyKey, $amount);
                self::$redis->expire($monthlyKey, 35 * 86400); // 35 days
            } catch (Throwable) {}
        }

        // DB update — ON DUPLICATE KEY UPDATE
        if (self::$pdo === null) {
            return;
        }

        try {
            $stmt = self::$pdo->prepare(
                'INSERT INTO reward_budget_tracking (period_type, period_key, amount_spent)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE amount_spent = amount_spent + VALUES(amount_spent)'
            );

            $stmt->execute(['daily',   $today, $amount]);
            $stmt->execute(['monthly', $month, $amount]);

        } catch (PDOException $e) {
            error_log('RewardConfig::trackBudgetSpend PDO error: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // castValue() — value_type ke hisaab se PHP type mein convert karo
    // ─────────────────────────────────────────────────────────────────────────
    private static function castValue(string $raw, string $type): mixed
    {
        return match ($type) {
            'inr', 'percent' => (float)$raw,
            'coins', 'days', 'count' => (int)$raw,
            'bool'  => (bool)(int)$raw,
            default => $raw,
        };
    }
}
