<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Captures team membership actions that modify pivot tables and do not emit Eloquent model events. */
class AuditMutationRequests
{
    private const TRACKED_ROUTES = [
        'team-members.store',
        'team-members.update',
        'team-members.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        if (! in_array($routeName, self::TRACKED_ROUTES, true)) {
            return $next($request);
        }

        try {
            $response = $next($request);
            $validationFailed = $request->hasSession() && $request->session()->has('errors');
            $failed = $response->getStatusCode() >= 400 || $validationFailed;
            $this->write($request, (string) $routeName, $failed ? 'action_failed' : 'action', $response->getStatusCode(), $validationFailed ? 'validation_rejected' : null);

            return $response;
        } catch (Throwable $exception) {
            $status = method_exists($exception, 'getStatusCode') ? (int) $exception->getStatusCode() : 500;
            $this->write($request, (string) $routeName, 'action_failed', $status, class_basename($exception));
            throw $exception;
        }
    }

    private function write(Request $request, string $routeName, string $event, int $status, ?string $failureReason): void
    {
        $organization = $request->attributes->get('currentOrganization');
        $actorId = $request->user()?->getAuthIdentifier();
        if ($organization === null || $actorId === null) {
            return;
        }

        $targetId = $request->route('teamMember');
        if (is_object($targetId) && method_exists($targetId, 'getKey')) {
            $targetId = $targetId->getKey();
        }

        AuditLog::query()->create([
            'organization_id' => (int) $organization->getKey(),
            'branch_id' => $request->attributes->get('currentBranch')?->getKey(),
            'actor_id' => (int) $actorId,
            'event' => $event,
            'auditable_type' => 'team_membership',
            'auditable_id' => is_numeric($targetId) ? (int) $targetId : null,
            'summary' => Str::limit($routeName.' · HTTP '.$status.($failureReason ? ' · '.$failureReason : ''), 255, ''),
            'changed_fields' => ['route_name', 'http_status'],
            'old_values' => [],
            'new_values' => ['route_name' => $routeName, 'http_status' => $status],
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'request_id' => $request->header('X-Request-ID'),
        ]);
    }
}
