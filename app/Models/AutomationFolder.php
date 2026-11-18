<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationFolder extends Model
{
    protected $table = 'automation_folders';

    protected $fillable = [
        'tenant_id',
        'name',
        'parent_id',
    ];

    public function workflows(): HasMany
    {
        return $this->hasMany(AutomationWorkflow::class, 'folder_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}

