<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SocialAccountController;
use App\Http\Controllers\Api\VkIntegrationController;
use App\Http\Controllers\Api\VkWebhookController;
use App\Http\Controllers\Api\WorkingHourController;
use App\Http\Controllers\Api\YooKassaWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/vk', VkWebhookController::class);
Route::post('/webhooks/yookassa', YooKassaWebhookController::class);

Route::get('/oauth/vk/callback', [OAuthController::class, 'vkCallback']);
Route::get('/oauth/yandex/callback', [OAuthController::class, 'yandexCallback']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/oauth/vk/start', [OAuthController::class, 'vkStart']);
Route::get('/oauth/yandex/start', [OAuthController::class, 'yandexStart']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/oauth/vk/link/start', [OAuthController::class, 'vkStart']);
    Route::get('/oauth/yandex/link/start', [OAuthController::class, 'yandexStart']);

    Route::patch('/profile', [ProfileController::class, 'update']);

    Route::get('/social-accounts', [SocialAccountController::class, 'index']);
    Route::delete('/social-accounts/{socialAccount}', [SocialAccountController::class, 'destroy']);

    Route::get('/vk/group', [VkIntegrationController::class, 'showGroup']);
    Route::post('/vk/group', [VkIntegrationController::class, 'storeGroup']);
    Route::post('/vk/group/detach', [VkIntegrationController::class, 'destroyGroup']);
    Route::delete('/vk/group', [VkIntegrationController::class, 'destroyGroup']);

    Route::apiResource('services', ServiceController::class)->except(['show']);

    Route::get('/working-hours', [WorkingHourController::class, 'index']);
    Route::put('/working-hours', [WorkingHourController::class, 'sync']);

    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::patch('/appointments/{appointment}', [AppointmentController::class, 'update']);

    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    Route::patch('/activity-logs/{activityLog}/read', [ActivityLogController::class, 'markRead']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-snapshot', [NotificationController::class, 'unreadSnapshot']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::get('/billing/plans', [BillingController::class, 'plans']);
    Route::post('/billing/checkout', [BillingController::class, 'createCheckout']);
    Route::get('/billing/transactions', [BillingController::class, 'transactions']);
});
