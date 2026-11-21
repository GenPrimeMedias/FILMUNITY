<?php

namespace App\Http\Controllers;

use App\Support\AssociationMembershipForm;
use App\Support\AssociationPayment;
use App\Support\AssociationWorkspace;
use App\Support\FilmunityReviewer;
use App\Support\PaymentNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AssociationPaymentController extends Controller
{
    public function index(Request $request, string $scope = 'member'): Response
    {
        $user = $request->user();
        $query = DB::table('association_payment_requests as payment');
        if ($scope === 'audit') {
            abort_unless(FilmunityReviewer::allows($user), 403);
        } elseif ($scope === 'admin') {
            $owned = AssociationWorkspace::forAdmin($user);
            abort_unless((clone $owned)->exists(), 403);
            $query->whereIn('payment.association_id', $owned->select('id'));
        } else {
            \App\Support\MembershipEligibility::requireIndividual($user);
            $query->where('payment.member_id', $user->getAuthIdentifier());
        }
        $filters = $this->filters($request);
        if ($filters['status'] !== null) {
            $query->where('payment.status', $filters['status']);
        }
        $association = $request->query('association');
        if ($scope === 'admin' && $association !== null) {
            abort_unless(is_string($association) && Str::isUuid($association)
                && AssociationWorkspace::forAdmin($user)->where('id', $association)->exists(), 404);
            $query->where('payment.association_id', $association);
        }
        if ($filters['q'] !== '') {
            $query->where(function ($matches) use ($filters): void {
                foreach (["payment.snapshot->>'association_name'", "payment.snapshot->>'member_name'",
                    "payment.snapshot->>'member_email'", 'cast(payment.id as text)'] as $column) {
                    $matches->orWhereRaw("strpos(lower($column), lower(?)) > 0", [$filters['q']]);
                }
            });
        }
        $sort = match ($filters['sort']) {
            'member' => "lower(payment.snapshot->>'member_name')",
            'amount' => 'payment.amount_minor', 'status' => 'payment.status',
            'created_at' => 'payment.created_at',
            default => "lower(payment.snapshot->>'association_name')",
        };
        $payments = $query->leftJoin('association_payment_receipts as receipt', 'receipt.payment_id', '=', 'payment.id')
            ->select('payment.id', 'payment.membership_type', 'payment.amount_minor', 'payment.currency',
                'payment.status', 'payment.due_on', 'payment.created_at', 'receipt.id as receipt_id')
            ->selectRaw("payment.snapshot->>'association_name' as association_name, payment.snapshot->>'member_name' as member_name")
            ->orderByRaw($sort.' '.$filters['direction'])->orderBy('payment.id')->paginate(15)
            ->appends([...$filters, 'association' => is_string($association) ? $association : null])
            ->through(function (object $payment): object {
                $payment->amount = AssociationMembershipForm::formatFee($payment->amount_minor);
                unset($payment->amount_minor);
                return $payment;
            });

        return Inertia::render('membership-payments', [
            'payments' => $payments, 'filters' => $filters, 'scope' => $scope,
            'associationFilter' => is_string($association) ? $association : null,
        ]);
    }

    public function create(Request $request, string $application): RedirectResponse
    {
        $fields = $request->validate([
            'instructions' => ['nullable', 'string', 'max:3000'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);
        $id = DB::transaction(function () use ($request, $application, $fields): string {
            $record = DB::table('association_membership_applications')->where('id', $application)->lockForUpdate()->first();
            abort_if($record === null, 404);
            AssociationPayment::requireOwner($request->user(), $record->association_id);
            AssociationPayment::requireState($record->status === 'accepted_for_payment', 'Approve this application before requesting payment.');
            $existing = DB::table('association_payment_requests')->where('application_id', $application)->whereNull('renewal_membership_id')->value('id');
            if ($existing !== null) {
                return $existing;
            }
            AssociationPayment::requireState(in_array($record->membership_type, ['yearly', 'lifetime'], true)
                && $record->fee_minor !== null && $record->currency === 'INR', 'The applicant must confirm the membership type and fee first.');
            AssociationPayment::requireState(!\App\Support\MembershipEligibility::representative(\App\Models\User::findOrFail($record->member_id)), 'This applicant represents an association and cannot start another association membership.');
            $association = DB::table('association_registration_applications')->where('id', $record->association_id)->first();
            $settings = DB::table('association_workspace_settings')->where('association_id', $record->association_id)->first();
            $member = DB::table('users')->where('id', $record->member_id)->first(['name', 'email']);
            $instructions = trim($fields['instructions'] ?? $settings?->payment_instructions ?? '');
            AssociationPayment::requireState((int) $record->fee_minor === 0 || $instructions !== '', 'Add payment instructions before sending the request.');
            $id = (string) Str::uuid();
            $values = [
                'id' => $id, 'application_id' => $application, 'association_id' => $record->association_id,
                'member_id' => $record->member_id, 'created_by' => $request->user()->getAuthIdentifier(),
                'plan_id' => $record->plan_id, 'plan_snapshot' => $record->plan_snapshot,
                'membership_type' => $record->membership_type, 'amount_minor' => $record->fee_minor,
                'currency' => $record->currency, 'instructions' => $instructions, 'due_on' => $fields['due_on'] ?? null,
                'snapshot' => json_encode([
                    'association_name' => $settings?->display_name ?? $association->name,
                    'plan_name' => json_decode($record->plan_snapshot ?? '{}', true)['name'] ?? ($record->membership_type === 'yearly' ? 'Yearly' : 'Life-Time'),
                    'legal_name' => $association->name, 'official_email' => $association->official_contact_email,
                    'office_address' => $association->registered_office_address,
                    'accent' => $settings?->accent ?? '#b9d66b',
                    'logo' => $settings?->logo ? 'data:'.str_replace(';', ';base64,', $settings->logo) : null,
                    'member_name' => $member->name, 'member_email' => $member->email,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            ];
            DB::table('association_payment_requests')->insert($values);
            AssociationPayment::event($id, $request->user(), 'payment_requested');
            PaymentNotification::record((object) $values, 'requested', 'Association membership payment request',
                'A membership payment request is ready. Sign in to FILMUNITY to see the amount and instructions.');

            return $id;
        });

        return to_route('membership-payments.show', $id);
    }

    public function show(Request $request, string $payment): Response
    {
        $record = AssociationPayment::visible($request->user())->where('id', $payment)->first();
        abort_if($record === null, 404);
        $owned = AssociationWorkspace::forAdmin($request->user())->where('id', $record->association_id)->exists();
        $proofSearch = $request->query('proof_q');
        $proofSort = $request->query('proof_sort');
        $proofFilters = [
            'proof_q' => is_string($proofSearch) ? mb_substr(trim(str_replace("\0", '', $proofSearch)), 0, 120) : '',
            'proof_sort' => in_array($proofSort, ['reference', 'paid_on', 'status', 'created_at'], true) ? $proofSort : 'created_at',
            'proof_direction' => $request->query('proof_direction') === 'asc' ? 'asc' : 'desc',
        ];
        $proofQuery = DB::table('association_payment_submissions')->where('payment_id', $payment);
        if ($proofFilters['proof_q'] !== '') {
            $proofQuery->whereRaw('strpos(lower(reference), lower(?)) > 0', [$proofFilters['proof_q']]);
        }
        $submissions = $proofQuery->orderBy($proofFilters['proof_sort'], $proofFilters['proof_direction'])->orderBy('id')
            ->paginate(10, ['id', 'reference', 'paid_on', 'status', 'review_note', 'reviewed_at', 'created_at'], 'attempts_page');
        $events = DB::table('association_payment_events as event')->join('users', 'users.id', '=', 'event.actor_id')
            ->where('event.payment_id', $payment)->orderByDesc('event.id')
            ->paginate(10, ['event.id', 'event.action', 'event.note', 'event.created_at', 'users.name as actor'], 'events_page');
        $submissions->appends([...$proofFilters, 'events_page' => $events->currentPage()]);
        $events->appends([...$proofFilters, 'attempts_page' => $submissions->currentPage()]);
        $snapshot = json_decode($record->snapshot, true, flags: JSON_THROW_ON_ERROR);
        if (empty($snapshot['logo'])) {
            $associationId = $record->association_id ?? DB::table('association_payment_requests')->where('id', $record->payment_id)->value('association_id');
            if ($associationId) $snapshot['logo'] = \App\Support\AssociationBranding::data($associationId)['logo'];
        }
        $receipt = DB::table('association_payment_receipts')->where('payment_id', $payment)->first(['id', 'number']);
        $membership = DB::table('association_memberships')->where(function ($query) use ($payment, $record): void {
            $query->where('payment_id', $payment);
            if ($record->renewal_membership_id !== null) $query->orWhere('id', $record->renewal_membership_id);
        })->first(['id', 'number', 'starts_at', 'ends_at', 'status']);

        return Inertia::render('membership-payment', [
            'payment' => [
                'id' => $record->id, 'application_id' => $record->application_id,
                'membership_type' => $record->membership_type, 'amount' => AssociationMembershipForm::formatFee($record->amount_minor),
                'zeroFee' => (int) $record->amount_minor === 0, 'currency' => $record->currency,
                'status' => $record->status, 'instructions' => $record->instructions,
                'due_on' => $record->due_on, 'created_at' => $record->created_at,
                'acknowledged_at' => $record->acknowledged_at, 'snapshot' => $snapshot,
            ],
            'isAuditor' => FilmunityReviewer::allows($request->user()),
            'canManage' => $owned, 'isApplicant' => !\App\Support\MembershipEligibility::representative($request->user()) && (string) $record->member_id === (string) $request->user()->getAuthIdentifier(),
            'proofFilters' => $proofFilters,
            'submissions' => $submissions, 'events' => $events, 'receipt' => $receipt, 'membership' => $membership,
            'pendingEmails' => DB::table('association_payment_notifications')->where('payment_id', $payment)->whereNull('sent_at')->count(),
        ]);
    }

    public function submit(Request $request, string $payment): RedirectResponse
    {
        \App\Support\MembershipEligibility::requireIndividual($request->user());
        $visible = AssociationPayment::visible($request->user())->where('id', $payment)->first(['member_id']);
        abort_if($visible === null, 404);
        abort_unless((string) $visible->member_id === (string) $request->user()->getAuthIdentifier(), 403);
        $fields = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'],
        ]);
        $path = null;
        $saved = false;
        try {
            DB::transaction(function () use ($request, $payment, $fields, &$path): void {
                $record = DB::table('association_payment_requests')->where('id', $payment)->lockForUpdate()->first();
                abort_unless((string) $record->member_id === (string) $request->user()->getAuthIdentifier(), 403);
                AssociationPayment::requireState($record->status === 'requested' && (int) $record->amount_minor > 0,
                    'This payment is not accepting another submission.');
                $path = $request->file('proof')->store('association-payment-proofs', 'local');
                AssociationPayment::requireState(is_string($path), 'The proof could not be saved. Try again.');
                $id = (string) Str::uuid();
                DB::table('association_payment_submissions')->insert([
                    'id' => $id, 'payment_id' => $payment, 'association_id' => $record->association_id,
                    'reference' => $fields['reference'], 'reference_key' => Str::lower(Str::squish($fields['reference'])),
                    'paid_on' => $fields['paid_on'], 'proof_path' => $path, 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
                ]);
                DB::table('association_payment_requests')->where('id', $payment)->update(['status' => 'submitted', 'updated_at' => now()->toIso8601String()]);
                AssociationPayment::event($payment, $request->user(), 'proof_submitted', $fields['reference']);
                PaymentNotification::record($record, 'submitted-'.$id, 'Membership payment submitted for verification',
                    'Payment proof has been submitted. The association can review the transaction in FILMUNITY.', notifyOffice: true);
            });
            $saved = true;
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => 'This transaction reference is already submitted or acknowledged for this association.']);
        } finally {
            if (! $saved && is_string($path)) {
                Storage::disk('local')->delete($path);
            }
        }

        return to_route('membership-payments.show', $payment);
    }

    public function decide(Request $request, string $payment, string $submission): RedirectResponse
    {
        $visible = AssociationPayment::visible($request->user())->where('id', $payment)->first(['association_id']);
        abort_if($visible === null, 404);
        AssociationPayment::requireOwner($request->user(), $visible->association_id);
        $fields = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'return'])],
            'note' => ['nullable', 'required_if:decision,return', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($request, $payment, $submission, $fields): void {
            $record = DB::table('association_payment_requests')->where('id', $payment)->lockForUpdate()->first();
            AssociationPayment::requireOwner($request->user(), $record->association_id);
            $proof = DB::table('association_payment_submissions')->where('payment_id', $payment)->where('id', $submission)->first();
            abort_if($proof === null, 404);
            if ($record->status === 'acknowledged' && $proof->status === 'accepted' && $fields['decision'] === 'accept') {
                return;
            }
            AssociationPayment::requireState($record->status === 'submitted' && $proof->status === 'submitted',
                'This submission has already been reviewed.');
            if ($fields['decision'] === 'accept') {
                AssociationPayment::activate($record, $request->user(), $proof, $fields['note'] ?? null);
            } else {
                DB::table('association_payment_submissions')->where('id', $submission)->update([
                    'status' => 'returned', 'review_note' => $fields['note'], 'reviewed_by' => $request->user()->getAuthIdentifier(),
                    'reviewed_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
                ]);
                DB::table('association_payment_requests')->where('id', $payment)->update(['status' => 'requested', 'updated_at' => now()->toIso8601String()]);
                AssociationPayment::event($payment, $request->user(), 'proof_returned', $fields['note']);
                PaymentNotification::record($record, 'returned-'.$submission, 'Membership payment needs an update',
                    'The association returned your proof with this reason: '.$fields['note']);
            }
        });

        return to_route('membership-payments.show', $payment);
    }

    public function zeroFee(Request $request, string $payment): RedirectResponse
    {
        $fields = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $payment, $fields): void {
            $record = AssociationPayment::visible($request->user())->where('id', $payment)->lockForUpdate()->first();
            abort_if($record === null, 404);
            AssociationPayment::requireOwner($request->user(), $record->association_id);
            AssociationPayment::requireState((int) $record->amount_minor === 0, 'Use payment proof verification for a paid membership.');
            AssociationPayment::activate($record, $request->user(), null, $fields['note'] ?? 'Zero-fee membership confirmed.');
        });

        return to_route('membership-payments.show', $payment);
    }

    public function proof(Request $request, string $payment, string $submission): StreamedResponse
    {
        abort_unless(AssociationPayment::visible($request->user())->where('id', $payment)->exists(), 404);
        $path = DB::table('association_payment_submissions')->where('payment_id', $payment)->where('id', $submission)->value('proof_path');
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'membership-payment-proof.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function receipt(Request $request, string $receipt): \Illuminate\Http\Response
    {
        $record = DB::table('association_payment_receipts')->where('id', $receipt)->first();
        abort_if($record === null, 404);
        abort_unless(AssociationPayment::visible($request->user())->where('id', $record->payment_id)->exists(), 404);
        $snapshot = json_decode($record->snapshot, true, flags: JSON_THROW_ON_ERROR);
        if (empty($snapshot['logo'])) {
            $associationId = $record->association_id ?? DB::table('association_payment_requests')->where('id', $record->payment_id)->value('association_id');
            if ($associationId) $snapshot['logo'] = \App\Support\AssociationBranding::data($associationId)['logo'];
        }
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$record->number.'.html"';
        }

        return response()->view('receipts.association-membership', ['receipt' => $snapshot], 200, $headers);
    }

    public function retryEmails(Request $request, string $payment): RedirectResponse
    {
        $record = AssociationPayment::visible($request->user())->where('id', $payment)->first(['member_id', 'association_id']);
        abort_if($record === null, 404);
        abort_unless((string) $record->member_id === (string) $request->user()->getAuthIdentifier()
            || AssociationWorkspace::forAdmin($request->user())->where('id', $record->association_id)->exists(), 403);
        $ids = DB::table('association_payment_notifications')->where('payment_id', $payment)->whereNull('sent_at')->pluck('id');
        foreach ($ids as $id) {
            PaymentNotification::deliver($id);
        }

        return to_route('membership-payments.show', $payment);
    }

    private function filters(Request $request): array
    {
        $q = $request->query('q');
        $sort = $request->query('sort');
        $status = $request->query('status');

        return [
            'q' => is_string($q) ? mb_substr(trim(str_replace("\0", '', $q)), 0, 120) : '',
            'sort' => in_array($sort, ['association', 'member', 'amount', 'status', 'created_at'], true) ? $sort : 'created_at',
            'direction' => $request->query('direction') === 'asc' ? 'asc' : 'desc',
            'status' => in_array($status, ['requested', 'submitted', 'acknowledged'], true) ? $status : null,
        ];
    }
}
