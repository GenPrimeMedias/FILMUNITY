<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_profile_drafts', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->jsonb('profile');
            $table->unsignedSmallInteger('wizard_step');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_profile_drafts');
    }
};
