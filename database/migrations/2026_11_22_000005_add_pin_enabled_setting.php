<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_workspace_protection', function (Blueprint $table): void {
            $table->boolean('pin_enabled')->default(false)->after('pin_hash');
        });
        DB::table('association_workspace_protection')->whereNotNull('pin_hash')->update(['pin_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('association_workspace_protection', function (Blueprint $table): void {
            $table->dropColumn('pin_enabled');
        });
    }
};
