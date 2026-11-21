<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class MembershipApplicationView
{
    public static function present(object $application, bool $platformAdmin = false): object
    {
        $branding = AssociationMembershipForm::settings($application->association_id);
        $application->association_logo_url = $branding?->has_logo ? route('membership-applications.logo', ['association' => $application->association_id, 'v' => $branding->version], false) : null;
        $storedPlan = DB::table('association_membership_applications')->where('id', $application->id)->value('plan_snapshot');
        $application->plan_name = json_decode($storedPlan ?? '{}', true)['name'] ?? null;
        $application->fee = AssociationMembershipForm::formatFee($application->fee_minor);
        unset($application->fee_minor);
        $application->form_snapshot = json_decode($application->form_snapshot ?? 'null', true, flags: JSON_THROW_ON_ERROR);

        if ($application->form_snapshot !== null) {
            foreach ($application->form_snapshot['answers'] as &$answer) {
                if (isset($answer['file_path'])) {
                    $answer['download_url'] = route($platformAdmin ? 'filmunity-admin.membership-document' : 'membership-applications.document', [
                        'application' => $application->id, 'field' => $answer['id'],
                    ], false);
                    unset($answer['file_path']);
                }
            }
            unset($answer);
        }

        $application->payment = DB::table('association_payment_requests')->where('application_id', $application->id)->whereNull('renewal_membership_id')
            ->first(['id', 'status']);
        $membership = DB::table('association_memberships')->where('payment_id', $application->payment?->id)->first();
        $application->membership = $membership === null ? null : MembershipLifecycle::present($membership,
            DB::table('association_registration_applications')->where('id', $application->association_id)->value('status'));
        $applicantId = DB::table('association_membership_applications')->where('id', $application->id)->value('member_id');
        $applicant = \App\Models\User::find($applicantId);
        $application->eligible_individual = $applicant !== null && !MembershipEligibility::representative($applicant);
        $application->payment_instructions = null;
        $application->plan_options = null;
        if ($application->status === 'accepted_for_payment' && $application->payment === null) {
            $settings = AssociationMembershipForm::settings($application->association_id);
            $application->payment_instructions = $settings?->payment_instructions;
            if ($application->membership_type === null && $application->fee === null && $settings !== null) {
                $application->plan_options = [
                    'version' => (int) $settings->version,
                    'plans' => AssociationPlans::choices($application->association_id),
                    'yearly' => AssociationMembershipForm::formatFee($settings->yearly_fee_minor),
                    'lifetime' => AssociationMembershipForm::formatFee($settings->lifetime_fee_minor),
                ];
            }
        }

        $communications = DB::table('membership_correspondence')->where('application_id', $application->id);
        $user = request()->user();
        if (!$platformAdmin && !AssociationWorkspace::forAdmin($user)->where('id', $application->association_id)->exists()) $communications->where('recipient', $user->email);
        $application->communications = $communications->orderBy('created_at')->limit(30)->get(['event', 'recipient', 'sent_at', 'attempts', 'last_error', 'created_at']);
        return $application;
    }
}
