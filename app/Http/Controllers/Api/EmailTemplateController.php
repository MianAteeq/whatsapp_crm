<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    /**
     * List email templates
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $query = EmailTemplate::where('tenant_id', $tenantId)->latest();

        if ($request->filled('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'LIKE', "%{$s}%")
                  ->orWhere('subject', 'LIKE', "%{$s}%");
            });
        }

        $templates = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $templates->items(),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
                'total' => $templates->total(),
            ],
        ]);
    }

    /**
     * Store new template
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'subject' => 'required|string|max:255',
            'category' => 'required|string|max:50',
            'html_body' => 'required|string',
            'text_body' => 'nullable|string',
            ...$this->editorRules(),
        ]);

        $template = EmailTemplate::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'category' => $validated['category'],
            'html_body' => $validated['html_body'],
            'text_body' => $validated['text_body'] ?? strip_tags($validated['html_body']),
            'editor_data' => $validated['editor_data'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Email template created successfully.',
            'data' => $template,
        ], 201);
    }

    /**
     * Show template
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $template = EmailTemplate::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $template,
        ]);
    }

    /**
     * Update template
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $template = EmailTemplate::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:191',
            'subject' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string|max:50',
            'html_body' => 'sometimes|required|string',
            'text_body' => 'nullable|string',
            ...$this->editorRules(),
        ]);

        $template->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Email template updated successfully.',
            'data' => $template,
        ]);
    }

    private function editorRules(): array
    {
        $rules = [
            'editor_data' => 'nullable|array:frame_id,body_html,brand',
            'editor_data.frame_id' => 'required_with:editor_data|string|max:80',
            'editor_data.body_html' => 'required_with:editor_data|string',
            'editor_data.brand' => 'required_with:editor_data|array:company_name,company_full_name,logo_url,website_url,contact_label,phone,email,department,address,navigation,socials',
            'editor_data.brand.logo_url' => 'nullable|url:http,https|max:2048',
            'editor_data.brand.website_url' => 'nullable|url:http,https|max:2048',
            'editor_data.brand.email' => 'nullable|email|max:191',
            'editor_data.brand.navigation' => 'sometimes|array|max:4',
            'editor_data.brand.navigation.*.label' => 'nullable|string|max:40',
            'editor_data.brand.navigation.*.url' => 'nullable|url:http,https|max:2048',
            'editor_data.brand.socials' => 'sometimes|array:linkedin,facebook,instagram,x',
            'editor_data.brand.socials.*' => 'nullable|url:http,https|max:2048',
        ];
        foreach (['company_name', 'company_full_name', 'contact_label', 'phone', 'department', 'address'] as $field) {
            $rules['editor_data.brand.'.$field] = 'nullable|string|max:1000';
        }

        return $rules;
    }

    /**
     * Duplicate template
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $template = EmailTemplate::where('tenant_id', $tenantId)->findOrFail($id);

        $copy = $template->replicate();
        $copy->name = $template->name . ' (Copy)';
        $copy->save();

        return response()->json([
            'success' => true,
            'message' => 'Email template duplicated.',
            'data' => $copy,
        ], 201);
    }

    /**
     * Delete template
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $template = EmailTemplate::where('tenant_id', $tenantId)->findOrFail($id);
        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email template deleted successfully.',
        ]);
    }
}
