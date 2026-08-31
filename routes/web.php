<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebAuthController;

Route::get('/', function () {
    return redirect('/login');
});

// Login Page
Route::get('/login', function () {
    if (auth()->check()) {
        return redirect('/app');
    }
    return view('auth.login');
})->name('login');

// Handle Web Session Creation
Route::post('/login', [WebAuthController::class, 'storeSession']);
Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');

// Protected Web App
Route::get('/app', function () {
    return view('chat.index');
})->middleware('auth');

// Public Policy Pages
Route::get('/help/child-safety', function () {
    return view('help.child-safety');
})->name('help.child-safety');

// Admin Routes
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [\App\Http\Controllers\AdminController::class, 'index'])->name('dashboard');
    Route::get('/users', [\App\Http\Controllers\AdminController::class, 'users'])->name('users');
    Route::post('/users/{user}/toggle-admin', [\App\Http\Controllers\AdminController::class, 'toggleAdmin'])->name('users.toggle_admin');
    Route::get('/tokens', [\App\Http\Controllers\AdminController::class, 'tokens'])->name('tokens');
    Route::post('/tokens', [\App\Http\Controllers\AdminController::class, 'generateToken'])->name('tokens.generate');
    Route::delete('/tokens/{tokenId}', [\App\Http\Controllers\AdminController::class, 'revokeToken'])->name('tokens.revoke');
});
