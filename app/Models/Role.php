<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['key', 'name', 'description', 'permissions', 'is_system'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean'];
    }

    public function users()
    {
        return $this->hasMany(User::class, 'role', 'key');
    }
}
