<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Set-based PostgreSQL updates are also logged correctly by migrate --pretend.
        DB::statement(<<<'SQL'
            UPDATE association_memberships AS membership
            SET starts_at = (receipt.snapshot->>'starts_at')::timestamptz,
                ends_at = NULLIF(receipt.snapshot->>'ends_at', '')::timestamptz
            FROM association_payment_receipts AS receipt
            WHERE membership.id = receipt.membership_id
                AND receipt.snapshot->>'starts_at' IS NOT NULL
                AND receipt.snapshot->>'issued_at' IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            UPDATE association_payment_requests AS payment
            SET acknowledged_at = (receipt.snapshot->>'issued_at')::timestamptz
            FROM association_payment_receipts AS receipt
            WHERE payment.id = receipt.payment_id
                AND payment.status = 'acknowledged'
                AND receipt.snapshot->>'starts_at' IS NOT NULL
                AND receipt.snapshot->>'issued_at' IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            UPDATE association_payment_submissions AS submission
            SET reviewed_at = (receipt.snapshot->>'issued_at')::timestamptz
            FROM association_payment_receipts AS receipt
            WHERE submission.payment_id = receipt.payment_id
                AND submission.status = 'accepted'
                AND receipt.snapshot->>'starts_at' IS NOT NULL
                AND receipt.snapshot->>'issued_at' IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            UPDATE association_payment_receipts
            SET issued_at = (snapshot->>'issued_at')::timestamptz
            WHERE snapshot->>'starts_at' IS NOT NULL
                AND snapshot->>'issued_at' IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        // Correct instants cannot be reverted to a timezone-dependent interpretation.
    }
};
