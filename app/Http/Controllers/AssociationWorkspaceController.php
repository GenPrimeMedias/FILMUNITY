<?php

namespace App\Http\Controllers;

use App\Support\AssociationMembershipForm;
use App\Support\AssociationWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class AssociationWorkspaceController
{
    private const FIELDS = [
        'id', 'name', 'organisation_type', 'industry', 'city', 'representative_role',
        'status', 'review_note', 'registration_number', 'registering_authority',
        'state_or_region', 'registered_on', 'official_contact_email', 'registered_office_address',
        'website', 'created_at', 'reviewed_at', 'approval_email_sent_at',
    ];

    public function index(Request $request): Response
    {
        $search = $request->query('q');
        $sort = $request->query('sort');
        $filters = [
            'q' => is_string($search) ? mb_substr(trim(str_replace("\0", '', $search)), 0, 120) : '',
            'sort' => in_array($sort, ['name', 'industry', 'city', 'created_at'], true) ? $sort : 'name',
            'direction' => $request->query('direction') === 'desc' ? 'desc' : 'asc',
        ];
        $query = AssociationWorkspace::forAdmin($request->user());
        if ($filters['q'] !== '') {
            $query->where(function ($builder) use ($filters): void {
                foreach (['name', 'industry', 'city', 'registration_number', 'official_contact_email', 'cast(id as text)'] as $column) {
                    $builder->orWhereRaw("strpos(lower($column), lower(?)) > 0", [$filters['q']]);
                }
            });
        }
        $column = $filters['sort'] === 'created_at' ? 'created_at' : 'lower('.$filters['sort'].')';
        $workspaces = $query->orderByRaw($column.' '.$filters['direction'])->orderBy('id')
            ->paginate(20, [...self::FIELDS, DB::raw('certificate_path IS NOT NULL AS has_certificate')])
            ->appends($filters);

        return Inertia::render('association-workspaces', [
            'workspaces' => $workspaces,
            'workspace' => null,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, string $workspace): Response
    {
        $record = AssociationWorkspace::forAdmin($request->user())
            ->where('id', $workspace)->first([...self::FIELDS, DB::raw('certificate_path IS NOT NULL AS has_certificate')]);

        abort_if($record === null, 404);

        return Inertia::render('association-workspaces', [
            'workspaces' => null,
            'workspace' => $record,
            'branding' => AssociationMembershipForm::present($record, AssociationMembershipForm::settings($workspace)),
        ]);
    }
}
