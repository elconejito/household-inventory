<?php

namespace App\Transformers;

use App\Models\HouseholdInvitation;
use League\Fractal\TransformerAbstract;

class HouseholdInvitationTransformer extends TransformerAbstract
{
    /**
     * @param  HouseholdInvitation  $invitation
     * @return array<string, string|bool|null>
     */
    public function transform(mixed $invitation): array
    {
        $resource = [
            'type' => 'household-invitations',
            'id' => (string) $invitation->getKey(),
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'created_at' => $invitation->created_at->toISOString(),
            'expires_at' => $invitation->expires_at->toISOString(),
            'accepted_at' => $invitation->accepted_at?->toISOString(),
            'revoked_at' => $invitation->revoked_at?->toISOString(),
            'is_expired' => ! $invitation->expires_at->isFuture(),
        ];

        if ($invitation->getAttribute('invitation_url') !== null) {
            $resource['invitation_url'] = $invitation->getAttribute('invitation_url');
        }

        return $resource;
    }
}
