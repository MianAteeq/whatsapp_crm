<?php

namespace App\Imports;

use App\Models\Tag;
use App\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class ContactsImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected $mappings;

    public function __construct(array $mappings = [])
    {
        $this->mappings = $mappings;
    }

    private function getRowValue($row, string $field): string
    {
        // If there's a mapping for this field, look up the mapped header slugified
        if (!empty($this->mappings[$field])) {
            $mappedHeader = $this->mappings[$field];
            $slugifiedKey = \Illuminate\Support\Str::slug($mappedHeader, '_');
            
            if (isset($row[$slugifiedKey])) {
                return trim((string)$row[$slugifiedKey]);
            }
        }
        
        // Fallback: Check if the original field name is in the row directly (e.g. default column names)
        $slugifiedField = \Illuminate\Support\Str::slug($field, '_');
        if (isset($row[$slugifiedField])) {
            return trim((string)$row[$slugifiedField]);
        }

        return '';
    }

    public function collection(Collection $rows)
    {
        $user = Auth::user();
        if (!$user) return;
        $tenantId = $user->tenant_id;

        // Extract all non-empty phone numbers in this chunk
        $phones = $rows->map(fn($r) => $this->getRowValue($r, 'phone'))
            ->filter()
            ->unique()
            ->toArray();

        if (empty($phones)) {
            return;
        }

        // Fetch already existing phone numbers for this tenant in one query
        $existingPhones = Contact::where('tenant_id', $tenantId)
            ->whereIn('phone', $phones)
            ->pluck('phone')
            ->map(fn($p) => trim((string)$p))
            ->toArray();

        // Extract all unique tag names in this chunk
        $allTagNames = [];
        foreach ($rows as $row) {
            $tagsValue = $this->getRowValue($row, 'tags');
            if (!empty($tagsValue)) {
                foreach (explode(',', $tagsValue) as $t) {
                    $cleaned = trim((string)$t);
                    if ($cleaned !== '') {
                        $allTagNames[] = $cleaned;
                    }
                }
            }
        }
        $allTagNames = array_unique($allTagNames);

        // Pre-create/fetch all tags in this chunk to avoid queries in the loop
        $tagCache = [];
        foreach ($allTagNames as $tagName) {
            $tag = Tag::firstOrCreate(['name' => $tagName]);
            $tagCache[$tagName] = $tag->id;
        }

        // Perform insert within a single database transaction
        \Illuminate\Support\Facades\DB::transaction(function () use ($rows, $tenantId, $existingPhones, $tagCache) {
            foreach ($rows as $row) {
                $name = $this->getRowValue($row, 'name');
                $phone = $this->getRowValue($row, 'phone');

                if ($name === '' || $phone === '') {
                    continue;
                }

                if (in_array($phone, $existingPhones)) {
                    continue;
                }

                $contact = Contact::create([
                    'tenant_id' => $tenantId,
                    'name'      => $name,
                    'phone'     => $phone,
                    'email'     => $this->getRowValue($row, 'email') ?: null,
                    'company'   => $this->getRowValue($row, 'company') ?: null,
                    'job_title' => $this->getRowValue($row, 'job_title') ?: null,
                    'address'   => $this->getRowValue($row, 'address') ?: null,
                    'website'   => $this->getRowValue($row, 'website') ?: null,
                    'notes'     => $this->getRowValue($row, 'notes') ?: null,
                    'status'    => 'active',
                ]);

                $tagsValue = $this->getRowValue($row, 'tags');
                if (!empty($tagsValue)) {
                    $rowTagIds = [];
                    foreach (explode(',', $tagsValue) as $t) {
                        $tagName = trim((string)$t);
                        if (isset($tagCache[$tagName])) {
                            $rowTagIds[] = $tagCache[$tagName];
                        }
                    }
                    if (!empty($rowTagIds)) {
                        $contact->tags()->sync($rowTagIds);
                    }
                }
            }
        });
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
