<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $table = 'email_templates';

    protected $fillable = [
        'tenant_id',
        'name',
        'subject',
        'category',
        'html_body',
        'text_body',
        'editor_data',
    ];

    protected $casts = ['editor_data' => 'array'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function campaigns()
    {
        return $this->hasMany(EmailCampaign::class, 'template_id');
    }
}
