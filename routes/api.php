<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductBatchController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {

    Route::post('/login', [AuthController::class, 'login']);

});

Route::middleware(['auth:sanctum', 'check.active'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('check.role:admin,cashier')->group(function () {

        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id');
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/{id}', [TransactionController::class, 'show'])->whereNumber('id');
        Route::get('/units', [ProductController::class, 'indexUnit']);
        Route::get('/categories', [ProductController::class, 'indexCategory']);

    });

    Route::middleware('check.role:admin,owner')->group(function () {

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
        Route::put('/users/{id}', [UserController::class, 'update'])->whereNumber('id');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereNumber('id');
        Route::put('/users/{id}/status', [UserController::class, 'updateStatus'])->whereNumber('id');
        Route::get('/users/{id}/logs', [UserController::class, 'logs'])->whereNumber('id');

        Route::put('/profile/update', [UserController::class, 'updateProfile']);
        Route::get('/profile/logs', [UserController::class, 'myLogs']);

    });
    
    Route::middleware('check.role:cashier')->group(function () {

        Route::post('/transactions', [TransactionController::class, 'store']);

    });
        
    Route::middleware('check.role:admin')->group(function () {
            
        # Products
        Route::put('/products/{id}', [ProductController::class, 'update'])->whereNumber('id');
        Route::delete('/products/{id}', [ProductController::class, 'destroy'])->whereNumber('id');
        Route::post('/create/product', [ProductController::class, 'store']);

        // CRUD Kategori
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{id}', [CategoryController::class, 'update'])->whereNumber('id');
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->whereNumber('id');

        // CRUD Unit (Jenis Satuan)
        Route::post('/units', [UnitController::class, 'store']);
        Route::put('/units/{id}', [UnitController::class, 'update'])->whereNumber('id');
        Route::delete('/units/{id}', [UnitController::class, 'destroy'])->whereNumber('id');

        // CRUD Batch produk
        Route::put('/batches/{id}', [ProductBatchController::class, 'update'])->whereNumber('id');
        Route::delete('/batches/{id}', [ProductBatchController::class, 'destroy'])->whereNumber('id');

        // Transaksi
        Route::get('/cashiers', [TransactionController::class, 'indexCashier']);
        Route::get('/transactions/export', [TransactionController::class, 'export']);
        Route::put('/transactions/{id}', [TransactionController::class, 'update'])->whereNumber('id');
        Route::post('/transactions/{id}/cancel', [TransactionController::class, 'cancel'])->whereNumber('id');

    });
    
});
