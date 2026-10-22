<?php

namespace App\Providers;

use App\Services\RolePermissions;
use App\Support\AuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    /** Route parameters that are always numeric record ids (see routes/web.php). */
    private const NUMERIC_ROUTE_PARAMETERS = ['case', 'lgu', 'conference', 'user', 'circle', 'plan', 'record', 'candidate', 'note', 'device', 'assessment', 'task',
        'report', 'merge', 'member', 'governance', 'document', 'access', 'transfer', 'referral', 'notification', 'message', 'legacy', 'intervention', 'hold',
        'field', 'consent', 'attachment', 'action'];

    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
            // Debug pages show configuration and stack traces; never in production.
            if (config('app.debug')) {
                logger()->critical('APP_DEBUG=true is not allowed in production; debug output has been switched off.');
                config(['app.debug' => false]);
            }
            // A stray public/hot file (left by "npm run dev") would make every page load
            // scripts from the developer's machine. Production always uses the built assets.
            Vite::useHotFile(storage_path('framework/vite.hot'));
        }
        // Case documents and attachments are stored on the default disk; it must never be
        // the web-public disk.
        if (config('filesystems.default') === 'public') {
            logger()->critical('FILESYSTEM_DISK=public is not allowed for case files; using the private local disk.');
            config(['filesystems.default' => 'local']);
        }
        // Record identifiers in URLs are numeric. Anything else is a 404, never a
        // server error from an int-typed controller argument.
        Route::patterns(array_fill_keys(self::NUMERIC_ROUTE_PARAMETERS, '[0-9]+'));
        Vite::prefetch(concurrency: 3);
        Vite::useCspNonce();

        // Sign-in activity in the audit trail. Failed attempts keep the account id when it
        // exists, never the typed password or an unknown email address.
        Event::listen(Login::class, fn ($e) => AuditLog::record('auth.login', 'user', $e->user->id, ['remember' => $e->remember], $e->user->id));
        Event::listen(Logout::class, fn ($e) => $e->user ? AuditLog::record('auth.logout', 'user', $e->user->id, [], $e->user->id) : null);
        Event::listen(Failed::class, fn ($e) => AuditLog::record('auth.failed', 'user', $e->user?->id, ['known_account' => (bool) $e->user], $e->user?->id, 'failure'));
        Event::listen(Lockout::class, fn ($e) => AuditLog::record('auth.lockout', 'user', null, [], null, 'failure'));
        Event::listen(PasswordReset::class, fn ($e) => AuditLog::record('auth.password_reset', 'user', $e->user->id, [], $e->user->id));

        Password::defaults(fn () => Password::min(10)->letters()->mixedCase()->numbers()->symbols()->uncompromised());
        foreach (RolePermissions::PERMISSIONS as $permission) {
            Gate::define($permission, fn ($user) => $user->hasPermission($permission));
        }
        Gate::define('administer-system', fn ($user) => $user->hasPermission('admin.manage'));
        Gate::define('view-case-content', fn ($user) => $user->canViewSensitiveCaseData());
        Gate::define('manage-casework', fn ($user) => $user->hasPermission('cases.view'));
        Gate::define('view-analytics', fn ($user) => $user->hasPermission('analytics.view'));
        Gate::define('management-dashboard-view', fn ($user) => $user->hasPermission('management.dashboard.view'));
        Gate::define('management-report-export', fn ($user) => $user->hasPermission('management.report.export'));

        RateLimiter::for('login', fn ($request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')) . '|' . $request->ip()));
        RateLimiter::for('public-report', fn ($request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('sensitive-write', fn ($request) => Limit::perMinute(60)->by(($request->user()?->id ?? 'guest') . '|' . $request->ip()));
        RateLimiter::for('file-upload', fn ($request) => Limit::perMinute(12)->by(($request->user()?->id ?? 'guest') . '|' . $request->ip()));
        RateLimiter::for('offline-sync', fn ($request) => Limit::perMinute(30)->by(($request->user()?->id ?? 'guest') . '|' . $request->header('X-Kalinga-Device-ID') . '|' . $request->ip()));
    }
}
