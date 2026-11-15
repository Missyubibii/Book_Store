<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status')->default('active')->after('password');
            $table->string('staff_access_status')->default('active')->after('account_status');

            $table->index('account_status');
            $table->index('staff_access_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_status']);
            $table->dropIndex(['staff_access_status']);
            $table->dropColumn(['account_status', 'staff_access_status']);
        });
    }
};

