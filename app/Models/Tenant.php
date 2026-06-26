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
