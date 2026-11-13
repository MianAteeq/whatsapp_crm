<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_if($user->status === 'blocked', 403, 'Your account has been blocked.');

        $path = preg_replace('#^api/#', '', $request->path());
        if (str_starts_with($path, 'admin/')) {
            abort_unless($user->role === 'superadmin', 403, 'Super Admin access is required.');

            return $next($request);
        }

        if ($path === 'logout' || str_starts_with($path, 'user/') || $path === 'access/roles') {
            return $next($request);
        }

        $module = match (true) {
            str_starts_with($path, 'team/users') => 'users',
            str_starts_with($path, 'dashboard/') => 'dashboard',
            str_starts_with($path, 'contacts'), str_starts_with($path, 'tags'), str_starts_with($path, 'categories') => 'contacts',
            str_starts_with($path, 'conversations'), str_starts_with($path, 'messages'), $path === 'whatsapp/templates/send' => 'inbox',
            str_starts_with($path, 'whatsapp/templates'), $path === 'whatsapp/performance-insights' => 'templates',
            str_starts_with($path, 'whatsapp/') => 'whatsapp',
            str_starts_with($path, 'campaign') => 'campaigns',
            str_starts_with($path, 'email/templates') => 'email_templates',
            str_starts_with($path, 'email/settings'), str_starts_with($path, 'email/sending-domains') => 'email_settings',
            str_starts_with($path, 'email/') => 'email_campaigns',
            str_starts_with($path, 'scraper/') => 'scraper',
            str_starts_with($path, 'automation/') => 'automation',
            default => null,
        };

        // Template sync changes local data even though its endpoint uses GET.
        $action = $request->isMethodSafe() && $path !== 'whatsapp/templates/sync' ? 'view' : 'manage';
        abort_unless($module && $user->hasPermission("$module.$action"), 403, 'You do not have permission to perform this action.');

        return $next($request);
    }
}
