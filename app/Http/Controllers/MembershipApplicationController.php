<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ApplicationCommunication;
use App\Support\AssociationMembershipForm;
use App\Support\AssociationWorkspace;
use App\Support\MembershipApplicationView;
use App\Support\MembershipEligibility;
use Illuminate\Database\Query\Builder;
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

final class MembershipApplicationController extends Controller
{
    public function index(Request $request, bool $applying = false): Response
    {
        MembershipEligibility::requireIndividual($request->user());
        $member = $request->user()->getAuthIdentifier();
        $filters = $this->filters($request);
        $history = $this->listing()->where('application.member_id', $member);

        $associations = DB::table('association_registration_applications as registrations')
            ->leftJoin('association_workspace_settings as settings', 'settings.association_id', '=', 'registrations.id')
            ->where('registrations.status', 'approved')
            ->where('registrations.applicant_id', '!=', $member)
            ->whereNotIn('registrations.id', DB::table('association_membership_applications')->select('association_id')
                ->where('member_id', $member)->where('status', '!=', 'declined'))
            ->select('registrations.id', 'registrations.name', 'registrations.industry', 'registrations.city',
                'settings.display_name', 'settings.description', 'settings.accent', 'settings.fields', 'settings.version', 'settings.yearly_fee_minor', 'settings.lifetime_fee_minor')
            ->selectRaw('settings.logo IS NOT NULL AS has_logo')
            ->orderByRaw('lower(coalesce(settings.display_name, registrations.name))')->orderBy('registrations.id')
            ->get()->map(fn (object $association): array => [
                'id' => $association->id,
                'industry' => $association->industry,
                'city' => $association->city,
                ...AssociationMembershipForm::present($association, $association),
            ]);

        if (MembershipEligibility::representative($request->user())) {
            $associations = collect();
        }
        if ($applying) {
            return Inertia::render('membership-apply', [
                'associations' => $associations, 'identity' => $request->user()->only(['name', 'email']),
            ]);
        }

        return Inertia::render('membership-applications', [
            'associations' => $associations,
            'applications' => $this->filterListing($history, $filters)->paginate(15)->appends($filters)
                ->through($this->presentApplication(...)),
            'filters' => $filters,
            'identity' => $request->user()->only(['name', 'email']),
        ]);
    }

    public function apply(Request $request): Response
    {
        MembershipEligibility::requireIndividual($request->user());

        return $this->index($request, true);
    }

    public function store(Request $request): RedirectResponse
    {
        MembershipEligibility::requireIndividual($request->user());
        $fields = $request->validate([
            'association_id' => ['required', 'uuid'],
            'statement' => ['nullable', 'string', 'max:2000'],
            'version' => ['sometimes', 'integer', 'min:0'],
            'answers' => ['sometimes', 'array', 'max:'.AssociationMembershipForm::MAX_FIELDS],
        ]);
        $member = $request->user()->getAuthIdentifier();

        $paths = [];
        $saved = false;
        try {
            $applicationId = (string) Str::uuid();
            DB::transaction(function () use ($fields, $member, $request, &$paths, $applicationId): void {
                MembershipEligibility::requireIndividual($request->user());
                $association = DB::table('association_registration_applications')
                    ->where('id', $fields['association_id'])->where('status', 'approved')
                    ->where('applicant_id', '!=', $member)->sharedLock()->first(['id']);

                if ($association === null) {
                    throw ValidationException::withMessages(['association_id' => 'Choose an available approved association.']);
                }

                $settings = AssociationMembershipForm::settings($association->id);
                if ((int) ($settings?->version ?? 0) !== (int) ($fields['version'] ?? 0)) {
                    throw ValidationException::withMessages(['association_id' => 'This form changed. Reload it before submitting.']);
                }

                if ($settings?->yearly_fee_minor === null || $settings?->lifetime_fee_minor === null) {
                    throw ValidationException::withMessages(['association_id' => 'This association must configure its membership fees before accepting applications.']);
                }
                // Type and fee are confirmed by the member only after approval.
                $feeMinor = null;

                $schema = json_decode($settings?->fields ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                $rules = $schema === [] ? ['statement' => ['required', 'string', 'max:2000']] : [];
                $labels = [];
                foreach ($schema as $field) {
                    $key = 'answers.'.$field['id'];
                    $labels[$key] = $field['label'];
                    $rules[$key] = match ($field['type']) {
                        'text' => ['string', 'max:255'],
                        'textarea' => ['string', 'max:2000'],
                        'number' => ['numeric', 'max:1e15', 'min:-1e15'],
                        'email' => ['string', 'email', 'max:254'],
                        'tel' => ['string', 'max:30', 'regex:/^(?=.*[0-9])[0-9+ .()\-]+$/D'],
                        'url' => ['string', 'url:http,https', 'max:2048'],
                        'date' => ['date_format:Y-m-d'],
                        'time' => ['date_format:H:i'],
                        'checkbox' => [$field['required'] ? 'accepted' : 'boolean'],
                        'file' => ['file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'],
                        'multiple' => ['array', 'list', 'max:20'],
                        default => ['string', Rule::in($field['options'])],
                    };
                    array_unshift($rules[$key], $field['required'] ? 'required' : 'nullable');
                    if ($field['type'] === 'multiple') {
                        $rules[$key.'.*'] = ['required', 'string', 'distinct', Rule::in($field['options'])];
                        $labels[$key.'.*'] = $field['label'];
                    }
                }
                $validated = $request->validate($rules, [], $labels);
                $answers = $validated['answers'] ?? [];
                $bytes = 0;
                $rows = [];
                foreach ($schema as $field) {
                    $id = $field['id'];
                    $value = $answers[$id] ?? null;
                    $row = ['id' => $id, 'label' => $field['label'], 'value' => $value];
                    if ($field['type'] === 'checkbox') {
                        $row['description'] = $field['description'] ?? null;
                        $row['value'] = $value !== null && filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
                    }
                    if ($field['type'] === 'file' && $value !== null) {
                        $bytes += $value->getSize();
                        if ($bytes > 10 * 1024 * 1024) {
                            throw ValidationException::withMessages(["answers.$id" => 'Documents may total up to 10 MB per application.']);
                        }
                        $path = $value->store('association-membership-documents', 'local');
                        if ($path === false) {
                            throw ValidationException::withMessages(["answers.$id" => 'The document could not be saved. Try again.']);
                        }
                        $paths[] = $path;
                        $row['file_path'] = $path;
                        $row['file_name'] = mb_substr(basename(str_replace('\\', '/', $value->getClientOriginalName())), 0, 255);
                        $row['value'] = 'Document uploaded';
                    }
                    $rows[] = $row;
                }

                DB::table('association_membership_applications')->insert([
                    'membership_type' => null,
                    'fee_minor' => $feeMinor,
                    'currency' => null,
                    'association_id' => $association->id,
                    'statement' => $validated['statement'] ?? '',
                    'form_snapshot' => json_encode([
                        'name' => $request->user()->name,
                        'email' => $request->user()->email,
                        'answers' => $rows,
                    ], JSON_THROW_ON_ERROR),
                    'id' => $applicationId,
                    'member_id' => $member,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                ApplicationCommunication::record($applicationId, 'submitted');
            });
            $saved = true;
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['association_id' => 'You already have an open application for this association.']);
        } finally {
            if (! $saved && $paths !== []) {
                Storage::disk('local')->delete($paths);
            }
        }

        return to_route('membership-applications.index');
    }

    public function queue(Request $request): Response
    {
        $owned = AssociationWorkspace::forAdmin($request->user());
        abort_unless((clone $owned)->exists(), 403);

        $association = $request->query('association');
        if ($association !== null) {
            abort_unless(is_string($association) && Str::isUuid($association) &&
                (clone $owned)->where('id', $association)->exists(), 404);
        }

        $query = $this->listing()
            ->addSelect('members.name as member_name', 'members.email as member_email')
            ->join('users as members', 'members.id', '=', 'application.member_id')
            ->whereIn('application.association_id', $owned->select('id'));

        if ($association !== null) {
            $query->where('application.association_id', $association);
        }

        $filters = $this->filters($request, reviewing: true);

        return Inertia::render('association-membership-review', [
            'applications' => $this->filterListing($query, $filters, reviewing: true)->paginate(15)
                ->appends([...$filters, 'association' => $association])
                ->through($this->presentApplication(...)),
            'associationFilter' => $association,
            'filters' => $filters,
        ]);
    }

    public function decide(Request $request, string $application): RedirectResponse
    {
        $owned = AssociationWorkspace::forAdmin($request->user());
        abort_unless((clone $owned)->exists(), 403);

        $target = DB::table('association_membership_applications')->where('id', $application)
            ->whereIn('association_id', $owned->select('id'));
        $record = (clone $target)->first(['association_id']);
        abort_if($record === null, 404);

        $fields = $request->validate([
            'status' => ['required', Rule::in(['accepted_for_payment', 'declined'])],
            'decision_note' => ['nullable', 'required_if:status,declined', 'string', 'max:2000'],
        ]);

        $updated = DB::transaction(function () use ($target, $fields, $request, $application): int {
            if ($fields['status'] === 'accepted_for_payment') {
                $applicant = User::findOrFail((clone $target)->value('member_id'));
                if (MembershipEligibility::representative($applicant)) {
                    throw ValidationException::withMessages(['status' => 'Association representatives are not eligible for another association membership.']);
                }
            }
            $updated = $target->where('status', 'pending')->update([
                ...$fields,
                'reviewer_id' => $request->user()->getAuthIdentifier(),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            if ($updated === 1) {
                ApplicationCommunication::record($application, $fields['status'] === 'declined' ? 'declined' : 'approved');
            }

            return $updated;
        });

        if ($updated !== 1) {
            throw ValidationException::withMessages(['status' => 'This application can no longer be decided.']);
        }

        $filters = $request->query('association') === $record->association_id
            ? ['association' => $record->association_id] : [];

        return to_route('association-membership-review.index', [
            ...$filters,
            ...array_intersect_key(
                array_filter($this->filters($request, reviewing: true), fn ($value) => $value !== null && $value !== ''),
                $request->query(),
            ),
        ]);
    }

    public function document(Request $request, string $application, string $field): StreamedResponse
    {
        $record = DB::table('association_membership_applications')->where('id', $application)
            ->where(function (Builder $query) use ($request): void {
                $user = $request->user();
                $query->when(! MembershipEligibility::representative($user), fn ($own) => $own->where('member_id', $user->getAuthIdentifier()), fn ($own) => $own->whereRaw('1 = 0'))
                    ->orWhereIn('association_id', AssociationWorkspace::forAdmin($user)->select('id'));
            })->first(['form_snapshot']);
        abort_if($record === null, 404);

        $snapshot = json_decode($record->form_snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        foreach ($snapshot['answers'] ?? [] as $answer) {
            if (($answer['id'] ?? null) === $field && isset($answer['file_path'])) {
                $path = $answer['file_path'];
                $disk = Storage::disk('local');
                abort_unless($disk->exists($path), 404);

                return $disk->download($path, 'membership-document.'.pathinfo($path, PATHINFO_EXTENSION), [
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        abort(404);
    }

    private function presentApplication(object $application): object
    {
        return MembershipApplicationView::present($application);
    }

    private function filters(Request $request, bool $reviewing = false): array
    {
        $status = $request->query('status');
        $sort = $request->query('sort');
        $search = $request->query('q');
        $sorts = ['association', 'industry', 'status', 'submitted'];
        if ($reviewing) {
            $sorts[] = 'applicant';
        }

        return [
            'status' => in_array($status, ['pending', 'approved', 'declined'], true) ? $status : null,
            'q' => is_string($search) ? Str::limit(trim(str_replace("\0", '', $search)), 120, '') : '',
            'sort' => in_array($sort, $sorts, true) ? $sort : ($reviewing ? 'applicant' : 'association'),
            'direction' => $request->query('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    private function filterListing(Builder $query, array $filters, bool $reviewing = false): Builder
    {
        $association = 'coalesce(settings.display_name, associations.name)';
        $applicant = "coalesce(application.form_snapshot->>'name', members.name)";
        $email = "coalesce(application.form_snapshot->>'email', members.email)";
        $status = $filters['status'];
        if ($status !== null) {
            $query->where('application.status', $status === 'approved' ? 'accepted_for_payment' : $status);
        }

        if ($filters['q'] !== '') {
            $search = $filters['q'];
            $columns = [$association, 'associations.industry', 'cast(application.id as text)'];
            if ($reviewing) {
                $columns[] = $applicant;
                $columns[] = $email;
            }
            $query->where(function (Builder $builder) use ($columns, $search): void {
                foreach ($columns as $column) {
                    $builder->orWhereRaw("strpos(lower($column), lower(?)) > 0", [$search]);
                }
            });
        }

        $sort = match ($filters['sort']) {
            'applicant' => "lower($applicant)",
            'industry' => 'lower(associations.industry)',
            'status' => 'application.status',
            'submitted' => 'application.created_at',
            default => "lower($association)",
        };

        return $query->orderByRaw($sort.' '.$filters['direction'])
            ->orderByDesc('application.created_at')->orderBy('application.id');
    }

    private function listing(): Builder
    {
        return DB::table('association_membership_applications as application')
            ->join('association_registration_applications as associations', 'associations.id', '=', 'application.association_id')
            ->leftJoin('association_workspace_settings as settings', 'settings.association_id', '=', 'associations.id')
            ->select('application.id', 'application.association_id', 'application.statement', 'application.status', 'application.decision_note',
                'application.created_at', 'application.reviewed_at', 'application.form_snapshot', 'application.membership_type', 'application.fee_minor', 'application.currency', 'associations.industry')
            ->selectRaw('coalesce(settings.display_name, associations.name) as association_name');
    }
}
