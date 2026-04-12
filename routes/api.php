<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductBatchController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {

    Route::post('/login', [AuthController::class, 'login']);

    Route::post('/password/email', [ForgotPasswordController::class, 'sendResetLinkEmail']);

    Route::get('/check-first-owner', [UserController::class, 'checkFirstOwner']);

    Route::post('/register-first-user', [UserController::class, 'registerFirstUser']);

});

Route::middleware(['auth:sanctum', 'check.active', 'update.lastseen'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh-token', [AuthController::class, 'refresh']);

    Route::middleware('check.single.session')->group(function () {

        # Notifikasi
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread', [NotificationController::class, 'checkUnread']);
        Route::patch('/notifications/mark-multiple-read', [NotificationController::class, 'markMultipleAsRead']);
        Route::patch('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereNumber('id');
        Route::post('/fcm-token', [NotificationController::class, 'storeToken']);

        Route::get('/products/stock-alerts', [ProductController::class, 'getStockAlerts']);

        Route::middleware('check.role:owner,admin,cashier')->group(function () {

            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id');
            Route::get('/transactions', [TransactionController::class, 'index']);
            Route::get('/transactions/{id}', [TransactionController::class, 'show'])->whereNumber('id');
            Route::get('/units', [ProductController::class, 'indexUnit']);
            Route::get('/categories', [ProductController::class, 'indexCategory']);

        });

        Route::middleware('check.role:admin,cashier')->group(function () {

            Route::post('/transactions/{id}/cancel', [TransactionController::class, 'cancel'])->whereNumber('id');

        });

        Route::middleware('check.role:admin,owner')->group(function () {

            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
            Route::put('/users/{id}', [UserController::class, 'update'])->whereNumber('id');
            Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereNumber('id');
            Route::put('/users/{id}/status', [UserController::class, 'updateStatus'])->whereNumber('id');
            Route::get('/users/{id}/logs', [UserController::class, 'logs'])->whereNumber('id');

            Route::get('/cashiers', [TransactionController::class, 'indexCashier']);

            Route::get('/transactions/export', [TransactionController::class, 'export']);

            Route::put('/profile/update', [UserController::class, 'updateProfile']);
            Route::get('/profile/logs', [UserController::class, 'myLogs']);

        });

        Route::middleware('check.role:cashier')->group(function () {

            Route::get('/cashier/dashboard', [DashboardController::class, 'indexCashier']);

            Route::post('/transactions', [TransactionController::class, 'store']);

            Route::post('/products/validate-cart', [ProductController::class, 'validateCart']);
            Route::get('/products/{id}/best-batch', [ProductBatchController::class, 'getBestBatch'])->whereNumber('id');

        });

        Route::middleware('check.role:admin')->group(function () {

            # Dashboard
            Route::get('/admin/dashboard', [DashboardController::class, 'indexAdmin']);

            # Products
            Route::put('/products/{id}', [ProductController::class, 'update'])->whereNumber('id');
            Route::delete('/products/{id}', [ProductController::class, 'destroy'])->whereNumber('id');
            Route::post('/products/{id}/restore', [ProductController::class, 'restore']);
            Route::post('/create/product', [ProductController::class, 'store']);
            Route::get('/products/check-name', [ProductController::class, 'checkName']);

            # CRUD Kategori
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{id}', [CategoryController::class, 'update'])->whereNumber('id');
            Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->whereNumber('id');

            # CRUD Unit (Jenis Satuan)
            Route::post('/units', [UnitController::class, 'store']);
            Route::put('/units/{id}', [UnitController::class, 'update'])->whereNumber('id');
            Route::delete('/units/{id}', [UnitController::class, 'destroy'])->whereNumber('id');

            # CRUD Batch produk
            Route::put('/batches/{id}', [ProductBatchController::class, 'update'])->whereNumber('id');
            Route::delete('/batches/{id}', [ProductBatchController::class, 'destroy'])->whereNumber('id');

            # Transaksi
            Route::put('/transactions/{id}', [TransactionController::class, 'update'])->whereNumber('id');

        });

        Route::middleware('check.role:owner')->group(function () {

            Route::get('/owner/dashboard', [DashboardController::class, 'indexOwner']);

            Route::get('/logs', [LogController::class, 'index']);

        });
    });

});
