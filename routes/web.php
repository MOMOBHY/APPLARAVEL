<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\NoteServiceController;
use App\Http\Controllers\PermissionController;
use Illuminate\Support\Facades\Route;

// Frontend historique conservé tel quel.
Route::get('/', fn () => redirect('/gfp/index.html'));

Route::get('/login', fn () => view('auth.login'))->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();

        if ($user->hasRole('ROLE_ADMIN_DSI')) {
            return view('dashboards.admin');
        }
        if ($user->hasRole('ROLE_DRH')) {
            return view('dashboards.drh');
        }
        if ($user->hasRole('ROLE_SOUS_DIRECTEUR', 'ROLE_DIRECTEUR')) {
            return view('dashboards.direction');
        }
        if ($user->hasRole('ROLE_GESTIONNAIRE_RH')) {
            return view('dashboards.gestionnaire');
        }
        if ($user->hasRole('ROLE_SECRETAIRE')) {
            return view('dashboards.secretaire');
        }

        return view('dashboards.agent');
    })->name('dashboard');

    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
    Route::get('/notes', [NoteServiceController::class, 'index'])->name('notes.index');
});
