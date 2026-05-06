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

            ->latest()

            ->paginate(20);

        return response()->json($contacts);
    }



    // ========================================
    // STORE CONTACT
    // ========================================

    public function store(Request $request)
    {

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

        $request->validate([

            'file' => 'required|mimes:csv,xlsx,xls'

        ]);


        Excel::import(

            new ContactsImport,

            $request->file('file')

        );


        return response()->json([

            'message' => 'Contacts imported successfully'

        ]);
    }
}
