<?php
namespace App\Support;

use App\Mail\ApplicationWorkflowNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class ApplicationCommunication
{
    public static function record(string $application, string $event): void
    {
        $record = DB::table('association_membership_applications')->where('id', $application)->first();
        $association = DB::table('association_registration_applications')->where('id', $record->association_id)->first();
        $member = DB::table('users')->where('id', $record->member_id)->first(['name', 'email']);
        $heading = match ($event) { 'submitted' => 'Membership application received', 'declined' => 'Membership application declined',
            'approved' => 'Membership application approved — choose your plan', 'plan_confirmed' => 'Membership plan and fee confirmed', default => 'Membership application updated' };
        $body = match ($event) { 'submitted' => 'Your application is pending association review. Membership type is selected after approval.',
            'declined' => 'The association has declined this application.',
            'approved' => 'The association approved your application. Open My Memberships to select Yearly or Life-Time and confirm the current fee. Payment details follow after plan confirmation.',
            'plan_confirmed' => 'Membership plan: '.(json_decode($record->plan_snapshot ?? '{}', true)['name'] ?? $record->membership_type).'. Membership type: '.$record->membership_type.'. Fee: INR '.AssociationMembershipForm::formatFee($record->fee_minor).'. The association can now send payment details.', default => '' };
        $body .= "\n".($record->decision_note ?? '')."\nApplication reference: ".$application;
        $destinations = [['email' => $member->email, 'reply' => $association->official_contact_email]];
        if ($association->official_contact_email) $destinations[] = ['email' => $association->official_contact_email, 'reply' => $member->email];
        foreach ($destinations as $destination) {
            $id = (string) Str::uuid();
            DB::table('membership_correspondence')->insertOrIgnore(['id' => $id, 'application_id' => $application, 'event' => $event, 'recipient' => $destination['email'],
                'snapshot' => json_encode(['association_id' => $record->association_id, 'heading' => $heading, 'body' => $body, 'reply_to' => $destination['reply']], JSON_THROW_ON_ERROR), 'created_at' => now()->toIso8601String()]);
            DB::afterCommit(fn () => self::deliver($id));
        }
    }
    public static function deliver(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $row = DB::table('membership_correspondence')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->sent_at !== null || $row->attempts >= 5 || $row->delivery_state === 'uncertain') return;
            $values = ['attempts' => $row->attempts + 1];
            try {
                $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
                AssociationMailer::send($snapshot['association_id'], $row->recipient, new ApplicationWorkflowNotice($snapshot));
                $values += ['sent_at' => now()->toIso8601String(), 'last_error' => null, 'delivery_state' => 'sent'];
            } catch (\Throwable $error) { $known = $error instanceof \App\Exceptions\AssociationMailNotSent; $values['delivery_state'] = $known ? 'failed' : 'uncertain'; $values['last_error'] = $known ? 'Email delivery failed. Retry from the application history.' : 'Delivery outcome uncertain. Association administrator must review provider evidence before another send.'; }
            DB::table('membership_correspondence')->where('id', $id)->update($values);
        });
    }
}
