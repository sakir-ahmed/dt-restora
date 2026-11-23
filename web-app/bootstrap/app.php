<?php

use App\Exceptions\InsufficientStockException;
use App\Http\Middleware\AuthenticateAttendanceDevice;
use App\Http\Middleware\EnsureWebsiteEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use App\Support\DependencyInspector;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware as SpatiePermission;
use Spatie\Permission\Middleware\RoleMiddleware as SpatieRole;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            SetLocale::class,
            EnsureWebsiteEnabled::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'role' => SpatieRole::class,
            'permission' => SpatiePermission::class,
            'auth' => Authenticate::class,
            'attendance.device' => AuthenticateAttendanceDevice::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));

        $middleware->validateCsrfTokens(except: [
            'payments/sslcommerz/ipn',
            'webhooks/aggregators/*',
        ]);

        // First-party POS uses the staff session cookie for /api/v1/sync.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Any delete the database refuses because other records still use the row is explained the same way
        // in every module: what uses it, how many, in which order to clear it, and where.
        $exceptions->render(function (QueryException $e, Request $request) {
            $violation = in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1217], true)
                || str_contains($e->getMessage(), 'FOREIGN KEY constraint failed');

            if (! $violation || ! $request->isMethod('DELETE')) {
                return null;
            }

            $model = collect($request->route()?->parameters() ?? [])->first(fn ($value) => $value instanceof Model);

            if (! $model instanceof Model) {
                return null;
            }

            $name = (string) ($model->name ?? class_basename($model).' #'.$model->getKey());
            $message = __('common.in_use', ['name' => $name]);
            $steps = DependencyInspector::steps($model);

            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'error' => ['code' => 'IN_USE', 'message' => $message, 'details' => $steps]], 409);
            }

            return back()->with('error', $message)->with('error_steps', $steps);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $status = match (true) {
                    $e instanceof InsufficientStockException => 409,
                    $e instanceof ValidationException => 422,
                    $e instanceof AuthenticationException => 401,
                    $e instanceof AuthorizationException => 403,
                    $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                    default => 500,
                };

                $code = match ($status) {
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    409 => $e instanceof InsufficientStockException ? 'INSUFFICIENT_STOCK' : 'CONFLICT',
                    422 => 'VALIDATION_FAILED',
                    429 => 'TOO_MANY_REQUESTS',
                    default => 'SERVER_ERROR',
                };

                $message = $status === 500
                    ? __('api.server_error')
                    : ($e instanceof ValidationException
                        ? (collect($e->errors())->flatten()->first() ?: __('api.validation_failed'))
                        : $e->getMessage());

                $error = ['code' => $code, 'message' => $message];

                if ($e instanceof ValidationException) {
                    $error['details'] = $e->errors();
                }

                return response()->json(['success' => false, 'error' => $error], $status);
            }

            // An upload past PHP's post_max_size never reaches validation;
            // it becomes a friendly field error instead of an error page.
            if ($e instanceof PostTooLargeException) {
                return back()->withErrors(['images' => __('common.upload_too_large')]);
            }

            if (! $request->inertia()) {
                return null;
            }

            // Validation and auth failures must redirect with field errors.
            // Rendering them here would replace every form error with a 500 page.
            if ($e instanceof ValidationException || $e instanceof AuthenticationException) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
            } else {
                $status = 500;
            }

            if ($status === 419 || $status === 429) {
                return null;
            }

            return Inertia::render('errors/Error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
