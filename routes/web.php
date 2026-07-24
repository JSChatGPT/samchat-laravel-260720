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
