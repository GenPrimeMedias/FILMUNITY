<?php
namespace App\Http\Controllers;

use App\Support\AssociationBranding;
use App\Support\AssociationPayment;
use App\Support\AssociationWorkspace;
use App\Support\MembershipLifecycle;
use App\Support\MembershipReminder;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MembershipReminderController extends Controller
{
    public function store(Request $request, string $membership): RedirectResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $record = DB::table('association_memberships')->where('id', $membership)
            ->whereIn('association_id', AssociationWorkspace::forAdmin($request->user())->select('id'))->first();
        abort_if($record === null, 404);
        $id = DB::transaction(function () use ($record, $request): string {
            $association = DB::table('association_registration_applications')->where('id', $record->association_id)->lockForUpdate()->first();
            AssociationPayment::requireOwner($request->user(), $record->association_id);
            $current = DB::table('association_memberships')->where('id', $record->id)->lockForUpdate()->first();
            AssociationPayment::requireState($association->status === 'approved' && MembershipLifecycle::status($current) === 'expiring_soon',
                'Reminders are available only for active yearly memberships expiring within 30 days.');
            $day = CarbonImmutable::now('UTC')->toDateString();
            $existing = DB::table('association_membership_reminders')->where('membership_id', $current->id)
                ->where('expires_at', $current->ends_at)->where('reminder_on', $day)->first();
            if ($existing !== null) return $existing->id;
            $member = DB::table('users')->where('id', $current->member_id)->first(['name', 'email']);
            $id = (string) Str::uuid();
            DB::table('association_membership_reminders')->insert([
                'id' => $id, 'membership_id' => $current->id, 'association_id' => $current->association_id,
                'requested_by' => $request->user()->getAuthIdentifier(), 'expires_at' => CarbonImmutable::parse($current->ends_at)->toIso8601String(),
                'reminder_on' => $day, 'recipient' => $member->email,
                'snapshot' => json_encode([...AssociationBranding::data($current->association_id), 'association_id' => $current->association_id, 'member_name' => $member->name,
                    'membership_number' => $current->number, 'expires_at' => CarbonImmutable::parse($current->ends_at)->utc()->format('d M Y H:i').' UTC',
                    'url' => url('/membership-renewals/'.$current->id)], JSON_THROW_ON_ERROR),
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            ]);
            return $id;
        });
        DB::afterCommit(fn () => MembershipReminder::deliver($id));
        return redirect()->back()->with('status', 'Renewal reminder delivery requested. Check the delivery status in the queue.');
    }
}
