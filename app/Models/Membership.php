<?php

namespace App\Models;

use App\Enums\MembershipRole;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['household_id', 'user_id', 'role', 'joined_at'])]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory, SoftDeletes;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'joined_at' => 'immutable_datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
