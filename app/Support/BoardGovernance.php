<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BoardGovernance
{
    public static function recheckQuorum(string $association, string $meeting, ?int $actor = null): object
    {
        $record = DB::table('association_governance_meetings')->where('association_id', $association)->where('id', $meeting)->lockForUpdate()->firstOrFail();
        $policy = json_decode((string) $record->policy_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $participants = DB::table('association_governance_participants')->where('association_id', $association)->where('meeting_id', $meeting)->get();
        $term = DB::table('association_governance_terms')->where('association_id', $association)->where('id', $record->term_id)->firstOrFail();

        $eligibleSeats = (int) DB::table('association_governance_positions as position')
            ->where('position.association_id', $association)->where('position.body_id', $record->body_id)->where('position.active', true)
            ->sum('position.seats');
        $serving = (int) DB::table('association_governance_appointments')->where('association_id', $association)->where('term_id', $term->id)->whereIn('status', ['scheduled', 'serving'])->count();
        $attendingInvited = $participants->where('voting_eligible', true)->count();
        $present = $participants->where('voting_eligible', true)->where('attendance_status', 'present')->count();
        $denominator = match ($policy['quorum_denominator'] ?? null) {
            'eligible_seats' => $eligibleSeats,
            'serving_appointments' => $serving,
            'attending_invited' => $attendingInvited,
            default => null,
        };

        $required = null;
        $state = 'manual_required';
        $reason = null;
        $reviewed = ($policy['reviewed'] ?? false) === true || ($policy['status'] ?? null) === 'reviewed';
        if ($reviewed && $denominator !== null) {
            if (($policy['quorum_type'] ?? null) === 'fixed_count') {
                $required = (int) ($policy['quorum_count'] ?? 0);
            } elseif (($policy['quorum_type'] ?? null) === 'percentage') {
                $raw = $denominator * ((float) ($policy['quorum_percent'] ?? 0) / 100);
                $required = match ($policy['rounding_policy'] ?? 'ceil') {
                    'floor' => (int) floor($raw),
                    'round' => (int) round($raw),
                    default => (int) ceil($raw),
                };
            } else {
                $reason = 'The policy quorum type is unsupported.';
            }
            if ($required !== null) {
                $state = $present >= $required ? 'passed' : 'failed';
            }
        } else {
            $reason = 'A reviewed policy snapshot and supported quorum rule are required.';
        }

        DB::table('association_governance_meetings')->where('id', $meeting)->update([
            'quorum_state' => $state, 'quorum_required' => $required, 'quorum_present' => $present,
            'quorum_denominator_value' => $denominator, 'quorum_manual_reason' => $reason,
            'quorum_confirmed_by' => $state === 'manual_required' ? null : $actor,
            'quorum_confirmed_at' => $state === 'manual_required' ? null : now(), 'updated_at' => now(),
        ]);

        return (object) compact('state', 'required', 'present', 'denominator', 'reason');
    }

    public static function finalizeDecision(string $association, string $meeting, string $agendaItem, int $actor): object
    {
        $record = DB::table('association_governance_meetings')->where('association_id', $association)->where('id', $meeting)->lockForUpdate()->firstOrFail();
        abort_unless($record->quorum_state === 'passed', 422, 'A passed quorum check is required before a decision can be finalized.');
        $policy = json_decode((string) $record->policy_snapshot, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($policy['reviewed'] ?? false) === true || ($policy['status'] ?? null) === 'reviewed', 422, 'A reviewed policy snapshot is required before a decision can be finalized.');
        $item = DB::table('association_governance_agenda_items')->where('association_id', $association)->where('meeting_id', $meeting)->where('id', $agendaItem)->lockForUpdate()->firstOrFail();
        $votes = DB::table('association_governance_votes')->where('association_id', $association)->where('meeting_id', $meeting)->where('agenda_item_id', $agendaItem)->get();
        $counts = ['for' => 0, 'against' => 0, 'abstain' => 0, 'recused' => 0];
        foreach ($votes as $vote) {
            if (array_key_exists($vote->choice, $counts)) {
                $counts[$vote->choice]++;
            }
        }
        $decisive = $counts['for'] + $counts['against'];
        $outcome = 'deferred';
        $rule = $policy['voting_rule'] ?? null;
        if ($rule === 'unanimity') {
            $outcome = $counts['for'] > 0 && $counts['against'] === 0 && $counts['abstain'] === 0 ? 'carried' : 'rejected';
        } elseif ($rule === 'percentage') {
            $threshold = (float) ($policy['majority_percent'] ?? 0);
            $outcome = $decisive > 0 && (($counts['for'] / $decisive) * 100) >= $threshold ? 'carried' : (($counts['for'] === $counts['against']) ? 'tie' : 'rejected');
        } elseif ($rule === 'ordinary_majority') {
            $outcome = $counts['for'] > $counts['against'] ? 'carried' : ($counts['for'] === $counts['against'] ? 'tie' : 'rejected');
        } else {
            abort(422, 'This voting rule requires a reviewed manual determination.');
        }

        $resolution = null;
        if ($outcome === 'carried') {
            $number = DB::table('association_governance_agenda_items')->where('association_id', $association)->whereNotNull('resolution_number')->count() + 1;
            $resolution = 'RES-'.now()->format('Y').'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        }
        DB::table('association_governance_agenda_items')->where('id', $agendaItem)->update([
            'for_count' => $counts['for'], 'against_count' => $counts['against'], 'abstained_count' => $counts['abstain'],
            'recused_count' => $counts['recused'], 'outcome' => $outcome, 'resolution_number' => $resolution, 'updated_at' => now(),
        ]);

        return (object) ['outcome' => $outcome, 'resolution' => $resolution, ...$counts];
    }

    public static function uuid(): string
    {
        return (string) Str::uuid();
    }
}
