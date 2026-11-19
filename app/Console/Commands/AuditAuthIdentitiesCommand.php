<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lệnh kiểm toán chỉ đọc (read-only) đối soát tình trạng xác thực & định danh người dùng.
 *
 * Bảo mật:
 * - Tuyệt đối không thay đổi dữ liệu trong CSDL.
 * - Tuyệt đối không xuất mật khẩu, hash, token hoặc toàn bộ Google Subject ID.
 */
class AuditAuthIdentitiesCommand extends Command
{
    protected $signature = 'bookstore:audit-auth-identities';

    protected $description = 'Kiểm toán chỉ đọc phương thức đăng nhập, xác thực email và xung đột định danh (không chỉnh sửa CSDL).';

    public function handle(): int
    {
        $hasExternalTable = Schema::hasTable('user_external_identities');
        $users = $hasExternalTable
            ? User::query()->with('externalIdentities')->orderBy('id')->get()
            : User::query()->orderBy('id')->get();

        $passwordOnly = [];
        $googleOnly = [];
        $bothMethods = [];
        $noUsableMethod = [];
        $unverifiedEmails = [];
        $emailBuckets = [];

        foreach ($users as $user) {
            $hasPassword = $user->hasUsablePasswordAuthentication();
            $hasGoogle = $user->hasLinkedGoogleIdentity();

            if ($hasPassword && $hasGoogle) {
                $bothMethods[] = $user->id;
            } elseif ($hasGoogle) {
                $googleOnly[] = $user->id;
            } elseif ($hasPassword) {
                $passwordOnly[] = $user->id;
            } else {
                $noUsableMethod[] = $user->id;
            }

            if (! $user->hasVerifiedEmail()) {
                $unverifiedEmails[] = $user->id;
            }

            $normalizedEmail = Str::lower(trim((string) $user->email));
            $emailBuckets[$normalizedEmail][] = $user->id;
        }

        $duplicateEmailGroups = array_filter($emailBuckets, static fn (array $ids): bool => count($ids) > 1);

        $this->info('=== BÁO CÁO KIỂM TOÁN ĐỊNH DANH & XÁC THỰC (CHỈ ĐỌC) ===');
        $this->table(
            ['Hạng mục kiểm toán', 'Số lượng', 'Danh sách ID (ẩn bí mật)'],
            [
                ['Tổng số tài khoản', (string) $users->count(), '—'],
                ['Chỉ có Mật khẩu', (string) count($passwordOnly), implode(', ', $passwordOnly) ?: 'Không có'],
                ['Chỉ có Google OAuth', (string) count($googleOnly), implode(', ', $googleOnly) ?: 'Không có'],
                ['Cả Mật khẩu và Google', (string) count($bothMethods), implode(', ', $bothMethods) ?: 'Không có'],
                ['Không có phương thức hợp lệ', (string) count($noUsableMethod), implode(', ', $noUsableMethod) ?: 'Không có'],
                ['Email chưa xác thực', (string) count($unverifiedEmails), implode(', ', $unverifiedEmails) ?: 'Không có'],
                ['Nhóm email trùng lặp (không phân biệt hoa/thường)', (string) count($duplicateEmailGroups), count($duplicateEmailGroups) > 0 ? 'Phát hiện xung đột' : 'Không có'],
            ]
        );

        if (! $hasExternalTable) {
            $this->warn('Cảnh báo: Bảng user_external_identities chưa tồn tại trong CSDL hiện tại.');
        }

        return self::SUCCESS;
    }
}
