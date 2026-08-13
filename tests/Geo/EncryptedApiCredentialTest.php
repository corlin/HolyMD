<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Geo\EncryptedApiCredential;
use PHPUnit\Framework\TestCase;

final class EncryptedApiCredentialTest extends TestCase
{
    public function test_generated_environment_values_decrypt_to_the_original_provider_key(): void
    {
        $plain = 'sk-provider-secret-that-must-not-be-printed';
        $encrypted = EncryptedApiCredential::encrypt($plain);
        $credentialName = 'HOLYMD_TEST_GEO_CREDENTIAL_' . bin2hex(random_bytes(4));
        $keyName = 'HOLYMD_TEST_GEO_KEY_' . bin2hex(random_bytes(4));

        try {
            putenv($credentialName . '=' . $encrypted['credential']);
            putenv($keyName . '=' . $encrypted['key']);

            self::assertSame($plain, EncryptedApiCredential::fromEnvironment($credentialName, $keyName)->reveal());
            self::assertStringNotContainsString($plain, $encrypted['credential']);
            self::assertStringNotContainsString($plain, $encrypted['key']);
        } finally {
            putenv($credentialName);
            putenv($keyName);
        }
    }
}
