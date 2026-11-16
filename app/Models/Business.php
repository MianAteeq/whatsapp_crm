<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    protected $fillable = [
        'tenant_id',
        'business_name',
        'normalized_business_name',
        'website',
        'website_domain',
        'phone',
        'normalized_phone',
        'phone_original',
        'address',
        'city',
        'state',
        'country',
        'google_place_id',
        'google_maps_url',
        'rating',
        'review_count',
        'source',
        'source_details',
        'first_discovered_at',
        'last_discovered_at',
        'owner_id', 'email', 'notes', 'status', 'industry', 'linkedin_url', 'employee_count',
    ];

    protected $casts = [
        'rating' => 'float',
        'review_count' => 'integer',
        'source_details' => 'array',
        'first_discovered_at' => 'datetime',
        'last_discovered_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'business_categories');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'business_id');
    }

    public function scrapeResults(): HasMany
    {
        return $this->hasMany(ScrapeResult::class, 'business_id');
    }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function opportunities() { return $this->hasMany(Opportunity::class); }
    public function tasks() { return $this->hasMany(CrmTask::class); }
    public function appointments() { return $this->hasMany(Appointment::class); }
    public function activities() { return $this->hasMany(SalesActivity::class)->latest('occurred_at'); }
}
