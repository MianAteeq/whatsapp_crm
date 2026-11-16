<?php
namespace App\Http\Middleware;
use App\Services\PhaseOne\AccessService; use Closure; use Illuminate\Http\Request;
class RequireTenantPermission { public function handle(Request $request,Closure $next,string $permission){ if(!$request->user() || !app(AccessService::class)->can($request->user(),$permission)) return response()->json(['message'=>'You do not have permission to perform this action.','required_permission'=>$permission],403); return $next($request); } }
