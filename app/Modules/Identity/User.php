<?php

namespace App\Modules\Identity;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasUuids, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'session_version'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'is_platform_admin' => 'boolean',
            'session_version' => 'integer',
        ];
    }
}
