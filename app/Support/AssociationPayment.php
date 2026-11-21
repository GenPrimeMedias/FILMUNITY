<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AssociationPayment
{
    public static function visible(Authenticatable $user): Builder
    {
        $query = DB::table('association_payment_requests');
        if (! FilmunityReviewer::allows($user)) {
            $query->where(function (Builder $scope) use ($user): void {
                $scope->when(!MembershipEligibility::representative($user), fn ($own) => $own->where('member_id', $user->getAuthIdentifier()), fn ($own) => $own->whereRaw('1 = 0'))
                    ->orWhereIn('association_id', AssociationWorkspace::forAdmin($user)->select('id'));
            });
        }

        return $query;
    }

    public static function requireOwner(Authenticatable $user, string $association): void
    {
        abort_unless(AssociationWorkspace::forAdmin($user)->where('id', $association)->exists(), 403);
    }

    public static function requireState(bool $allowed, string $message): void
    {
        if (! $allowed) {
            throw ValidationException::withMessages(['payment' => $message]);
        }
    }

    public static function event(string $payment, Authenticatable $actor, string $action, ?string $note = null): void
    {
        DB::table('association_payment_events')->insert([
            'payment_id' => $payment, 'actor_id' => $actor->getAuthIdentifier(),
            'action' => $action, 'note' => $note, 'created_at' => now()->toIso8601String(),
        ]);
    }

    // Caller holds the payment lock in a transaction.
    public static function activate(object $payment, Authenticatable $actor, ?object $submission, ?string $note): void
    {
        if ($payment->status === 'acknowledged') {
            return;
        }
        self::requireState(
            ((int) $payment->amount_minor === 0)
                ? $payment->status === 'requested'
                : $payment->status === 'submitted' && $submission?->status === 'submitted',
            'A submitted payment must be verified before activation.',
        );
        $association = DB::table('association_registration_applications')->where('id', $payment->association_id)
            ->where('status', 'approved')->lockForUpdate()->first();
        abort_if($association === null, 404);
        $renewing = ($payment->renewal_membership_id ?? null) !== null;
        $existing = DB::table('association_memberships')->where('association_id', $payment->association_id)
            ->where('member_id', $payment->member_id)->lockForUpdate()->first();
        if ($renewing) {
            self::requireState($existing !== null && $existing->id === $payment->renewal_membership_id
                && $existing->membership_type === 'yearly' && $existing->status === 'active' && $existing->ends_at !== null,
                'Only an active-status yearly membership can be renewed. Contact the association if suspended.');
            self::requireState(CarbonImmutable::parse($existing->ends_at)->equalTo(CarbonImmutable::parse($payment->renewal_from)),
                'The membership validity changed since this renewal request. Contact the association.');
        } else {
            self::requireState($existing === null, 'This member already has a membership. Use the membership history.');
        }

        DB::table('association_receipt_counters')->insertOrIgnore(['association_id' => $payment->association_id, 'sequence' => 0]);
        $sequence = (int) DB::table('association_receipt_counters')->where('association_id', $payment->association_id)->value('sequence') + 1;
        DB::table('association_receipt_counters')->where('association_id', $payment->association_id)->update(['sequence' => $sequence]);
        $issued = CarbonImmutable::now('UTC');
        $number = str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        $membershipId = $renewing ? $existing->id : (string) Str::uuid();
        $termStart = $renewing && CarbonImmutable::parse($existing->ends_at)->gt($issued)
            ? CarbonImmutable::parse($existing->ends_at)->utc() : $issued;
        $ends = $payment->membership_type === 'yearly' ? $termStart->addYearNoOverflow() : null;
        $membershipNumber = $renewing ? $existing->number : AssociationMembershipNumber::allocate($payment->association_id, $actor);
        if ($renewing) {
            DB::table('association_memberships')->where('id', $membershipId)->update([
                'plan_id' => $payment->plan_id ?? null, 'plan_snapshot' => $payment->plan_snapshot ?? null,
                'ends_at' => $ends->toIso8601String(), 'updated_at' => $issued->toIso8601String(),
            ]);
        } else {
            DB::table('association_memberships')->insert([
                'id' => $membershipId, 'payment_id' => $payment->id, 'association_id' => $payment->association_id,
                'plan_id' => $payment->plan_id ?? null, 'plan_snapshot' => $payment->plan_snapshot ?? null,
                'member_id' => $payment->member_id, 'number' => $membershipNumber,
                'membership_type' => $payment->membership_type, 'status' => 'active',
                'starts_at' => $issued->toIso8601String(), 'ends_at' => $ends?->toIso8601String(),
                'created_at' => $issued->toIso8601String(), 'updated_at' => $issued->toIso8601String(),
            ]);
        }
        DB::table('association_membership_terms')->insert([
            'id' => (string) Str::uuid(), 'membership_id' => $membershipId, 'payment_id' => $payment->id,
            'starts_at' => $termStart->toIso8601String(), 'ends_at' => $ends?->toIso8601String(),
            'created_at' => $issued->toIso8601String(),
        ]);
        $snapshot = json_decode($payment->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $snapshot = [
            ...$snapshot,
            'receipt_number' => 'REC-'.$issued->format('Y').'-'.$number,
            'payment_id' => $payment->id, 'application_id' => $payment->application_id,
            'membership_number' => $membershipNumber, 'membership_type' => $payment->membership_type,
            'amount' => AssociationMembershipForm::formatFee($payment->amount_minor), 'currency' => $payment->currency,
            'reference' => $submission?->reference ?? 'Zero-fee confirmation',
            'paid_on' => $submission?->paid_on, 'issued_at' => $issued->toIso8601String(),
            'starts_at' => $termStart->toIso8601String(), 'ends_at' => $ends?->toIso8601String(),
            'officer' => $actor->name,
        ];
        DB::table('association_payment_receipts')->insert([
            'id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'membership_id' => $membershipId,
            'association_id' => $payment->association_id, 'number' => $snapshot['receipt_number'],
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'issued_at' => $issued->toIso8601String(),
        ]);
        if ($submission !== null) {
            DB::table('association_payment_submissions')->where('id', $submission->id)->update([
                'status' => 'accepted', 'review_note' => $note, 'reviewed_by' => $actor->getAuthIdentifier(),
                'reviewed_at' => $issued->toIso8601String(), 'updated_at' => $issued->toIso8601String(),
            ]);
        }
        DB::table('association_payment_requests')->where('id', $payment->id)->update([
            'status' => 'acknowledged', 'acknowledged_at' => $issued->toIso8601String(), 'updated_at' => $issued->toIso8601String(),
        ]);
        $financeReceipt = DB::table('association_payment_receipts')->where('payment_id', $payment->id)->value('id');
        if (AssociationFinance::settings($payment->association_id)) {
            \App\Services\Accounting\AccountingMasters::syncMembers($payment->association_id,$membershipId);
        }
        AssociationFinance::collect($financeReceipt, $actor);
        self::event($payment->id, $actor, $renewing ? 'membership_renewed' : 'membership_activated', $note);
        PaymentNotification::record($payment, 'activated', $renewing ? 'Your yearly membership has been renewed' : 'Your association membership is active',
            'Your membership is active. Open My Memberships or My payments to view your receipt and workspace.', notifyOffice: true);
    }
}
