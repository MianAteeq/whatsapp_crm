<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $fillable = [
       
        'name', 'tenant_id', 'tag_category_id', 'description', 'color'
    ];

    public function contacts()
    {
        return $this->belongsToMany(Contact::class);
    }
    public function category() { return $this->belongsTo(TagCategory::class, 'tag_category_id'); }
}
