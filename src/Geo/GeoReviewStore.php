<?php
declare(strict_types=1);
namespace HolyMD\Geo;
interface GeoReviewStore { public function saveReview(GeoReview $review): void; public function enqueueRetry(string $articleSlug, string $bodyHash, string $reason): void; }
