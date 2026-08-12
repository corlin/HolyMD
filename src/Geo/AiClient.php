<?php
declare(strict_types=1);
namespace HolyMD\Geo;
final readonly class AiResponse { public function __construct(public string $json) {} }
interface AiClient { public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse; }
