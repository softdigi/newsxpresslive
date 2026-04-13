<?php
/**
 * helpers/totp.php
 *
 * Pure-PHP TOTP (RFC 6238) implementation — no Composer dependency.
 *
 * Provides:
 *   TotpHelper::generateSecret()        – 16-char Base32 secret
 *   TotpHelper::generateCode($secret)   – current 6-digit TOTP code
 *   TotpHelper::verifyCode($secret,$code,$window) – constant-time verify
 *   TotpHelper::qrCodeUrl($secret,$email,$issuer) – otpauth:// URI for QR
 *   TotpHelper::generateBackupCodes()   – 8 one-time backup codes
 *   TotpHelper::hashBackupCode($code)   – bcrypt hash for storage
 *   TotpHelper::verifyBackupCode($code,$hashes) – check + invalidate used code
 */

declare(strict_types=1);

class TotpHelper
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DIGITS       = 6;
    private const PERIOD       = 30;   // seconds

    // ── Key generation ────────────────────────────────────────────────────

    /**
     * Generate a cryptographically random 16-character Base32 secret.
     */
    public static function generateSecret(int $length = 16): string
    {
        $bytes  = random_bytes((int)ceil($length * 5 / 8));
        $result = '';
        $buffer = 0;
        $bits   = 0;
        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits  += 8;
            while ($bits >= 5) {
                $bits  -= 5;
                $result .= self::BASE32_CHARS[($buffer >> $bits) & 0x1F];
            }
        }
        return substr($result, 0, $length);
    }

    // ── TOTP code generation ──────────────────────────────────────────────

    /**
     * Compute the 6-digit TOTP code for a given secret and Unix timestamp.
     */
    public static function generateCode(string $secret, ?int $timestamp = null): string
    {
        $time    = (int)(($timestamp ?? time()) / self::PERIOD);
        $key     = self::base32Decode($secret);
        $msg     = pack('J', $time);                    // 8-byte big-endian
        $hash    = hash_hmac('sha1', $msg, $key, true);
        $offset  = ord($hash[19]) & 0x0F;
        $code    = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
            ((ord($hash[$offset + 3]) & 0xFF))
        ) % (10 ** self::DIGITS);

        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a user-supplied 6-digit code.
     * $window = 1 allows one period before/after (handles clock drift).
     */
    public static function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!ctype_digit($code) || strlen($code) !== self::DIGITS) {
            return false;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            $ts      = $now + ($i * self::PERIOD);
            $expected = self::generateCode($secret, $ts);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    // ── QR Code URI ───────────────────────────────────────────────────────

    /**
     * Return an otpauth:// URI consumable by Google Authenticator / Authy.
     * Pass this to any QR code library or Google Charts URL.
     */
    public static function qrCodeUrl(
        string $secret,
        string $email,
        string $issuer = 'NewsXpress Admin'
    ): string {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($email),
            rawurlencode($secret),
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    /**
     * Return a Google Charts QR code image URL (no server-side library needed).
     * Size: 200×200 pixels.
     */
    public static function googleQrImageUrl(string $otpauthUri): string
    {
        return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl='
             . rawurlencode($otpauthUri);
    }

    // ── Backup codes ──────────────────────────────────────────────────────

    /**
     * Generate 8 random alphanumeric backup codes (format: XXXX-XXXX).
     * Returns plain-text codes (show to user once, then store hashed).
     *
     * @return array<string>
     */
    public static function generateBackupCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw    = strtoupper(bin2hex(random_bytes(4)));
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        }
        return $codes;
    }

    /**
     * Return a bcrypt hash suitable for DB storage.
     */
    public static function hashBackupCode(string $code): string
    {
        return password_hash(strtoupper($code), PASSWORD_BCRYPT);
    }

    /**
     * Check whether $code matches any unused entry in $hashes.
     * Returns the updated $hashes array (matched entry set to null) on
     * success, or false on failure.
     *
     * @param  array<string|null> $hashes
     * @return array<string|null>|false
     */
    public static function verifyBackupCode(string $code, array $hashes)
    {
        $code = strtoupper(preg_replace('/\s+/', '', $code));
        foreach ($hashes as $idx => $hash) {
            if ($hash === null) continue;
            if (password_verify($code, $hash)) {
                $hashes[$idx] = null;  // consume it
                return $hashes;
            }
        }
        return false;
    }

    // ── Internal helpers ──────────────────────────────────────────────────

    private static function base32Decode(string $input): string
    {
        $input   = strtoupper(rtrim($input, '='));
        $output  = '';
        $buffer  = 0;
        $bits    = 0;
        $charMap = array_flip(str_split(self::BASE32_CHARS));

        foreach (str_split($input) as $char) {
            if (!isset($charMap[$char])) continue;
            $buffer = ($buffer << 5) | $charMap[$char];
            $bits  += 5;
            if ($bits >= 8) {
                $bits   -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $output;
    }
}
