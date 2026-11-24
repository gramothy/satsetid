<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table): void {
            if (! Schema::hasColumn('queue_tickets', 'two_ahead_notified_at')) {
                $table->timestamp('two_ahead_notified_at')->nullable()->after('three_ahead_notified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->dropColumn('two_ahead_notified_at');
        });
    }
};
