<?php

namespace App\Http\Controllers;

use App\Support\AssociationMailer;
use App\Support\WorkspaceProtection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssociationMailController extends Controller
{
    public function show(Request $request, string $workspace)
    {
        WorkspaceProtection::owner($request, $workspace);
        return response()->json(AssociationMailer::publicConfiguration($workspace))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, string $workspace)
    {
        WorkspaceProtection::owner($request, $workspace);
        $data = $request->validate([
            'mode' => ['required', 'in:managed,smtp'], 'sender_email' => ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'],
            'sender_name' => ['required', 'string', 'max:160', 'not_regex:/[\r\n]/'], 'reply_to' => ['nullable', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'],
            'host' => ['nullable', 'required_if:mode,smtp', 'string', 'max:253', 'not_regex:/[\r\n]/'], 'port' => ['required', 'integer', 'in:'.implode(',', config('association-mail.smtp_ports'))],
            'encryption' => ['required', 'in:starttls,tls'], 'username' => ['nullable', 'required_if:mode,smtp', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'password' => ['nullable', 'string', 'max:1024'], 'remove_password' => ['required', 'boolean'], 'provider_reviewed' => ['accepted'],
        ]);
        $old = AssociationMailer::configuration($workspace);
        $data['password'] = $data['remove_password'] ? '' : (($data['password'] ?? '') !== '' ? $data['password'] : ($old['password'] ?? ''));
        $data['reply_to'] ??= '';
        $data['provider_reviewed'] = true;
        unset($data['remove_password']);
        try {
            AssociationMailer::checkIdentity($workspace, $data);
            if ($data['mode'] === 'smtp') {
                AssociationMailer::validatedAddress($data['host'], $data['port']);
            }
        } catch (\App\Exceptions\AssociationMailNotSent $error) {
            throw ValidationException::withMessages(['mail' => $error->getMessage()]);
        }
        DB::table('association_mail_settings')->updateOrInsert(['association_id' => $workspace], ['encrypted_configuration' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'status' => 'not_tested', 'last_error' => null, 'tested_at' => null, 'updated_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);
        WorkspaceProtection::event($request, $workspace, 'mail_configuration_saved', 'workspace', 'Delivery mode and provider review saved; no connection details or credentials retained in audit.');
        return back()->with('status', 'Email configuration saved. Test delivery to your verified account before using it.');
    }

    public function test(Request $request, string $workspace)
    {
        WorkspaceProtection::owner($request, $workspace);
        abort_unless($request->user()->hasVerifiedEmail(), 403);
        $status = 'test_sent';
        $error = null;
        try {
            AssociationMailer::send($workspace, $request->user()->email, (new \App\Mail\AssociationMembershipUpdate($workspace, 'Association email configuration test', 'This test was requested by the signed-in association administrator. Delivery does not verify domain ownership or authorize a sender.')));
        } catch (\App\Exceptions\AssociationMailNotSent $exception) {
            $status = 'failed'; $error = $exception->getMessage();
        } catch (\Throwable) {
            $status = 'uncertain'; $error = 'Delivery outcome is uncertain. Review the provider log and its sender/TLS/authentication requirements before another test. Connection details are not exposed.';
        }
        DB::table('association_mail_settings')->where('association_id', $workspace)->update(['status' => $status, 'last_error' => $error, 'tested_at' => now()]);
        WorkspaceProtection::event($request, $workspace, 'mail_test_'.$status, 'workspace');
        return response()->json(['status' => $status, 'message' => $error ?? 'Test submitted to your verified signed-in email address. Inbox receipt and domain authorization must be checked separately.']);
    }
}
