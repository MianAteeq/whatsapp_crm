<?php

namespace App\Http\Controllers\Api\Builder;

use App\Http\Controllers\Controller;
use App\Services\Builder\FormBuilderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

class FormBuilderController extends Controller
{
    public function __construct(
        protected FormBuilderService $formService
    ) {}

    /**
     * Get list of forms and surveys
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $filters = $request->only(['search', 'form_type', 'status', 'sort_by', 'sort_order', 'per_page']);

        $forms = $this->formService->getForms($tenantId, $filters);

        return response()->json([
            'success' => true,
            'data' => $forms->items(),
            'meta' => [
                'current_page' => $forms->currentPage(),
                'last_page' => $forms->lastPage(),
                'total' => $forms->total(),
                'per_page' => $forms->perPage(),
            ],
        ]);
    }

    /**
     * Create a new form or survey
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120'],
            'form_type' => ['nullable', 'string', 'in:lead_form,survey,contact_form,registration'],
            'page_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:draft,published,archived'],
            'schema' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'crm_mapping' => ['nullable', 'array'],
        ]);

        try {
            $form = $this->formService->createForm($tenantId, $userId, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Form created successfully.',
                'data' => $form,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get single form details for editing
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $form = \App\Models\BuilderForm::forTenant($tenantId)
            ->with(['page:id,title,slug'])
            ->withCount(['submissions'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $form,
        ]);
    }

    /**
     * Update form or survey
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120'],
            'form_type' => ['nullable', 'string', 'in:lead_form,survey,contact_form,registration'],
            'page_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:draft,published,archived'],
            'schema' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'crm_mapping' => ['nullable', 'array'],
        ]);

        try {
            $form = $this->formService->updateForm($tenantId, $id, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Form updated successfully.',
                'data' => $form,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Toggle publish state
     */
    public function togglePublish(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        try {
            $form = $this->formService->togglePublish($tenantId, $id);

            return response()->json([
                'success' => true,
                'message' => $form->status === \App\Models\BuilderForm::STATUS_PUBLISHED
                    ? 'Form published successfully.'
                    : 'Form moved to drafts.',
                'data' => $form,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Duplicate a form
     */
    public function clone(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;

        try {
            $form = $this->formService->cloneForm($tenantId, $id, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Form duplicated successfully.',
                'data' => $form,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete form
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $this->formService->deleteForm($tenantId, $id);

        return response()->json([
            'success' => true,
            'message' => 'Form deleted successfully.',
        ]);
    }
}

