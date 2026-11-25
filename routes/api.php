<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\FamilyInvitationController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TrashController;
use App\Http\Middleware\IdentifyFamily;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)
    ->group(function () {
        Route::post('/register', 'register')->middleware('throttle:auth-register')->name('register');
        Route::post('/login', 'login')->middleware('throttle:auth-login')->name('login');
        Route::post('/forgot-password', 'forgotPassword')->middleware('throttle:auth-forgot-password')->name('password.email');
        Route::post('/reset-password', 'resetPassword')->middleware('throttle:auth-reset-password')->name('password.reset');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', 'logout')->name('logout');
            Route::post('/change-password', 'changePassword')->name('password.change');
            Route::get('/user', 'user')->name('user');
        });
    });

Route::middleware(['auth:sanctum', IdentifyFamily::class])->group(function () {
    Route::controller(TransactionController::class)->group(function () {
        Route::get('/transactions', 'index')->name('transactions.index');

        Route::prefix('transaction')->group(function () {
            Route::get('/', 'index');
            Route::post('/save', 'save')->name('transactions.save');
            Route::get('/{id}', 'show')->name('transactions.show');
            Route::delete('/{id}', 'destroy')->name('transactions.destroy');
            Route::post('/{id}/restore', 'restore')->name('transactions.restore');
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
            Route::post('/{id}/restore', 'restore')->name('debts.restore');
        });
    });

    Route::controller(CategoryController::class)->prefix('category')->group(function () {
        Route::get('/', 'index')->name('categories.index');
        Route::post('/save', 'save')->name('categories.save');
        Route::get('/{id}', 'show')->name('categories.show');
        Route::delete('/{id}', 'destroy')->name('categories.destroy');
        Route::post('/{id}/restore', 'restore')->name('categories.restore');
    });

    Route::controller(RecurringTransactionController::class)->prefix('recurring-transaction')->group(function () {
        Route::get('/', 'index')->name('recurring.index');
        Route::post('/save', 'save')->name('recurring.save');
        Route::get('/{id}', 'show')->name('recurring.show');
        Route::delete('/{id}', 'destroy')->name('recurring.destroy');
        Route::post('/{id}/restore', 'restore')->name('recurring.restore');
    });

    Route::controller(TrashController::class)->group(function () {
        Route::get('/trash', 'index')->name('trash.index');
        Route::delete('/trash/empty', 'emptyTrash')->name('trash.empty');
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/family/members', [FamilyController::class, 'members'])->name('family.members');
    Route::get('/family/members/{id}', [FamilyController::class, 'show'])->name('family.members.show');

    Route::post('/family/invitations', [FamilyInvitationController::class, 'store'])->name('family.invitations.store');

    Route::controller(ReportController::class)->group(function () {
        Route::get('/reports', 'index')->name('reports.index');
        Route::get('/reports/export', 'export')->name('reports.export');
    });

    Route::controller(BudgetController::class)->group(function () {
        Route::get('/budgets', 'index')->name('budgets.index');
        Route::prefix('budget')->group(function () {
            Route::post('/save', 'save')->name('budgets.save');
            Route::delete('/{id}', 'destroy')->name('budgets.destroy');
            Route::post('/{id}/restore', 'restore')->name('budgets.restore');
        });
    });
});
