<?php

namespace App\Http\Controllers;

use App\Support\AssociationMembershipAccess;
use App\Support\AssociationMembershipForm;
use App\Support\AssociationPayment;
use App\Support\AssociationWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AssociationMemberController extends Controller
{
    public function confirmPlan(Request $request, string $application): RedirectResponse
    {
        \App\Support\MembershipEligibility::requireIndividual($request->user());
        $fields = $request->validate([
            'membership_type' => ['nullable', Rule::in(['yearly', 'lifetime'])],
            'plan_id' => ['nullable', 'uuid'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($request, $application, $fields): void {
            $record = DB::table('association_membership_applications')->where('id', $application)
                ->where('member_id', $request->user()->getAuthIdentifier())->lockForUpdate()->first();
            abort_if($record === null, 404);
            AssociationPayment::requireState($record->status === 'accepted_for_payment'
                && $record->membership_type === null && $record->fee_minor === null
                && ! DB::table('association_payment_requests')->where('application_id', $application)->exists(),
                'This application already has its membership type and fee recorded.');
            abort_unless(DB::table('association_registration_applications')->where('id', $record->association_id)
                ->where('status', 'approved')->sharedLock()->first(['id']) !== null, 404);
            $settings = AssociationMembershipForm::settings($record->association_id);
            AssociationPayment::requireState($settings !== null && (int) $settings->version === (int) $fields['version']
                && $settings->yearly_fee_minor !== null && $settings->lifetime_fee_minor !== null,
                'The membership fees changed or are not configured. Reload the page.');
            $plan = \App\Support\AssociationPlans::selected($record->association_id, $fields);
            DB::table('association_membership_applications')->where('id', $application)->update([
                'membership_type' => $plan['term'],
                'plan_id' => $plan['id'], 'plan_snapshot' => json_encode($plan, JSON_THROW_ON_ERROR),
                'fee_minor' => $plan['amount_minor'],
                'currency' => 'INR', 'plan_confirmed_at' => now()->toIso8601String(), 'updated_at' => now(),
            ]);
            \App\Support\ApplicationCommunication::record($application, 'plan_confirmed');
        });

        return to_route('membership-applications.index');
    }

    public function index(Request $request, string $scope = 'member'): Response
    {
        $user = $request->user();
        $query = DB::table('association_memberships as membership')
            ->join('association_registration_applications as association', 'association.id', '=', 'membership.association_id')
            ->join('users as member', 'member.id', '=', 'membership.member_id');
        if ($scope === 'admin') {
            $owned = AssociationWorkspace::forAdmin($user);
            abort_unless((clone $owned)->exists(), 403);
            $query->whereIn('membership.association_id', $owned->select('id'));
        } else {
            \App\Support\MembershipEligibility::requireIndividual($user);
            $query->where('membership.member_id', $user->getAuthIdentifier());
        }
        $renewalQueue = $scope === 'admin' && $request->query('view') === 'renewals';
        $search = $request->query('q');
        $sort = $request->query('sort');
        $filters = [
            'q' => is_string($search) ? mb_substr(trim(str_replace("\0", '', $search)), 0, 120) : '',
            'sort' => in_array($sort, ['association', 'member', 'number', 'starts_at', 'ends_at', 'status'], true) ? $sort : ($renewalQueue ? 'ends_at' : 'association'),
            'view' => $renewalQueue ? 'renewals' : '',
            'status' => in_array($request->query('status'), ['active', 'expiring_soon', 'expired', 'lifetime', 'suspended'], true) ? $request->query('status') : '',
            'direction' => $request->query('direction') === 'desc' ? 'desc' : 'asc',
        ];
        if ($filters['q'] !== '') {
            $query->where(function ($matches) use ($filters): void {
                foreach (['association.name', 'member.name', 'membership.number'] as $column) {
                    $matches->orWhereRaw("strpos(lower($column), lower(?)) > 0", [$filters['q']]);
                }
            });
        }
        $now = now()->toIso8601String();
        $soon = now()->addDays(\App\Support\MembershipLifecycle::RENEWAL_DAYS)->toIso8601String();
        if ($renewalQueue) {
            $filters['status'] = 'expiring_soon';
            $query->where('association.status', 'approved')->where('membership.status', 'active')
                ->where('membership.membership_type', 'yearly')->where('membership.starts_at', '<=', $now)
                ->where('membership.ends_at', '>', $now)->where('membership.ends_at', '<=', $soon);
        } elseif ($filters['status'] === 'suspended') {
            $query->where('membership.status', 'suspended');
        } elseif ($filters['status'] !== '') {
            $query->where('membership.status', 'active')->where('membership.starts_at', '<=', $now);
            if ($filters['status'] === 'lifetime') $query->where('membership.membership_type', 'lifetime');
            else {
                $query->where('membership.membership_type', 'yearly')->whereNotNull('membership.ends_at');
                if ($filters['status'] === 'expired') $query->where('membership.ends_at', '<=', $now);
                elseif ($filters['status'] === 'expiring_soon') $query->where('membership.ends_at', '>', $now)->where('membership.ends_at', '<=', $soon);
                else $query->where('membership.ends_at', '>', $soon);
            }
        }
        $column = match ($filters['sort']) {
            'member' => 'lower(member.name)', 'number' => 'membership.number',
            'starts_at' => 'membership.starts_at', 'ends_at' => 'membership.ends_at',
            'status' => 'lifecycle_sort', default => 'lower(association.name)',
        };
        $memberships = $query->select('membership.id', 'membership.number', 'membership.membership_type',
            'membership.status', 'membership.plan_snapshot', 'membership.association_id', 'membership.starts_at', 'membership.ends_at', 'membership.payment_id',
            'association.name as association_name', 'association.status as association_status', 'member.name as member_name')
            ->selectRaw("CASE WHEN membership.status <> 'active' THEN membership.status
                WHEN membership.starts_at > ? THEN 'not_started'
                WHEN membership.membership_type = 'lifetime' THEN 'lifetime'
                WHEN membership.ends_at IS NULL THEN 'invalid_term'
                WHEN membership.ends_at <= ? THEN 'expired'
                WHEN membership.ends_at <= ? THEN 'expiring_soon' ELSE 'active' END as lifecycle_sort", [$now, $now, $soon])
            ->orderByRaw($column.' '.$filters['direction'])->orderBy('membership.id')->paginate(15)->appends($filters)
            ->through(function (object $membership) use ($renewalQueue): object {
                $membership->plan_name = json_decode($membership->plan_snapshot ?? '{}', true)['name'] ?? ($membership->membership_type === 'yearly' ? 'Yearly' : 'Life-Time');
                unset($membership->plan_snapshot);
                $settings = AssociationMembershipForm::settings($membership->association_id);
                $membership->logo_url = $settings?->has_logo ? route('membership-applications.logo', ['association' => $membership->association_id, 'v' => $settings->version], false) : null;
                \App\Support\MembershipLifecycle::present($membership, $membership->association_status);
                $membership->reminder = $renewalQueue ? \App\Support\MembershipReminder::latest($membership->id, $membership->ends_at) : null;
                return $membership;
            });

        return Inertia::render('association-members', ['memberships' => $memberships, 'filters' => $filters, 'scope' => $scope, 'renewalQueue' => $renewalQueue]);
    }

    public function workspace(Request $request, string $membership): Response
    {
        \App\Support\MembershipEligibility::requireIndividual($request->user());
        $record = AssociationMembershipAccess::forMember($request->user())->where('membership.id', $membership)
            ->select('membership.id', 'membership.number', 'membership.membership_type', 'membership.starts_at',
                'membership.ends_at', 'membership.payment_id', 'association.id as association_id', 'association.name')->first();
        abort_if($record === null, 404);

        return Inertia::render('association-member-workspace', [
            'membership' => $record,
            'branding' => AssociationMembershipForm::present((object) ['id' => $record->association_id, 'name' => $record->name],
                AssociationMembershipForm::settings($record->association_id)),
        ]);
    }
}
