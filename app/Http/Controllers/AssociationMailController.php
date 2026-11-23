<?php

namespace App\Http\Controllers;

use App\Exceptions\AssociationMailNotSent;
use App\Mail\AssociationMembershipUpdate;
use App\Support\AssociationMailer;
use App\Support\WorkspaceProtection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssociationMailController extends Controller
{
    public function show(Request $request, string $workspace): JsonResponse
    {
        WorkspaceProtection::owner($request, $workspace);

        return response()->json([...AssociationMailer::publicConfiguration($workspace), 'deliveries' => self::deliveryRows($workspace)])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, string $workspace): RedirectResponse
    {
        WorkspaceProtection::owner($request, $workspace);
        $data = $request->validate([
            'mode' => ['required', 'in:managed,smtp'], 'sender_email' => ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'],
            'sender_name' => ['required', 'string', 'max:160', 'not_regex:/[\r\n]/'], 'reply_to' => ['nullable', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'],
            'host' => ['nullable', 'required_if:mode,smtp', 'string', 'max:253', 'not_regex:/[\r\n]/'], 'port' => ['required', 'integer', 'in:'.implode(',', config('association-mail.smtp_ports'))],
            'encryption' => ['required', 'in:starttls,tls'], 'username' => ['nullable', 'required_if:mode,smtp', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'password' => ['nullable', 'string', 'max:1024'], 'remove_password' => ['required', 'boolean'], 'provider_reviewed' => ['accepted'],
        ]);
        $old = $data['remove_password'] ? null : AssociationMailer::configuration($workspace);
        $data['password'] = $data['remove_password'] ? '' : (($data['password'] ?? '') !== '' ? $data['password'] : ($old['password'] ?? ''));
        $data['reply_to'] ??= '';
        $data['provider_reviewed'] = true;
        unset($data['remove_password']);
        try {
            AssociationMailer::checkIdentity($workspace, $data);
            if ($data['mode'] === 'smtp') {
                AssociationMailer::validatedAddress($data['host'], $data['port']);
            }
        } catch (AssociationMailNotSent $error) {
            throw ValidationException::withMessages(['mail' => $error->getMessage()]);
        }
        DB::table('association_mail_settings')->updateOrInsert(['association_id' => $workspace], ['encrypted_configuration' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'status' => 'not_tested', 'last_error' => null, 'tested_at' => null, 'updated_by' => $request->user()->id, 'updated_at' => now()->toIso8601String(), 'created_at' => DB::table('association_mail_settings')->where('association_id', $workspace)->value('created_at') ?? now()->toIso8601String()]);
        WorkspaceProtection::event($request, $workspace, 'mail_configuration_saved', 'workspace', 'Delivery mode and provider review saved; no connection details or credentials retained in audit.');

        return back()->with('status', 'Email configuration saved. Test delivery to your verified account before using it.');
    }

    /** @return list<array<string, mixed>> */
    public static function deliveryRows(string $workspace): array
    {
        $rows = [];
        foreach (['payment', 'application', 'renewal'] as $kind) {
            foreach (self::deliveryQuery($workspace, $kind)->orderByDesc('notice.created_at')->limit(10)->get(['notice.id', 'notice.recipient', 'notice.attempts', 'notice.sent_at', 'notice.last_error', 'notice.delivery_state', 'notice.attempted_at']) as $row) {
                $rows[] = ['kind' => $kind, ...((array) $row), 'needs_review' => self::needsReview($row)];
            }
        }

        return $rows;
    }

    private static function needsReview(\stdClass $row): bool
    {
        return $row->delivery_state === 'uncertain' || ($row->delivery_state === 'sending' && $row->attempted_at !== null && CarbonImmutable::parse($row->attempted_at)->lessThan(now()->subMinutes(5)));
    }

    private static function deliveryQuery(string $workspace, string $kind): Builder
    {
        return match ($kind) {
            'payment' => DB::table('association_payment_notifications as notice')->join('association_payment_requests as source', 'source.id', '=', 'notice.payment_id')->where('source.association_id', $workspace),
            'application' => DB::table('membership_correspondence as notice')->join('association_membership_applications as source', 'source.id', '=', 'notice.application_id')->where('source.association_id', $workspace),
            'renewal' => DB::table('association_membership_reminders as notice')->where('notice.association_id', $workspace),
            default => throw new \InvalidArgumentException('Unknown delivery kind.'),
        };
    }

    public function review(Request $request, string $workspace): RedirectResponse
    {
        WorkspaceProtection::owner($request, $workspace);
        $data = $request->validate(['kind' => ['required', 'in:payment,application,renewal'], 'id' => ['required', 'uuid'], 'decision' => ['required', 'in:retry,close'], 'reason' => ['required', 'string', 'max:2000']]);

        return DB::transaction(function () use ($request, $workspace, $data) {
            $row = self::deliveryQuery($workspace, $data['kind'])->where('notice.id', $data['id'])->lock('for update of notice')->first(['notice.*']);
            abort_if(! $row, 404);
            if (! self::needsReview($row)) {
                throw ValidationException::withMessages(['mail' => 'Only uncertain or interrupted deliveries older than five minutes can be reviewed. Reload the current outcome.']);
            }
            $table = match ($data['kind']) {
                'payment' => 'association_payment_notifications', 'application' => 'membership_correspondence', 'renewal' => 'association_membership_reminders', default => throw new \InvalidArgumentException('Unknown delivery kind.')
            };
            DB::table($table)->where('id', $row->id)->update(['delivery_state' => $data['decision'] === 'retry' ? 'failed' : 'closed', 'last_error' => $data['decision'] === 'retry' ? 'Administrator confirmed non-delivery; an explicit existing-workflow retry is permitted.' : 'Administrator closed uncertain outcome without another send.']);
            WorkspaceProtection::event($request, $workspace, 'mail_uncertain_'.$data['decision'], 'workspace', $data['kind'].' occurrence '.$row->id.': '.$data['reason']);

            return back()->with('status', 'Delivery review recorded. No email was sent by this decision.');
        });
    }

    public function test(Request $request, string $workspace): JsonResponse
    {
        WorkspaceProtection::owner($request, $workspace);
        abort_unless($request->user()->hasVerifiedEmail(), 403);
        $status = 'test_sent';
        $error = null;
        try {
            AssociationMailer::send($workspace, $request->user()->email, (new AssociationMembershipUpdate($workspace, 'Association email configuration test', 'This test was requested by the signed-in association administrator. Delivery does not verify domain ownership or authorize a sender.')));
        } catch (AssociationMailNotSent $exception) {
            $status = 'failed';
            $error = $exception->getMessage();
        } catch (\Throwable) {
            $status = 'uncertain';
            $error = 'Delivery outcome is uncertain. Review the provider log and its sender/TLS/authentication requirements before another test. Connection details are not exposed.';
        }
        DB::table('association_mail_settings')->where('association_id', $workspace)->update(['status' => $status, 'last_error' => $error, 'tested_at' => now()->toIso8601String()]);
        WorkspaceProtection::event($request, $workspace, 'mail_test_'.$status, 'workspace');

        return response()->json(['status' => $status, 'message' => $error ?? 'Test submitted to your verified signed-in email address. Inbox receipt and domain authorization must be checked separately.']);
    }
}
