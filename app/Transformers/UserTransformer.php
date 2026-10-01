<?php

namespace App\Transformers;

use App\Models\User;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class UserTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['membership'];

    /**
     * @param  User  $user
     * @return array{type: string, id: string, name: string, email: string}
     */
    public function transform(mixed $user): array
    {
        return [
            'type' => 'users',
            'id' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    public function includeMembership(User $user): ResourceInterface
    {
        $membership = $user->memberships()->first();

        return $membership === null
            ? $this->null()
            : $this->item($membership, new MembershipTransformer);
    }
}
