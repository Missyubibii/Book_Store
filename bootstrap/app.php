<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEffectiveSuperAdmin;
use App\Http\Middleware\EnsureEligibleForPasswordOnboarding;
use App\Http\Middleware\EnsureStaffAccessIsActive;
use App\Http\Middleware\ResolveBranchContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'staff.active' => EnsureStaffAccessIsActive::class,
            'staff.onboarding' => EnsureEligibleForPasswordOnboarding::class,
            'branch.context' => ResolveBranchContext::class,
            'super_admin.effective' => EnsureEffectiveSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
