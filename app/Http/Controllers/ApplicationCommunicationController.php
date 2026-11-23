<?php

namespace App\Http\Controllers;

use App\Support\ApplicationCommunication;
use App\Support\AssociationWorkspace;
use App\Support\MembershipEligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ApplicationCommunicationController extends Controller
{
    public function retry(Request $request, string $application): RedirectResponse
    {
        $record = DB::table('association_membership_applications')->where('id', $application)->where(function ($scope) use ($request): void {
            $scope->when(! MembershipEligibility::representative($request->user()), fn ($own) => $own->where('member_id', $request->user()->getAuthIdentifier()), fn ($own) => $own->whereRaw('1 = 0'))->orWhereIn('association_id', AssociationWorkspace::forAdmin($request->user())->select('id'));
        })->first();
        abort_if($record === null, 404);
        $query = DB::table('membership_correspondence')->where('application_id', $application)->whereNull('sent_at')->where('attempts', '<', 5);
        if (! AssociationWorkspace::forAdmin($request->user())->where('id', $record->association_id)->exists()) {
            $query->where('recipient', $request->user()->email);
        }
        foreach ($query->limit(20)->pluck('id') as $id) {
            ApplicationCommunication::deliver($id);
        }

        return back()->with('status', 'Application email delivery updated.');
    }
}
