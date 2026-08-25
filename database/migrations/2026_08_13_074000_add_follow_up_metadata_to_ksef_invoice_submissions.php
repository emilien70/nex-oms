<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->timestamp('next_follow_up_at')->nullable()->after('last_checked_at');
            $table->unsignedInteger('follow_up_attempts')->default(0)->after('next_follow_up_at');
            $table->timestamp('last_follow_up_at')->nullable()->after('follow_up_attempts');
            $table->string('last_follow_up_error_code', 120)->nullable()->after('last_follow_up_at');
            $table->text('last_follow_up_error_message')->nullable()->after('last_follow_up_error_code');
            $table->index(
                ['status', 'next_follow_up_at'],
                'ksef_invoice_submission_follow_up_due_index',
            );
        });

        DB::table('ksef_invoice_submissions')
            ->whereIn('status', ['submitted', 'processing', 'uncertain'])
            ->whereNull('next_follow_up_at')
            ->update(['next_follow_up_at' => now()]);

        DB::table('ksef_invoice_submissions')
            ->where('status', 'accepted')
            ->whereNull('next_follow_up_at')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('ksef_invoice_upos')
                    ->whereColumn(
                        'ksef_invoice_upos.ksef_invoice_submission_id',
                        'ksef_invoice_submissions.id',
                    );
            })
            ->update(['next_follow_up_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->dropIndex('ksef_invoice_submission_follow_up_due_index');
            $table->dropColumn([
                'next_follow_up_at',
                'follow_up_attempts',
                'last_follow_up_at',
                'last_follow_up_error_code',
                'last_follow_up_error_message',
            ]);
        });
    }
};
