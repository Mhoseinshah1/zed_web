<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PlansController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\TutorialsController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health check (unauthenticated, before any middleware groups)
Route::get('/health', [HealthController::class, 'check'])->name('health');

// Public pages
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/plans', [PlansController::class, 'index'])->name('plans');
Route::get('/faq', [FaqController::class, 'index'])->name('faq');
Route::get('/tutorials', [TutorialsController::class, 'index'])->name('tutorials');
Route::get('/status', [StatusController::class, 'index'])->name('status');
Route::get('/contact', [ContactController::class, 'index'])->name('contact');

// Authentication (Laravel Breeze-compatible routes)
Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => view('auth.login'))->name('login');
    Route::get('/register', fn () => view('auth.register'))->name('register');
});

// User panel (requires authentication)
Route::middleware('auth')->prefix('panel')->name('panel.')->group(function () {
    Route::get('/', fn () => view('panel.dashboard'))->name('dashboard');
    Route::get('/profile', fn () => view('panel.profile'))->name('profile');
    Route::get('/orders', fn () => view('panel.orders'))->name('orders');
    Route::get('/services', fn () => view('panel.services'))->name('services');
    Route::get('/tickets', fn () => view('panel.tickets'))->name('tickets');

    Route::post('/logout', function () {
        auth()->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return redirect('/');
    })->name('logout');
});
