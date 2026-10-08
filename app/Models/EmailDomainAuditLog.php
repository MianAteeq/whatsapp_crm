<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailDomainAuditLog extends Model
{
    protected $table = 'email_domain_audit_logs';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'domain_id',
        'event',
        'old_status',
        'new_status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function domain()
    {
        return $this->belongsTo(EmailSendingDomain::class, 'domain_id');
    }

    /**
     * Record an audit event helper.
     */
    public static function record(
        int $tenantId,
        ?int $userId,
        ?int $domainId,
        string $event,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'domain_id' => $domainId,
            'event' => $event,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'metadata' => $metadata,
        ]);
    }
}
