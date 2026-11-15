<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tạo bảng `staff_activation_proposals` cho quy trình đề xuất và phê duyệt kích hoạt nhân sự.
     *
     * Ràng buộc CSDL bảo đảm người duyệt không thể là chính người được đề xuất kích hoạt,
     * đồng thời mỗi tài khoản chỉ có tối đa một đề xuất đang ở trạng thái `pending`.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('staff_activation_proposals', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->rawColumn('target_status', "varchar(20) check (target_status = 'active')")->default('active');
            $table->rawColumn('status', "varchar(20) check (status in ('pending', 'approved', 'rejected', 'cancelled'))")->default('pending');
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->text('proposal_reason');
            $table->timestamp('proposed_at')->useCurrent();
            if ($driver === 'sqlite') {
                $table->rawColumn('reviewed_by', 'integer check (reviewed_by is null or reviewed_by != user_id)')->nullable();
                $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            } else {
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'branch_id']);
        });

        $tableName = Schema::getConnection()->getTablePrefix().'staff_activation_proposals';

        if ($driver !== 'sqlite') {
            DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT staff_activation_proposals_no_self_review CHECK (reviewed_by IS NULL OR reviewed_by != user_id)");
        }

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement("CREATE UNIQUE INDEX staff_activation_proposals_one_pending_per_user ON {$tableName} (user_id) WHERE status = 'pending'");
        }
    }

    /**
     * Xóa chỉ mục duy nhất từng phần (nếu có) và bảng `staff_activation_proposals`.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS staff_activation_proposals_one_pending_per_user');
        }

        Schema::dropIfExists('staff_activation_proposals');
    }
};
