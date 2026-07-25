<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\CentralPayController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NowPaymentsController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PlansController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RenewalController;
use App\Http\Controllers\RepresentativeController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\ServiceAddonController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\TutorialsController;
use App\Http\Controllers\UserServiceActionController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

// NOWPayments IPN webhook — no auth, no CSRF (verified by HMAC-SHA512 signature)
Route::post('/webhooks/nowpayments', [NowPaymentsController::class, 'ipn'])
    ->middleware('noindex')
    ->name('webhooks.nowpayments');

// CentralPay return URL — GET, no CSRF, user is redirected back after payment
// Always verify server-to-server inside the callback before trusting the outcome
Route::get('/payments/centralpay/callback', [CentralPayController::class, 'callback'])
    ->middleware('noindex')
    ->name('payments.centralpay.callback');

// Telegram admin-bot webhook — no auth, no CSRF (verified by the secret-token
// header inside the controller; only allowed admins in the management group).
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->middleware('noindex')
    ->name('telegram.webhook');

// Public health probes are registered STATELESS in routes/health.php (outside
// the `web` group) via bootstrap/app.php, so they never start a session or set
// session/XSRF cookies. See routes/health.php.

// ── SEO: XML sitemaps + robots.txt ───────────────────────────────────────────
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/sitemap-pages.xml', [SitemapController::class, 'pages'])->name('sitemap.pages');
Route::get('/sitemap-tutorials.xml', [SitemapController::class, 'tutorials'])->name('sitemap.tutorials');
Route::get('/robots.txt', RobotsController::class)->name('robots');

// Public pages
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/plans', [PlansController::class, 'index'])->name('plans');
Route::get('/faq', [FaqController::class, 'index'])->name('faq');
Route::get('/tutorials', [TutorialsController::class, 'index'])->name('tutorials');
Route::get('/tutorials/{slug}', [TutorialsController::class, 'show'])->name('tutorials.show');
Route::get('/status', [StatusController::class, 'index'])->name('status');
Route::get('/contact', [ContactController::class, 'index'])->name('contact');

// Pretty aliases for common static pages → CMS pages. PERMANENT (301): the
// canonical URLs are /pages/{slug}; the destinations are guaranteed to exist
// and be active by DefaultPagesSeeder (firstOrCreate — admin edits preserved).
Route::get('/terms', fn () => redirect()->route('pages.show', 'terms', 301));
Route::get('/privacy', fn () => redirect()->route('pages.show', 'privacy', 301));
Route::get('/about', fn () => redirect()->route('pages.show', 'about', 301));

// CMS static pages (keep last so it doesn't shadow named routes above)
Route::get('/pages/{slug}', [PageController::class, 'show'])->name('pages.show');

// Authentication — guest-only. noindex: login/register must never be indexed.
Route::middleware(['noindex', 'guest'])->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    // Per-username+IP brute-force protection is enforced inside the controller.
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    // Throttle registration to curb automated/spam sign-ups (per IP).
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
});

// Logout (any authenticated user)
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ── Email OTP verification ───────────────────────────────────────────────────
// auth + noindex; NEVER behind email.verified (no redirect loops). The GET
// notice page never sends a code — sending is an explicit rate-limited POST.
Route::middleware(['noindex', 'auth'])->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');
    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:email-verification-verify')
        ->name('verification.verify');
    Route::post('/email/verification/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:email-verification-resend')
        ->name('verification.resend');
    Route::patch('/email/verification/change-address', [EmailVerificationController::class, 'changeAddress'])
        ->middleware('throttle:email-verification-change')
        ->name('verification.change');
});

// Theme / appearance preference — available to guests (cookie) and users (saved)
Route::post('/theme', [ThemeController::class, 'update'])->name('theme.update');

// Buy flow — POST to prevent accidental double-submit on page reload.
// Server-side idempotency (purchase intent + fingerprint) is the real guarantee;
// the throttle blunts abusive bursts of submissions.
Route::post('/plans/{plan}/buy', [CheckoutController::class, 'buy'])
    ->middleware(['auth', 'email.verified', 'profile.complete', 'throttle:purchase-submit'])
    ->name('plans.buy');

// User dashboard (prefix: /dashboard, name: dashboard.*)
// noindex: all private user pages return X-Robots-Tag: noindex, nofollow, noarchive.
// email.verified guards the whole dashboard (orders/payments, services,
// renewals/add-ons, wallet + top-up, representative, tickets, profile) —
// enforcing the onboarding order: email first, then phone/profile completion.
Route::middleware(['noindex', 'auth', 'email.verified'])->prefix('dashboard')->name('dashboard.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('index');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/discount', [OrderController::class, 'applyDiscount'])->name('orders.discount.apply');
    Route::delete('/orders/{order}/discount', [OrderController::class, 'removeDiscount'])->name('orders.discount.remove');
    Route::get('/orders/{order}/pay', [PaymentController::class, 'show'])->name('orders.pay');
    Route::post('/orders/{order}/pay', [PaymentController::class, 'submit'])
        ->middleware('throttle:10,1')
        ->name('orders.pay.submit');
    Route::get('/orders/{order}/nowpayments', [NowPaymentsController::class, 'show'])->name('orders.nowpayments');
    Route::post('/orders/{order}/nowpayments/check', [NowPaymentsController::class, 'checkStatus'])->name('orders.nowpayments.check');
    Route::get('/services', [ServiceController::class, 'index'])->name('services');
    Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::middleware('profile.complete')->group(function () {
        Route::get('/services/{service}/renew', [RenewalController::class, 'show'])->middleware('throttle:purchase-intent')->name('services.renew');
        Route::post('/services/{service}/renew', [RenewalController::class, 'submit'])->middleware('throttle:purchase-submit')->name('services.renew.submit');
        Route::get('/services/{service}/extra-traffic', [ServiceAddonController::class, 'showTraffic'])->middleware('throttle:purchase-intent')->name('services.extra-traffic');
        Route::post('/services/{service}/extra-traffic', [ServiceAddonController::class, 'submitTraffic'])->middleware('throttle:purchase-submit')->name('services.extra-traffic.submit');
        Route::get('/services/{service}/extra-time', [ServiceAddonController::class, 'showTime'])->middleware('throttle:purchase-intent')->name('services.extra-time');
        Route::post('/services/{service}/extra-time', [ServiceAddonController::class, 'submitTime'])->middleware('throttle:purchase-submit')->name('services.extra-time.submit');
    });

    // Marzban self-service actions — throttled to prevent abuse
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/services/{service}/refresh', [ServiceController::class, 'refresh'])
            ->name('services.refresh');
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

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // Representative / referral dashboard
    Route::get('/representative', [RepresentativeController::class, 'index'])->name('representative');
    Route::post('/representative/request', [RepresentativeController::class, 'requestAccess'])->name('representative.request');

    // Support tickets
    Route::get('/tickets', [SupportTicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/create', [SupportTicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [SupportTicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets/{ticket}', [SupportTicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/reply', [SupportTicketController::class, 'reply'])->name('tickets.reply');
    Route::post('/tickets/{ticket}/close', [SupportTicketController::class, 'close'])->name('tickets.close');

    Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
    Route::get('/profile/complete', [ProfileController::class, 'complete'])->name('profile.complete');
    Route::post('/profile/phone', [ProfileController::class, 'savePhone'])->name('profile.phone.save');
    Route::post('/profile/phone/send-otp', [ProfileController::class, 'sendOtp'])
        ->middleware('throttle:5,1')
        ->name('profile.phone.send-otp');
    Route::post('/profile/phone/verify', [ProfileController::class, 'verifyPhone'])->name('profile.phone.verify');

    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet');
    Route::middleware('profile.complete')->group(function () {
        Route::get('/wallet/topup', [WalletController::class, 'topupForm'])->name('wallet.topup');
        Route::post('/wallet/topup', [WalletController::class, 'processTopup'])
            ->middleware('throttle:10,1')
            ->name('wallet.topup.submit');
    });
});

// Legacy /panel redirects → /dashboard
Route::middleware(['noindex', 'auth'])->prefix('panel')->group(function () {
    Route::redirect('/', '/dashboard', 301);
    Route::redirect('/orders', '/dashboard/orders', 301);
    Route::redirect('/services', '/dashboard/services', 301);
    Route::redirect('/profile', '/dashboard/profile', 301);
    Route::redirect('/tickets', '/dashboard', 301);
});
