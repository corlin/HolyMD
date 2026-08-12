<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use RuntimeException;
final class GeoAiException extends RuntimeException { public function __construct(string $message, public readonly bool $retryable) { parent::__construct($message); } }
