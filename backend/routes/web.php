<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Login route for authentication middleware redirection
// Returns 401 for API requests, view for web requests
Route::get('/login', function (Request $request) {
    if ($request->expectsJson() || $request->is('api/*')) {
        return response()->json([
            'timestamp' => now()->toISOString(),
            'message' => 'Unauthenticated.',
        ], 401);
    }
    
    return view('auth.login');
})->name('login');