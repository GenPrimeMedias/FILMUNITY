<?php

namespace App\Http\Middleware;

use App\Support\WorkspaceProtection;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ProtectWorkspaceMutation
{
    public function handle(Request $request, Closure $next, ?string $resource = null): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) || ! $request->user() || ! $request->user()->hasVerifiedEmail()) {
            return $next($request);
        }
        $action = $request->route()?->getActionName();
        $prefix = 'App\\Http\\Controllers\\';
        $resource ??= match ($action) {
            $prefix.'AssociationWorkspaceSetupController@update', $prefix.'AssociationMailController@save', $prefix.'AssociationMailController@review' => 'workspace',
            $prefix.'AccountingMastersController@group', $prefix.'AccountingMastersController@vendor',
            $prefix.'AccountingMastersController@sync', $prefix.'AccountingMastersController@classify' => 'masters',
            $prefix.'AssociationFinanceController@settings' => 'finance-policy',
            $prefix.'AssociationFinanceController@initialize', $prefix.'AssociationFinanceController@account',
            $prefix.'AssociationFinanceController@sync', $prefix.'AssociationFinanceController@close',
            $prefix.'AssociationFinanceController@save', $prefix.'AssociationFinanceController@decide',
            $prefix.'AssociationFinanceController@clear' => 'masters',
            $prefix.'AssociationTaxController@settings', $prefix.'AssociationTaxController@save' => 'tax-configuration',
            $prefix.'VendorTdsReviewController@store' => 'tax-configuration',
            $prefix.'TdsReconciliationController@record', $prefix.'TdsReconciliationController@match',
            $prefix.'TdsReconciliationController@release', $prefix.'CorrectionNoteController@save',
            $prefix.'BillingInvoiceReminderController@store', $prefix.'BillingInvoiceReminderController@retry',
            $prefix.'BillingInvoiceReminderController@resolve', $prefix.'RecurringMemberInvoiceController@save',
            $prefix.'RecurringMemberInvoiceController@generate', $prefix.'RecurringMemberInvoiceController@state',
            $prefix.'BillSettlementController@save', $prefix.'BillSettlementController@allocate',
            $prefix.'BillSettlementController@release', $prefix.'BillSettlementController@bridge',
            $prefix.'TallyXmlExportController@mapping' => 'masters',
            $prefix.'BoardManagementController@saveSetup', $prefix.'BoardManagementController@storeTerm',
            $prefix.'BoardManagementController@updateTerm', $prefix.'BoardManagementController@storeMeeting', $prefix.'BoardManagementController@sendMeetingNotice',
            $prefix.'BoardManagementController@storeParticipant', $prefix.'BoardManagementController@updateParticipant',
            $prefix.'BoardManagementController@updateAppointment', $prefix.'BoardManagementController@addReviewer',
            $prefix.'BoardManagementController@updateGovernanceSettings',
            $prefix.'BoardManagementController@portrait',
            $prefix.'BoardManagementController@storeAgendaItem', $prefix.'BoardManagementController@recordVote',
            $prefix.'BoardManagementController@finalizeDecision',
            $prefix.'BoardManagementController@updateMinutes', $prefix.'BoardManagementController@storeAction',
            $prefix.'BoardManagementController@updateAction', $prefix.'BoardManagementController@reassignAction' => 'board',
            default => null,
        };
        if ($resource === null) {
            return $next($request);
        }
        $association = (string) ($request->route('workspace') ?? $request->route('association'));
        if (! WorkspaceProtection::pinEnabled($association)) {
            return $next($request);
        }
        WorkspaceProtection::requireUnlock($request, $association);

        return DB::transaction(function () use ($request, $next, $resource, $association) {
            DB::table('association_workspace_protection')->where('association_id', $association)->lockForUpdate()->first();
            WorkspaceProtection::requireUnlock($request, $association);
            $row = WorkspaceProtection::leaseRow($association, $resource);
            if (! WorkspaceProtection::ownsLease($request, $row, (string) $request->header('X-Workspace-Lease')) || (string) $row->version !== (string) $request->header('X-Workspace-Version')) {
                throw ValidationException::withMessages(['protection' => 'Edit lease expired/replaced or this resource changed. Reload and reacquire before saving.']);
            }
            $response = $next($request);
            if ($response->getStatusCode() < 400) {
                DB::table('association_edit_leases')->where('association_id', $association)->where('resource', $resource)->update(['version' => $row->version + 1, 'token_hash' => null, 'expires_at' => null]);
                WorkspaceProtection::event($request, $association, 'protected_configuration_saved', $resource);
            }

            return $response;
        });
    }
}
