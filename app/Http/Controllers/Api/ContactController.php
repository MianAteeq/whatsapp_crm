<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\ContactsImport;
use App\Models\Business;
use App\Models\Category;
use App\Models\Contact;
use App\Models\EmailCampaignRecipient;
use App\Models\ScrapeResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ContactController extends Controller
{
    protected function currentUser()
    {
        return Auth::user() ?? abort(response()->json(['message' => 'Unauthorized'], 401));
    }

    /**
     * Get paginated contacts with rich relations & category/email filtering
     */
    public function index(Request $request)
    {
        $tenantId = $this->currentUser()->tenant_id;

        $query = Contact::with(['tags', 'categories', 'business', 'emailRecipients.campaign:id,name'])
            ->where('tenant_id', $tenantId);

        // 1. Text Search
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('first_name', 'like', '%' . $search . '%')
                  ->orWhere('last_name', 'like', '%' . $search . '%')
                  ->orWhere('phone', 'like', '%' . $search . '%')
                  ->orWhere('email', 'like', '%' . $search . '%')
                  ->orWhere('company', 'like', '%' . $search . '%')
                  ->orWhere('job_title', 'like', '%' . $search . '%')
                  ->orWhere('address', 'like', '%' . $search . '%')
                  ->orWhereHas('business', function ($bq) use ($search) {
                      $bq->where('business_name', 'like', '%' . $search . '%')
                        ->orWhere('city', 'like', '%' . $search . '%');
                  })
                  ->orWhereHas('categories', function ($cq) use ($search) {
                      $cq->where('name', 'like', '%' . $search . '%');
                  });
            });
        }

        // 2. Lifecycle Status Filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // 3. Category Filter (Requirement 1 & 13)
        if ($request->filled('category_id')) {
            $catId = (int) $request->category_id;
            $query->whereHas('categories', function ($cq) use ($catId) {
                $cq->where('categories.id', $catId);
            });
        }

        // 4. Tag Filter
        if ($request->filled('tag_id') || $request->filled('tag')) {
            $tagId = $request->input('tag_id') ?: $request->input('tag');
            $query->whereHas('tags', function ($tq) use ($tagId) {
                if (is_numeric($tagId)) {
                    $tq->where('tags.id', (int) $tagId);
                } else {
                    $tq->where('tags.name', $tagId);
                }
            });
        }

        // 5. Campaign & Email Status Filters (Requirement 9, 13, 14, 15)
        $campaignId = $request->filled('campaign_id') ? (int) $request->campaign_id : null;
        $emailStatus = $request->filled('email_status') ? strtolower(trim($request->email_status)) : null;

        if ($emailStatus && $emailStatus !== 'all') {
            if ($emailStatus === 'never_sent' || $emailStatus === 'not_sent') {
                if ($campaignId) {
                    // Contact has not received Campaign X (Requirement 15)
                    $query->whereDoesntHave('emailRecipients', function ($q) use ($campaignId) {
                        $q->where('campaign_id', $campaignId)
                          ->where(function ($sub) {
                              $sub->whereIn('status', ['sent', 'delivered', 'opened', 'clicked'])
                                  ->orWhereNotNull('sent_at');
                          });
                    });
                } else {
                    // Global Never Sent: contact has no successful send attempts (Requirement 14)
                    $query->whereDoesntHave('emailRecipients', function ($q) {
                        $q->whereIn('status', ['sent', 'delivered', 'opened', 'clicked', 'bounced', 'failed', 'complained', 'unsubscribed'])
                          ->orWhereNotNull('sent_at');
                    });
                }
            } elseif ($emailStatus === 'sent') {
                $query->whereHas('emailRecipients', function ($q) use ($campaignId) {
                    $q->when($campaignId, fn($cq) => $cq->where('campaign_id', $campaignId))
                      ->where('status', 'sent');
                });
            } elseif ($emailStatus === 'delivered') {
                $query->whereHas('emailRecipients', function ($q) use ($campaignId) {
                    $q->when($campaignId, fn($cq) => $cq->where('campaign_id', $campaignId))
                      ->where(function ($sub) {
                          $sub->where('status', 'delivered')->orWhereNotNull('delivered_at');
                      });
                });
            } elseif ($emailStatus === 'bounced') {
                $query->whereHas('emailRecipients', function ($q) use ($campaignId) {
                    $q->when($campaignId, fn($cq) => $cq->where('campaign_id', $campaignId))
                      ->where(function ($sub) {
                          $sub->where('status', 'bounced')->orWhereNotNull('bounced_at');
                      });
                });
            } elseif ($emailStatus === 'failed') {
                $query->whereHas('emailRecipients', function ($q) use ($campaignId) {
                    $q->when($campaignId, fn($cq) => $cq->where('campaign_id', $campaignId))
                      ->where(function ($sub) {
                          $sub->where('status', 'failed')->orWhereNotNull('failed_at');
                      });
                });
            } elseif ($emailStatus === 'unsubscribed') {
                $query->where(function ($q) use ($campaignId) {
                    $q->where('status', 'unsubscribed')
                      ->orWhereHas('emailRecipients', fn($rq) => $rq->when($campaignId, fn($cq) => $cq->where('campaign_id', $campaignId))->where('status', 'unsubscribed'))
                      ->orWhereHas('emailSuppressions');
                });
            }
        } elseif ($campaignId) {
            $query->whereHas('emailRecipients', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId);
            });
        }

        $paginated = $query->latest()->paginate($request->input('per_page', 10));

        // Format items with computed email status columns for UI table (Requirement 21)
        $items = collect($paginated->items())->map(function (Contact $c) {
            $latestRecipient = $c->emailRecipients->sortByDesc('created_at')->first();
            $globalEmailStatus = 'Never Sent';

            if ($latestRecipient) {
                $st = strtolower($latestRecipient->status);
                if (in_array($st, ['delivered', 'opened', 'clicked'])) {
                    $globalEmailStatus = 'Delivered';
                } elseif ($st === 'sent') {
                    $globalEmailStatus = 'Sent';
                } elseif ($st === 'bounced') {
                    $globalEmailStatus = 'Bounced';
                } elseif ($st === 'failed') {
                    $globalEmailStatus = 'Failed';
                } elseif ($st === 'unsubscribed') {
                    $globalEmailStatus = 'Unsubscribed';
                } elseif ($latestRecipient->sent_at) {
                    $globalEmailStatus = 'Sent';
                }
            }

            if ($c->status === 'unsubscribed') {
                $globalEmailStatus = 'Unsubscribed';
            }

            return array_merge($c->toArray(), [
                'business_name' => $c->business?->business_name ?? $c->company,
                'category_name' => $c->categories->first()?->name ?? 'General',
                'category' => $c->categories->first()?->name ?? 'General',
                'email_status' => $globalEmailStatus,
                'last_email' => $latestRecipient ? ($latestRecipient->delivered_at ?: ($latestRecipient->sent_at ?: $latestRecipient->created_at))?->format('M d, Y') : null,
                'last_campaign' => $latestRecipient?->campaign?->name ?? null,
                'delivery_status' => $latestRecipient?->status ?? 'never_sent',
                'lead_score' => $c->lead_quality_score,
            ]);
        });

        return response()->json([
            'current_page' => $paginated->currentPage(),
            'data' => $items,
            'first_page_url' => $paginated->url(1),
            'from' => $paginated->firstItem(),
            'last_page' => $paginated->lastPage(),
            'last_page_url' => $paginated->url($paginated->lastPage()),
            'next_page_url' => $paginated->nextPageUrl(),
            'path' => $paginated->path(),
            'per_page' => $paginated->perPage(),
            'prev_page_url' => $paginated->previousPageUrl(),
            'to' => $paginated->lastItem(),
            'total' => $paginated->total(),
        ]);
    }

    /**
     * Store contact with business & category relations
     */
    public function store(Request $request)
    {
        $tenant = $this->currentUser()->tenant;
        if ($tenant) {
            $limits = $tenant->getLimits();
            $maxContacts = $limits['contacts'] ?? 'Unlimited';

            if (strtolower($maxContacts) !== 'unlimited') {
                $maxContacts = (int) str_replace([',', ' '], '', $maxContacts);
                $currentContactsCount = Contact::where('tenant_id', $tenant->id)->count();
                if ($currentContactsCount >= $maxContacts) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Your current plan (' . ($tenant->plan ?? 'free') . ') supports up to ' . number_format($maxContacts) . ' contacts. Please upgrade your subscription tier to add more contacts.',
                    ], 400);
                }
            }
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'nullable|email',
            'business_id' => 'nullable|integer',
            'company' => 'nullable|string|max:255',
            'category_ids' => 'nullable|array',
            'category_id' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive,archived,unsubscribed',
            'tags' => 'nullable|array',
            'contact_type' => 'nullable|string|max:50',
        ]);

        $phoneNormalizer = app(\App\Services\Scraper\PhoneNormalizationService::class);
        $phoneNorm = $phoneNormalizer->normalizeE164($request->phone);

        // Separate Business Record Handling (Requirement 3)
        $businessId = $request->business_id;
        if (!$businessId && !empty($request->company)) {
            $biz = Business::firstOrCreate(
                ['tenant_id' => $tenant->id, 'business_name' => trim($request->company)],
                [
                    'normalized_business_name' => strtolower(trim($request->company)),
                    'phone' => $request->phone,
                    'normalized_phone' => $phoneNorm,
                    'website' => $request->website,
                    'address' => $request->address,
                    'source' => 'CRM Manual',
                    'first_discovered_at' => now(),
                    'last_discovered_at' => now(),
                ]
            );
            $businessId = $biz->id;
        }

        $nameParts = preg_split('/\s+/', trim($request->name), 2);
        $firstName = $request->input('first_name') ?: ($nameParts[0] ?? '');
        $lastName = $request->input('last_name') ?: ($nameParts[1] ?? '');

        $contact = Contact::create([
            'tenant_id' => $this->currentUser()->tenant_id,
            'business_id' => $businessId,
            'name' => $request->name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $request->phone,
            'phone_normalized' => $phoneNorm,
            'phone_original' => $request->phone,
            'email' => $request->email,
            'email_normalized' => $request->email ? strtolower(trim($request->email)) : null,
            'company' => $request->company,
            'job_title' => $request->job_title,
            'contact_type' => $request->contact_type ?: 'General',
            'address' => $request->address,
            'birthday' => $request->birthday,
            'website' => $request->website,
            'notes' => $request->notes,
            'status' => strtolower($request->status) ?: 'active',
            'source' => 'CRM',
        ]);

        // Attach Tags
        if ($request->tags) {
            $contact->tags()->sync($request->tags);
        }

        // Attach Categories (Requirement 5)
        $categoryIds = $request->category_ids ?: ($request->category_id ? [(int) $request->category_id] : []);
        if (!empty($categoryIds)) {
            $contact->categories()->sync($categoryIds);
            if ($businessId) {
                $biz = Business::find($businessId);
                $biz?->categories()->syncWithoutDetaching($categoryIds);
            }
        }

        return response()->json([
            'message' => 'Contact created successfully',
            'data' => $contact->load(['tags', 'categories', 'business']),
        ], 201);
    }

    /**
     * Show single contact with full details, categories, business, and audit
     */
    public function show($id)
    {
        $contact = Contact::with(['tags', 'categories', 'business', 'scrapeResults.search', 'scrapeResults.category'])
            ->where('tenant_id', $this->currentUser()->tenant_id)
            ->findOrFail($id);

        return response()->json($contact);
    }

    /**
     * Update contact
     */
    public function update(Request $request, $id)
    {
        $contact = Contact::where('tenant_id', $this->currentUser()->tenant_id)
            ->findOrFail($id);

        $phoneNormalizer = app(\App\Services\Scraper\PhoneNormalizationService::class);
        $phoneNorm = $request->phone ? $phoneNormalizer->normalizeE164($request->phone) : $contact->phone_normalized;

        $businessId = $request->input('business_id', $contact->business_id);
        if (!$businessId && !empty($request->company)) {
            $biz = Business::firstOrCreate(
                ['tenant_id' => $contact->tenant_id, 'business_name' => trim($request->company)],
                [
                    'normalized_business_name' => strtolower(trim($request->company)),
                    'phone' => $request->phone ?: $contact->phone,
                    'normalized_phone' => $phoneNorm,
                    'website' => $request->website ?: $contact->website,
                    'address' => $request->address ?: $contact->address,
                    'source' => 'CRM',
                ]
            );
            $businessId = $biz->id;
        }

        $contact->update([
            'business_id' => $businessId,
            'name' => $request->input('name', $contact->name),
            'first_name' => $request->input('first_name', $contact->first_name),
            'last_name' => $request->input('last_name', $contact->last_name),
            'phone' => $request->input('phone', $contact->phone),
            'phone_normalized' => $phoneNorm,
            'email' => $request->input('email', $contact->email),
            'email_normalized' => $request->email ? strtolower(trim($request->email)) : $contact->email_normalized,
            'company' => $request->input('company', $contact->company),
            'job_title' => $request->input('job_title', $contact->job_title),
            'contact_type' => $request->input('contact_type', $contact->contact_type),
            'address' => $request->input('address', $contact->address),
            'birthday' => $request->input('birthday', $contact->birthday),
            'website' => $request->input('website', $contact->website),
            'notes' => $request->input('notes', $contact->notes),
            'status' => $request->filled('status') ? strtolower($request->status) : $contact->status,
        ]);

        if ($request->has('tags')) {
            $contact->tags()->sync($request->tags);
        }

        if ($request->has('category_ids')) {
            $contact->categories()->sync($request->category_ids);
        } elseif ($request->filled('category_id')) {
            $contact->categories()->sync([(int) $request->category_id]);
        }

        return response()->json([
            'message' => 'Contact updated successfully',
            'data' => $contact->fresh(['tags', 'categories', 'business']),
        ]);
    }

    /**
     * Delete contact
     */
    public function destroy($id)
    {
        $contact = Contact::where('tenant_id', $this->currentUser()->tenant_id)
            ->findOrFail($id);

        $contact->delete();

        return response()->json([
            'message' => 'Contact deleted successfully',
        ]);
    }

    /**
     * Batch delete contacts
     */
    public function batchDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        Contact::where('tenant_id', $this->currentUser()->tenant_id)
            ->whereIn('id', $request->ids)
            ->get()
            ->each->delete();

        return response()->json([
            'message' => count($request->ids) . ' contacts deleted successfully',
        ]);
    }

    /**
     * Advanced search for contacts with multi-faceted filtering
     */
    public function advanceSearch(Request $request)
    {
        $tenantId = $this->currentUser()->tenant_id;

        $contacts = Contact::with(['tags', 'categories', 'business'])
            ->where('tenant_id', $tenantId);

        if ($request->filled('search')) {
            $search = $request->search;
            $contacts->where(function ($query) use ($search) {
                $query->where('name', 'like', '%' . $search . '%')
                      ->orWhere('phone', 'like', '%' . $search . '%')
                      ->orWhere('email', 'like', '%' . $search . '%')
                      ->orWhere('company', 'like', '%' . $search . '%')
                      ->orWhereHas('tags', function ($tq) use ($search) {
                          $tq->where('name', 'like', '%' . $search . '%');
                      })
                      ->orWhereHas('categories', function ($cq) use ($search) {
                          $cq->where('name', 'like', '%' . $search . '%');
                      });
            });
        }

        if ($request->filled('status')) {
            $contacts->where('status', $request->status);
        }

        if ($request->filled('category_id')) {
            $catId = (int) $request->category_id;
            $contacts->whereHas('categories', function ($cq) use ($catId) {
                $cq->where('categories.id', $catId);
            });
        }

        if ($request->filled('tags')) {
            $tagIds = (array) $request->tags;
            $contacts->whereHas('tags', function ($query) use ($tagIds) {
                $query->whereIn('tags.id', $tagIds);
            }, '=', count($tagIds));
        }

        // Email status filter
        if ($request->filled('email_status')) {
            $st = strtolower(trim($request->email_status));
            $campId = $request->input('campaign_id');
            if ($st === 'never_sent' || $st === 'not_sent') {
                if ($campId) {
                    $contacts->whereDoesntHave('emailRecipients', fn($q) => $q->where('campaign_id', $campId)->where(fn($sq) => $sq->whereIn('status', ['sent', 'delivered'])->orWhereNotNull('sent_at')));
                } else {
                    $contacts->whereDoesntHave('emailRecipients', fn($q) => $q->whereIn('status', ['sent', 'delivered', 'bounced', 'failed'])->orWhereNotNull('sent_at'));
                }
            } elseif ($st === 'sent') {
                $contacts->whereHas('emailRecipients', fn($q) => $q->when($campId, fn($cq) => $cq->where('campaign_id', $campId))->where('status', 'sent'));
            } elseif ($st === 'delivered') {
                $contacts->whereHas('emailRecipients', fn($q) => $q->when($campId, fn($cq) => $cq->where('campaign_id', $campId))->where('status', 'delivered'));
            } elseif ($st === 'bounced') {
                $contacts->whereHas('emailRecipients', fn($q) => $q->when($campId, fn($cq) => $cq->where('campaign_id', $campId))->where('status', 'bounced'));
            } elseif ($st === 'failed') {
                $contacts->whereHas('emailRecipients', fn($q) => $q->when($campId, fn($cq) => $cq->where('campaign_id', $campId))->where('status', 'failed'));
            }
        }

        $results = $contacts->distinct()->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'message' => 'Contacts fetched successfully',
            'data' => $results,
        ]);
    }

    /**
     * Get email activity history for a specific contact (Requirement 11, 20, 26)
     */
    public function emailActivities($id)
    {
        $tenantId = $this->currentUser()->tenant_id;
        $contact = Contact::where('tenant_id', $tenantId)->findOrFail($id);

        $activities = EmailCampaignRecipient::with('campaign:id,name,subject,from_name')
            ->where('contact_id', $contact->id)
            ->latest()
            ->get()
            ->map(function ($rec) {
                return [
                    'id' => $rec->id,
                    'campaign_id' => $rec->campaign_id,
                    'campaign_name' => $rec->campaign?->name ?? 'Direct Campaign',
                    'subject' => $rec->personalized_subject ?? $rec->campaign?->subject ?? '',
                    'status' => $rec->status,
                    'sent_at' => $rec->sent_at?->format('M d, Y h:i A'),
                    'delivered_at' => $rec->delivered_at?->format('M d, Y h:i A'),
                    'bounced_at' => $rec->bounced_at?->format('M d, Y h:i A'),
                    'failed_at' => $rec->failed_at?->format('M d, Y h:i A'),
                    'bounce_type' => $rec->bounce_type,
                    'bounce_reason' => $rec->bounce_reason,
                    'error_message' => $rec->error_message,
                    'provider' => $rec->provider,
                    'date' => ($rec->delivered_at ?: ($rec->sent_at ?: $rec->created_at))?->format('F j, Y'),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $activities,
        ]);
    }

    /**
     * Get scraper discovery provenance history for a specific contact (Requirement 7 & 20)
     */
    public function scraperHistory($id)
    {
        $tenantId = $this->currentUser()->tenant_id;
        $contact = Contact::where('tenant_id', $tenantId)->findOrFail($id);

        $history = ScrapeResult::with(['search:id,keyword,location,created_at', 'category:id,name'])
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contact->id)
            ->latest('discovered_at')
            ->get()
            ->map(function ($res) {
                return [
                    'id' => $res->id,
                    'search_id' => $res->scrape_search_id,
                    'search_query' => $res->search?->keyword ?? 'Direct Discovery',
                    'location' => $res->search?->location ?? 'N/A',
                    'category_name' => $res->category?->name ?? 'General',
                    'discovery_source' => $res->discovery_source,
                    'discovered_at' => $res->discovered_at?->format('M d, Y') ?? $res->created_at?->format('M d, Y'),
                    'source_full' => ($res->category?->name ?? 'Uncategorized') . ' → ' . ($res->search?->location ?? 'Global') . ' → ' . ($res->search?->keyword ?? 'Search'),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }
}
