<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Thrown when a sale could not get the numbering lock (deadlock 1213 or lock wait timeout 1205) even after one retry.
 * The sale was rolled back completely; the cashier can simply press the button again.
 */
final class SaleBusyException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : __('The system is busy, please try again'), $code, $previous);
    }

    /**
     * JSON clients (the API) get a 503; web requests fall through to the default exception rendering.
     */
    public function render($request): ?Response
    {
        if ($request->expectsJson() || str_contains((string) $request->header('Content-Type', ''), 'application/json')) {
            return response()->json([
                'message' => $this->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return null;
    }
}
