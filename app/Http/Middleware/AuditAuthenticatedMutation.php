<?php

namespace App\Http\Middleware;

use App\Services\PhaseOne\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditAuthenticatedMutation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();
        if ($user && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $response->getStatusCode() < 400) {
            $segments = $request->segments();
            $module = $segments[1] ?? 'system';
            $route = $request->route();
            $action = $route?->getActionMethod() ?: strtolower($request->method());
            app(AuditService::class)->record((int) $user->tenant_id, $module, "api.{$action}", null, [], [], "{$request->method()} /{$request->path()}", ['status_code' => $response->getStatusCode()]);
        }
        return $response;
    }
}
