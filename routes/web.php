<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CentralWarehouseController;
use App\Http\Controllers\Admin\StaffApprovalController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Staff\MandatoryPasswordChangeController;
use App\Http\Controllers\Storefront\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - Book_Store E-commerce & Store Management
|--------------------------------------------------------------------------
*/

// Trang chủ công khai hiệu sách (Storefront Public Entry Point -> HomeController)
Route::get('/', HomeController::class)->name('home');

/*
|--------------------------------------------------------------------------
| Tuyến đường Xác thực Khách (Guest Authentication Routes)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    // Đăng ký tài khoản
    Route::get('/dang-ky', [RegisterController::class, 'create'])->name('register');
    Route::post('/dang-ky', [RegisterController::class, 'store']);

    // Đăng nhập
    Route::get('/dang-nhap', [LoginController::class, 'create'])->name('login');
    Route::post('/dang-nhap', [LoginController::class, 'store']);

    // Đăng nhập Google OAuth (Stage 01B.5.8)
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

/*
|--------------------------------------------------------------------------
| Tuyến đường Yêu cầu Đăng nhập (Authenticated Routes)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'account.active'])->group(function () {
    // Đăng xuất
    Route::post('/dang-xuat', [LogoutController::class, 'destroy'])->name('logout');

    // Xác thực địa chỉ email (không áp dụng 'verified' để tài khoản chưa xác thực có thể thao tác)
    Route::get('/xac-thuc-email', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');

    Route::get('/xac-thuc-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('/xac-thuc-email/gui-lai', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::post('/xac-thuc-email/demo', [EmailVerificationController::class, 'verifyDemo'])
        ->middleware('throttle:6,1')
        ->name('verification.demo');
});

/*
|--------------------------------------------------------------------------
| Tuyến đường Đổi Mật Khẩu Onboarding Nhân sự (Tách biệt khỏi `staff.active`)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'account.active', 'staff.onboarding'])
    ->prefix('nhan-su')
    ->name('staff.onboarding.')
    ->group(function () {
        Route::get('/doi-mat-khau-bat-buoc', [MandatoryPasswordChangeController::class, 'edit'])
            ->name('password.edit');

        Route::post('/doi-mat-khau-bat-buoc', [MandatoryPasswordChangeController::class, 'update'])
            ->middleware('throttle:6,1')
            ->name('password.update');
    });

/*
|--------------------------------------------------------------------------
| Điểm vào Quản trị Hệ thống Tối giản (Protected Admin Entry Point)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'account.active', 'staff.active', 'super_admin.effective'])
    ->group(function () {
        Route::get('/quan-tri', AdminDashboardController::class)
            ->name('admin.dashboard');
    });

/*
|--------------------------------------------------------------------------
| Quản trị Chi nhánh Bán lẻ & Kho Trung tâm Bất biến (Stage 01B.4)
|--------------------------------------------------------------------------
| Bảo vệ bởi `auth`, `account.active`, `staff.active` kết hợp `BranchPolicy` (`branches.*`).
*/
Route::middleware(['auth', 'account.active', 'staff.active'])
    ->prefix('quan-tri')
    ->name('admin.')
    ->group(function () {
        Route::get('/chi-nhanh', [BranchController::class, 'index'])
            ->name('branches.index');

        Route::get('/chi-nhanh/tao-moi', [BranchController::class, 'create'])
            ->name('branches.create');

        Route::post('/chi-nhanh', [BranchController::class, 'store'])
            ->name('branches.store');

        Route::get('/chi-nhanh/{branch}', [BranchController::class, 'show'])
            ->whereNumber('branch')
            ->name('branches.show');

        Route::get('/chi-nhanh/{branch}/nhat-ky-hoat-dong', [BranchController::class, 'auditLogs'])
            ->whereNumber('branch')
            ->name('branches.audit-logs');

        Route::patch('/chi-nhanh/{branch}', [BranchController::class, 'update'])
            ->whereNumber('branch')
            ->name('branches.update');

        Route::patch('/chi-nhanh/{branch}/trang-thai', [BranchController::class, 'updateStatus'])
            ->whereNumber('branch')
            ->name('branches.status.update');

        Route::get('/kho-trung-tam', CentralWarehouseController::class)
            ->name('central-warehouse.show');

        Route::get('/nhan-su', [StaffController::class, 'index'])
            ->name('staff.index');

        Route::get('/nhan-su/tao-moi', [StaffController::class, 'create'])
            ->name('staff.create');

        Route::post('/nhan-su', [StaffController::class, 'store'])
            ->name('staff.store');

        Route::get('/nhan-su/lien-ket', [StaffController::class, 'createLink'])
            ->name('staff.link.create');

        Route::post('/nhan-su/lien-ket', [StaffController::class, 'storeLink'])
            ->name('staff.link.store');

        Route::get('/nhan-su/phe-duyet', [StaffApprovalController::class, 'index'])
            ->name('staff.approvals.index');

        Route::post('/nhan-su/phe-duyet/{proposal}/duyet', [StaffApprovalController::class, 'approve'])
            ->whereNumber('proposal')
            ->name('staff.approvals.approve');

        Route::post('/nhan-su/phe-duyet/{proposal}/tu-choi', [StaffApprovalController::class, 'reject'])
            ->whereNumber('proposal')
            ->name('staff.approvals.reject');

        Route::get('/nhan-su/{staff}', [StaffController::class, 'show'])
            ->whereNumber('staff')
            ->name('staff.show');

        Route::get('/nhan-su/{staff}/nhat-ky-hoat-dong', [StaffController::class, 'auditLogs'])
            ->whereNumber('staff')
            ->name('staff.audit-logs');

        Route::post('/nhan-su/{staff}/phan-cong', [StaffController::class, 'upsertAssignment'])
            ->whereNumber('staff')
            ->name('staff.assignments.upsert');

        Route::delete('/nhan-su/{staff}/phan-cong/{branch}', [StaffController::class, 'deactivateAssignment'])
            ->whereNumber(['staff', 'branch'])
            ->name('staff.assignments.deactivate');

        Route::post('/nhan-su/{staff}/de-xuat-kich-hoat', [StaffController::class, 'submitProposal'])
            ->whereNumber('staff')
            ->name('staff.proposals.store');

        Route::post('/nhan-su/{staff}/kich-hoat-truc-tiep', [StaffController::class, 'activateDirect'])
            ->whereNumber('staff')
            ->name('staff.activate-direct');

        Route::patch('/nhan-su/{staff}/dinh-chi', [StaffController::class, 'suspend'])
            ->whereNumber('staff')
            ->name('staff.suspend');

        Route::patch('/nhan-su/{staff}/khoi-phuc', [StaffController::class, 'reactivate'])
            ->whereNumber('staff')
            ->name('staff.reactivate');

        Route::get('/nhat-ky-hoat-dong', [AuditLogController::class, 'index'])
            ->middleware('super_admin.effective')
            ->name('audit-logs.index');

        Route::get('/nhat-ky-hoat-dong/{auditLog}', [AuditLogController::class, 'show'])
            ->whereNumber('auditLog')
            ->middleware('super_admin.effective')
            ->name('audit-logs.show');
    });
