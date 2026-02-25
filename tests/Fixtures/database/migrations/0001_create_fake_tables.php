<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng fixture cho tests:
 *   - fake_users: user model đơn giản với 1 role
 *   - fake_leave_requests: model workflowable test
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fake_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->default('Test User');
            $table->string('role')->nullable();
            $table->string('console_organization_id')->nullable();
            $table->string('password')->default('hashed-password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('fake_leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title')->default('Test Leave');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_leave_requests');
        Schema::dropIfExists('fake_users');
    }
};
