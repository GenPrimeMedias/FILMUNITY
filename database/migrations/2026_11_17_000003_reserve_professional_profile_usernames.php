<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professional_profile_drafts', function (Blueprint $table): void {
            $table->string('username', 30)->nullable()->unique();
        });

        DB::statement(<<<'SQL'
            WITH candidates AS (
                SELECT user_id, regexp_replace(lower(btrim(profile->>'username')), '^@', '') AS handle
                FROM professional_profile_drafts
            ), eligible AS (
                SELECT user_id, handle, count(*) OVER (PARTITION BY handle) AS claim_count
                FROM candidates
                WHERE handle ~ '^[a-z0-9_]{3,30}$'
            )
            UPDATE professional_profile_drafts AS drafts
            SET username = eligible.handle,
                profile = jsonb_set(drafts.profile, '{username}', to_jsonb(eligible.handle))
            FROM eligible
            WHERE drafts.user_id = eligible.user_id AND eligible.claim_count = 1
            SQL);
    }

    public function down(): void
    {
        Schema::table('professional_profile_drafts', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
