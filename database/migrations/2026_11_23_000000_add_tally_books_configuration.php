<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('association_finance_settings', function (Blueprint $t): void { $t->date('financial_year_from')->nullable(); $t->date('financial_year_to')->nullable(); $t->date('books_beginning_from')->nullable(); $t->string('voucher_numbering_mode', 20)->default('automatic'); });
        Schema::table('association_finance_accounts', function (Blueprint $t): void { $t->bigInteger('opening_balance_minor')->default(0); $t->string('opening_side', 2)->default('DR'); });
    }
    public function down(): void { Schema::table('association_finance_accounts', fn (Blueprint $t) => $t->dropColumn(['opening_balance_minor', 'opening_side'])); Schema::table('association_finance_settings', fn (Blueprint $t) => $t->dropColumn(['financial_year_from', 'financial_year_to', 'books_beginning_from', 'voucher_numbering_mode'])); }
};
