<?php

namespace App\Modules\Memberships;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable $expires_at */
final class Invitation extends Model
{
    use HasUuids;

    protected $table = 'restaurant_invitations';

    protected $fillable = ['email', 'role', 'status', 'inviter_id', 'expires_at', 'token_digest'];

    protected $hidden = ['token_digest'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    public function effectiveStatus(): string
    {
        return $this->status === 'pending' && $this->expires_at->lte(now()) ? 'expired' : $this->status;
    }
}
