<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScraperBusinessEmail extends Model
{
    protected $table = 'scraper_business_emails';

    protected $fillable = [
        'scraped_business_id',
        'email',
        'email_normalized',
        'is_primary',
        'source_page',
        'source',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(ScrapedBusiness::class, 'scraped_business_id');
    }
}
