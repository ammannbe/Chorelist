<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schedule mode `dates`: the dates of the occurrences (e.g. waste collection).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('dates')->nullable()->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('dates');
        });
    }
};
