<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\FilmunityReviewer;
use App\Support\MembershipApplicationView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilmunityAdminController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        if ($request->user()) {
            return to_route('filmunity-admin.dashboard');
        }

        $request->session()->put('url.intended', route('filmunity-admin.dashboard'));

        return to_route('login');
    }

    public function dashboard(Request $request): Response
    {
        abort_unless(FilmunityReviewer::allows($request->user()), 403);

        $counts = DB::table('association_registration_applications')
            ->where('applicant_id', '!=', $request->user()->getAuthIdentifier())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('filmunity-admin', [
            'members' => User::query()->count(),
            'pending' => (int) ($counts['pending'] ?? 0),
            'approved' => (int) ($counts['approved'] ?? 0),
            'declined' => (int) ($counts['declined'] ?? 0),
        ]);
    }

    public function members(Request $request): Response
    {
        abort_unless(FilmunityReviewer::allows($request->user()), 403);

        $search = $request->query('q');
        $sort = $request->query('sort');
        $verification = $request->query('verification');
        $filters = [
            'q' => is_string($search) ? mb_substr(trim(str_replace("\0", '', $search)), 0, 120) : '',
            'sort' => in_array($sort, ['id', 'name', 'email', 'verified', 'created_at'], true) ? $sort : 'name',
            'direction' => $request->query('direction') === 'desc' ? 'desc' : 'asc',
            'verification' => in_array($verification, ['verified', 'unverified'], true) ? $verification : null,
        ];
        $query = DB::table('users')->select('id', 'name', 'email', 'email_verified_at', 'created_at', 'association_signup');
        if ($filters['q'] !== '') {
            $query->where(function ($builder) use ($filters): void {
                foreach (['name', 'email', 'cast(id as text)'] as $column) {
                    $builder->orWhereRaw("strpos(lower($column), lower(?)) > 0", [$filters['q']]);
                }
            });
        }
        if ($filters['verification'] !== null) {
            if ($filters['verification'] === 'verified') {
                $query->whereNotNull('email_verified_at');
            } else {
                $query->whereNull('email_verified_at');
            }
        }
        $column = match ($filters['sort']) {
            'name', 'email' => 'lower('.$filters['sort'].')',
            'verified' => '(email_verified_at IS NOT NULL)',
            default => $filters['sort'],
        };
        $members = $query->orderByRaw($column.' '.$filters['direction'])->orderBy('id')->paginate(20)->appends($filters);

        $selected = $request->query('account');
        $account = null;
        $registrations = null;
        $applications = null;
        if (is_string($selected) && ctype_digit($selected)) {
            // Limit expansion to an account visible on the current page.
            $account = collect($members->items())->first(fn ($member) => (string) $member->id === $selected);
            if ($account !== null) {
                $params = [...$filters, 'account' => $selected, 'page' => $members->currentPage()];
                $registrations = DB::table('association_registration_applications')
                    ->where('applicant_id', $account->id)->orderByDesc('created_at')->orderBy('id')
                    ->paginate(10, [
                        'id', 'name', 'organisation_type', 'industry', 'city', 'representative_role',
                        'status', 'review_note', 'registration_number', 'registering_authority',
                        'state_or_region', 'registered_on', 'official_contact_email', 'registered_office_address',
                        'website', 'created_at', 'reviewed_at',
                        DB::raw('certificate_path IS NOT NULL AS has_certificate'),
                    ], 'registrations_page')
                    ->appends([...$params, 'applications_page' => $request->integer('applications_page', 1)]);

                $applications = DB::table('association_membership_applications as application')
                    ->join('association_registration_applications as associations', 'associations.id', '=', 'application.association_id')
                    ->where('application.member_id', $account->id)
                    ->select('application.id', 'application.association_id', 'application.statement', 'application.status', 'application.decision_note',
                        'application.created_at', 'application.reviewed_at', 'application.form_snapshot',
                        'application.membership_type', 'application.fee_minor', 'application.currency',
                        'associations.name as association_name', 'associations.industry')
                    ->orderByDesc('application.created_at')->orderBy('application.id')
                    ->paginate(10, ['*'], 'applications_page')
                    ->appends([...$params, 'registrations_page' => $request->integer('registrations_page', 1)])
                    ->through(fn (object $application) => MembershipApplicationView::present($application, platformAdmin: true));
            }
        }

        return Inertia::render('filmunity-admin-members', [
            'members' => $members,
            'filters' => $filters,
            'expanded' => $account === null ? null : [
                'id' => $account->id,
                'registrations' => $registrations,
                'applications' => $applications,
            ],
        ]);
    }

    public function membershipDocument(Request $request, string $application, string $field): StreamedResponse
    {
        abort_unless(FilmunityReviewer::allows($request->user()), 403);
        $record = DB::table('association_membership_applications')->where('id', $application)->first(['form_snapshot']);
        abort_if($record === null, 404);
        $snapshot = json_decode($record->form_snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        foreach ($snapshot['answers'] ?? [] as $answer) {
            if (($answer['id'] ?? null) === $field && isset($answer['file_path'])) {
                $path = $answer['file_path'];
                abort_unless(Storage::disk('local')->exists($path), 404);

                return Storage::disk('local')->download($path, 'membership-document.'.pathinfo($path, PATHINFO_EXTENSION), [
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }
        abort(404);
    }
}
