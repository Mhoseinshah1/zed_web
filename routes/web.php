<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\CentralPayController;
use App\Http\Controllers\NowPaymentsController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PlansController;
use App\Http\Controllers\RenewalController;
use App\Http\Controllers\ServiceAddonController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TutorialsController;
use App\Http\Controllers\UserServiceActionController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

// NOWPayments IPN webhook — no auth, no CSRF (verified by HMAC-SHA512 signature)
Route::post('/webhooks/nowpayments', [NowPaymentsController::class, 'ipn'])
    ->name('webhooks.nowpayments');

// CentralPay return URL — GET, no CSRF, user is redirected back after payment
// Always verify server-to-server inside the callback before trusting the outcome
Route::get('/payments/centralpay/callback', [CentralPayController::class, 'callback'])
    ->name('payments.centralpay.callback');

// Health check (unauthenticated)
Route::get('/health', [HealthController::class, 'check'])->name('health');

// Public pages
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/plans', [PlansController::class, 'index'])->name('plans');
Route::get('/faq', [FaqController::class, 'index'])->name('faq');
Route::get('/tutorials', [TutorialsController::class, 'index'])->name('tutorials');
Route::get('/status', [StatusController::class, 'index'])->name('status');
Route::get('/contact', [ContactController::class, 'index'])->name('contact');

// Authentication — guest-only
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

// Logout (any authenticated user)
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Buy flow — POST to prevent accidental double-submit on page reload
Route::post('/plans/{plan}/buy', [CheckoutController::class, 'buy'])
    ->middleware('auth')
    ->name('plans.buy');

// User dashboard (prefix: /dashboard, name: dashboard.*)
Route::middleware('auth')->prefix('dashboard')->name('dashboard.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('index');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/discount', [OrderController::class, 'applyDiscount'])->name('orders.discount.apply');
    Route::delete('/orders/{order}/discount', [OrderController::class, 'removeDiscount'])->name('orders.discount.remove');
    Route::get('/orders/{order}/pay', [PaymentController::class, 'show'])->name('orders.pay');
    Route::post('/orders/{order}/pay', [PaymentController::class, 'submit'])->name('orders.pay.submit');
    Route::get('/orders/{order}/nowpayments', [NowPaymentsController::class, 'show'])->name('orders.nowpayments');
    Route::post('/orders/{order}/nowpayments/check', [NowPaymentsController::class, 'checkStatus'])->name('orders.nowpayments.check');
    Route::get('/services', [ServiceController::class, 'index'])->name('services');
    Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::get('/services/{service}/renew', [RenewalController::class, 'show'])->name('services.renew');
    Route::post('/services/{service}/renew', [RenewalController::class, 'submit'])->name('services.renew.submit');
    Route::get('/services/{service}/extra-traffic', [ServiceAddonController::class, 'showTraffic'])->name('services.extra-traffic');
    Route::post('/services/{service}/extra-traffic', [ServiceAddonController::class, 'submitTraffic'])->name('services.extra-traffic.submit');
    Route::get('/services/{service}/extra-time', [ServiceAddonController::class, 'showTime'])->name('services.extra-time');
    Route::post('/services/{service}/extra-time', [ServiceAddonController::class, 'submitTime'])->name('services.extra-time.submit');

    // Marzban self-service actions — throttled to prevent abuse
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/services/{service}/sync', [UserServiceActionController::class, 'sync'])
            ->name('services.sync');
        Route::post('/services/{service}/revoke-subscription', [UserServiceActionController::class, 'revokeSubscription'])
            ->name('services.revoke-subscription');
        Route::post('/services/{service}/reset-traffic', [UserServiceActionController::class, 'resetTraffic'])
            ->name('services.reset-traffic');
        Route::post('/services/{service}/disable', [UserServiceActionController::class, 'disable'])
            ->name('services.disable');
        Route::post('/services/{service}/enable', [UserServiceActionController::class, 'enable'])
            ->name('services.enable');
    });

    Route::get('/profile', fn () => view('dashboard.profile', ['user' => auth()->user()]))->name('profile');
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet');
    Route::get('/wallet/topup', [WalletController::class, 'topupForm'])->name('wallet.topup');
    Route::post('/wallet/topup', [WalletController::class, 'processTopup'])->name('wallet.topup.submit');
});

// Legacy /panel redirects → /dashboard
Route::middleware('auth')->prefix('panel')->group(function () {
    Route::redirect('/', '/dashboard', 301);
    Route::redirect('/orders', '/dashboard/orders', 301);
    Route::redirect('/services', '/dashboard/services', 301);
    Route::redirect('/profile', '/dashboard/profile', 301);
    Route::redirect('/tickets', '/dashboard', 301);
});
