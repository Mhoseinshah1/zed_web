<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PlansController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TutorialsController;
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

// Authentication — guest-only (redirect to panel if already logged in)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

// Logout (any authenticated user)
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// User panel (requires authentication)
Route::middleware('auth')->prefix('panel')->name('panel.')->group(function () {
    Route::get('/', fn () => view('panel.dashboard'))->name('dashboard');
    Route::get('/profile', fn () => view('panel.profile'))->name('profile');
    Route::get('/orders', fn () => view('panel.orders'))->name('orders');
    Route::get('/services', fn () => view('panel.services'))->name('services');
    Route::get('/tickets', fn () => view('panel.tickets'))->name('tickets');
});
