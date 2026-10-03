<?php

namespace Tests\Feature;

use App\Actions\ArchiveItem;
use App\Actions\CreateInventoryMovement;
use App\Actions\ManageHouseholdInvitations;
use App\Actions\ManageItemImage;
use App\Actions\ManageMembership;
use App\Enums\MembershipRole;
use App\Exceptions\HouseholdAdministrationConflict;
use App\Exceptions\InventoryArchiveBlocked;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

class InventoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migrateDedicatedDatabase;
    }

    public function runDatabaseMigrations(): void
    {
        if (getenv('RUN_MYSQL_CONCURRENCY_TESTS') !== '1'
            || DB::connection()->getDriverName() !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'household_inventory_test'
            || ! in_array(DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Requires RUN_MYSQL_CONCURRENCY_TESTS=1, PCNTL, and the local household_inventory_test MySQL database.');
        }
        $this->migrateDedicatedDatabase();
    }

    public function test_competing_consumptions_preserve_non_negative_stock_and_a_single_successful_ledger_entry(): void
    {
        [$household, $owner, $item, $location] = $this->inventoryContext();
        app(CreateInventoryMovement::class)->create($household, $owner, [
            'movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 1,
        ]);
        $consume = static fn () => app(CreateInventoryMovement::class)->create($household, $owner, [
            'movement_type' => 'consumption', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 1,
        ]);

        $results = $this->concurrently($household->id, [$consume, $consume]);

        $this->assertStatuses(['ok', 'validation'], $results);
        $this->assertDatabaseHas('inventory_levels', ['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 0]);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseCount('inventory_movement_entries', 2);
    }

    public function test_competing_primary_promotions_leave_exactly_one_active_primary_photo(): void
    {
        [$household, $owner, $item] = $this->inventoryContext();
        $first = ItemImage::factory()->for($item)->create(['is_primary' => true]);
        $second = ItemImage::factory()->for($item)->create();
        $third = ItemImage::factory()->for($item)->create();

        $results = $this->concurrently($household->id, [
            static fn () => app(ManageItemImage::class)->update($household, $second, ['is_primary' => true], $owner),
            static fn () => app(ManageItemImage::class)->update($household, $third, ['is_primary' => true], $owner),
        ]);

        $this->assertStatuses(['ok', 'ok'], $results);
        $this->assertSame(1, $item->images()->where('is_primary', true)->count());
        $this->assertFalse($first->fresh()->is_primary);
    }

    public function test_waiting_stock_write_returns_403_after_membership_removal_even_with_an_earlier_read_snapshot(): void
    {
        [$household, $owner, $item, $location] = $this->inventoryContext();
        $member = User::factory()->create();
        $membership = Membership::factory()->for($household)->for($member)->create();

        $results = $this->concurrently($household->id, [
            static fn () => DB::transaction(static function () use ($household, $member, $item, $location): void {
                Gate::forUser($member)->authorize('create', InventoryMovement::class);
                app(CreateInventoryMovement::class)->create($household, $member, [
                    'movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 1,
                ]);
            }),
        ], static fn () => app(ManageMembership::class)->remove($household, $membership, $owner));

        $this->assertStatuses(['forbidden'], $results);
        $this->assertSoftDeleted($membership);
        $this->assertDatabaseCount('inventory_levels', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_competing_invitation_acceptances_create_one_membership_only(): void
    {
        [$household, $owner] = $this->inventoryContext();
        $invitee = User::factory()->create();
        $invitation = app(ManageHouseholdInvitations::class)->create($household, $owner, $invitee->email);
        $token = substr(parse_url($invitation->invitation_url, PHP_URL_FRAGMENT), strlen('token='));
        $accept = static fn () => app(ManageHouseholdInvitations::class)->accept($token, $invitee, []);

        $results = $this->concurrently($household->id, [$accept, $accept]);

        $this->assertStatuses(['conflict', 'ok'], $results);
        $this->assertSame(1, Membership::query()->where('user_id', $invitee->id)->count());
        $this->assertNotNull(HouseholdInvitation::query()->findOrFail($invitation->id)->accepted_at);
    }

    public function test_competing_owner_departures_cannot_remove_the_final_owner(): void
    {
        [$household, $firstOwner] = $this->inventoryContext();
        $secondOwner = User::factory()->create();
        $first = $household->memberships()->where('user_id', $firstOwner->id)->firstOrFail();
        $second = Membership::factory()->for($household)->for($secondOwner)->owner()->create();

        $results = $this->concurrently($household->id, [
            static fn () => app(ManageMembership::class)->leave($household, $first),
            static fn () => app(ManageMembership::class)->leave($household, $second),
        ]);

        $this->assertStatuses(['conflict', 'ok'], $results);
        $this->assertSame(1, $household->memberships()->where('role', MembershipRole::Owner)->count());
        $this->assertSame(1, $household->memberships()->onlyTrashed()->count());
    }

    public function test_competing_archive_and_restock_cannot_leave_positive_stock_on_an_archived_item(): void
    {
        [$household, $owner, $item, $location] = $this->inventoryContext();

        $results = $this->concurrently($household->id, [
            static fn () => app(ArchiveItem::class)->archive($household, $item, $owner),
            static fn () => app(CreateInventoryMovement::class)->create($household, $owner, [
                'movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 1,
            ]),
        ]);

        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['status'] === 'ok')));
        $this->assertContains($results[0]['status'], ['ok', 'archive_blocked']);
        $this->assertContains($results[1]['status'], ['ok', 'validation']);
        if ($item->fresh()->trashed()) {
            $this->assertDatabaseCount('inventory_levels', 0);
            $this->assertDatabaseCount('inventory_movements', 0);
        } else {
            $this->assertDatabaseHas('inventory_levels', ['item_id' => $item->id, 'quantity' => 1]);
            $this->assertDatabaseCount('inventory_movements', 1);
        }
    }

    /** @return array{Household, User, Item, Location} */
    private function inventoryContext(): array
    {
        $household = Household::factory()->create();
        $owner = User::factory()->create();
        Membership::factory()->for($household)->for($owner)->owner()->create();

        return [$household, $owner, Item::factory()->for($household)->create(), Location::factory()->for($household)->create()];
    }

    /**
     * @param  array<int, Closure>  $operations
     * @return array<int, array{status: string, detail?: string}>
     */
    private function concurrently(int $householdId, array $operations, ?Closure $beforeRelease = null): array
    {
        DB::purge();
        $workers = [];
        try {
            foreach ($operations as $operation) {
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if ($sockets === false) {
                    throw new RuntimeException('Unable to create concurrency-test sockets.');
                }
                $pid = pcntl_fork();
                if ($pid === -1) {
                    fclose($sockets[0]);
                    fclose($sockets[1]);
                    throw new RuntimeException('Unable to fork a concurrency-test worker.');
                }
                if ($pid === 0) {
                    fclose($sockets[0]);
                    foreach ($workers as $worker) {
                        fclose($worker['socket']);
                    }
                    $this->runWorker($sockets[1], $operation);
                }
                fclose($sockets[1]);
                stream_set_timeout($sockets[0], 15);
                $workers[] = ['pid' => $pid, 'socket' => $sockets[0]];
            }

            DB::beginTransaction();
            Household::query()->lockForUpdate()->findOrFail($householdId);
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }
            $connectionIds = [];
            foreach ($workers as $worker) {
                $line = fgets($worker['socket']);
                $this->assertNotFalse($line, 'Every worker must announce its locking connection.');
                $announcement = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $this->assertIsInt($announcement['waiting_connection_id'] ?? null);
                $connectionIds[] = $announcement['waiting_connection_id'];
            }
            $this->assertWorkersAreBlocked($connectionIds);
            if ($beforeRelease !== null) {
                $beforeRelease();
            }
            DB::commit();

            return array_map(static function (array $worker): array {
                $line = fgets($worker['socket']);
                if ($line === false) {
                    throw new RuntimeException('A concurrency-test worker did not return a result.');
                }

                return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }, $workers);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                if (pcntl_waitpid($worker['pid'], $status, WNOHANG) === 0) {
                    posix_kill($worker['pid'], SIGKILL);
                    pcntl_waitpid($worker['pid'], $status);
                }
            }
        }
    }

    /** @param array<int, int> $connectionIds */
    private function assertWorkersAreBlocked(array $connectionIds): void
    {
        $parentConnectionId = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $deadline = microtime(true) + 15;
        do {
            $waitingCount = DB::table('performance_schema.data_lock_waits as waits')
                ->join('performance_schema.threads as requester', 'requester.THREAD_ID', '=', 'waits.REQUESTING_THREAD_ID')
                ->join('performance_schema.threads as blocker', 'blocker.THREAD_ID', '=', 'waits.BLOCKING_THREAD_ID')
                ->whereIn('requester.PROCESSLIST_ID', $connectionIds)
                ->where('blocker.PROCESSLIST_ID', $parentConnectionId)
                ->distinct()->count('requester.PROCESSLIST_ID');
            if ($waitingCount === count($connectionIds)) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        $this->assertSame(count($connectionIds), $waitingCount, 'Every worker must be blocked by the held database lock before it is released.');
    }

    /** @param resource $socket */
    private function runWorker($socket, Closure $operation): never
    {
        stream_set_timeout($socket, 15);
        try {
            $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $announced = false;
            DB::connection()->beforeExecuting(static function (string $sql) use ($socket, $connectionId, &$announced): void {
                if (! $announced && str_contains(strtolower($sql), 'households') && str_contains(strtolower($sql), 'for update')) {
                    $announced = true;
                    fwrite($socket, json_encode(['waiting_connection_id' => $connectionId], JSON_THROW_ON_ERROR)."\n");
                }
            });
            if (fgets($socket) !== "go\n") {
                throw new RuntimeException('The concurrency-test start barrier was not released.');
            }
            $operation();
            $result = ['status' => 'ok'];
        } catch (Throwable $exception) {
            $result = ['status' => match (true) {
                $exception instanceof ValidationException => 'validation',
                $exception instanceof HouseholdAdministrationConflict => 'conflict',
                $exception instanceof InventoryArchiveBlocked => 'archive_blocked',
                $exception instanceof HttpException && $exception->getStatusCode() === 403 => 'forbidden',
                default => 'unexpected',
            }, 'detail' => $exception->getMessage()];
        }
        fwrite($socket, json_encode($result, JSON_THROW_ON_ERROR)."\n");
        fclose($socket);
        DB::disconnect();
        exit(0);
    }

    /** @param array<int, string> $expected
     * @param  array<int, array{status: string, detail?: string}>  $results
     */
    private function assertStatuses(array $expected, array $results): void
    {
        $actual = array_column($results, 'status');
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, json_encode($results, JSON_THROW_ON_ERROR));
    }
}
