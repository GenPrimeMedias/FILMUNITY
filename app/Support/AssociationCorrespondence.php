<?php
namespace App\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;

final class AssociationCorrespondence
{
    public static function address(Mailable $mail, string $association, ?string $replyTo = null): Mailable
    {
        $official = DB::table('association_registration_applications')->where('id', $association)->value('official_contact_email');
        $name = AssociationBranding::data($association)['association_name'];
        $mail->from(config('mail.from.address'), $name.' via FILMUNITY');
        $reply = $replyTo ?? $official;
        if (is_string($reply) && filter_var($reply, FILTER_VALIDATE_EMAIL)) $mail->replyTo($reply);
        return $mail;
    }
}
