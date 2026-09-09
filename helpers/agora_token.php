<?php
/**
 * helpers/agora_token.php
 *
 * Pure-PHP Agora AccessToken2 builder.
 *
 * Compatible with Agora SDK v4+ (AccessToken2 format, prefix "007").
 * Matches the reference implementations at
 * https://github.com/AgoraIO/Tools/tree/master/DynamicKey/AgoraDynamicKey
 *
 * Usage:
 *   $token = AgoraTokenBuilder::buildTokenWithUid(
 *       AGORA_APP_ID,
 *       AGORA_APP_CERT,
 *       'channel-abc',
 *       0,          // uid  (0 = let Agora assign)
 *       AgoraTokenBuilder::ROLE_PUBLISHER,
 *       3600        // seconds until token expires
 *   );
 *
 * Environment variables required (set in server env or .env):
 *   AGORA_APP_ID    — 32-char hex App ID from Agora Console
 *   AGORA_APP_CERT  — 32-char hex App Certificate from Agora Console
 */

declare(strict_types=1);

class AgoraTokenBuilder
{
    // ── Roles ────────────────────────────────────────────────────────────────
    const ROLE_PUBLISHER  = 1;
    const ROLE_SUBSCRIBER = 2;

    // ── Agora AccessToken2 constants ─────────────────────────────────────────
    private const VERSION          = '007';
    private const SERVICE_TYPE_RTC = 1;

    // RTC privilege IDs
    private const PRIV_JOIN_CHANNEL   = 1;
    private const PRIV_PUBLISH_AUDIO  = 2;
    private const PRIV_PUBLISH_VIDEO  = 3;
    private const PRIV_PUBLISH_DATA   = 4;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Build an Agora RTC AccessToken2.
     *
     * @param string $appId            32-char hex App ID
     * @param string $appCertificate   32-char hex App Certificate
     * @param string $channelName      Agora channel name
     * @param int    $uid              User ID (0 = let server assign)
     * @param int    $role             ROLE_PUBLISHER | ROLE_SUBSCRIBER
     * @param int    $tokenExpireSec   Token TTL in seconds (default 3600)
     * @param int    $privExpireSec    Privilege TTL in seconds (default same as token)
     * @return string                  Agora token string starting with "007"
     */
    public static function buildTokenWithUid(
        string $appId,
        string $appCertificate,
        string $channelName,
        int    $uid,
        int    $role,
        int    $tokenExpireSec = 3600,
        int    $privExpireSec  = 0
    ): string {
        if ($privExpireSec <= 0) {
            $privExpireSec = $tokenExpireSec;
        }

        $issueTs    = time();
        $tokenExpiry = $issueTs + $tokenExpireSec;
        $privExpiry  = $issueTs + $privExpireSec;
        $salt        = random_int(1, 0x7FFFFFFF);

        // Build privilege map (sorted by key, as reference implementations do)
        $privileges = [self::PRIV_JOIN_CHANNEL => $privExpiry];
        if ($role === self::ROLE_PUBLISHER) {
            $privileges[self::PRIV_PUBLISH_AUDIO] = $privExpiry;
            $privileges[self::PRIV_PUBLISH_VIDEO] = $privExpiry;
            $privileges[self::PRIV_PUBLISH_DATA]  = $privExpiry;
        }
        ksort($privileges);

        // Pack RTC service body
        $serviceBody = self::packString($channelName)
                     . self::packString((string)$uid)
                     . self::packPrivileges($privileges);

        // Build message (what is signed)
        $msg  = $appId;
        $msg .= self::packUint32($issueTs);
        $msg .= self::packUint32($salt);
        $msg .= self::packUint32($tokenExpiry);
        $msg .= self::packUint16(1); // number of services
        $msg .= self::packUint16(self::SERVICE_TYPE_RTC);
        $msg .= self::packString($serviceBody);

        // Signature = HMAC-SHA256(msg, appCertificate)
        $sig = hash_hmac('sha256', $msg, $appCertificate, true /* raw */);

        // Final payload = pack_string(sig) + msg
        $payload    = self::packString($sig) . $msg;
        $compressed = gzcompress($payload, 9);

        return self::VERSION . base64_encode($compressed);
    }

    // ── Private packing helpers ───────────────────────────────────────────────

    /** Little-endian uint16 */
    private static function packUint16(int $v): string
    {
        return pack('v', $v);
    }

    /** Little-endian uint32 */
    private static function packUint32(int $v): string
    {
        return pack('V', $v);
    }

    /** uint16 length prefix + raw bytes */
    private static function packString(string $s): string
    {
        return self::packUint16(strlen($s)) . $s;
    }

    /** Serialize privileges map: uint16 count + (uint16 id + uint32 expiry)* */
    private static function packPrivileges(array $privileges): string
    {
        $data = self::packUint16(count($privileges));
        foreach ($privileges as $id => $expiry) {
            $data .= self::packUint16((int)$id);
            $data .= self::packUint32((int)$expiry);
        }
        return $data;
    }
}
