<?php

namespace Database\Seeders;

use App\Actions\ArchiveItem;
use App\Enums\MembershipRole;
use App\Models\Category;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Str;

class DemoHouseholdDetails
{
    public static function seed(Household $household, User $owner): void
    {
        if ($household->memberships()->whereBelongsTo($owner)->where('role', MembershipRole::Owner)->exists()) {
            $member = User::query()->firstOrCreate(['email' => 'inventory-demo-member@example.test'], [
                'name' => 'Alex Morgan',
                'password' => Str::random(40),
            ]);

            if ($member->wasRecentlyCreated) {
                $household->memberships()->create([
                    'user_id' => $member->getKey(),
                    'role' => MembershipRole::Member,
                    'joined_at' => now()->subWeeks(3),
                ]);
            }

            $household->invitations()->firstOrCreate(['email' => 'sam@example.test'], [
                'role' => MembershipRole::Member,
                'token_hash' => hash('sha256', Str::random(64)),
                'invited_by' => $owner->getKey(),
                'created_at' => now(),
                'expires_at' => now()->addWeek(),
            ]);
        }

        $retired = $household->items()->withTrashed()->firstOrCreate(['name' => 'Coffee filters, basket style'], [
            'counting_unit' => 'box',
            'description' => 'Retired when the kitchen switched to a cone-filter coffee maker.',
        ]);

        if ($retired->wasRecentlyCreated) {
            app(ArchiveItem::class)->archive($household, $retired, $owner);
        }

        $paper = $household->items()->where('name', 'Toilet Paper')->first();
        $soap = $household->items()->where('name', 'Dish Soap')->first();
        self::note($paper, $owner, 'Count individual rolls when opening a new pack.');
        self::note($soap, $owner, 'Buy the unscented refill bottle for the kitchen dispenser.');
        self::note($household->locations()->where('name', 'Main Pantry')->first(), $owner, 'Keep open packs in front of sealed refills.');
        self::note($household->categories()->where('name', 'Paper Goods')->first(), $owner, 'Check the basement shelf before adding paper supplies to the shopping list.');
        self::note($paper?->inventoryMovements()->where('movement_type', 'restock')->oldest('id')->first(), $owner, 'Added the rolls from this week’s shopping trip.');
        self::note($soap?->inventoryAlerts()->whereNull('resolved_at')->oldest('id')->first(), $owner, 'Pick up a refill on the next grocery trip.');
    }

    private static function note(Item|Location|Category|InventoryMovement|InventoryAlert|null $record, User $owner, string $body): void
    {
        if ($record === null || $record->notes()->withTrashed()->exists()) {
            return;
        }

        $note = $record->notes()->make(['body' => $body]);
        $note->creator()->associate($owner);
        $note->save();
    }
}
