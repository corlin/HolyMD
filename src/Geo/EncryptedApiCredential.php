<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use InvalidArgumentException;
use RuntimeException;

final readonly class EncryptedApiCredential
{
    private const KEY_BYTES = 32;
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    private function __construct(private string $value)
    {
    }

    /** @return array{credential:string,key:string} */
    public static function encrypt(string $plain): array
    {
        if ($plain === '' || strlen($plain) > 8192) {
            throw new InvalidArgumentException('GEO API credential must contain between 1 and 8192 bytes.');
        }
        // AES-256-GCM via OpenSSL so shared hosts without ext-sodium work.
        // Layout: iv (12) || tag (16) || ciphertext.
        $key = random_bytes(self::KEY_BYTES);
        $iv = random_bytes(self::IV_BYTES);
        $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('GEO API credential could not be encrypted.');
        }

        return [
            'credential' => base64_encode($iv . $tag . $ciphertext),
            'key' => base64_encode($key),
        ];
    }

    public static function fromEnvironment(
        string $credentialVariable = 'HOLYMD_GEO_API_CREDENTIAL',
        string $keyVariable = 'HOLYMD_GEO_API_KEY',
    ): self {
        $encrypted = \HolyMD\Config\Env::get($credentialVariable);
        $key = \HolyMD\Config\Env::get($keyVariable);
        if (!is_string($encrypted) || !is_string($key) || $encrypted === '' || $key === '') {
            throw new RuntimeException('GEO API credentials must be configured.');
        }
        $payload = base64_decode($encrypted, true);
        $decodedKey = base64_decode($key, true);
        if (
            $payload === false
            || strlen($payload) < self::IV_BYTES + self::TAG_BYTES
            || $decodedKey === false
            || strlen($decodedKey) !== self::KEY_BYTES
        ) {
            throw new RuntimeException('GEO API credential encryption is invalid.');
        }
        $iv = substr($payload, 0, self::IV_BYTES);
        $tag = substr($payload, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($payload, self::IV_BYTES + self::TAG_BYTES);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $decodedKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('GEO API credential could not be decrypted.');
        }
        return new self($plain);
    }

    public function reveal(): string
    {
        return $this->value;
    }
}
