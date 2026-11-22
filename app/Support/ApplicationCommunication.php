<?php

namespace App\Support;

use App\Mail\ApplicationWorkflowNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ApplicationCommunication
{
    public static function record(string $application, string $event): void
    {
        $record = DB::table('association_membership_applications')->where('id', $application)->first();
        $association = DB::table('association_registration_applications')->where('id', $record->association_id)->first();
        $member = DB::table('users')->where('id', $record->member_id)->first(['name', 'email']);
        $heading = match ($event) {
            'submitted' => 'Membership application received', 'declined' => 'Membership application declined',
            'approved' => 'Membership application approved — choose your plan', 'plan_confirmed' => 'Membership plan and fee confirmed', default => 'Membership application updated'
        };
        $body = match ($event) {
            'submitted' => 'Your application is pending association review. Membership type is selected after approval.',
            'declined' => 'The association has declined this application.',
            'approved' => 'The association approved your application. Open My Memberships to select Yearly or Life-Time and confirm the current fee. Payment details follow after plan confirmation.',
            'plan_confirmed' => 'Membership plan: '.(json_decode($record->plan_snapshot ?? '{}', true)['name'] ?? $record->membership_type).'. Membership type: '.$record->membership_type.'. Fee: INR '.AssociationMembershipForm::formatFee($record->fee_minor).'. The association can now send payment details.', default => ''
        };
        $body .= "\n".($record->decision_note ?? '')."\nApplication reference: ".$application;
        $destinations = [['email' => $member->email, 'reply' => $association->official_contact_email]];
        if ($association->official_contact_email) {
            $destinations[] = ['email' => $association->official_contact_email, 'reply' => $member->email];
        }
        foreach ($destinations as $destination) {
            $id = (string) Str::uuid();
            DB::table('membership_correspondence')->insertOrIgnore(['id' => $id, 'application_id' => $application, 'event' => $event, 'recipient' => $destination['email'],
                'snapshot' => json_encode(['association_id' => $record->association_id, 'heading' => $heading, 'body' => $body, 'reply_to' => $destination['reply']], JSON_THROW_ON_ERROR), 'created_at' => now()->toIso8601String()]);
            DB::afterCommit(fn () => self::deliver($id));
        }
    }

    public static function deliver(string $id): void
    {
        AssociationDelivery::perform('membership_correspondence', $id, function ($row) {
            $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
            AssociationMailer::send($snapshot['association_id'], $row->recipient, new ApplicationWorkflowNotice($snapshot));
        }, 'Email delivery failed. Retry from the application history.');
    }
}
