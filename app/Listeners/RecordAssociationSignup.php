<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Registered;

final class RecordAssociationSignup
{
    public function handle(Registered $event): void
    {
        if (! ($event->user instanceof User) || ! request()->hasSession()) {
            return;
        }

        if (request()->isMethod('POST') && ! request()->boolean('association_signup')) {
            request()->session()->forget('filmunity.association-registration');

            return;
        }

        if (request()->session()->get('filmunity.association-registration') === true) {
            $event->user->forceFill(['association_signup' => true])->save();
        }
    }
}
