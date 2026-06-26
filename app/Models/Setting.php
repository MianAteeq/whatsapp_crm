<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['key', 'value', 'group', 'type'])]
class Setting extends Model
{
    //
}
