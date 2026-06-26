<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check() && auth()->user()->status === 'blocked') {
            // Revoke their tokens to force log out
            auth()->user()->tokens()->delete();
            
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended by system administrators.'
            ], 403);
        }

        return $next($request);
    }
}
