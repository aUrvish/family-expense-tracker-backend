<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Middleware\IdentifyFamily;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)
    ->group(function () {
        Route::post('/register', 'register')->name('register');
        Route::post('/login', 'login')->name('login');
        Route::post('/forgot-password', 'forgotPassword')->name('password.email');
        Route::post('/reset-password', 'resetPassword')->name('password.reset');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', 'logout')->name('logout');
            Route::get('/user', 'user')->name('user');
        });
    });

Route::get('/categories', [CategoryController::class, 'index'])->middleware('auth:sanctum')->name('categories.index');

Route::middleware(['auth:sanctum', IdentifyFamily::class])->group(function () {
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('/transaction/save', [TransactionController::class, 'save'])->name('transactions.save');
    Route::get('/transaction/{id}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::delete('/transaction/{id}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
});
