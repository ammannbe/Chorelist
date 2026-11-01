<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->nullable()->after('password');
            $table->string('timezone', 64)->nullable()->after('locale');
            $table->json('preferences')->nullable()->after('timezone');
            // Secret of the calendar subscription URL (/ical/{token}.ics), null until requested.
            $table->string('ical_token', 64)->nullable()->unique()->after('preferences');
        });

        // Synced tables (App\Models\Concerns\Syncable): UUIDs from the client, timestamps in milliseconds.
        Schema::create('task_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null once the owner deleted their account (the list is soft deleted then, so members pull the deletion).
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('icon', 16)->nullable();
            $table->boolean('ranking_enabled')->default(true);
            // week, month, all
            $table->string('ranking_period', 10)->default('month');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);
        });

        Schema::create('task_list_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Personal: order of the lists, no notifications of this list.
            $table->double('position')->nullable();
            $table->boolean('muted')->default(false);
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);
            $table->unique(['task_list_id', 'user_id']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->string('icon', 16)->nullable();
            $table->string('category', 100)->nullable();
            $table->unsignedTinyInteger('points')->default(1);
            // fixed (calendar, RRULE), after_completion (interval after the last completion), on_demand, once
            $table->string('mode', 20);
            // RFC 5545 RRULE without DTSTART (fixed and after_completion), e.g. FREQ=WEEKLY;BYDAY=MO
            $table->string('rrule')->nullable();
            // First occurrence (DTSTART); an occurrence lasts window_days more days (due = start + window_days).
            $table->date('starts_on');
            $table->unsignedSmallInteger('window_days')->default(0);
            $table->date('ends_on')->nullable();
            // Reminders in days before the due date (0 = on the due date).
            $table->json('reminders')->nullable();
            // On demand: remind when not done for this many days.
            $table->unsignedSmallInteger('remind_after_days')->nullable();
            $table->date('paused_until')->nullable();
            $table->boolean('archived')->default(false);
            // Steps [{id, text, done}], reset when the task is completed.
            $table->json('checklist')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);
            $table->index(['task_list_id', 'updated_at']);
        });

        Schema::create('completions', function (Blueprint $table) {
            // Completions of an occurrence have a deterministic id (UUID v5 of task and date),
            // so members completing the same occurrence offline end up with one document.
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_list_id')->constrained()->cascadeOnDelete();
            // Who gets the points, and who entered it (may complete for another member).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('entered_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Start date of the occurrence, null for extra and on demand completions.
            $table->date('occurrence')->nullable();
            $table->date('done_on');
            // done, extra, skipped, postponed, undone
            $table->string('type', 12);
            $table->date('postponed_to')->nullable();
            $table->unsignedTinyInteger('points')->default(0);
            $table->string('note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);
            $table->index(['task_list_id', 'updated_at']);
        });

        // History of changes ("who set up what"), written by the server, pulled only.
        Schema::create('activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_list_id')->constrained()->cascadeOnDelete();
            $table->uuid('task_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->json('data')->nullable();
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);
            $table->index(['task_list_id', 'updated_at']);
        });

        Schema::create('absences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('list_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('task_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        // Sent reminders, so every reminder goes out once (key: task, kind, occurrence, offset).
        Schema::create('reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 160);
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_deliveries');
        Schema::dropIfExists('list_invites');
        Schema::dropIfExists('absences');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('completions');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_list_user');
        Schema::dropIfExists('task_lists');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['locale', 'timezone', 'preferences', 'ical_token']));
    }
};
