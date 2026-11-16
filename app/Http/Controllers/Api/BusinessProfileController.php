<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfileAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class BusinessProfileController extends Controller
{
    private const FIELDS = [
        'name', 'legal_name', 'address_line_1', 'address_line_2', 'city',
        'region', 'postal_code', 'country_code', 'currency_code', 'timezone',
        'customer_timezone_policy',
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->profileData($request->user()->tenant),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(in_array(strtolower((string) $request->user()->role), ['admin', 'owner', 'superadmin'], true), 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'legal_name' => 'required|string|max:255',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:2048',
            'remove_logo' => 'nullable|boolean',
            'address_line_1' => 'required|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'required|string|max:120',
            'region' => 'nullable|string|max:120',
            'postal_code' => 'nullable|string|max:30',
            'country_code' => 'required|string|size:2',
            'currency_code' => 'required|string|size:3',
            'timezone' => 'required|timezone',
            'customer_timezone_policy' => 'required|in:business_only,customer_when_available',
        ]);

        $tenant = $request->user()->tenant;
        $before = Arr::only($tenant->getAttributes(), array_merge(self::FIELDS, ['logo_path']));
        $oldCurrency = $tenant->currency_code;
        $payload = Arr::only($validated, self::FIELDS);
        $payload['country_code'] = strtoupper($payload['country_code']);
        $payload['currency_code'] = strtoupper($payload['currency_code']);

        if ($request->boolean('remove_logo') && $tenant->logo_path) {
            Storage::disk('public')->delete($tenant->logo_path);
            $payload['logo_path'] = null;
        }

        if ($request->hasFile('logo')) {
            if ($tenant->logo_path) {
                Storage::disk('public')->delete($tenant->logo_path);
            }
            $payload['logo_path'] = $request->file('logo')->store("tenant-logos/{$tenant->id}", 'public');
        }

        $tenant->update($payload);
        $tenant->refresh();

        $after = Arr::only($tenant->getAttributes(), array_merge(self::FIELDS, ['logo_path']));
        $changes = [];
        foreach ($after as $field => $value) {
            $oldValue = $field === 'logo_path' ? ($before['logo_path'] ?? null) : ($before[$field] ?? null);
            if ((string) $oldValue !== (string) $value) {
                $changes[$field] = ['from' => $oldValue, 'to' => $value];
            }
        }

        if ($changes !== []) {
            BusinessProfileAuditLog::create([
                'tenant_id' => $tenant->id,
                'user_id' => $request->user()->id,
                'changes' => $changes,
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Business profile saved successfully.',
            'data' => $this->profileData($tenant),
            'currency_change_notice' => $oldCurrency && $oldCurrency !== $tenant->currency_code
                ? "Currency changed from {$oldCurrency} to {$tenant->currency_code}. Existing historical financial values were not converted or rewritten."
                : null,
        ]);
    }

    private function profileData($tenant): array
    {
        $profile = Arr::only($tenant->toArray(), self::FIELDS);
        $profile['name'] = $profile['name'] ?? '';
        $profile['legal_name'] = $profile['legal_name'] ?: $profile['name'];
        foreach (['address_line_1', 'address_line_2', 'city', 'region', 'postal_code', 'country_code'] as $field) {
            $profile[$field] = $profile[$field] ?? '';
        }
        $profile['currency_code'] = $profile['currency_code'] ?: 'USD';
        $profile['timezone'] = $profile['timezone'] ?: 'UTC';
        $profile['customer_timezone_policy'] = $profile['customer_timezone_policy'] ?: 'customer_when_available';

        return [
            ...$profile,
            'logo_url' => $tenant->logo_url,
            'timezone_precedence' => $tenant->customer_timezone_policy === 'customer_when_available'
                ? 'Use a valid customer timezone when available; otherwise use the workspace timezone.'
                : 'Always use the workspace timezone.',
            'currency_policy' => 'Currency changes apply to new values only. Historical financial values are never converted automatically.',
        ];
    }
}
