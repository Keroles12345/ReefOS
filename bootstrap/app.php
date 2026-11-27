
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup(
            'web',
            \App\Http\Middleware\InitializeTenantContext::class
        );

        $middleware->appendToGroup(
            'web',
            \App\Http\Middleware\SetDashboardLocale::class
        );

        $middleware->alias([
            'staff' => \App\Http\Middleware\StaffMiddleware::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'superadmin' => \App\Http\Middleware\SuperAdminMiddleware::class,
            'accounting-role' => \App\Http\Middleware\AccountingRoleMiddleware::class,
            'supervisor' => \App\Http\Middleware\SupervisorMiddleware::class,
            'system-permission' => \App\Http\Middleware\SystemPermissionMiddleware::class,
            'tenant.api-key' => \App\Http\Middleware\AuthenticateTenantApiKey::class,
            'tenant.api-scope' => \App\Http\Middleware\RequireTenantApiScope::class,
            'tenant.api-legacy-company' => \App\Http\Middleware\RequireSingleCompanyLegacyApi::class,
            'StaffSales' => \App\Http\Middleware\StaffSallesMiddleware::class,
            'StaffOperations' => \App\Http\Middleware\StaffOperationsMiddleware::class,
            'StaffReseller' => \App\Http\Middleware\StaffResellerMiddleware::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();

/*
|--------------------------------------------------------------------------
| Hostinger Public Directory
|--------------------------------------------------------------------------
|
| On Hostinger, the Laravel public directory is located in public_html,
| outside the MarinaX application directory.
|
*/

$applicationPath = dirname(__DIR__);
$hostingerPublicPath = dirname($applicationPath).'/public_html';
$localPublicPath = $applicationPath.'/public_html';

if (basename($applicationPath) === 'MarinaX' && is_dir($hostingerPublicPath)) {
    $app->usePublicPath($hostingerPublicPath);
} elseif (is_dir($localPublicPath)) {
    $app->usePublicPath($localPublicPath);
} elseif (is_dir($hostingerPublicPath)) {
    $app->usePublicPath($hostingerPublicPath);
}

return $app;
