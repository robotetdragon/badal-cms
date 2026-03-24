<?php
// =============================================================================
//  core/WebPush.php — Web Push notifications (VAPID, sans dépendance)
//
//  Implémente :
//    - Génération et stockage des clés VAPID (ECDSA P-256)
//    - Gestion des abonnements push (JSON flat-file)
//    - Envoi de notifications via VAPID (sans payload chiffré)
//    - Le Service Worker fetch le contenu depuis le serveur
//
//  Requiert : PHP 7.4+, extension OpenSSL avec support EC (prime256v1)
// =============================================================================

class WebPush {

    private string $configDir;
    private string $vapidFile;
    private string $subsFile;
    private string $notifFile;

    public function __construct(string $configDir) {
        $this->configDir  = rtrim($configDir, '/');
        $this->vapidFile  = $this->configDir . '/vapid.json';
        $this->subsFile   = $this->configDir . '/push_subscriptions.json';
        $this->notifFile  = $this->configDir . '/push_notification.json';
    }

    // =========================================================================
    //  VAPID Keys
    // =========================================================================

    /**
     * Retourne les clés VAPID (les génère si absentes).
     * @return array{publicKey: string, privateKey: string} base64url-encoded
     */
    public function ensureVapidKeys(): array {
        if (file_exists($this->vapidFile)) {
            $keys = json_decode(file_get_contents($this->vapidFile), true);
            if (!empty($keys['publicKey']) && !empty($keys['privateKey'])) {
                return $keys;
            }
        }
        return $this->generateVapidKeys();
    }

    /** Retourne la clé publique VAPID en base64url (pour le JS client). */
    public function getPublicKey(): string {
        return $this->ensureVapidKeys()['publicKey'];
    }

    /** Vérifie si les clés VAPID existent. */
    public function isConfigured(): bool {
        if (!file_exists($this->vapidFile)) return false;
        $keys = json_decode(file_get_contents($this->vapidFile), true);
        return !empty($keys['publicKey']) && !empty($keys['privateKey']);
    }

    private function generateVapidKeys(): array {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$key) {
            throw new \RuntimeException('OpenSSL EC key generation failed: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);
        $x = $details['ec']['x'];
        $y = $details['ec']['y'];
        $d = $details['ec']['d'];

        // Clé publique : point non compressé (65 octets : 0x04 + x + y)
        $publicRaw = "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT)
                            . str_pad($y, 32, "\x00", STR_PAD_LEFT);

        $keys = [
            'publicKey'  => self::b64url($publicRaw),
            'privateKey' => self::b64url(str_pad($d, 32, "\x00", STR_PAD_LEFT)),
        ];

        file_put_contents($this->vapidFile, json_encode($keys, JSON_PRETTY_PRINT), LOCK_EX);
        return $keys;
    }

    // =========================================================================
    //  Subscriptions
    // =========================================================================

    public function getSubscriptions(): array {
        if (!file_exists($this->subsFile)) return [];
        $data = json_decode(file_get_contents($this->subsFile), true);
        return is_array($data) ? $data : [];
    }

    public function getCount(): int {
        return count($this->getSubscriptions());
    }

    /**
     * Ajoute un abonnement push.
     * @param array $sub {endpoint, keys: {p256dh, auth}}
     */
    public function subscribe(array $sub): bool {
        if (empty($sub['endpoint'])) return false;

        $subs = $this->getSubscriptions();

        // Dédoublonner par endpoint
        foreach ($subs as $existing) {
            if (($existing['endpoint'] ?? '') === $sub['endpoint']) {
                return true; // déjà abonné
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

    /** Supprime un abonnement par endpoint. */
    public function unsubscribe(string $endpoint): bool {
        $subs = $this->getSubscriptions();
        $subs = array_values(array_filter($subs, fn($s) => ($s['endpoint'] ?? '') !== $endpoint));
        return file_put_contents(
            $this->subsFile,
            json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
    }

    // =========================================================================
    //  Notification content (fetched by SW)
    // =========================================================================

    /** Stocke le contenu de la prochaine notification (lu par le SW via fetch). */
    public function setNotification(string $title, string $body, string $url = '', string $icon = ''): bool {
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

    /** Retourne le contenu de la dernière notification. */
    public function getNotification(): array {
        if (!file_exists($this->notifFile)) return [];
        return json_decode(file_get_contents($this->notifFile), true) ?: [];
    }

    // =========================================================================
    //  Send push to all subscribers
    // =========================================================================

    /**
     * Envoie un push (sans payload) à tous les abonnés.
     * Le SW fetchera le contenu via /push-notification.json
     *
     * @return array{sent: int, failed: int, cleaned: int}
     */
    public function sendAll(string $contactEmail): array {
        $subs    = $this->getSubscriptions();
        $keys    = $this->ensureVapidKeys();
        $result  = ['sent' => 0, 'failed' => 0, 'cleaned' => 0];
        $cleaned = [];

        foreach ($subs as $i => $sub) {
            $endpoint = $sub['endpoint'] ?? '';
            if (!$endpoint) continue;

            $status = $this->sendPush($endpoint, $keys, $contactEmail);

            if ($status >= 200 && $status < 300) {
                $result['sent']++;
            } elseif ($status === 404 || $status === 410) {
                // Abonnement expiré ou invalide → supprimer
                $cleaned[] = $i;
                $result['cleaned']++;
            } else {
                $result['failed']++;
            }
        }

        // Nettoyer les abonnements expirés
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
     * Envoie un push sans payload à un endpoint.
     * @return int HTTP status code
     */
    private function sendPush(string $endpoint, array $vapidKeys, string $contactEmail): int {
        $origin = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);

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

    private function createJwt(array $header, array $payload, string $privateKeyB64): string {
        $segments  = self::b64url(json_encode($header));
        $segments .= '.' . self::b64url(json_encode($payload));

        // Reconstruire la clé privée PEM depuis les 32 octets bruts
        $d = self::b64urlDecode($privateKeyB64);
        $pem = $this->privateKeyToPem($d);

        $key = openssl_pkey_get_private($pem);
        if (!$key) {
            throw new \RuntimeException('VAPID private key invalid: ' . openssl_error_string());
        }

        openssl_sign($segments, $derSig, $key, OPENSSL_ALGO_SHA256);

        // Convertir la signature DER en format brut (r || s, 64 octets)
        $rawSig = $this->derToRaw($derSig);

        return $segments . '.' . self::b64url($rawSig);
    }

    /** Convertit une signature ECDSA DER en format brut (64 octets). */
    private function derToRaw(string $der): string {
        $offset = 2; // skip 0x30 + length

        // R
        $offset++; // skip 0x02
        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        // S
        $offset++; // skip 0x02
        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);

        // Normaliser à 32 octets chacun
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return substr($r, -32) . substr($s, -32);
    }

    /**
     * Construit un PEM EC Private Key à partir du paramètre d (32 octets).
     * On génère une clé complète via OpenSSL puis on remplace le paramètre d.
     */
    private function privateKeyToPem(string $d): string {
        // Générer une clé temporaire pour obtenir la structure PEM
        $tmpKey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = openssl_pkey_get_details($tmpKey);

        // Remplacer d, recalculer le point public
        // On reconstruit via DER pour injecter notre d
        $privHex = bin2hex($d);

        // DER EC Private Key avec le paramètre d
        // Structure ASN.1 : ECPrivateKey (RFC 5915)
        $oid = '06082a8648ce3d030107'; // OID prime256v1
        $dOctet = '0420' . $privHex;   // OCTET STRING (32 bytes)

        // On utilise openssl pour dériver le point public à partir de d
        // Trick: créer le DER, l'importer, et exporter en PEM
        $ecPriv = '020101' . $dOctet . 'a00a' . $oid;
        $ecPrivLen = strlen(hex2bin($ecPriv));
        $seq = '30' . dechex($ecPrivLen) . $ecPriv;

        $der = hex2bin($seq);
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";

        // Tester si OpenSSL accepte cette clé
        $test = @openssl_pkey_get_private($pem);
        if ($test) {
            return $pem;
        }

        // Fallback : utiliser openssl via CLI pour générer la PEM
        // (certaines versions de PHP ne gèrent pas le DER minimal)
        return $this->privateKeyToPemFallback($d);
    }

    private function privateKeyToPemFallback(string $d): string {
        // Construire un DER EC PARAMETERS + PRIVATE KEY plus complet
        // en utilisant le format PKCS#8
        $privKeyHex = bin2hex($d);

        // PKCS#8 DER for EC private key on prime256v1
        $pkcs8Hex = '30770201010420' . $privKeyHex
                  . 'a00a06082a8648ce3d030107a14403420004';

        // On a besoin du point public, mais on ne l'a pas.
        // Alternative: générer via openssl CLI
        $tmpFile = tempnam(sys_get_temp_dir(), 'vapid');
        $derData = hex2bin('30770201010420' . $privKeyHex . 'a00a06082a8648ce3d030107');
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($derData), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";
        file_put_contents($tmpFile, $pem);

        // Utiliser openssl pour compléter la clé (dériver le point public)
        $cmd = 'openssl ec -in ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
        $fullPem = shell_exec($cmd);
        @unlink($tmpFile);

        if ($fullPem && strpos($fullPem, 'BEGIN') !== false) {
            return $fullPem;
        }

        // Dernier recours : la clé minimale sans point public
        return $pem;
    }

    // =========================================================================
    //  Base64url helpers
    // =========================================================================

    public static function b64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
