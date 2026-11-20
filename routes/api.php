<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\ReportController;
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
    Route::controller(TransactionController::class)->group(function () {
        Route::get('/transactions', 'index')->name('transactions.index');

        Route::prefix('transaction')->group(function () {
            Route::get('/', 'index');
            Route::post('/save', 'save')->name('transactions.save');
            Route::get('/{id}', 'show')->name('transactions.show');
            Route::delete('/{id}', 'destroy')->name('transactions.destroy');
        });
    });

    Route::controller(DebtController::class)->group(function () {
        Route::get('/debts', 'index')->name('debts.index');

        Route::prefix('debt')->group(function () {
            Route::get('/', 'index');
            Route::post('/save', 'save')->name('debts.save');
            Route::get('/{id}', 'show')->name('debts.show');
            Route::post('/{id}/settle', 'settle')->name('debts.settle');
            Route::delete('/{id}', 'destroy')->name('debts.destroy');
        });
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/family/members', [FamilyController::class, 'members'])->name('family.members');
    Route::get('/family/members/{id}', [FamilyController::class, 'show'])->name('family.members.show');

    Route::controller(ReportController::class)->group(function () {
        Route::get('/reports', 'index')->name('reports.index');
        Route::get('/reports/export', 'export')->name('reports.export');
    });

    Route::controller(BudgetController::class)->group(function () {
        Route::get('/budgets', 'index')->name('budgets.index');
        Route::prefix('budget')->group(function () {
            Route::post('/save', 'save')->name('budgets.save');
            Route::delete('/{id}', 'destroy')->name('budgets.destroy');
        });
    });
});
