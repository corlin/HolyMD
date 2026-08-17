<?php

declare(strict_types=1);

namespace HolyMD\Geo;

final readonly class GeoScore
{
    /**
     * @param int $total 0-100 total score
     * @param array<int, array{field: string, label: string, weight: int, earned: int, reason: string}> $breakdown
     */
    public function __construct(
        public int $total,
        public array $breakdown
    ) {
    }

    /**
     * Return semantic grade: 'excellent' (>=80), 'good' (50-79), 'weak' (<50)
     */
    public function grade(): string
    {
        if ($this->total >= 80) {
            return 'excellent';
        }
        if ($this->total >= 50) {
            return 'good';
        }
        return 'weak';
    }

    /**
     * Label associated with grade
     */
    public function gradeLabel(): string
    {
        return match ($this->grade()) {
            'excellent' => '优秀',
            'good' => '良好',
            'weak' => '待优化',
        };
    }
}
