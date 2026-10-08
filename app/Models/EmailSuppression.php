<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSuppression extends Model
{
    protected $table = 'email_suppressions';

    const REASON_UNSUBSCRIBED = 'unsubscribed';
    const REASON_HARD_BOUNCE = 'hard_bounce';
    const REASON_SPAM_COMPLAINT = 'spam_complaint';
    const REASON_MANUALLY_SUPPRESSED = 'manually_suppressed';

    protected $fillable = [
        'tenant_id',
        'contact_id',
        'email',
        'reason',
        'details',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Check if email is suppressed for a given tenant
     */
    public static function isSuppressed(int $tenantId, string $email): bool
    {
        return static::where('tenant_id', $tenantId)
            ->where('email', strtolower(trim($email)))
            ->exists();
    }
}
