<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RequestLogContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        $routeName = $request->route()?->getName();

        Log::withContext([
            'request_id' => $requestId,
            'route_name' => $routeName,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'organization_id' => $organization?->getKey(),
            'branch_id' => $branch?->getKey(),
        ]);

        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } catch (Throwable $exception) {
            // Avoid treating expected client errors (for example 403/404) as server failures.
            if (! ($exception instanceof HttpExceptionInterface) || $exception->getStatusCode() >= 500) {
                Log::error('Metrik POS request failed with an unhandled exception.', [
                    'request_id' => $requestId,
                    'exception_class' => $exception::class,
                ]);
            }

            throw $exception;
        } finally {
            Log::withoutContext();
        }
    }
}
