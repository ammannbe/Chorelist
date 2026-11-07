<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Set once the household is mirrored from the AccountManager (HOUSEHOLDS_DRIVER=konto).
            $table->uuid('konto_household_uuid')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('household_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['admin', 'member'])->default('member');
            $table->timestamp('joined_at')->nullable();
            $table->unique(['household_id', 'user_id']);
        });

        // Only the SHA-256 of the token is stored.
        Schema::create('household_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->enum('role', ['admin', 'member'])->default('member');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index('email');
        });

        // Lists shared with a household: every member has the same access as an individual list member.
        Schema::create('task_list_household', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('task_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['task_list_id', 'household_id']);
        });

        // Access through a household is kept as membership too (personal order, muting, sync),
        // false: the user is not a member on their own, only through a household.
        Schema::table('task_list_user', function (Blueprint $table) {
            $table->boolean('direct')->default(true)->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_list_user', function (Blueprint $table) {
            $table->dropColumn('direct');
        });
        Schema::dropIfExists('task_list_household');
        Schema::dropIfExists('household_invitations');
        Schema::dropIfExists('household_user');
        Schema::dropIfExists('households');
    }
};
