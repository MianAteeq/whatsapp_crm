<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappTemplateController extends Controller
{
    public function sync()
    {
        $setting = WhatsappSetting::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->first();

        // ======================================
        // FETCH TEMPLATES
        // ======================================

        $response = Http::withToken($setting->access_token)
            ->get("https://graph.facebook.com/v19.0/{$setting->business_account_id}/message_templates")
            ->json();

        // ======================================
        // STORE TEMPLATES
        // ======================================

        foreach ($response['data'] ?? [] as $template) {
            WhatsappTemplate::updateOrCreate(
                [
                    'tenant_id'   => auth()->user()->tenant_id,
                    'template_id' => $template['id'],
                ],
                [
                    'name'       => $template['name'],
                    'category'   => $template['category'] ?? null,
                    'language'   => $template['language'] ?? null,
                    'status'     => $template['status'] ?? null,
                    'components' => $template['components'] ?? [],
                ]
            );
        }

        return response()->json([
            'success'  => true,
            'response' => $response,
        ]);
    }

    public function index()
    {
        $templates = WhatsappTemplate::where(
            'tenant_id',
            auth()->user()->tenant_id
        )
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $templates,
        ]);
    }

    public function store(Request $request)
    {
        // ======================================
        // VALIDATION
        // ======================================

        $request->validate([
            'name'     => 'required|string',
            'category' => 'required|string',
            'language' => 'required|string',
            'body'     => 'required|string',
        ]);

        // ======================================
        // SETTINGS
        // ======================================

        $setting = WhatsappSetting::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->first();

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp settings not found',
            ], 404);
        }

        // ======================================
        // COMPONENTS
        // ======================================

        $components = [];
        $frontendBodyExample = null;

        if (isset($request->components) && is_array($request->components)) {
            foreach ($request->components as $component) {
                if (
                    isset($component['type']) &&
                    strtoupper($component['type']) === 'BODY'
                ) {
                    if (!empty($component['text'])) {
                        $request['body'] = $component['text'];
                    }

                    if (isset($component['example']['body_text'])) {
                        $frontendBodyExample = $component['example']['body_text'];
                    }
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | HEADER
        |--------------------------------------------------------------------------
        */

        if (isset($request->components) && is_array($request->components)) {
            foreach ($request->components as $component) {
                if (
                    isset($component['type']) &&
                    strtoupper($component['type']) === 'HEADER'
                ) {
                    $headerType = strtoupper($component['format']);
                    $header = [
                        'type'   => 'HEADER',
                        'format' => $headerType,
                    ];

                    /*
                    |--------------------------------------------------------------------------
                    | TEXT HEADER
                    |--------------------------------------------------------------------------
                    */

                    if ($headerType === 'TEXT') {
                        $header['text'] = $component['text'];

                        if (!empty($component['example']['header_text'])) {
                            $header['example'] = [
                                'header_text' => $component['example']['header_text'],
                            ];
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | MEDIA HEADER
                    |--------------------------------------------------------------------------
                    */ elseif (in_array($headerType, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                        if (!empty($component['example']['header_handle'][0])) {
                            $header['example'] = [
                                'header_handle' => [
                                    $component['example']['header_handle'][0],
                                ],
                            ];
                        }
                    }

                    $components[] = $header;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | BODY
        |--------------------------------------------------------------------------
        */

        $body = [
            'type' => 'BODY',
            'text' => $request->body,
        ];

        /*
        |--------------------------------------------------------------------------
        | BODY EXAMPLES
        |--------------------------------------------------------------------------
        |
        | Priority:
        | 1. components[].example.body_text
        | 2. samples.body_text
        |
        */

        if ($frontendBodyExample) {
            $body['example'] = [
                'body_text' => $frontendBodyExample,
            ];
        } elseif ($request->filled('samples.body_text')) {
            $bodyExamples = [];

            foreach ($request->samples['body_text'] as $sample) {
                if (is_array($sample)) {
                    $bodyExamples[] = $sample;
                } else {
                    $bodyExamples[] = [(string) $sample];
                }
            }

            $body['example'] = [
                'body_text' => $bodyExamples,
            ];
        }

        $components[] = $body;

        /*
        |--------------------------------------------------------------------------
        | FOOTER
        |--------------------------------------------------------------------------
        */

        if ($request->filled('footer')) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $request->footer,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | BUTTONS
        |--------------------------------------------------------------------------
        */

        if ($request->filled('buttons')) {
            $buttons = [];

            foreach ($request->buttons as $button) {
                if ($button['type'] === 'URL') {
                    $buttonData = [
                        'type' => 'URL',
                        'text' => $button['text'],
                        'url'  => $button['url'],
                    ];

                    if (!empty($button['example'])) {
                        $buttonData['example'] = [
                            $button['example'],
                        ];
                    }

                    $buttons[] = $buttonData;
                } elseif ($button['type'] === 'PHONE_NUMBER') {
                    $buttons[] = [
                        'type'         => 'PHONE_NUMBER',
                        'text'         => $button['text'],
                        'phone_number' => $button['phone_number'],
                    ];
                } elseif ($button['type'] === 'QUICK_REPLY') {
                    $buttons[] = [
                        'type' => 'QUICK_REPLY',
                        'text' => $button['text'],
                    ];
                }
            }

            if (!empty($buttons)) {
                $components[] = [
                    'type'    => 'BUTTONS',
                    'buttons' => $buttons,
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | FINAL PAYLOAD
        |--------------------------------------------------------------------------
        */

        $payload = [
            'name'       => strtolower($request->name),
            'category'   => strtoupper($request->category),
            'language'   => $request->language ?? 'en_US',
            'components' => $components,
        ];

        // ======================================
        // LOG PAYLOAD
        // ======================================

        Log::info('WhatsApp Template Payload', $payload);

        // ======================================
        // SEND TO META
        // ======================================

        $response = Http::withToken($setting->access_token)
            ->post(
                "https://graph.facebook.com/v19.0/{$setting->business_account_id}/message_templates",
                $payload
            );

        $responseData = $response->json();

        // ======================================
        // LOG RESPONSE
        // ======================================

        Log::info('WhatsApp Template Response', $responseData);

        // ======================================
        // HANDLE META ERRORS
        // ======================================

        if (isset($responseData['error'])) {
            return response()->json([
                'success' => false,
                'message' => $responseData['error']['message'] ?? 'Meta API Error',
                'error'   => $responseData['error'],
                'payload' => $payload,
            ], 422);
        }

        // ======================================
        // STORE TEMPLATE
        // ======================================

        $template = WhatsappTemplate::create([
            'tenant_id'   => auth()->user()->tenant_id,
            'template_id' => $responseData['id'] ?? null,
            'name'        => strtolower($request->name),
            'category'    => strtoupper($request->category),
            'language'    => $request->language,
            'status'      => 'PENDING',
            'components'  => $components,
        ]);

        // ======================================
        // SUCCESS RESPONSE
        // ======================================

        return response()->json([
            'success'  => true,
            'message'  => 'Template submitted successfully',
            'data'     => $template,
            'response' => $responseData,
        ]);
    }

    public function uploadMedia(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
        ]);

        $setting = WhatsappSetting::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->first();

        $file = $request->file('file');

        /*
        |--------------------------------------------------------------------------
        | STEP 1 — CREATE UPLOAD SESSION
        |--------------------------------------------------------------------------
        */

        $appId = '1333790982005297';

        $sessionResponse = Http::withToken($setting->access_token)
            ->post(
                "https://graph.facebook.com/v25.0/{$appId}/uploads",
                [
                    'file_name'   => $file->getClientOriginalName(),
                    'file_length' => $file->getSize(),
                    'file_type'   => $file->getMimeType(),
                ]
            );

        if (!$sessionResponse->successful()) {
            return response()->json([
                'success' => false,
                'step'    => 'create_upload_session',
                'error'   => $sessionResponse->json(),
            ], 500);
        }

        $uploadId = $sessionResponse->json()['id'];

        /*
        |--------------------------------------------------------------------------
        | STEP 2 — UPLOAD FILE BINARY
        |--------------------------------------------------------------------------
        */

        $binaryResponse = Http::withHeaders([
            'Authorization' => 'OAuth ' . $setting->access_token,
            'file_offset'   => '0',
        ])
            ->withBody(
                file_get_contents($file->getRealPath()),
                $file->getMimeType()
            )
            ->post("https://graph.facebook.com/v25.0/{$uploadId}");

        if (!$binaryResponse->successful()) {
            return response()->json([
                'success' => false,
                'step'    => 'upload_binary',
                'error'   => $binaryResponse->json(),
            ], 500);
        }

        /*
        |--------------------------------------------------------------------------
        | FINAL HANDLE
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success'       => true,
            'upload_id'     => $uploadId,
            'header_handle' => $binaryResponse->json()['h'],
            'response'      => $binaryResponse->json(),
        ]);
    }

    public function destroy($id)
    {
        // ======================================
        // FIND TEMPLATE
        // ======================================

        $template = WhatsappTemplate::find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        // ======================================
        // SETTINGS
        // ======================================

        $setting = WhatsappSetting::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->first();

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp settings not found',
            ], 404);
        }

        // ======================================
        // DELETE FROM META
        // ======================================

        $response = Http::withToken($setting->access_token)
            ->delete(
                "https://graph.facebook.com/v19.0/{$setting->business_account_id}/message_templates",
                [
                    'name' => $template->name,
                ]
            );

        $responseData = $response->json();

        Log::info('WhatsApp Template Delete Response', $responseData);

        // ======================================
        // HANDLE ERRORS
        // ======================================

        if (isset($responseData['error'])) {

            return response()->json([
                'success' => false,
                'message' => $responseData['error']['message'] ?? 'Meta API Error',
                'error'   => $responseData['error'],
            ], 422);
        }

        // ======================================
        // DELETE FROM DATABASE
        // ======================================

        $template->delete();

        // ======================================
        // SUCCESS RESPONSE
        // ======================================

        return response()->json([
            'success' => true,
            'message' => 'Template deleted successfully',
        ]);
    }

    public function update(Request $request, $id)
    {
        // ======================================
        // FIND TEMPLATE
        // ======================================

        $template = WhatsappTemplate::find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        // ======================================
        // VALIDATION
        // ======================================

        $request->validate([
            'name'     => 'required|string',
            'category' => 'required|string',
            'language' => 'required|string',
            'body'     => 'required|string',
        ]);

        // ======================================
        // SETTINGS
        // ======================================

        $setting = WhatsappSetting::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->first();

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp settings not found',
            ], 404);
        }

        // ======================================
        // DELETE OLD TEMPLATE FROM META
        // ======================================

        Http::withToken($setting->access_token)
            ->delete(
                "https://graph.facebook.com/v19.0/{$setting->business_account_id}/message_templates",
                [
                    'name' => $template->meta_template_name ?? $template->name,
                ]
            );

        // ======================================
        // GENERATE META VERSION NAME
        // ======================================

        $baseName = strtolower($request->name);

        preg_match('/_v(\d+)$/', $template->meta_template_name, $matches);

        $version = isset($matches[1])
            ? ((int) $matches[1]) + 1
            : 2;

        $metaTemplateName = $baseName . '_v' . $version;

        // ======================================
        // COMPONENTS
        // ======================================

        $components = [];
        $frontendBodyExample = null;

        if (isset($request->components) && is_array($request->components)) {

            foreach ($request->components as $component) {

                if (
                    isset($component['type']) &&
                    strtoupper($component['type']) === 'BODY'
                ) {

                    if (!empty($component['text'])) {
                        $request['body'] = $component['text'];
                    }

                    if (isset($component['example']['body_text'])) {
                        $frontendBodyExample = $component['example']['body_text'];
                    }
                }
            }
        }

        /*
    |--------------------------------------------------------------------------
    | HEADER
    |--------------------------------------------------------------------------
    */

        if (isset($request->components) && is_array($request->components)) {

            foreach ($request->components as $component) {

                if (
                    isset($component['type']) &&
                    strtoupper($component['type']) === 'HEADER'
                ) {

                    $headerType = strtoupper($component['format']);

                    $header = [
                        'type'   => 'HEADER',
                        'format' => $headerType,
                    ];

                    if ($headerType === 'TEXT') {

                        $header['text'] = $component['text'];

                        if (!empty($component['example']['header_text'])) {

                            $header['example'] = [
                                'header_text' => $component['example']['header_text'],
                            ];
                        }
                    } elseif (in_array($headerType, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {

                        if (!empty($component['example']['header_handle'][0])) {

                            $header['example'] = [
                                'header_handle' => [
                                    $component['example']['header_handle'][0],
                                ],
                            ];
                        }
                    }

                    $components[] = $header;
                }
            }
        }

        /*
    |--------------------------------------------------------------------------
    | BODY
    |--------------------------------------------------------------------------
    */

        $body = [
            'type' => 'BODY',
            'text' => $request->body,
        ];

        if ($frontendBodyExample) {

            $body['example'] = [
                'body_text' => $frontendBodyExample,
            ];
        } elseif ($request->filled('samples.body_text')) {

            $bodyExamples = [];

            foreach ($request->samples['body_text'] as $sample) {

                if (is_array($sample)) {
                    $bodyExamples[] = $sample;
                } else {
                    $bodyExamples[] = [(string) $sample];
                }
            }

            $body['example'] = [
                'body_text' => $bodyExamples,
            ];
        }

        $components[] = $body;

        /*
    |--------------------------------------------------------------------------
    | FOOTER
    |--------------------------------------------------------------------------
    */

        if ($request->filled('footer')) {

            $components[] = [
                'type' => 'FOOTER',
                'text' => $request->footer,
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | BUTTONS
    |--------------------------------------------------------------------------
    */

        if ($request->filled('buttons')) {

            $buttons = [];

            foreach ($request->buttons as $button) {

                if ($button['type'] === 'URL') {

                    $buttonData = [
                        'type' => 'URL',
                        'text' => $button['text'],
                        'url'  => $button['url'],
                    ];

                    if (!empty($button['example'])) {

                        $buttonData['example'] = [
                            $button['example'],
                        ];
                    }

                    $buttons[] = $buttonData;
                } elseif ($button['type'] === 'PHONE_NUMBER') {

                    $buttons[] = [
                        'type'         => 'PHONE_NUMBER',
                        'text'         => $button['text'],
                        'phone_number' => $button['phone_number'],
                    ];
                } elseif ($button['type'] === 'QUICK_REPLY') {

                    $buttons[] = [
                        'type' => 'QUICK_REPLY',
                        'text' => $button['text'],
                    ];
                }
            }

            if (!empty($buttons)) {

                $components[] = [
                    'type'    => 'BUTTONS',
                    'buttons' => $buttons,
                ];
            }
        }

        /*
    |--------------------------------------------------------------------------
    | FINAL PAYLOAD
    |--------------------------------------------------------------------------
    */

        $payload = [
            'name'       => $metaTemplateName,
            'category'   => strtoupper($request->category),
            'language'   => $request->language ?? 'en_US',
            'components' => $components,
        ];

        // ======================================
        // SEND TO META
        // ======================================

        $response = Http::withToken($setting->access_token)
            ->post(
                "https://graph.facebook.com/v19.0/{$setting->business_account_id}/message_templates",
                $payload
            );

        $responseData = $response->json();

        // ======================================
        // HANDLE ERRORS
        // ======================================

        if (isset($responseData['error'])) {

            return response()->json([
                'success' => false,
                'message' => $responseData['error']['message'] ?? 'Meta API Error',
                'error'   => $responseData['error'],
                'payload' => $payload,
            ], 422);
        }

        // ======================================
        // UPDATE DATABASE
        // ======================================

        $template->update([
            'template_id'       => $responseData['id'] ?? $template->template_id,
            'name'              => $baseName,
            'meta_template_name' => $metaTemplateName,
            'category'          => strtoupper($request->category),
            'language'          => $request->language,
            'status'            => 'PENDING',
            'components'        => $components,
        ]);

        // ======================================
        // SUCCESS RESPONSE
        // ======================================

        return response()->json([
            'success'  => true,
            'message'  => 'Template updated successfully',
            'data'     => $template,
            'response' => $responseData,
        ]);
    }

    public function performanceInsights()
    {
        $tenantId = auth()->user()->tenant_id;



        // =====================================================
        // TOTAL MESSAGE COUNTS
        // =====================================================

        $totalSent = WhatsappMessageLog::where(
            'tenant_id',
            $tenantId
        )->count();



        $totalDelivered = WhatsappMessageLog::where(
            'tenant_id',
            $tenantId
        )
            ->whereNotNull('delivered_at')
            ->count();



        $totalRead = WhatsappMessageLog::where(
            'tenant_id',
            $tenantId
        )
            ->whereNotNull('read_at')
            ->count();



        $totalReplies = WhatsappMessageLog::where(
            'tenant_id',
            $tenantId
        )
            ->whereNotNull('replied_at')
            ->count();



        // =====================================================
        // GLOBAL RATES
        // =====================================================

        $deliveryRate = $totalSent > 0

            ? round(
                ($totalDelivered / $totalSent) * 100,
                1
            )

            : 0;



        $readRate = $totalDelivered > 0

            ? round(
                ($totalRead / $totalDelivered) * 100,
                1
            )

            : 0;



        // =====================================================
        // TEMPLATE ANALYTICS
        // =====================================================

        $templates = WhatsappMessageLog::select(

            'template_name',

            DB::raw('COUNT(*) as total_sent'),

            DB::raw("
            SUM(
                CASE
                    WHEN delivered_at IS NOT NULL
                    THEN 1
                    ELSE 0
                END
            ) as total_delivered
        "),

            DB::raw("
            SUM(
                CASE
                    WHEN read_at IS NOT NULL
                    THEN 1
                    ELSE 0
                END
            ) as total_read
        "),

            DB::raw("
            SUM(
                CASE
                    WHEN replied_at IS NOT NULL
                    THEN 1
                    ELSE 0
                END
            ) as total_replied
        ")

        )
            ->where('tenant_id', $tenantId)
            ->groupBy('template_name')
            ->orderByDesc('total_sent')
            ->get();



        // =====================================================
        // FORMAT TOP TEMPLATES
        // =====================================================

        $topTemplates = $templates->map(function ($item) {

            $sent = (int) $item->total_sent;

            $delivered = (int) $item->total_delivered;

            $read = (int) $item->total_read;

            $replied = (int) $item->total_replied;



            // ======================================
            // FIX INVALID DATA
            // ======================================

            if ($read > $delivered) {

                $delivered = $read;
            }



            // ======================================
            // DELIVERY RATE
            // ======================================

            $templateDeliveryRate = $sent > 0

                ? round(
                    ($delivered / $sent) * 100,
                    1
                )

                : 0;



            // ======================================
            // READ RATE
            // ======================================

            $templateReadRate = $delivered > 0

                ? round(
                    ($read / $delivered) * 100,
                    1
                )

                : 0;



            return [

                'template_name' => $item->template_name,

                'sent' => $sent,

                'delivered' => $delivered,

                'reads' => $read,

                'replies' => $replied,

                'delivery_rate' => $templateDeliveryRate,

                'read_rate' => $templateReadRate,

                'status' => $templateReadRate >= 70

                    ? 'excellent'

                    : (

                        $templateReadRate >= 40

                        ? 'good'

                        : 'poor'

                    ),

            ];
        });



        // =====================================================
        // CATEGORY HEALTH
        // =====================================================

        $categoryPerformance = WhatsappTemplate::select(

            'category',

            DB::raw('COUNT(*) as total_templates'),

            DB::raw("
            SUM(
                CASE
                    WHEN status = 'APPROVED'
                    THEN 1
                    ELSE 0
                END
            ) as approved_templates
        ")

        )
            ->where('tenant_id', $tenantId)
            ->groupBy('category')
            ->get()
            ->map(function ($item) {

                $score = $item->total_templates > 0

                    ? round(
                        (
                            $item->approved_templates /
                            $item->total_templates
                        ) * 100
                    )

                    : 0;



                return [

                    'category' => strtoupper(
                        $item->category
                    ),

                    'score' => $score,

                    'total' => (int) $item->total_templates,

                    'approved' => (int) $item->approved_templates,

                ];
            });



        // =====================================================
        // HEALTH ALERT
        // =====================================================

        $lowestCategory = $categoryPerformance
            ->sortBy('score')
            ->first();



        $healthAlert = null;



        if (

            $lowestCategory

            &&

            $lowestCategory['score'] < 80

        ) {

            $healthAlert =

                strtolower(
                    $lowestCategory['category']
                )

                .

                ' template quality dropped below optimal threshold.';
        }



        // =====================================================
        // WEEKLY PERFORMANCE
        // =====================================================

        $weeklyPerformance = WhatsappMessageLog::select(

            DB::raw("DATE(created_at) as date"),

            DB::raw("COUNT(*) as sent"),

            DB::raw("
            SUM(
                CASE
                    WHEN delivered_at IS NOT NULL
                    THEN 1
                    ELSE 0
                END
            ) as delivered
        ")

        )
            ->where('tenant_id', $tenantId)
            ->whereDate(
                'created_at',
                '>=',
                now()->subDays(7)
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {

                return [

                    'date' => $item->date,

                    'day' => date(
                        'D',
                        strtotime($item->date)
                    ),

                    'sent' => (int) $item->sent,

                    'delivered' => (int) $item->delivered,

                ];
            });



        // =====================================================
        // RECENT ACTIVITY
        // =====================================================

        $recentActivity = WhatsappMessageLog::where(
            'tenant_id',
            $tenantId
        )
            ->latest()
            ->take(10)
            ->get([

                'template_name',

                'recipient',

                'status',

                'sent_at',

                'delivered_at',

                'read_at',

                'created_at',

            ]);



        // =====================================================
        // FINAL RESPONSE
        // =====================================================

        return response()->json([

            'success' => true,



            // =================================================
            // HERO INSIGHT
            // =================================================

            'hero_insight' => [

                'delivery_rate' => $deliveryRate,

                'quality_score' => $deliveryRate >= 90

                    ? 'Exceptional'

                    : (

                        $deliveryRate >= 70

                        ? 'Good'

                        : 'Needs Improvement'

                    ),

            ],



            // =================================================
            // KPI STATS
            // =================================================

            'stats' => [

                'total_sent' => $totalSent,

                'total_delivered' => $totalDelivered,

                'total_read' => $totalRead,

                'delivery_rate' => $deliveryRate,

                'read_rate' => $readRate,

                'replies' => $totalReplies,

            ],



            // =================================================
            // CATEGORY HEALTH
            // =================================================

            'category_health' => [

                'categories' => $categoryPerformance,

                'alert' => $healthAlert,

            ],



            // =================================================
            // CHARTS
            // =================================================

            'charts' => [

                'weekly_performance' => $weeklyPerformance,

            ],



            // =================================================
            // TOP TEMPLATES
            // =================================================

            'top_templates' => $topTemplates,



            // =================================================
            // RECENT ACTIVITY
            // =================================================

            'recent_activity' => $recentActivity,

        ]);
    }
}
