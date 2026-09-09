<?php
/**
 * helpers/referral_fraud.php
 *
 * ReferralFraudDetector — 5-point fraud analysis for referral sign-ups.
 *
 * Checks (score 0-100):
 *   1. VPN/Proxy/Tor detection  (+40)
 *   2. Same IP abuse            (+10 per extra, max +40)
 *   3. Device reuse             (+20 per extra device use, max +60)
 *   4. Rapid installs           (+35)
 *   5. Country mismatch         (+20)
 *
 * Action thresholds (from reward_config):
 *   score >= fraud_block_threshold (default 70) → 'block'
 *   score >= fraud_flag_threshold  (default 40) → 'flag'
 *   else                                         → 'allow'
 *
 * Usage:
 *   require_once __DIR__ . '/referral_fraud.php';
 *   require_once __DIR__ . '/reward_config.php';
 *
 *   ReferralFraudDetector::init($pdo, $redis);
 *
 *   $result = ReferralFraudDetector::analyze(
 *       ip:                '103.x.x.x',
 *       deviceFingerprint: 'sha256hex',
 *       referrerUid:       'uid-abc',
 *       refereeUid:        'uid-xyz',
 *       country:           'IN'
 *   );
 *   // ['score' => 40, 'flags' => ['vpn_detected'], 'action' => 'flag']
 */

declare(strict_types=1);

class ReferralFraudDetector
{
    // ── Redis TTLs ────────────────────────────────────────────────────────────
    private const VPN_CACHE_TTL     = 3600;   // 1 hour
    private const IP_COUNTER_TTL    = 86400;  // 24 hours
    private const RAPID_COUNTER_TTL = 3600;   // 1 hour

    // ── ipapi.co endpoint ─────────────────────────────────────────────────────
    private const IPAPI_URL = 'https://ipapi.co/%s/json/';

    // ── Dependencies ──────────────────────────────────────────────────────────
    private static ?PDO   $pdo   = null;
    private static ?Redis $redis = null;

    // ─────────────────────────────────────────────────────────────────────────
    // Dependency injection
    // ─────────────────────────────────────────────────────────────────────────
    public static function init(PDO $pdo, ?Redis $redis = null): void
    {
        self::$pdo   = $pdo;
        self::$redis = $redis;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // analyze() — main entry point
    // ─────────────────────────────────────────────────────────────────────────
    public static function analyze(
        string  $ip,
        string  $deviceFingerprint,
        string  $referrerUid,
        string  $refereeUid,
        string  $country
    ): array {
        $score = 0;
        $flags = [];

        // ── Check 1: VPN/Proxy/Tor ────────────────────────────────────────────
        if (self::isVPN($ip)) {
            $score += 40;
            $flags[] = 'vpn_detected';
        }

        // ── Check 2: IP abuse ─────────────────────────────────────────────────
        $ipScore = self::checkIpAbuse($ip);
        if ($ipScore > 0) {
            $score  += min(40, $ipScore);
            $flags[] = 'same_ip';
        }

        // ── Check 3: Device reuse ─────────────────────────────────────────────
        $deviceScore = self::checkDeviceReuse($deviceFingerprint, $refereeUid);
        if ($deviceScore > 0) {
            $score  += min(60, $deviceScore);
            $flags[] = 'same_device';
        }

        // ── Check 4: Rapid installs ───────────────────────────────────────────
        if (self::isRapidInstall($referrerUid)) {
            $score  += 35;
            $flags[] = 'rapid_install';
        }

        // ── Check 5: Country mismatch ─────────────────────────────────────────
        if (self::checkCountryMismatch($referrerUid, $country)) {
            $score  += 20;
            $flags[] = 'country_mismatch';
        }

        // ── Clamp score to 100 ────────────────────────────────────────────────
        $score = min(100, $score);

        // ── Determine action ──────────────────────────────────────────────────
        $blockThreshold = (int)RewardConfig::get('fraud_block_threshold', 70);
        $flagThreshold  = (int)RewardConfig::get('fraud_flag_threshold',  40);

        $action = match (true) {
            $score >= $blockThreshold => 'block',
            $score >= $flagThreshold  => 'flag',
            default                   => 'allow',
        };

        return [
            'score'  => $score,
            'flags'  => $flags,
            'action' => $action,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Check 1: VPN / Proxy / Tor
    // ipapi.co se check karo, Redis mein 1 hour cache karo
    // ─────────────────────────────────────────────────────────────────────────
    private static function isVPN(string $ip): bool
    {
        // Sanity check — private/reserved IPs are never VPN
        if (self::isPrivateIP($ip)) {
            return false;
        }

        $cacheKey = 'vpn_check:' . md5($ip);

        // Redis cache check
        if (self::$redis !== null) {
            try {
                $cached = self::$redis->get($cacheKey);
                if ($cached !== false && $cached !== null) {
                    return (bool)(int)$cached;
                }
            } catch (Throwable) {}
        }

        // ipapi.co API call
        $result = false;
        try {
            $apiKey = $_ENV['IPAPI_KEY'] ?? getenv('IPAPI_KEY') ?: '';
            $url    = sprintf(self::IPAPI_URL, urlencode($ip));
            if ($apiKey !== '') {
                $url .= '?key=' . urlencode($apiKey);
            }

            $ctx  = stream_context_create(['http' => [
                'timeout' => 3,
                'header'  => 'User-Agent: NewsXpressLive/1.0',
            ]]);
            $body = @file_get_contents($url, false, $ctx);

            if ($body !== false) {
                $data   = json_decode($body, true);
                $result = !empty($data['is_vpn'])
                       || !empty($data['is_proxy'])
                       || !empty($data['is_tor']);
            }
        } catch (Throwable) {
            // API fail hone par safe fallback — not a VPN
            $result = false;
        }

        // Redis cache store
        if (self::$redis !== null) {
            try {
                self::$redis->setex($cacheKey, self::VPN_CACHE_TTL, $result ? '1' : '0');
            } catch (Throwable) {}
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Check 2: Same IP daily abuse
    // ─────────────────────────────────────────────────────────────────────────
    private static function checkIpAbuse(string $ip): int
    {
        // IP ko sha256 hash karo (privacy protection)
        $hash    = hash('sha256', $ip);
        $date    = date('Y-m-d');
        $redisKey = "referral_ip:{$hash}:{$date}";

        $maxAllowed = (int)RewardConfig::get('max_installs_per_ip_daily', 3);

        $count = 1; // Current install
        if (self::$redis !== null) {
            try {
                $count = self::$redis->incr($redisKey);
                self::$redis->expire($redisKey, self::IP_COUNTER_TTL);
            } catch (Throwable) {
                $count = 1;
            }
        }

        // Agar allowed se zyada hai toh +10 per extra
        $extra = max(0, $count - $maxAllowed);
        return $extra * 10;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Check 3: Same device fingerprint reuse
    // Last 30 din mein ek device se kitne alag referees hain
    // ─────────────────────────────────────────────────────────────────────────
    private static function checkDeviceReuse(string $fingerprint, string $refereeUid): int
    {
        if (self::$pdo === null || $fingerprint === '') {
            return 0;
        }

        try {
            $stmt = self::$pdo->prepare(
                'SELECT COUNT(DISTINCT referee_uid)
                 FROM referrals
                 WHERE device_fingerprint = ?
                   AND referee_uid != ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            );
            $stmt->execute([$fingerprint, $refereeUid]);
            $count = (int)$stmt->fetchColumn();

            // Count > 0 matlab same device se pehle bhi koi register hua
            return $count > 0 ? ($count * 20) : 0;

        } catch (PDOException $e) {
            error_log('ReferralFraudDetector::checkDeviceReuse error: ' . $e->getMessage());
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Check 4: Rapid installs per referrer per hour
    // ─────────────────────────────────────────────────────────────────────────
    private static function isRapidInstall(string $referrerUid): bool
    {
        $threshold = (int)RewardConfig::get('rapid_install_threshold', 5);
        $redisKey  = "rapid_install:{$referrerUid}";

        if (self::$redis !== null) {
            try {
                $count = self::$redis->incr($redisKey);
                self::$redis->expire($redisKey, self::RAPID_COUNTER_TTL);
                return $count > $threshold;
            } catch (Throwable) {}
        }

        // Redis nahi hai toh DB se last 1 hour count check karo
        if (self::$pdo === null) {
            return false;
        }

        try {
            $stmt = self::$pdo->prepare(
                'SELECT COUNT(*)
                 FROM referrals
                 WHERE referrer_uid = ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
            );
            $stmt->execute([$referrerUid]);
            return ((int)$stmt->fetchColumn()) >= $threshold;

        } catch (PDOException $e) {
            error_log('ReferralFraudDetector::isRapidInstall error: ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Check 5: Country mismatch
    // Referrer India mein hai, referee US/UK se VPN ke through aaya
    // ─────────────────────────────────────────────────────────────────────────
    private static function checkCountryMismatch(string $referrerUid, string $refereeCountry): bool
    {
        if (self::$pdo === null) {
            return false;
        }

        try {
            // Referrer ka registered country fetch karo
            $stmt = self::$pdo->prepare(
                'SELECT country FROM referrals
                 WHERE referrer_uid = ?
                 ORDER BY created_at ASC
                 LIMIT 1'
            );
            $stmt->execute([$referrerUid]);
            $referrerCountry = $stmt->fetchColumn();

            // Referrer IN hai aur referee kisi aur country se (VPN pattern)
            if ($referrerCountry === 'IN' && $refereeCountry !== 'IN' && $refereeCountry !== '') {
                return true;
            }

            return false;

        } catch (PDOException $e) {
            error_log('ReferralFraudDetector::checkCountryMismatch error: ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utility: Private/Reserved IP check — inhe VPN check mein skip karo
    // ─────────────────────────────────────────────────────────────────────────
    private static function isPrivateIP(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
