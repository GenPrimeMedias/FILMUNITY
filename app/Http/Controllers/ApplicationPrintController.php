<?php

namespace App\Http\Controllers;

use App\Support\AssociationBranding;
use App\Support\AssociationWorkspace;
use App\Support\FilmunityReviewer;
use App\Support\MembershipEligibility;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class ApplicationPrintController extends Controller
{
    public function registration(Request $request, string $application): Response
    {
        $query = DB::table('association_registration_applications')->where('id', $application);
        if (! FilmunityReviewer::allows($request->user())) {
            $query->where('applicant_id', $request->user()->getAuthIdentifier());
        }
        $record = $query->first();
        abort_if($record === null, 404);
        $fields = [
            'name' => 'Legal organisation name', 'organisation_type' => 'Organisation type',
            'industry' => 'Film industry', 'city' => 'City', 'state_or_region' => 'State or region',
            'registration_number' => 'Registration number', 'registering_authority' => 'Registering authority',
            'registered_on' => 'Date registered', 'official_contact_email' => 'Official contact email',
            'registered_office_address' => 'Registered office address', 'website' => 'Website',
            'representative_role' => 'Authorised representative role',
        ];
        $answers = [];
        foreach ($fields as $key => $label) {
            $answers[] = ['label' => $label, 'value' => $record->$key ?? null];
        }

        return $this->document('Association registration application', $record, [
            'association_name' => 'FILMUNITY', 'logo' => '/brand/filmunity-logo-transparent.png',
        ], $answers, $record->review_note, $record->certificate_path ? ['Registration certificate supplied (original filename not retained).'] : []);
    }

    public function membership(Request $request, string $application): Response
    {
        $query = DB::table('association_membership_applications')->where('id', $application);
        if (! FilmunityReviewer::allows($request->user())) {
            $query->where(function ($scope) use ($request) {
                if (! MembershipEligibility::representative($request->user())) {
                    $scope->where('member_id', $request->user()->getAuthIdentifier());
                } else {
                    $scope->whereRaw('1 = 0');
                }
                $scope->orWhereIn('association_id', AssociationWorkspace::forAdmin($request->user())->select('id'));
            });
        }
        $record = $query->first();
        abort_if($record === null, 404);
        $snapshot = json_decode($record->form_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $plan = json_decode($record->plan_snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $answers = [
            ['label' => 'Applicant name at submission', 'value' => $snapshot['name'] ?? 'Not retained'],
            ['label' => 'Email at submission', 'value' => $snapshot['email'] ?? 'Not retained'],
            ['label' => 'Membership type', 'value' => $record->membership_type ?? 'Choose after approval'],
            ['label' => 'Confirmed membership plan', 'value' => $plan['name'] ?? 'Not yet confirmed'],
            ['label' => 'Application statement', 'value' => $record->statement],
        ];
        $attachments = [];
        foreach ($snapshot['answers'] ?? [] as $answer) {
            $attachment = isset($answer['file_path']);
            $answers[] = [
                'label' => $answer['label'] ?? 'Submitted answer',
                'description' => $answer['description'] ?? null,
                'value' => $attachment ? 'Document supplied — see attachment checklist.' : ($answer['value'] ?? null),
            ];
            if ($attachment) {
                $attachments[] = ($answer['label'] ?? 'Attachment').' — '.($answer['file_name'] ?? 'Original filename not retained');
            }
        }

        return $this->document('Membership application', $record, AssociationBranding::data($record->association_id), $answers, $record->decision_note, $attachments);
    }

    private function document(string $title, object $record, array $branding, array $answers, ?string $note, array $attachments): Response
    {
        return response()->view('print.application', compact('title', 'record', 'branding', 'answers', 'note', 'attachments'))
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
