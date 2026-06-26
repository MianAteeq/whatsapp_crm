<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\ContactsImport;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;


class ContactController extends Controller
{
    protected function currentUser()
    {
        return Auth::user() ?? abort(response()->json(['message' => 'Unauthorized'], 401));
    }

    public function index(Request $request)
    {

        $contacts = Contact::with('tags')

            ->where('tenant_id', $this->currentUser()->tenant_id)

            ->when($request->search, function ($query) use ($request) {

                $query->where(function ($q) use ($request) {

                    $q->where('name', 'like', '%' . $request->search . '%')

                        ->orWhere('phone', 'like', '%' . $request->search . '%')

                        ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            })

            ->when($request->status, function ($query) use ($request) {

                $query->where('status', $request->status);
            })

            ->paginate($request->input('per_page', 10));

        return response()->json($contacts);
    }



    // ========================================
    // STORE CONTACT
    // ========================================

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
                        'message' => 'Your current plan (' . ($tenant->plan ?? 'free') . ') supports up to ' . number_format($maxContacts) . ' contacts. Please upgrade your subscription tier to add more contacts.'
                    ], 400);
                }
            }
        }

        $request->validate([

            'name' => 'required|string|max:255',

            'phone' => 'required|string|max:20',

            'email' => 'nullable|email',

            'status' => 'nullable|in:active,inactive',

            'tags' => 'nullable|array'

        ]);


        $contact = Contact::create([

            'tenant_id' => $this->currentUser()->tenant_id,

            'name' => $request->name,

            'phone' => $request->phone,

            'email' => $request->email,

            'company' => $request->company,

            'job_title' => $request->job_title,

            'address' => $request->address,

            'birthday' => $request->birthday,

            'website' => $request->website,

            'notes' => $request->notes,

            'status' => strtolower($request->status) ?? 'active'

        ]);


        // Attach Tags
        if ($request->tags) {

            $contact->tags()->sync($request->tags);
        }


        return response()->json([

            'message' => 'Contact created successfully',

            'data' => $contact->load('tags')

        ]);
    }



    // ========================================
    // SHOW SINGLE CONTACT
    // ========================================

    public function show($id)
    {

        $contact = Contact::with('tags')

            ->where('tenant_id', $this->currentUser()->tenant_id)

            ->findOrFail($id);

        return response()->json($contact);
    }



    // ========================================
    // UPDATE CONTACT
    // ========================================

    public function update(Request $request, $id)
    {

        $contact = Contact::where('tenant_id', $this->currentUser()->tenant_id)

            ->findOrFail($id);


        $contact->update([

            'name' => $request->name,

            'phone' => $request->phone,

            'email' => $request->email,

            'company' => $request->company,

            'job_title' => $request->job_title,

            'address' => $request->address,

            'birthday' => $request->birthday,

            'website' => $request->website,

            'notes' => $request->notes,

            'status' => strtolower($request->status) ?? 'active'

        ]);


        // Update Tags
        if ($request->tags) {

            $contact->tags()->sync($request->tags);
        }


        return response()->json([

            'message' => 'Contact updated successfully',

            'data' => $contact->load('tags')

        ]);
    }



    // ========================================
    // DELETE CONTACT
    // ========================================

    public function destroy($id)
    {

        $contact = Contact::where('tenant_id', $this->currentUser()->tenant_id)

            ->findOrFail($id);

        $contact->delete();

        return response()->json([

            'message' => 'Contact deleted successfully'

        ]);
    }

    public function import(Request $request)
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
                        'message' => 'Your current plan (' . ($tenant->plan ?? 'free') . ') supports up to ' . number_format($maxContacts) . ' contacts. Please upgrade your subscription to import more contacts.'
                    ], 400);
                }
            }
        }

        $request->validate([
            'file' => 'required|mimes:csv,xlsx,xls'
        ]);

        $mappings = [];
        if ($request->filled('mappings')) {
            $mappings = json_decode($request->input('mappings'), true) ?: [];
        }

        Excel::import(
            new ContactsImport($mappings),
            $request->file('file')
        );


        return response()->json([

            'message' => 'Contacts imported successfully'

        ]);
    }

    public function advanceSearch(Request $request)
{
    $request->validate([

        'search' => 'nullable|string',

        'status' => 'nullable|in:active,inactive',

        'tags' => 'nullable|array',

        'last_touchpoint' => 'nullable|in:today,24_hours,7_days,30_days,90_days'

    ]);


    $contacts = Contact::with('tags')

        ->where(
            'tenant_id',
            auth()->user()->tenant_id
        );



    // ==========================================
    // SEARCH
    // ==========================================

    if ($request->filled('search')) {

        $search = $request->search;

        $contacts->where(function ($query) use ($search) {

            $query->where(
                    'name',
                    'like',
                    '%' . $search . '%'
                )

                ->orWhere(
                    'phone',
                    'like',
                    '%' . $search . '%'
                )

                ->orWhere(
                    'email',
                    'like',
                    '%' . $search . '%'
                )

                ->orWhere(
                    'company',
                    'like',
                    '%' . $search . '%'
                )

                ->orWhereHas('tags', function ($tagQuery) use ($search) {

                    $tagQuery->where(
                        'name',
                        'like',
                        '%' . $search . '%'
                    );

                });

        });

    }



    // ==========================================
    // STATUS FILTER
    // ==========================================

    if ($request->filled('status')) {

        $contacts->where(
            'status',
            $request->status
        );

    }



    // ==========================================
    // TAG FILTER
    // MATCH ALL SELECTED TAGS
    // ==========================================

    if ($request->filled('tags')) {

        $tagIds = $request->tags;

        $contacts->whereHas('tags', function ($query) use ($tagIds) {

            $query->whereIn(
                'tags.id',
                $tagIds
            );

        }, '=', count($tagIds));

    }



    // ==========================================
    // LAST TOUCHPOINT FILTER
    // ==========================================

    if ($request->filled('last_touchpoint')) {

        switch ($request->last_touchpoint) {

            case 'today':

                $contacts->whereDate(
                    'updated_at',
                    today()
                );

                break;



            case '24_hours':

                $contacts->where(
                    'updated_at',
                    '>=',
                    now()->subHours(24)
                );

                break;



            case '7_days':

                $contacts->where(
                    'updated_at',
                    '>=',
                    now()->subDays(7)
                );

                break;



            case '30_days':

                $contacts->where(
                    'updated_at',
                    '>=',
                    now()->subDays(30)
                );

                break;



            case '90_days':

                $contacts->where(
                    'updated_at',
                    '>=',
                    now()->subDays(90)
                );

                break;

        }

    }



    // ==========================================
    // FETCH CONTACTS
    // ==========================================

    $contacts = $contacts

        ->distinct()

        ->latest()

        ->paginate($request->input('per_page', 10));




    // ==========================================
    // RESPONSE
    // ==========================================

    return response()->json([

        'success' => true,

        'message' => 'Contacts fetched successfully',

        'filters' => [

            'search' => $request->search,

            'status' => $request->status,

            'tags' => $request->tags,

            'last_touchpoint' => $request->last_touchpoint

        ],

        'data' => $contacts

    ]);

}
}
