<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\HouseholdInvitationController;
use App\Http\Controllers\Api\InventoryAlertController;
use App\Http\Controllers\Api\InventoryLevelController;
use App\Http\Controllers\Api\InventoryMovementController;
use App\Http\Controllers\Api\InventoryMovementRecorderController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ItemImageController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\PermanentDeletionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:register')
    ->name('register');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('login');
Route::post('/household-invitations/accept', [HouseholdInvitationController::class, 'accept'])
    ->middleware('throttle:invitation-acceptance')
    ->name('household-invitations.accept');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'show'])->name('user.show');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/household', [HouseholdController::class, 'show'])->name('household.show');
    Route::patch('/household', [HouseholdController::class, 'update'])->name('household.update');
    Route::get('/memberships', [MembershipController::class, 'index'])->name('memberships.index');
    Route::patch('/memberships/{membership}', [MembershipController::class, 'update'])->name('memberships.update');
    Route::delete('/memberships/{membership}', [MembershipController::class, 'destroy'])->name('memberships.destroy');
    Route::post('/memberships/{membership}/restore', [MembershipController::class, 'restore'])->name('memberships.restore');
    Route::post('/membership/leave', [MembershipController::class, 'leave'])->name('membership.leave');

    Route::get('/household-invitations', [HouseholdInvitationController::class, 'index'])->name('household-invitations.index');
    Route::post('/household-invitations', [HouseholdInvitationController::class, 'store'])
        ->middleware('throttle:invitations')->name('household-invitations.store');
    Route::post('/household-invitations/{household_invitation}/resend', [HouseholdInvitationController::class, 'resend'])
        ->middleware('throttle:invitations')->name('household-invitations.resend');
    Route::delete('/household-invitations/{household_invitation}', [HouseholdInvitationController::class, 'destroy'])->name('household-invitations.destroy');

    Route::get('/items', [ItemController::class, 'index'])->name('items.index');
    Route::post('/items', [ItemController::class, 'store'])->name('items.store');
    Route::get('/items/{item}', [ItemController::class, 'show'])->name('items.show');
    Route::patch('/items/{item}', [ItemController::class, 'update'])->name('items.update');
    Route::delete('/items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');
    Route::post('/items/{item}/restore', [ItemController::class, 'restore'])->name('items.restore');
    Route::delete('/items/{item}/permanently', [PermanentDeletionController::class, 'item'])->name('items.permanently');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('/categories/{category}/restore', [CategoryController::class, 'restore'])->name('categories.restore');
    Route::delete('/categories/{category}/permanently', [PermanentDeletionController::class, 'category'])->name('categories.permanently');

    Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
    Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
    Route::get('/locations/{location}', [LocationController::class, 'show'])->name('locations.show');
    Route::patch('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
    Route::delete('/locations/{location}', [LocationController::class, 'destroy'])->name('locations.destroy');
    Route::post('/locations/{location}/restore', [LocationController::class, 'restore'])->name('locations.restore');
    Route::delete('/locations/{location}/permanently', [PermanentDeletionController::class, 'location'])->name('locations.permanently');

    Route::get('/inventory-levels', [InventoryLevelController::class, 'index'])->name('inventory-levels.index');
    Route::post('/inventory-levels', [InventoryLevelController::class, 'store'])->name('inventory-levels.store');
    Route::get('/inventory-levels/{inventory_level}', [InventoryLevelController::class, 'show'])->name('inventory-levels.show');
    Route::patch('/inventory-levels/{inventory_level}', [InventoryLevelController::class, 'update'])->name('inventory-levels.update');

    Route::get('/inventory-movements', [InventoryMovementController::class, 'index'])->name('inventory-movements.index');
    Route::get('/inventory-movement-recorders', [InventoryMovementRecorderController::class, 'index'])->name('inventory-movement-recorders.index');
    Route::post('/inventory-movements', [InventoryMovementController::class, 'store'])->name('inventory-movements.store');
    Route::get('/inventory-movements/{inventory_movement}', [InventoryMovementController::class, 'show'])->name('inventory-movements.show');

    Route::get('/inventory-alerts', [InventoryAlertController::class, 'index'])->name('inventory-alerts.index');
    Route::post('/inventory-alerts', [InventoryAlertController::class, 'store'])->name('inventory-alerts.store');
    Route::get('/inventory-alerts/{inventory_alert}', [InventoryAlertController::class, 'show'])->name('inventory-alerts.show');
    Route::post('/inventory-alerts/{inventory_alert}/resolve', [InventoryAlertController::class, 'resolve'])->name('inventory-alerts.resolve');

    foreach ([
        'items' => 'item',
        'categories' => 'category',
        'locations' => 'location',
        'inventory-movements' => 'inventory_movement',
        'inventory-alerts' => 'inventory_alert',
    ] as $notableType => $parameter) {
        Route::get("/{$notableType}/{{$parameter}}/notes", [NoteController::class, 'index'])
            ->defaults('notable_type', $notableType)->name("{$notableType}.notes.index");
        Route::post("/{$notableType}/{{$parameter}}/notes", [NoteController::class, 'store'])
            ->defaults('notable_type', $notableType)->name("{$notableType}.notes.store");
    }

    Route::patch('/notes/{note}', [NoteController::class, 'update'])->name('notes.update');
    Route::delete('/notes/{note}', [NoteController::class, 'destroy'])->name('notes.destroy');
    Route::post('/notes/{note}/restore', [NoteController::class, 'restore'])->name('notes.restore');
    Route::delete('/notes/{note}/permanently', [PermanentDeletionController::class, 'note'])->name('notes.permanently');

    Route::get('/items/{item}/images', [ItemImageController::class, 'index'])->name('items.images.index');
    Route::post('/items/{item}/images', [ItemImageController::class, 'store'])->name('items.images.store');
    Route::patch('/item-images/{item_image}', [ItemImageController::class, 'update'])->name('item-images.update');
    Route::delete('/item-images/{item_image}', [ItemImageController::class, 'destroy'])->name('item-images.destroy');
    Route::post('/item-images/{item_image}/restore', [ItemImageController::class, 'restore'])->name('item-images.restore');
    Route::delete('/item-images/{item_image}/permanently', [PermanentDeletionController::class, 'image'])->name('item-images.permanently');
    Route::get('/item-images/{item_image}/thumbnail', [ItemImageController::class, 'thumbnail'])->name('item-images.thumbnail');
    Route::get('/item-images/{item_image}/display', [ItemImageController::class, 'display'])->name('item-images.display');
});
