<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use HolyMD\Auth\AdminGuard;
use HolyMD\Auth\Unauthorized;
use HolyMD\Http\Csrf;
use HolyMD\Http\Response;
use HolyMD\Http\ServerRequest;
use HolyMD\Queue\JobStatusRepository;
use HolyMD\Config\SiteTimezone;

final readonly class JobsController
{
    private AdminTimeFormatter $timeFormatter;

    public function __construct(private JobStatusRepository $jobs, private AdminGuard $guard, private Csrf $csrf, ?AdminTimeFormatter $timeFormatter = null)
    {
        $this->timeFormatter = $timeFormatter ?? new AdminTimeFormatter(SiteTimezone::fromEnvironment());
    }

    public function index(ServerRequest $request): Response
    {
        try {
            $this->guard->requireAdministrator();
        } catch (Unauthorized) {
            return Response::json(['error' => 'Administrator authentication is required.'], 401);
        }
        $summary = $this->jobs->summary();
        $recent = $this->jobs->recent();
        foreach ($recent as &$job) {
            $job['created_at_display'] = $this->timeFormatter->format(is_string($job['created_at'] ?? null) ? $job['created_at'] : null);
        }
        unset($job);
        $csrfToken = $this->csrf->token();
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/jobs.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
