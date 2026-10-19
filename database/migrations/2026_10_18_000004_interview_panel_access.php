<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_stages', function (Blueprint $table) {
            $table->boolean('is_shortlist')->default(false)->after('is_rejected');
        });

        DB::table('candidate_stages')
            ->whereRaw("LOWER(REPLACE(name, ' ', '')) like 'shortlist%'")
            ->update(['is_shortlist' => true]);

        Schema::table('vacancy_panel_members', function (Blueprint $table) {
            $table->string('member_type', 16)->default('external')->after('email');
            $table->uuid('employee_id')->nullable()->after('member_type');
            $table->string('access_token', 80)->nullable()->unique()->after('invite_code_expires_at');
            $table->uuid('recommended_application_id')->nullable()->after('status');
        });

        $body = '<p>Hello {{recipient_name}},</p>'
            .'<p>You have been invited to the interview panel for <strong>{{vacancy_title}}</strong> at {{company_name}}.</p>'
            .'<p><a href="{{score_url}}">Open your score sheet</a>. This link is only for you.</p>'
            .'<p>Enter this code to confirm it is you:</p>'
            .'<p style="font-size:22px;letter-spacing:3px;"><strong>{{invite_code}}</strong></p>'
            .'<p>{{panel_instructions}}</p>';

        DB::table('hr_templates')
            ->where('system_key', 'email.panel_invite')
            ->where(function ($query) {
                $query->where('body', 'like', '%Have an invite code%')
                    ->orWhere('body', 'not like', '%{{score_url}}%');
            })
            ->update([
                'body' => $body,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('vacancy_panel_members', function (Blueprint $table) {
            $table->dropUnique(['access_token']);
            $table->dropColumn(['member_type', 'employee_id', 'access_token', 'recommended_application_id']);
        });

        Schema::table('candidate_stages', function (Blueprint $table) {
            $table->dropColumn('is_shortlist');
        });
    }
};
