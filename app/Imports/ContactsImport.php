<?php

namespace App\Imports;

use App\Models\Tag;
use App\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ContactsImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {

        foreach ($rows as $row) {

            // Skip empty rows
            if (!$row['name'] || !$row['phone']) {
                continue;
            }

            // Prevent duplicate phone numbers
            $exists = Contact::where(
                'tenant_id',
                Auth::user()->tenant_id
            )
            ->where('phone', $row['phone'])
            ->exists();

            if ($exists) {
                continue;
            }


            // Create Contact
            $contact = Contact::create([

                'tenant_id' => Auth::user()->tenant_id,

                'name' => $row['name'],

                'phone' => $row['phone'],

                'email' => $row['email'] ?? null,

                'company' => $row['company'] ?? null,

                'job_title' => $row['job_title'] ?? null,

                'address' => $row['address'] ?? null,

                'website' => $row['website'] ?? null,

                'notes' => $row['notes'] ?? null,

                'status' => 'active'

            ]);


            // Optional Tags
            // CSV format example:
            // VIP,Wholesale

            if (!empty($row['tags'])) {

                $tagNames = explode(',', $row['tags']);

                $tagIds = [];

                foreach ($tagNames as $tagName) {

                    $tag = Tag::firstOrCreate([

                        'name' => trim($tagName)

                    ]);

                    $tagIds[] = $tag->id;
                }

                $contact->tags()->sync($tagIds);

            }

        }

    }
}
