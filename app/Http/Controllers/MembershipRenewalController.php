<?php

namespace App\Http\Controllers;

use App\Support\AssociationBranding;
use App\Support\AssociationMembershipForm;
use App\Support\AssociationPayment;
use App\Support\AssociationWorkspace;
use App\Support\MembershipLifecycle;
use App\Support\PaymentNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class MembershipRenewalController extends Controller
{
    public function show(Request $request, string $membership): Response
    {
        $record = DB::table('association_memberships')->where('id', $membership)
            ->where(function ($scope) use ($request): void {
                $scope->when(!\App\Support\MembershipEligibility::representative($request->user()), fn ($own) => $own->where('member_id', $request->user()->getAuthIdentifier()), fn ($own) => $own->whereRaw('1 = 0'))
                    ->orWhereIn('association_id', AssociationWorkspace::forAdmin($request->user())->select('id'));
            })->first();
        abort_if($record === null, 404);
        $association = DB::table('association_registration_applications')->where('id', $record->association_id)->first();
        $settings = AssociationMembershipForm::settings($record->association_id);
        $record = MembershipLifecycle::present($record, $association->status);
        $record->association_name = $association->name;
        $record->member_name = DB::table('users')->where('id', $record->member_id)->value('name');
        $record->end_token = $record->ends_at ? CarbonImmutable::parse($record->ends_at)->utc()->toIso8601String() : '';
        $record->canRenew = !\App\Support\MembershipEligibility::representative($request->user()) && $record->canRenew && (string) $record->member_id === (string) $request->user()->getAuthIdentifier();
        $sort = in_array($request->query('sort'), ['created_at', 'starts_at', 'ends_at', 'amount_minor'], true) ? $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $q = is_string($request->query('q')) ? mb_substr(trim(str_replace("\0", '', $request->query('q'))), 0, 120) : '';
        $terms = DB::table('association_membership_terms as term')
            ->join('association_payment_requests as payment', 'payment.id', '=', 'term.payment_id')
            ->leftJoin('association_payment_receipts as receipt', 'receipt.payment_id', '=', 'payment.id')
            ->where('term.membership_id', $membership);
        if ($q !== '') $terms->where(function ($scope) use ($q): void {
            $scope->whereRaw("strpos(lower(coalesce(receipt.number, '')), lower(?)) > 0", [$q])
                ->orWhereRaw('strpos(lower(cast(payment.id as text)), lower(?)) > 0', [$q]);
        });
        $column = $sort === 'amount_minor' ? 'payment.amount_minor' : 'term.'.$sort;
        $history = $terms->select('term.*', 'payment.amount_minor', 'payment.currency', 'payment.renewal_membership_id', 'receipt.id as receipt_id', 'receipt.number as receipt_number')
            ->orderBy($column, $direction)->orderBy('term.id')->paginate(15)->appends(compact('q', 'sort', 'direction'))
            ->through(function (object $term): object {
                $term->amount = AssociationMembershipForm::formatFee($term->amount_minor);
                unset($term->amount_minor);
                return $term;
            });
        $renewalPlan = \App\Support\AssociationPlans::renewal($record);
        if ($renewalPlan === null) $record->canRenew = false;
        return Inertia::render('membership-renewal', [
            'membership' => $record, 'renewalPlan' => $renewalPlan === null ? null : ['name' => $renewalPlan['name'], 'fee' => AssociationMembershipForm::formatFee($renewalPlan['amount_minor'])],
            'branding' => AssociationMembershipForm::present((object) ['id' => $association->id, 'name' => $association->name], $settings),
            'history' => $history, 'filters' => compact('q', 'sort', 'direction'),
            'isOwner' => AssociationWorkspace::forAdmin($request->user())->where('id', $record->association_id)->exists(),
        ]);
    }

    public function store(Request $request, string $membership): RedirectResponse
    {
        \App\Support\MembershipEligibility::requireIndividual($request->user());
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'end_token' => ['required', 'date'],
            'fee' => ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/D'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $id = DB::transaction(function () use ($request, $membership, $data): string {
            $base = DB::table('association_memberships')->where('id', $membership)
                ->where('member_id', $request->user()->getAuthIdentifier())->first();
            abort_if($base === null, 404);
            // Same lock order as activation: association, then membership, then settings.
            $association = DB::table('association_registration_applications')->where('id', $base->association_id)->lockForUpdate()->first();
            abort_unless($association?->status === 'approved', 404);
            $record = DB::table('association_memberships')->where('id', $membership)->lockForUpdate()->first();
            AssociationPayment::requireState($record->ends_at !== null
                && CarbonImmutable::parse($record->ends_at)->equalTo(CarbonImmutable::parse($data['end_token'])),
                'Membership validity changed. Reload before requesting another renewal.');
            AssociationPayment::requireState(MembershipLifecycle::eligible($record, $association->status),
                'Renewals are available only for eligible yearly memberships within 30 days of expiry or after expiry.');
            $open = MembershipLifecycle::openPayment($membership);
            if ($open !== null) return $open->id;
            $settings = DB::table('association_workspace_settings')->where('association_id', $record->association_id)->sharedLock()->first();
            $plan = \App\Support\AssociationPlans::renewal($record);
            AssociationPayment::requireState($settings !== null && $plan !== null && (int) $settings->version === (int) $data['version']
                && $plan['amount_minor'] === AssociationMembershipForm::feeMinor($data['fee']),
                'The plan or renewal fee changed or is inactive. Reload to review the current plan.');
            AssociationPayment::requireState($plan['amount_minor'] === 0 || trim($settings->payment_instructions ?? '') !== '',
                'The association must add payment instructions before renewal.');
            $initial = DB::table('association_payment_requests')->where('id', $record->payment_id)->first();
            $member = DB::table('users')->where('id', $record->member_id)->first(['name', 'email']);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now('UTC')->toIso8601String();
            $values = [
                'id' => $id, 'application_id' => $initial->application_id, 'association_id' => $record->association_id,
                'member_id' => $record->member_id, 'created_by' => $request->user()->getAuthIdentifier(),
                'renewal_membership_id' => $membership, 'renewal_from' => CarbonImmutable::parse($record->ends_at)->toIso8601String(),
                'plan_id' => $plan['id'], 'plan_snapshot' => json_encode($plan, JSON_THROW_ON_ERROR),
                'membership_type' => 'yearly', 'amount_minor' => $plan['amount_minor'], 'currency' => 'INR',
                'instructions' => $settings->payment_instructions ?? '', 'status' => 'requested',
                'snapshot' => json_encode([
                    ...AssociationBranding::data($record->association_id), 'legal_name' => $association->name,
                    'official_email' => $association->official_contact_email, 'office_address' => $association->registered_office_address,
                    'accent' => $settings->accent, 'member_name' => $member->name, 'member_email' => $member->email,
                    'plan_name' => $plan['name'], 'purpose' => 'renewal', 'membership_number' => $record->number,
                    'renewal_policy' => 'Early renewal extends from current expiry. After expiry the new term starts at acknowledgement.',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now,
            ];
            DB::table('association_payment_requests')->insert($values);
            AssociationPayment::event($id, $request->user(), 'renewal_requested');
            PaymentNotification::record((object) $values, 'requested', 'Yearly membership renewal payment',
                'Your renewal payment request is ready. Submit payment evidence in My payments. Validity extends only after association acknowledgement.', notifyOffice: true);
            return $id;
        });
        return to_route('membership-payments.show', $id);
    }
}
