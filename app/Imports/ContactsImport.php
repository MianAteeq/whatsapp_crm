<?php

namespace App\Imports;

use App\Models\Tag;
use App\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use App\Services\Scraper\PhoneNormalizationService;
use Illuminate\Support\Facades\Validator;

class ContactsImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected $mappings;
    public int $successCount = 0;
    public int $duplicateCount = 0;
    public int $invalidCount = 0;
    public int $limitSkippedCount = 0;

    public function __construct(array $mappings = [], private ?int $remaining = null, private array $categoryIds = [])
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

        $normalizer = app(PhoneNormalizationService::class);
        // Look up both raw and normalized phones so differently formatted duplicates are skipped.
        $phones = $rows->map(fn($r) => $this->getRowValue($r, 'phone'))
            ->filter()
            ->unique()
            ->toArray();

        $normalizedPhones = array_values(array_filter(array_map(fn ($phone) => $normalizer->normalizeE164($phone), $phones)));

        // Fetch already existing phone numbers for this tenant in one query
        $existingPhones = Contact::where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereIn('phone', $phones)->orWhereIn('phone_normalized', $normalizedPhones)->orWhereNull('phone_normalized'))
            ->get(['phone', 'phone_normalized']);
        $seen = [];
        foreach ($existingPhones as $existing) {
            $seen['raw:'.trim($existing->phone)] = true;
            $normalized = $existing->phone_normalized ?: $normalizer->normalizeE164($existing->phone);
            if ($normalized) $seen['normalized:'.$normalized] = true;
        }

        // Reuse tags within this chunk; only create tags for imported valid rows.
        $tagCache = [];

        // Perform insert within a single database transaction
        \Illuminate\Support\Facades\DB::transaction(function () use ($rows, $tenantId, $normalizer, &$seen, &$tagCache) {
            foreach ($rows as $row) {
                $name = $this->getRowValue($row, 'name');
                $phone = $this->getRowValue($row, 'phone');
                $normalizedPhone = $normalizer->normalizeE164($phone);
                $email = strtolower($this->getRowValue($row, 'email')) ?: null;
                $values = [];
                foreach (['company', 'job_title', 'address', 'website', 'notes'] as $field) {
                    $values[$field] = $this->getRowValue($row, $field) ?: null;
                }
                $tagNames = array_values(array_filter(array_map('trim', explode(',', $this->getRowValue($row, 'tags')))));

                if (!$normalizedPhone || Validator::make([...$values, 'name' => $name, 'phone' => $phone, 'email' => $email, 'tags' => $tagNames], [
                    'name' => 'required|string|max:255', 'phone' => 'required|string|max:50', 'email' => 'nullable|email|max:191',
                    'company' => 'nullable|string|max:255', 'job_title' => 'nullable|string|max:255', 'tags.*' => 'string|max:255',
                ])->fails()) {
                    $this->invalidCount++;
                    continue;
                }

                if (isset($seen['raw:'.$phone]) || isset($seen['normalized:'.$normalizedPhone])) {
                    $this->duplicateCount++;
                    continue;
                }
                if ($this->remaining !== null && $this->successCount >= $this->remaining) {
                    $this->limitSkippedCount++;
                    continue;
                }

                $parts = preg_split('/\s+/', $name, 2);
                $contact = Contact::create([
                    'tenant_id' => $tenantId,
                    'name'      => $name,
                    'first_name' => $parts[0] ?? '',
                    'last_name' => $parts[1] ?? '',
                    'phone'     => $phone,
                    'phone_normalized' => $normalizedPhone,
                    'phone_original' => $phone,
                    'email' => $email,
                    'email_normalized' => $email,
                    ...$values,
                    'status'    => 'active',
                    'source' => 'CSV Import',
                ]);
                $seen['raw:'.$phone] = true;
                $seen['normalized:'.$normalizedPhone] = true;
                $this->successCount++;
                if ($this->categoryIds) $contact->categories()->syncWithoutDetaching($this->categoryIds);

                if ($tagNames) {
                    $rowTagIds = [];
                    foreach ($tagNames as $tagName) {
                        $tagCache[$tagName] ??= Tag::firstOrCreate(['name' => $tagName])->id;
                        $rowTagIds[] = $tagCache[$tagName];
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
