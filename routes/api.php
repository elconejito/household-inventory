<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InventoryAlertController;
use App\Http\Controllers\Api\InventoryLevelController;
use App\Http\Controllers\Api\InventoryMovementController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\LocationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:register')
    ->name('register');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'show'])->name('user.show');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/items', [ItemController::class, 'index'])->name('items.index');
    Route::post('/items', [ItemController::class, 'store'])->name('items.store');
    Route::get('/items/{item}', [ItemController::class, 'show'])->name('items.show');
    Route::patch('/items/{item}', [ItemController::class, 'update'])->name('items.update');
    Route::delete('/items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');
    Route::post('/items/{item}/restore', [ItemController::class, 'restore'])->name('items.restore');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('/categories/{category}/restore', [CategoryController::class, 'restore'])->name('categories.restore');

    Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
    Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
    Route::get('/locations/{location}', [LocationController::class, 'show'])->name('locations.show');
    Route::patch('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
    Route::delete('/locations/{location}', [LocationController::class, 'destroy'])->name('locations.destroy');
    Route::post('/locations/{location}/restore', [LocationController::class, 'restore'])->name('locations.restore');

    Route::get('/inventory-levels', [InventoryLevelController::class, 'index'])->name('inventory-levels.index');
    Route::post('/inventory-levels', [InventoryLevelController::class, 'store'])->name('inventory-levels.store');
    Route::get('/inventory-levels/{inventory_level}', [InventoryLevelController::class, 'show'])->name('inventory-levels.show');
    Route::patch('/inventory-levels/{inventory_level}', [InventoryLevelController::class, 'update'])->name('inventory-levels.update');

    Route::get('/inventory-movements', [InventoryMovementController::class, 'index'])->name('inventory-movements.index');
    Route::post('/inventory-movements', [InventoryMovementController::class, 'store'])->name('inventory-movements.store');
    Route::get('/inventory-movements/{inventory_movement}', [InventoryMovementController::class, 'show'])->name('inventory-movements.show');

    Route::get('/inventory-alerts', [InventoryAlertController::class, 'index'])->name('inventory-alerts.index');
    Route::post('/inventory-alerts', [InventoryAlertController::class, 'store'])->name('inventory-alerts.store');
    Route::get('/inventory-alerts/{inventory_alert}', [InventoryAlertController::class, 'show'])->name('inventory-alerts.show');
    Route::post('/inventory-alerts/{inventory_alert}/resolve', [InventoryAlertController::class, 'resolve'])->name('inventory-alerts.resolve');
});
