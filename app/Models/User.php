<?php

namespace App\Models;

use App\Support\AccessControl;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'avatar', 'status', 'tenant_id', 'role', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $appends = ['permissions'];

    public function accessRole()
    {
        return $this->belongsTo(Role::class, 'role', 'key');
    }

    public function getPermissionsAttribute(): array
    {
        if ($this->role === 'superadmin') {
            return AccessControl::keys();
        }

        return $this->accessRole?->permissions ?? [];
    }

    public function hasPermission(string $permission): bool
    {
        return $this->status !== 'blocked' && ($this->role === 'superadmin' || in_array($permission, $this->permissions, true));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
