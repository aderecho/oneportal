<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AdminMvpController;
use App\Http\Controllers\Api\AdvertisementController;
use App\Http\Controllers\Api\NewsPostController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\SamlLaunchController;
use App\Http\Controllers\SamlMetadataController;
use App\Http\Controllers\SamlSsoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});

Route::get('/csrf-token', function () {
    return response()->json([
        'status' => true,
        'message' => 'Success',
        'data' => ['token' => csrf_token()],
    ]);
});

Route::post('/login', [SessionController::class, 'store'])
    ->middleware('guest')
    ->name('login');

Route::post('/logout', [SessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->get('/api/dashboard', DashboardController::class);
Route::get('/api/advertisements/active', [AdvertisementController::class, 'active']);

Route::middleware('auth')->prefix('/api/admin')->group(function () {
    Route::get('/overview', [AdminMvpController::class, 'overview']);
    Route::get('/advertisements', [AdvertisementController::class, 'index']);
    Route::post('/advertisements', [AdvertisementController::class, 'store']);
    Route::post('/advertisements/{advertisement}', [AdvertisementController::class, 'update']);
    Route::post('/departments', [AdminMvpController::class, 'createDepartment']);
    Route::post('/users', [AdminMvpController::class, 'createUser']);
    Route::post('/service-providers', [AdminMvpController::class, 'createServiceProvider']);
    Route::post('/user-access', [AdminMvpController::class, 'assignAccess']);
});

Route::middleware('auth')->post('/api/news-posts', [NewsPostController::class, 'store']);
Route::middleware('auth')->post('/api/notifications/{notification}/read', [NotificationController::class, 'markRead']);

Route::get('/sso/{slug}', SamlLaunchController::class)->name('saml.launch');
Route::get('/saml2/metadata', SamlMetadataController::class);
Route::get('/saml2/sso', SamlSsoController::class)->name('saml.sso');
