<?php

namespace App\Http\Controllers;

use App\Mail\AssociationRegistrationApproved;
use App\Support\FilmunityNavigation;
use App\Support\FilmunityReviewer;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AssociationRegistrationController extends Controller
{
    private const TYPES = ['Association', 'Union'];

    private const INDUSTRIES = ['Tamil', 'Malayalam', 'Telugu', 'Kannada', 'Multi-industry'];

    public function start(Request $request): RedirectResponse
    {
        if ($request->user() !== null) {
            return $request->user()->association_signup
                ? to_route('association-registration.index')
                : to_route('membership-applications.index');
        }

        if ($request->query('mode') === 'member') {
            $request->session()->forget('filmunity.association-registration');

            return to_route('register');
        }

        $request->session()->put('filmunity.association-registration', true);

        return redirect()->route('register', ['association' => 1]);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->association_signup, 403);
        $request->session()->forget('filmunity.association-registration');

        return $this->listingPage($request,
            $this->applications()->where('applicant_id', $request->user()->getAuthIdentifier()),
            false, FilmunityReviewer::allows($request->user()));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->association_signup, 403);
        $this->ensureCanApply($request);

        $fields = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'organisation_type' => ['required', Rule::in(self::TYPES)],
            'industry' => ['required', Rule::in(self::INDUSTRIES)],
            'city' => ['required', 'string', 'max:100'],
            'representative_role' => ['required', 'string', 'max:100'],
            'registration_number' => ['required', 'string', 'max:100'],
            'registering_authority' => ['required', 'string', 'max:160'],
            'state_or_region' => ['required', 'string', 'max:100'],
            'registered_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            // The official mailbox may be the representative's login address.
            'official_contact_email' => ['required', 'email:rfc', 'max:254'],
            'registered_office_address' => ['required', 'string', 'max:1000'],
            'website' => ['nullable', 'url:http,https', 'max:2048'],
            'certificate' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        unset($fields['certificate']);
        $path = $request->file('certificate')->store('association-certificates', 'local');
        if ($path === false) {
            throw ValidationException::withMessages(['certificate' => 'The certificate could not be saved. Please try again.']);
        }

        $saved = false;
        try {
            DB::transaction(function () use ($request, $user, $fields, $path): void {
                DB::table('users')->where('id', $user->getAuthIdentifier())->lockForUpdate()->first();
                $this->ensureCanApply($request);

                $this->applications()->insert([
                    ...$fields,
                    'certificate_path' => $path,
                    'id' => (string) Str::uuid(),
                    'applicant_id' => $user->getAuthIdentifier(),
                    'name_key' => Str::lower(Str::squish($fields['name'])),
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            $saved = true;
        } finally {
            if (! $saved) {
                Storage::disk('local')->delete($path);
            }
        }

        return to_route('association-registration.index');
    }

    private function ensureCanApply(Request $request): void
    {
        if (! FilmunityNavigation::canApplyForAssociation($request->user())) {
            throw ValidationException::withMessages(['application' => 'You already have a pending or approved association application.']);
        }
    }

    public function certificate(Request $request, string $application): StreamedResponse
    {
        $query = $this->applications()->where('id', $application);

        if (! FilmunityReviewer::allows($request->user())) {
            $query->where('applicant_id', $request->user()->getAuthIdentifier());
        }

        $path = $query->value('certificate_path');
        $disk = Storage::disk('local');
        abort_unless(is_string($path) && $disk->exists($path), 404);

        return $disk->download($path, 'registration-certificate.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function reviewQueue(Request $request): Response
    {
        abort_unless(FilmunityReviewer::allows($request->user()), 403);

        return $this->listingPage($request,
            $this->applications()->where('applicant_id', '!=', $request->user()->getAuthIdentifier()),
            true, true);
    }

    private function listingPage(Request $request, Builder $query, bool $reviewing, bool $canReview): Response
    {
        $status = $request->query('status');
        $status = in_array($status, ['pending', 'approved', 'declined'], true) ? $status : null;

        $search = $request->query('q');
        $search = is_string($search) ? Str::substr(trim(str_replace("\0", '', $search)), 0, 120) : '';
        $sort = $request->query('sort');
        $sort = in_array($sort, ['name', 'industry', 'status', 'created_at'], true) ? $sort : 'name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $matches) use ($search): void {
                foreach (['name', 'industry', 'city', 'registration_number', 'official_contact_email', 'cast(id as text)'] as $column) {
                    $matches->orWhereRaw("strpos(lower($column), lower(?)) > 0", [$search]);
                }
            });
        }

        if ($sort === 'name') {
            $query->orderByRaw('lower(name) '.$direction);
        } else {
            $query->orderBy($sort, $direction)->orderByRaw('lower(name)');
        }

        return $this->page($query, $reviewing, $canReview, $status, [
            'q' => $search, 'sort' => $sort, 'direction' => $direction,
        ]);
    }

    public function review(Request $request, string $application): RedirectResponse
    {
        abort_unless(FilmunityReviewer::allows($request->user()), 403);

        $decision = $request->validate([
            'status' => ['required', Rule::in(['approved', 'declined'])],
            'review_note' => ['nullable', 'required_if:status,declined', 'string', 'max:2000'],
        ]);

        $target = $this->applications()->where('id', $application);
        $registration = (clone $target)->first(['applicant_id', 'official_contact_email']);
        abort_if($registration === null, 404);
        abort_if((string) $registration->applicant_id === (string) $request->user()->getAuthIdentifier(), 403);

        $updated = $target->where('status', 'pending')->update([
            ...$decision,
            'reviewer_id' => $request->user()->getAuthIdentifier(),
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        if ($updated !== 1) {
            throw ValidationException::withMessages(['status' => 'This registration has already been reviewed.']);
        }

        if ($decision['status'] === 'approved' && filled($registration->official_contact_email)) {
            return $this->sendApprovalEmail($request, $application);
        }

        return $this->reviewRedirect($request);
    }

    public function sendApprovalEmail(Request $request, string $application): RedirectResponse
    {
        abort_unless(FilmunityReviewer::allows($request->user()), 403);

        try {
            DB::transaction(function () use ($request, $application): void {
                $query = $this->applications()->where('id', $application)->where('status', 'approved');
                $record = (clone $query)->lockForUpdate()->first();
                abort_if($record === null, 404);
                abort_if((string) $record->applicant_id === (string) $request->user()->getAuthIdentifier(), 403);

                if ($record->approval_email_sent_at !== null || blank($record->official_contact_email)) {
                    return;
                }

                Mail::to($record->official_contact_email)->send(
                    new AssociationRegistrationApproved($record->name, $record->id),
                );

                $query->update(['approval_email_sent_at' => now()]);
            });
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return $this->reviewRedirect($request)->withErrors([
                'approvalEmail' => 'The approval is saved. Email delivery failed. Retry from the approved application.',
            ]);
        }

        return $this->reviewRedirect($request);
    }

    private function reviewRedirect(Request $request): RedirectResponse
    {
        return to_route('association-registration.review', array_intersect_key(
            $request->query(),
            array_flip(['q', 'sort', 'direction', 'status']),
        ));
    }

    private function applications(): Builder
    {
        return DB::table('association_registration_applications');
    }

    private function page(Builder $query, bool $reviewing, bool $canReview, ?string $status = null, array $queueFilters = []): Response
    {
        $applications = $query->orderBy('id')->paginate(15, [
            'id', 'name', 'organisation_type', 'industry', 'city', 'representative_role',
            'status', 'review_note', 'registration_number', 'registering_authority',
            'state_or_region', 'registered_on', 'official_contact_email', 'registered_office_address',
            'website', 'created_at', 'reviewed_at', 'approval_email_sent_at',
            DB::raw('certificate_path IS NOT NULL AS has_certificate'),
        ]);

        $applications->appends(array_filter([...$queueFilters, 'status' => $status], fn ($value) => $value !== null && $value !== ''));

        return Inertia::render('association-registration', [
            'applications' => [
                'data' => $applications->items(),
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'total' => $applications->total(),
                'prev_page_url' => $applications->previousPageUrl(),
                'next_page_url' => $applications->nextPageUrl(),
            ],
            'reviewing' => $reviewing,
            'statusFilter' => $status,
            'queueFilters' => $queueFilters,
            'canReview' => $canReview,
            'types' => self::TYPES,
            'industries' => self::INDUSTRIES,
        ]);
    }
}
