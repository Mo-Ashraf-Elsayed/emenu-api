<?php

namespace App\Modules\Memberships;

use App\Modules\Identity\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Membership extends Model
{
    use HasUuids;

    protected $table = 'restaurant_memberships';

    protected $fillable = ['user_id', 'role', 'status'];

    /** @return BelongsTo<User,$this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
