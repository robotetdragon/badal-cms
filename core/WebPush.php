<?php

// =============================================================================
//  core/WebPush.php — Web Push notifications (VAPID, no dependency)
//
//  Implements:
//    - VAPID key generation and storage (ECDSA P-256)
//    - Push subscription management (JSON flat-file)
//    - Notification sending via VAPID (without encrypted payload)
//    - The Service Worker fetches content from the server
//
//  Requires: PHP 7.4+, OpenSSL extension with EC support (prime256v1)
// =============================================================================

class WebPush
{
    // =========================================================================
    //  Properties
    // =========================================================================

    private string $configDir;
    private string $vapidFile;
    private string $subsFile;
    private string $notifFile;

    // =========================================================================
    //  Constructor
    // =========================================================================

    public function __construct(string $configDir)
    {
        $this->configDir = rtrim($configDir, '/');
        $this->vapidFile = $this->configDir . '/vapid.json';
        $this->subsFile  = $this->configDir . '/push_subscriptions.json';
        $this->notifFile = $this->configDir . '/push_notification.json';
    }

    // =========================================================================
    //  VAPID Keys
    // =========================================================================

    /**
     * Returns the VAPID keys (generates them if absent).
     *
     * @return array{publicKey: string, privateKey: string} base64url-encoded
     */
    public function ensureVapidKeys(): array
    {
        if (file_exists($this->vapidFile)) {
            $keys = json_decode(file_get_contents($this->vapidFile), true);
            if (!empty($keys['publicKey']) && !empty($keys['privateKey'])) {
                return $keys;
            }
        }

        return $this->generateVapidKeys();
    }

    /**
     * Returns the VAPID public key in base64url (for the client JS).
     *
     * @return string
     */
    public function getPublicKey(): string
    {
        return $this->ensureVapidKeys()['publicKey'];
    }

    /**
     * Checks whether VAPID keys exist.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        if (!file_exists($this->vapidFile)) {
            return false;
        }

        $keys = json_decode(file_get_contents($this->vapidFile), true);

        return !empty($keys['publicKey']) && !empty($keys['privateKey']);
    }

    /**
     * Generates a new VAPID key pair and persists it to disk.
     *
     * @return array
     */
    private function generateVapidKeys(): array
    {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$key) {
            throw new \RuntimeException(
                'OpenSSL EC key generation failed: ' . openssl_error_string()
            );
        }

        $details = openssl_pkey_get_details($key);
        $x       = $details['ec']['x'];
        $y       = $details['ec']['y'];
        $d       = $details['ec']['d'];

        // Public key: uncompressed point (65 bytes: 0x04 + x + y)
        $publicRaw = "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT)
                            . str_pad($y, 32, "\x00", STR_PAD_LEFT);

        $keys = [
            'publicKey'  => self::b64url($publicRaw),
            'privateKey' => self::b64url(str_pad($d, 32, "\x00", STR_PAD_LEFT)),
        ];

        file_put_contents(
            $this->vapidFile,
            json_encode($keys, JSON_PRETTY_PRINT),
            LOCK_EX
        );

        return $keys;
    }

    // =========================================================================
    //  Subscriptions
    // =========================================================================

    /**
     * Returns all current push subscriptions.
     *
     * @return array
     */
    public function getSubscriptions(): array
    {
        if (!file_exists($this->subsFile)) {
            return [];
        }

        $data = json_decode(file_get_contents($this->subsFile), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Returns the number of active subscriptions.
     *
     * @return int
     */
    public function getCount(): int
    {
        return count($this->getSubscriptions());
    }

    /**
     * Adds a push subscription.
     *
     * @param  array $sub  {endpoint, keys: {p256dh, auth}}
     * @return bool
     */
    public function subscribe(array $sub): bool
    {
        if (empty($sub['endpoint'])) {
            return false;
        }

        $subs = $this->getSubscriptions();

        // Deduplicate by endpoint
        foreach ($subs as $existing) {
            if (($existing['endpoint'] ?? '') === $sub['endpoint']) {
                return true; // already subscribed
            }
        }

        $subs[] = [
            'endpoint' => $sub['endpoint'],
            'keys'     => $sub['keys'] ?? [],
            'created'  => date('Y-m-d H:i:s'),
        ];

        return file_put_contents(
            $this->subsFile,
            json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
    }

    /**
     * Removes a subscription by endpoint.
     *
     * @param  string $endpoint
     * @return bool
     */
    public function unsubscribe(string $endpoint): bool
    {
        $subs = $this->getSubscriptions();
        $subs = array_values(array_filter(
            $subs,
            fn($s) => ($s['endpoint'] ?? '') !== $endpoint
        ));

        return file_put_contents(
            $this->subsFile,
            json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
    }

    // =========================================================================
    //  Notification content (fetched by SW)
    // =========================================================================

    /**
     * Stores the content of the next notification (read by the SW via fetch).
     *
     * @param  string $title
     * @param  string $body
     * @param  string $url
     * @param  string $icon
     * @return bool
     */
    public function setNotification(
        string $title,
        string $body,
        string $url  = '',
        string $icon = ''
    ): bool {
        $data = [
            'title' => $title,
            'body'  => $body,
            'url'   => $url,
            'icon'  => $icon,
            'time'  => time(),
        ];

        return file_put_contents(
            $this->notifFile,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
    }

    /**
     * Returns the content of the last notification.
     *
     * @return array
     */
    public function getNotification(): array
    {
        if (!file_exists($this->notifFile)) {
            return [];
        }

        return json_decode(file_get_contents($this->notifFile), true) ?: [];
    }

    // =========================================================================
    //  Send push to all subscribers
    // =========================================================================

    /**
     * Sends a push (without payload) to all subscribers.
     * The SW will fetch the content via /push-notification.json
     *
     * @param  string $contactEmail
     * @return array{sent: int, failed: int, cleaned: int}
     */
    public function sendAll(string $contactEmail): array
    {
        $subs    = $this->getSubscriptions();
        $keys    = $this->ensureVapidKeys();
        $result  = ['sent' => 0, 'failed' => 0, 'cleaned' => 0];
        $cleaned = [];

        foreach ($subs as $i => $sub) {
            $endpoint = $sub['endpoint'] ?? '';
            if (!$endpoint) {
                continue;
            }

            $status = $this->sendPush($endpoint, $keys, $contactEmail);

            if ($status >= 200 && $status < 300) {
                $result['sent']++;
            } elseif ($status === 404 || $status === 410) {
                // Expired or invalid subscription — remove
                $cleaned[] = $i;
                $result['cleaned']++;
            } else {
                $result['failed']++;
            }
        }

        // Clean up expired subscriptions
        if (!empty($cleaned)) {
            foreach ($cleaned as $i) {
                unset($subs[$i]);
            }
            $subs = array_values($subs);
            file_put_contents(
                $this->subsFile,
                json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        }

        return $result;
    }

    /**
     * Sends a push without payload to an endpoint.
     *
     * @param  string $endpoint
     * @param  array  $vapidKeys
     * @param  string $contactEmail
     * @return int    HTTP status code
     */
    private function sendPush(string $endpoint, array $vapidKeys, string $contactEmail): int
    {
        $origin = parse_url($endpoint, PHP_URL_SCHEME)
                . '://'
                . parse_url($endpoint, PHP_URL_HOST);

        // JWT VAPID
        $header  = ['typ' => 'JWT', 'alg' => 'ES256'];
        $payload = [
            'aud' => $origin,
            'exp' => time() + 43200, // 12h
            'sub' => 'mailto:' . $contactEmail,
        ];

        $jwt = $this->createJwt($header, $payload, $vapidKeys['privateKey']);

        $headers = [
            'Authorization: vapid t=' . $jwt . ', k=' . $vapidKeys['publicKey'],
            'TTL: 86400',
            'Content-Length: 0',
            'Urgency: normal',
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status;
    }

    // =========================================================================
    //  JWT ES256
    // =========================================================================

    /**
     * Creates a signed JWT (ES256) from header, payload and private key.
     *
     * @param  array  $header
     * @param  array  $payload
     * @param  string $privateKeyB64
     * @return string
     */
    private function createJwt(array $header, array $payload, string $privateKeyB64): string
    {
        $segments  = self::b64url(json_encode($header));
        $segments .= '.' . self::b64url(json_encode($payload));

        // Reconstruct the PEM private key from the raw 32 bytes
        $d   = self::b64urlDecode($privateKeyB64);
        $pem = $this->privateKeyToPem($d);

        $key = openssl_pkey_get_private($pem);
        if (!$key) {
            throw new \RuntimeException(
                'VAPID private key invalid: ' . openssl_error_string()
            );
        }

        openssl_sign($segments, $derSig, $key, OPENSSL_ALGO_SHA256);

        // Convert the DER signature to raw format (r || s, 64 bytes)
        $rawSig = $this->derToRaw($derSig);

        return $segments . '.' . self::b64url($rawSig);
    }

    /**
     * Converts an ECDSA DER signature to raw format (64 bytes).
     *
     * @param  string $der
     * @return string
     */
    private function derToRaw(string $der): string
    {
        $offset = 2; // skip 0x30 + length

        // R
        $offset++; // skip 0x02
        $rLen    = ord($der[$offset++]);
        $r       = substr($der, $offset, $rLen);
        $offset += $rLen;

        // S
        $offset++; // skip 0x02
        $sLen = ord($der[$offset++]);
        $s    = substr($der, $offset, $sLen);

        // Normalize to 32 bytes each
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return substr($r, -32) . substr($s, -32);
    }

    /**
     * Builds a PEM EC Private Key from the d parameter (32 bytes).
     * We generate a complete key via OpenSSL then replace the d parameter.
     *
     * @param  string $d
     * @return string
     */
    private function privateKeyToPem(string $d): string
    {
        // Generate a temporary key to get the PEM structure
        $tmpKey  = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = openssl_pkey_get_details($tmpKey);

        // Replace d, recalculate the public point
        // We reconstruct via DER to inject our d
        $privHex = bin2hex($d);

        // DER EC Private Key with the d parameter
        // ASN.1 structure: ECPrivateKey (RFC 5915)
        $oid     = '06082a8648ce3d030107'; // OID prime256v1
        $dOctet  = '0420' . $privHex;      // OCTET STRING (32 bytes)

        // We use openssl to derive the public point from d
        // Trick: create the DER, import it, and export as PEM
        $ecPriv    = '020101' . $dOctet . 'a00a' . $oid;
        $ecPrivLen = strlen(hex2bin($ecPriv));
        $seq       = '30' . dechex($ecPrivLen) . $ecPriv;

        $der = hex2bin($seq);
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";

        // Test if OpenSSL accepts this key
        $test = @openssl_pkey_get_private($pem);
        if ($test) {
            return $pem;
        }

        // Fallback: use openssl via CLI to generate the PEM
        // (some PHP versions don't handle the minimal DER)
        return $this->privateKeyToPemFallback($d);
    }

    /**
     * Fallback PEM generation using openssl CLI.
     *
     * @param  string $d
     * @return string
     */
    private function privateKeyToPemFallback(string $d): string
    {
        // Build a more complete DER EC PARAMETERS + PRIVATE KEY
        // using the PKCS#8 format
        $privKeyHex = bin2hex($d);

        // PKCS#8 DER for EC private key on prime256v1
        $pkcs8Hex = '30770201010420' . $privKeyHex
                  . 'a00a06082a8648ce3d030107a14403420004';

        // We need the public point, but we don't have it.
        // Alternative: generate via openssl CLI
        $tmpFile = tempnam(sys_get_temp_dir(), 'vapid');
        $derData = hex2bin('30770201010420' . $privKeyHex . 'a00a06082a8648ce3d030107');
        $pem     = "-----BEGIN EC PRIVATE KEY-----\n"
                 . chunk_split(base64_encode($derData), 64, "\n")
                 . "-----END EC PRIVATE KEY-----\n";
        file_put_contents($tmpFile, $pem);

        // Use openssl to complete the key (derive the public point)
        $cmd     = 'openssl ec -in ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
        $fullPem = shell_exec($cmd);
        @unlink($tmpFile);

        if ($fullPem && strpos($fullPem, 'BEGIN') !== false) {
            return $fullPem;
        }

        // Last resort: the minimal key without public point
        return $pem;
    }

    // =========================================================================
    //  Base64url helpers
    // =========================================================================

    /**
     * Encodes data to base64url (RFC 4648).
     *
     * @param  string $data
     * @return string
     */
    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decodes a base64url string back to binary.
     *
     * @param  string $data
     * @return string
     */
    public static function b64urlDecode(string $data): string
    {
        return base64_decode(
            strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4)
        );
    }
}
