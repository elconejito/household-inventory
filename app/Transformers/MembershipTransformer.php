<?php

namespace App\Transformers;

use App\Models\Membership;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class MembershipTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['household'];

    /**
     * @param  Membership  $membership
     * @return array{type: string, id: string, role: string, joined_at: string}
     */
    public function transform(mixed $membership): array
    {
        return [
            'type' => 'memberships',
            'id' => (string) $membership->getKey(),
            'role' => $membership->role->value,
            'joined_at' => $membership->joined_at->toISOString(),
        ];
    }

    public function includeHousehold(Membership $membership): ResourceInterface
    {
        return $membership->household === null
            ? $this->null()
            : $this->item($membership->household, new HouseholdTransformer);
    }
}
