<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use InvalidArgumentException;
use RuntimeException;

final readonly class EncryptedApiCredential
{
    private function __construct(private string $value)
    {
    }

    /** @return array{credential:string,key:string} */
    public static function encrypt(string $plain): array
    {
        if ($plain === '' || strlen($plain) > 8192) {
            throw new InvalidArgumentException('GEO API credential must contain between 1 and 8192 bytes.');
        }
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return [
            'credential' => base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key)),
            'key' => base64_encode($key),
        ];
    }

    public static function fromEnvironment(
        string $credentialVariable = 'HOLYMD_GEO_API_CREDENTIAL',
        string $keyVariable = 'HOLYMD_GEO_API_KEY',
    ): self {
        $encrypted = getenv($credentialVariable);
        $key = getenv($keyVariable);
        if (!is_string($encrypted) || !is_string($key) || $encrypted === '' || $key === '') {
            throw new RuntimeException('GEO API credentials must be configured.');
        }
        $ciphertext = base64_decode($encrypted, true);
        $decodedKey = base64_decode($key, true);
        if (
            $ciphertext === false
            || strlen($ciphertext) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES
            || $decodedKey === false
            || strlen($decodedKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        ) {
            throw new RuntimeException('GEO API credential encryption is invalid.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($ciphertext, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($ciphertext, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $decodedKey,
        );
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
