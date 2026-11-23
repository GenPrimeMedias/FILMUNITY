<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_governance_policies', function (Blueprint $table): void {
            $table->boolean('allow_external_appointees')->default(false)->after('allow_proxy');
        });
    }

    public function down(): void
    {
        Schema::table('association_governance_policies', function (Blueprint $table): void {
            $table->dropColumn('allow_external_appointees');
        });
    }
};
