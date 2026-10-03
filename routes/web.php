<?php

use Illuminate\Support\Facades\Route;

Route::view('/invitations/accept', 'app')->name('invitations.accept');

Route::view('/{path?}', 'app')
    ->where('path', '^(?!api(?:/|$)).*$')
    ->name('spa');
