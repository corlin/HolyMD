<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use RuntimeException;
interface AiClient { public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse; }
final readonly class AiResponse { public function __construct(public string $json) {} }
/** Decrypts deployment config only; plaintext is never persisted in jobs or reviews. */
final readonly class EncryptedApiCredential {
    private function __construct(private string $value) {}
    public static function fromEnvironment(string $credentialVariable = 'HOLYMD_GEO_API_CREDENTIAL', string $keyVariable = 'HOLYMD_GEO_API_KEY'): self {
        $encrypted = getenv($credentialVariable); $key = getenv($keyVariable);
        if (!is_string($encrypted) || !is_string($key) || $encrypted === '' || $key === '') throw new RuntimeException('GEO API credentials must be configured.');
        $ciphertext = base64_decode($encrypted, true); $decodedKey = base64_decode($key, true);
        if ($ciphertext === false || $decodedKey === false || !function_exists('sodium_crypto_secretbox_open')) throw new RuntimeException('GEO API credential encryption is unavailable or invalid.');
        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES; $nonce = substr($ciphertext, 0, $nonceLength);
        $plain = sodium_crypto_secretbox_open(substr($ciphertext, $nonceLength), $nonce, $decodedKey);
        if ($plain === false) throw new RuntimeException('GEO API credential could not be decrypted.');
        return new self($plain);
    }
    public function reveal(): string { return $this->value; }
}
