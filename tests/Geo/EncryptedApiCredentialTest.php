<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Config\Env;
use HolyMD\Geo\EncryptedApiCredential;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EncryptedApiCredentialTest extends TestCase
{
    public function test_generated_environment_values_decrypt_to_the_original_provider_key(): void
    {
        $plain = 'sk-provider-secret-that-must-not-be-printed';
        $encrypted = EncryptedApiCredential::encrypt($plain);
        $credentialName = 'HOLYMD_TEST_GEO_CREDENTIAL_' . bin2hex(random_bytes(4));
        $keyName = 'HOLYMD_TEST_GEO_KEY_' . bin2hex(random_bytes(4));

        try {
            Env::set($credentialName, $encrypted['credential']);
            Env::set($keyName, $encrypted['key']);

            self::assertSame($plain, EncryptedApiCredential::fromEnvironment($credentialName, $keyName)->reveal());
            self::assertStringNotContainsString($plain, $encrypted['credential']);
            self::assertStringNotContainsString($plain, $encrypted['key']);
        } finally {
            Env::set($credentialName, null);
            Env::set($keyName, null);
        }
    }

    public function test_encryption_uses_a_random_nonce_for_each_call(): void
    {
        $first = EncryptedApiCredential::encrypt('same-secret');
        $second = EncryptedApiCredential::encrypt('same-secret');

        self::assertNotSame($first['credential'], $second['credential']);
        self::assertNotSame($first['key'], $second['key']);
    }

    public function test_tampered_credentials_are_rejected(): void
    {
        $encrypted = EncryptedApiCredential::encrypt('provider-key');
        $payload = base64_decode($encrypted['credential'], true);
        self::assertIsString($payload);
        $payload[13] = $payload[13] === 'A' ? 'B' : 'A';
        $credentialName = 'HOLYMD_TEST_GEO_CREDENTIAL_' . bin2hex(random_bytes(4));
        $keyName = 'HOLYMD_TEST_GEO_KEY_' . bin2hex(random_bytes(4));
        Env::set($credentialName, base64_encode($payload));
        Env::set($keyName, $encrypted['key']);

        try {
            $this->expectException(RuntimeException::class);
            EncryptedApiCredential::fromEnvironment($credentialName, $keyName)->reveal();
        } finally {
            Env::set($credentialName, null);
            Env::set($keyName, null);
        }
    }
}
