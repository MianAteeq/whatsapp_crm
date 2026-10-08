<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSendingDomain extends Model
{
    protected $table = 'email_sending_domains';

    const STATUS_PENDING = 'pending';
    const STATUS_VERIFIED = 'verified';
    const STATUS_REJECTED = 'rejected';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'domain',
        'status',
        'mailtrap_domain_id',
        'mailtrap_domain_name',
        'spf_status',
        'dkim_status',
        'dmarc_status',
        'dns_records',
        'verified_at',
        'last_checked_at',
        'verification_error',
    ];

    protected $casts = [
        'dns_records' => 'array',
        'verified_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(EmailDomainAuditLog::class, 'domain_id');
    }

    public function campaigns()
    {
        return $this->hasMany(EmailCampaign::class, 'sending_domain_id');
    }

    /**
     * Check if domain is currently in a verified and valid sending state.
     */
    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    /**
     * Helper to normalize a domain string.
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        // Strip protocols if provided
        $domain = preg_replace('#^https?://#i', '', $domain);
        // Strip trailing path/slash/port
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];
        return strtolower(trim($domain));
    }
}
