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
            $table->string('follow_up_action', 20)->nullable()->after('follow_up_attempts');
        });

        DB::table('ksef_invoice_submissions')
            ->whereIn('status', ['submitted', 'processing'])
            ->update(['follow_up_action' => 'status']);

        DB::table('ksef_invoice_submissions')
            ->where('status', 'uncertain')
            ->update(['follow_up_action' => 'reconcile']);

        DB::table('ksef_invoice_submissions')
            ->where('status', 'accepted')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('ksef_invoice_upos')
                    ->whereColumn(
                        'ksef_invoice_upos.ksef_invoice_submission_id',
                        'ksef_invoice_submissions.id',
                    );
            })
            ->update([
                'follow_up_action' => 'upo',
                'follow_up_attempts' => 0,
            ]);
    }

    public function down(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->dropColumn('follow_up_action');
        });
    }
};
