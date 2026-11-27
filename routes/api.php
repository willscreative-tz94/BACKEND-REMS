<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\PropertyMediaController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\VerificationController;
use App\Http\Controllers\Api\ViewingController;
use App\Http\Controllers\Platform\OrganizationController as PlatformOrganizationController;
use App\Http\Controllers\Platform\SplashController;
use Illuminate\Support\Facades\Route;

/*
| REMS API. Every tenant route needs the X-Organization header (the agency
| slug); ResolveTenant checks membership and scopes every query to it.
*/

// ── Public: no sign-in ───────────────────────────────────────────────────
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
Route::post('auth/otp/resend', [AuthController::class, 'resendOtp'])->middleware('throttle:5,1');
Route::post('payments/mpesa/callback', [PaymentController::class, 'mpesaCallback']);
Route::get('app-config', [PublicController::class, 'config']);
Route::get('public/organizations', [PublicController::class, 'organizations']);

Route::middleware('tenant:public')->prefix('public')->group(function () {
    Route::get('brand', [PublicController::class, 'brand']);
    Route::get('properties', [PublicController::class, 'properties']);
    Route::get('properties/{slug}', [PublicController::class, 'property']);
    Route::post('properties/{property}/enquiry', [PublicController::class, 'enquiry'])->middleware('throttle:10,1');
});
Route::get('brand-settings', [PublicController::class, 'brand'])->middleware('tenant:public');

// ── Signed in ────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('organizations/{slug}/switch', [AuthController::class, 'switchOrganization']);

    // Works with or without a tenant (the user's agencies come back either way).
    Route::middleware('tenant:optional')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/profile', [AuthController::class, 'updateProfile']);
    });

    // Platform Console (REMS staff).
    Route::middleware('platform')->prefix('platform')->group(function () {
        Route::get('organizations', [PlatformOrganizationController::class, 'index']);
        Route::post('organizations', [PlatformOrganizationController::class, 'store']);
        Route::patch('organizations/{organization}', [PlatformOrganizationController::class, 'update']);
        Route::get('splash', [SplashController::class, 'show']);
        Route::post('splash', [SplashController::class, 'update']);
        Route::delete('splash/{asset}', [SplashController::class, 'reset'])->whereIn('asset', ['video', 'poster']);
    });

    // ── Inside an agency ─────────────────────────────────────────────────
    Route::middleware('tenant')->group(function () {
        Route::get('dashboard', DashboardController::class);

        Route::get('organization', [OrganizationController::class, 'show']);
        Route::put('organization', [OrganizationController::class, 'update']);
        Route::get('organization/brand', [OrganizationController::class, 'brand']);
        Route::post('organization/brand', [OrganizationController::class, 'updateBrand']);
        Route::get('audit-logs', [OrganizationController::class, 'auditLog']);

        Route::get('members', [MemberController::class, 'index']);
        Route::post('members', [MemberController::class, 'store']);
        Route::patch('members/{id}', [MemberController::class, 'update']);

        // Property listing, media, map, favourites
        Route::get('properties/map', [PropertyController::class, 'mapPoints']);
        Route::get('properties/cities', [PropertyController::class, 'cities']);
        Route::get('favorites', [PropertyController::class, 'favorites']);
        Route::post('properties/{property}/favorite', [PropertyController::class, 'toggleFavorite']);
        Route::post('properties/{property}/submit-verification', [PropertyController::class, 'submitForVerification']);
        Route::get('properties/{property}/availability', [ViewingController::class, 'availability']);
        Route::apiResource('properties', PropertyController::class);
        Route::post('properties/{property}/media', [PropertyMediaController::class, 'store']);
        Route::patch('properties/{property}/media/{media}', [PropertyMediaController::class, 'update']);
        Route::delete('properties/{property}/media/{media}', [PropertyMediaController::class, 'destroy']);

        // People
        Route::apiResource('agents', AgentController::class);
        Route::apiResource('customers', CustomerController::class);

        // Viewing / visit booking
        Route::get('viewings', [ViewingController::class, 'index']);
        Route::post('viewings', [ViewingController::class, 'store']);
        Route::patch('viewings/{viewing}', [ViewingController::class, 'update']);

        // Online sales & payments
        Route::get('sales', [SaleController::class, 'index']);
        Route::post('sales', [SaleController::class, 'store']);
        Route::get('sales/{sale}', [SaleController::class, 'show']);
        Route::patch('sales/{sale}', [SaleController::class, 'update']);
        Route::post('sales/{sale}/transition', [SaleController::class, 'transition']);

        Route::get('payments', [PaymentController::class, 'index']);
        Route::post('payments', [PaymentController::class, 'store']);
        Route::post('payments/initiate', [PaymentController::class, 'initiate']);
        Route::post('payments/{payment}/confirm', [PaymentController::class, 'confirm']);
        Route::post('payments/{payment}/refund', [PaymentController::class, 'refund']);
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt']);

        // Contracts & documentation
        Route::post('contracts/template', [ContractController::class, 'template']);
        Route::get('contracts', [ContractController::class, 'index']);
        Route::post('contracts', [ContractController::class, 'store']);
        Route::get('contracts/{contract}', [ContractController::class, 'show']);
        Route::patch('contracts/{contract}', [ContractController::class, 'update']);
        Route::post('contracts/{contract}/send', [ContractController::class, 'send']);
        Route::post('contracts/{contract}/sign', [ContractController::class, 'sign']);
        Route::get('contracts/{contract}/pdf', [ContractController::class, 'pdf']);

        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{document}/download', [DocumentController::class, 'download']);
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);

        // Customer-agent communication
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::post('conversations', [ConversationController::class, 'store']);
        Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'reply']);
        Route::post('conversations/{conversation}/toggle', [ConversationController::class, 'close']);

        // Commission management
        Route::get('commissions', [CommissionController::class, 'index']);
        Route::patch('commissions/{commission}', [CommissionController::class, 'update']);
        Route::post('commissions/{commission}/approve', [CommissionController::class, 'approve']);
        Route::post('commissions/{commission}/pay', [CommissionController::class, 'pay']);

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        // Reports & analytics
        Route::get('reports/overview', [ReportController::class, 'overview']);
        Route::get('reports/export/{report}', [ReportController::class, 'export']);

        // Admin & verifications
        Route::get('verifications', [VerificationController::class, 'index']);
        Route::post('verifications/{type}/{id}', [VerificationController::class, 'decide']);
    });
});
