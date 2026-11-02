<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    protected function shouldReturnJson($request, Throwable $e): bool
    {
        // A Livewire update is a POST with a JSON body, but Livewire shows what comes back in its own error overlay,
        // which prints it as a page. A JSON answer there is black text on a dark frame: an overlay that looks empty.
        if ($request->hasHeader('X-Livewire')) {
            return false;
        }

        if ($request->expectsJson()) {
            return true;
        }

        $contentType = $request->header('Content-Type', '');

        return str_contains($contentType, 'application/json');
    }

    /**
     * A Livewire update whose session is gone gets a plain 401. The default answer is a redirect to the login page,
     * which the browser follows, so Livewire would receive a whole HTML page where it expects JSON and stall.
     * The page script (resources/views/livewire-resilience.blade.php) turns the 401 into a trip to the login page.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->hasHeader('X-Livewire')) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return parent::unauthenticated($request, $exception);
    }


    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
