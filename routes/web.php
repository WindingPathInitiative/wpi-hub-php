<?php

use Illuminate\Support\Facades\Route;

// Node served a JSON welcome at the application root (controllers/index.js).
Route::get('/', fn () => response()->json([
    'message' => 'Welcome to the user hub',
    'version' => config('wpi.version'),
]));
