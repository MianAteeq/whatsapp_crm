<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6|confirmed',
            'plan' => 'nullable|string|exists:plans,key'
        ]);

        // 1. Create User (temporary without tenant)
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // 2. Create Tenant
        $tenant = Tenant::create([
            'name' => $request->name . "'s Workspace",
            'owner_id' => $user->id,
            'plan' => $request->plan ?? 'free'
        ]);

        // 3. Assign tenant to user
        $user->update([
            'tenant_id' => $tenant->id
        ]);

        // 4. Create token
        $token = $user->createToken('auth_token')->plainTextToken;

        $user->load('tenant');

        return response()->json([
            'message' => 'Registered successfully',
            'token' => $token,
            'user' => $user,
            'tenant' => $tenant
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials'],
            ]);
        }

        if ($user->status === 'blocked') {
            throw ValidationException::withMessages([
                'email' => ['Your account has been suspended by system administrators.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $user->load('tenant');

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $user
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    public function updateProfile(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'business_name' => 'nullable|string|max:255',
            'avatar' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        
        $user->update([
            'name' => $request->name,
            'avatar' => $request->avatar ?? $user->avatar,
        ]);

        if ($request->filled('business_name') && $user->tenant) {
            $user->tenant->update([
                'name' => $request->business_name
            ]);
        }

        $user->load('tenant');

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $user
        ]);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:6|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully'
        ]);
    }

    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $user = $request->user();

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $fileName = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            
            // Ensure directory exists
            $dir = public_path('uploads/avatars');
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $file->move($dir, $fileName);
            
            $avatarUrl = asset('uploads/avatars/' . $fileName);
            $user->update(['avatar' => $avatarUrl]);

            return response()->json([
                'success' => true,
                'message' => 'Avatar updated successfully',
                'avatar' => $avatarUrl,
                'user' => $user
            ]);
        }

        return response()->json(['success' => false, 'message' => 'No file uploaded'], 400);
    }
}