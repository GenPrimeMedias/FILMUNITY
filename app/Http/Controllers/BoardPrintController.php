<?php

namespace App\Http\Controllers;

use App\Support\WorkspaceProtection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class BoardPrintController
{
    public function show(Request $request, string $association, string $kind): Response
    {
        WorkspaceProtection::owner($request, $association);
        abort_unless(in_array($kind, ['directory', 'meeting', 'attendance', 'minutes', 'handover'], true), 404);
        $meeting = $request->query('meeting');
        $record = $meeting ? DB::table('association_governance_meetings')->where('association_id', $association)->where('id', $meeting)->first() : null;
        $data = [
            'kind' => $kind,
            'association' => DB::table('association_registration_applications')->where('id', $association)->first(['name']),
            'appointments' => DB::table('association_governance_appointments as appointment')->join('association_governance_positions as position', 'position.id', '=', 'appointment.position_id')->leftJoin('users as member', 'member.id', '=', 'appointment.member_id')->where('appointment.association_id', $association)->select('appointment.*', 'position.title as position_title', 'member.name as member_name')->orderBy('position.display_order')->get(),
            'meeting' => $record,
            'participants' => $record ? DB::table('association_governance_participants')->where('association_id', $association)->where('meeting_id', $record->id)->get() : collect(),
            'agenda' => $record ? DB::table('association_governance_agenda_items')->where('association_id', $association)->where('meeting_id', $record->id)->orderBy('sequence')->get() : collect(),
            'minutes' => $record ? DB::table('association_governance_minutes_versions')->where('association_id', $association)->where('meeting_id', $record->id)->whereIn('state', ['approved', 'published'])->orderByDesc('version')->first() : null,
            'history' => DB::table('association_governance_appointment_history')->where('association_id', $association)->orderByDesc('created_at')->get(),
        ];

        return response()->view('print.board', $data)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('Referrer-Policy', 'no-referrer');
    }

    public function calendar(Request $request, string $association, string $meeting): Response
    {
        WorkspaceProtection::owner($request, $association);
        $record = DB::table('association_governance_meetings')->where('association_id', $association)->where('id', $meeting)->firstOrFail();
        $stamp = now()->utc()->format('Ymd\THis\Z');
        $start = Carbon::parse($record->starts_at)->utc()->format('Ymd\THis\Z');
        $end = $record->ends_at ? Carbon::parse($record->ends_at)->utc()->format('Ymd\THis\Z') : Carbon::parse($record->starts_at)->utc()->addHour()->format('Ymd\THis\Z');
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//FILMUNITY//Board//EN\r\nBEGIN:VEVENT\r\nUID:{$record->id}@filmunity\r\nDTSTAMP:{$stamp}\r\nDTSTART:{$start}\r\nDTEND:{$end}\r\nSUMMARY:".addcslashes($record->title, ',;\\')."\r\nLOCATION:".addcslashes((string) $record->venue, ',;\\')."\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        return response($ics, 200, ['Content-Type' => 'text/calendar; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="board-meeting.ics"', 'Cache-Control' => 'private, no-store']);
    }
}
