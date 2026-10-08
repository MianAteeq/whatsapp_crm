<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = [];
    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function emailSetting()
    {
        return $this->hasOne(EmailSetting::class);
    }

    public function emailCampaigns()
    {
        return $this->hasMany(EmailCampaign::class);
    }

    public function emailTemplates()
    {
        return $this->hasMany(EmailTemplate::class);
    }

    public function emailSuppressions()
    {
        return $this->hasMany(EmailSuppression::class);
    }

    public function emailSendingDomains()
    {
        return $this->hasMany(EmailSendingDomain::class);
    }

    /**
     * Retrieve the active plan limits config for this tenant.
     */
    public function getLimits(): array
    {
        $planKey = $this->plan ?? 'free';
        $plan = Plan::where('key', $planKey)->first();
        if ($plan) {
            return $plan->limits;
        }

        return [
            'contacts' => '100',
            'messages' => '1000',
            'campaigns' => 'Disabled',
            'ai_replies' => 'Disabled'
        ];
    }
}
