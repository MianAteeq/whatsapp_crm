<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
   protected $guarded = [];


    // ==========================================
    // TEMPLATE
    // ==========================================

    public function template()
    {

        return $this->belongsTo(

            WhatsappTemplate::class,

            'template_id'

        );

    }



    // ==========================================
    // CAMPAIGN CONTACTS
    // ==========================================

    public function campaignContacts()
    {

        return $this->hasMany(

            CampaignContact::class

        );

    }
}
