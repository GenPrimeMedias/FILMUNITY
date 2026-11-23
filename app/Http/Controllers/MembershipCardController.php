<?php

namespace App\Http\Controllers;

use App\Support\AssociationBranding;
use App\Support\AssociationWorkspace;
use App\Support\IndividualMembership;
use App\Support\MembershipEligibility;
use App\Support\MembershipLifecycle;
use App\Support\MembershipQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MembershipCardController extends Controller
{
    public function platform(Request $request): Response
    {
        $summary = IndividualMembership::summary($request->user());

        return $this->render($request, ['issuer' => 'FILMUNITY', 'number' => $summary['number'], 'name' => $request->user()->name,
            'type' => 'Individual membership', 'status' => 'Active', 'joined' => $summary['joined_at'], 'expires' => null,
            'logo' => null, 'accent' => '#b9d66b', 'verify' => $summary['verification_url'], 'profile' => $summary['public_profile_url'],
            'photo' => $this->photoData($request->user()->getAuthIdentifier())]);
    }

    public function association(Request $request, string $membership): Response
    {
        $record = DB::table('association_memberships')->where('id', $membership)->where(function ($scope) use ($request): void {
            $scope->when(! MembershipEligibility::representative($request->user()), fn ($own) => $own->where('member_id', $request->user()->getAuthIdentifier()), fn ($own) => $own->whereRaw('1 = 0'))->orWhereIn('association_id', AssociationWorkspace::forAdmin($request->user())->select('id'));
        })->first();
        abort_if($record === null, 404);
        $token = DB::transaction(function () use ($record): object {
            DB::table('association_memberships')->where('id', $record->id)->lockForUpdate()->first();
            $token = DB::table('association_membership_card_tokens')->where('membership_id', $record->id)->first();
            if ($token !== null) {
                return $token;
            }
            $secret = Str::random(64);
            DB::table('association_membership_card_tokens')->insert(['membership_id' => $record->id, 'token_hash' => hash('sha256', $secret),
                'token_encrypted' => Crypt::encryptString($secret), 'created_at' => now()->toIso8601String()]);

            return DB::table('association_membership_card_tokens')->where('membership_id', $record->id)->first();
        });
        $association = DB::table('association_registration_applications')->where('id', $record->association_id)->first();
        $branding = AssociationBranding::data($record->association_id);
        $settings = DB::table('association_workspace_settings')->where('association_id', $record->association_id)->first(['accent']);

        return $this->render($request, ['issuer' => $branding['association_name'], 'number' => $record->number,
            'name' => DB::table('users')->where('id', $record->member_id)->value('name'),
            'type' => (json_decode($record->plan_snapshot ?? '{}', true)['name'] ?? ($record->membership_type === 'yearly' ? 'Yearly' : 'Life-Time')).' membership',
            'status' => $association->status === 'approved' ? str_replace('_', ' ', MembershipLifecycle::status($record)) : 'Association inactive',
            'joined' => $record->starts_at, 'expires' => $record->ends_at, 'logo' => $branding['logo'], 'accent' => $settings?->accent ?? '#b9d66b',
            'verify' => url('/verify-membership/association/'.Crypt::decryptString($token->token_encrypted)), 'profile' => null,
            'photo' => $this->photoData($record->member_id)]);
    }

    public function verify(string $kind, string $token): Response
    {
        abort_unless(in_array($kind, ['fu', 'association'], true) && preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        if ($kind === 'fu') {
            $record = DB::table('filmunity_individual_memberships as membership')->join('users', 'users.id', '=', 'membership.user_id')
                ->where('membership.token_hash', hash('sha256', $token))->first(['membership.id', 'users.name', 'users.email_verified_at']);
            abort_if($record === null, 404);
            $data = ['issuer' => 'FILMUNITY', 'number' => IndividualMembership::number($record), 'name' => $record->name,
                'status' => $record->email_verified_at !== null ? 'Active' : 'Unverified', 'expires' => null];
        } else {
            $record = DB::table('association_membership_card_tokens as token')->join('association_memberships as membership', 'membership.id', '=', 'token.membership_id')
                ->join('association_registration_applications as association', 'association.id', '=', 'membership.association_id')
                ->join('users', 'users.id', '=', 'membership.member_id')->where('token.token_hash', hash('sha256', $token))
                ->first(['membership.*', 'association.name as issuer', 'association.status as association_status', 'users.name']);
            abort_if($record === null, 404);
            $data = ['issuer' => $record->issuer, 'number' => $record->number, 'name' => $record->name,
                'status' => $record->association_status === 'approved' ? str_replace('_', ' ', MembershipLifecycle::status($record)) : 'Association inactive', 'expires' => $record->ends_at];
        }

        return response()->view('cards.verify', $data, 200, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }

    public function passport(Request $request): RedirectResponse
    {
        $record = IndividualMembership::ensure($request->user());
        $request->validate(['passport' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=300,min_height=400,max_width=2400,max_height=3200,ratio=3/4']]);
        $path = $request->file('passport')->store('membership-passports', 'local');
        abort_if($path === false, 500);
        try {
            $oldPath = DB::transaction(function () use ($record, $path): ?string {
                $current = DB::table('filmunity_individual_memberships')->where('id', $record->id)->lockForUpdate()->first();
                DB::table('filmunity_individual_memberships')->where('id', $record->id)->update(['passport_path' => $path, 'updated_at' => now()->toIso8601String()]);

                return $current->passport_path;
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return back()->with('status', 'Passport photo saved for your digital membership cards.');
    }

    private function photoData(int|string $user): ?string
    {
        $path = DB::table('filmunity_individual_memberships')->where('user_id', $user)->value('passport_path');
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return 'data:'.Storage::disk('local')->mimeType($path).';base64,'.base64_encode(Storage::disk('local')->get($path));
    }

    private function render(Request $request, array $card): Response
    {
        if (in_array($request->query('qr'), ['verification', 'profile'], true)) {
            $url = $request->query('qr') === 'profile' ? $card['profile'] : $card['verify'];
            abort_if($url === null, 404);
            $svg = base64_decode(explode(',', MembershipQr::data($url), 2)[1]);

            return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        $card['qr'] = MembershipQr::data($card['verify']);
        $card['profile_qr'] = $card['profile'] ? MembershipQr::data($card['profile']) : null;
        $headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];
        if ($request->query('download') === 'svg' || $request->query('preview') === 'svg') {
            $headers['Content-Type'] = 'image/svg+xml';
            if ($request->query('download') === 'svg') {
                $headers['Content-Disposition'] = 'attachment; filename="membership-card.svg"';
            }

            return response()->view('cards.svg', compact('card'), 200, $headers);
        }

        return response()->view('cards.show', compact('card'), 200, $headers);
    }
}
