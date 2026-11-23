<?php

namespace App\Http\Controllers;

use App\Services\LegacyMembershipPreview;
use App\Services\LegacyMembershipTemplate;
use App\Support\AssociationWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class LegacyMembershipPreviewController extends Controller
{
    public function index(Request $request): Response
    {
        $associations = AssociationWorkspace::forAdmin($request->user())->orderBy('name')->get(['id', 'name']);
        abort_if($associations->isEmpty(), 404);

        return Inertia::render('legacy-membership-preview', ['associations' => $associations, 'fields' => LegacyMembershipPreview::FIELDS]);
    }

    public function preview(Request $request, string $association): JsonResponse
    {
        abort_unless(AssociationWorkspace::forAdmin($request->user())->where('id', $association)->exists(), 404);
        $data = $request->validate(['file' => ['required', 'file', 'max:2048', 'extensions:xls,xlsx,csv'], 'sheet' => ['required', 'integer', 'min:0'], 'header_row' => ['required', 'integer', 'between:1,20'], 'delimiter' => ['required', 'in:comma,semicolon,tab'], 'mapping' => ['sometimes', 'array:'.implode(',', LegacyMembershipPreview::FIELDS)], 'mapping.*' => ['nullable', 'integer', 'between:0,63'], 'source_timezone' => ['nullable', 'timezone']]);
        $delimiter = match ($data['delimiter']) {
            'semicolon' => ';', 'tab' => "\t", default => ','
        };
        try {
            $table = LegacyMembershipPreview::read($request->file('file'), (int) $data['sheet'], (int) $data['header_row'], $delimiter);
            $automatic = LegacyMembershipPreview::templateMapping($table['headers']);
            $mapping = array_key_exists('mapping', $data) ? $data['mapping'] : $automatic;
            $report = ! empty($mapping) ? LegacyMembershipPreview::analyze($association, $table, $mapping, $data['source_timezone'] ?? null) : null;
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['file' => 'Unable to parse this workbook safely. Check its format, encryption and structure.']);
        }

        return response()->json(['headers' => $table['headers'], 'mapping' => $mapping, 'mapping_mode' => array_key_exists('mapping', $data) ? 'manual' : ($automatic ? 'automatic' : 'unmapped'), 'sheets' => $table['sheets'], 'sha256' => $table['sha256'], 'sample' => array_map(fn ($row) => array_column($row, 'value'), array_slice($table['source_rows'], 0, 5)), 'report' => $report, 'preview_only' => true])->header('Cache-Control', 'private, no-store');
    }

    public function template(Request $request, string $association): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless(AssociationWorkspace::forAdmin($request->user())->where('id', $association)->exists(), 404);
        $data = $request->validate(['format' => ['required', 'in:xlsx,csv,example']]);
        if ($data['format'] === 'csv') {
            return response(LegacyMembershipTemplate::csv())->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="filmunity-members-blank.csv"')->header('Cache-Control', 'private, no-store');
        }
        $filename = $data['format'] === 'example' ? 'filmunity-members-example.xlsx' : 'filmunity-members-blank.xlsx';

        return response()->download(resource_path('templates/legacy-membership/'.$filename), $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }
}
