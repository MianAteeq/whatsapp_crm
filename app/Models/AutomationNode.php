<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationNode extends Model
{
    protected $table = 'automation_nodes';
    
    protected $primaryKey = 'id';
    
    public $incrementing = false;
    
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workflow_id',
        'type',
        'label',
        'config',
        'position_x',
        'position_y'
    ];

    protected $casts = [
        'config' => 'array',
        'position_x' => 'double',
        'position_y' => 'double',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }
}
