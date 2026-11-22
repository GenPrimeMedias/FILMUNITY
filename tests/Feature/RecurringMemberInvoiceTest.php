<?php

namespace Tests\Feature;

use App\Services\Accounting\BillSettlementService as Bills;
use App\Services\Accounting\RecurringMemberInvoices;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FinanceWorkflowFixtures;
use Tests\TestCase;

class RecurringMemberInvoiceTest extends TestCase
{
    use \Tests\Support\WorkspaceMutationRequests;

    use DatabaseTransactions;
    use FinanceWorkflowFixtures;

    public function test_recurring_monthly_drafts_duplicates_pause_resume_and_frozen_lines(): void
    {
        [$owner, $id] = $this->association(true);
        [$party] = $this->member($owner, $id);
        $counter = DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', 'yearly')->value('id');
        $otherCounter = DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', 'other')->value('id');
        $url = "/association-finance/$id/billing/templates";
        $data = ['name' => 'Monthly synthetic member charges', 'party_account_id' => $party, 'starts_on' => '2027-01-31', 'due_days' => 7, 'items' => [['counter_account_id' => $counter, 'description' => 'Original monthly charge', 'amount' => '10.01'], ['counter_account_id' => $otherCounter, 'description' => 'Second charge', 'amount' => '2.02']]];
        $this->actingAs($owner)->postWithWorkspaceLease($url, $data)->assertSessionHasNoErrors();
        $template = DB::table('association_invoice_templates')->where('association_id', $id)->value('id');
        CarbonImmutable::setTestNow('2027-01-30 12:00:00');
        $this->postWithWorkspaceLease("$url/generate")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('association_invoice_template_runs', 0);
        CarbonImmutable::setTestNow('2027-01-31 12:00:00');
        $this->postWithWorkspaceLease("$url/generate")->assertSessionHasNoErrors();
        $this->postWithWorkspaceLease("$url/generate")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('association_invoice_template_runs', 1);
        $run = DB::table('association_invoice_template_runs')->where('template_id', $template)->first();
        $this->assertDatabaseHas('association_finance_vouchers', ['id' => $run->voucher_id, 'status' => 'draft', 'entry_date' => '2027-01-31']);
        $this->assertDatabaseHas('association_bill_documents', ['voucher_id' => $run->voucher_id, 'due_date' => '2027-02-07']);
        $this->assertSame('12.03', Bills::remaining($run->voucher_id));
        $before = DB::table('association_finance_lines')->where('voucher_id', $run->voucher_id)->orderBy('id')->get()->toJson();
        try {
            DB::transaction(fn () => DB::table('association_invoice_template_runs')->insert([...((array) $run), 'id' => (string) Str::uuid()]));
            $this->fail('The database must reject a second run for the same template and period.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0]);
        }
        CarbonImmutable::setTestNow('2027-03-31 12:00:00');
        $this->postWithWorkspaceLease("$url/generate")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('association_invoice_template_runs', 3);
        $this->assertDatabaseHas('association_finance_vouchers', ['entry_date' => '2027-02-28', 'kind' => 'invoice']);
        $this->assertDatabaseHas('association_finance_vouchers', ['entry_date' => '2027-03-31', 'kind' => 'invoice']);
        $this->postWithWorkspaceLease("$url/$template/state", ['paused' => true])->assertSessionHasNoErrors();
        CarbonImmutable::setTestNow('2027-06-15 12:00:00');
        $this->postWithWorkspaceLease("$url/generate")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('association_invoice_template_runs', 3);
        $this->postWithWorkspaceLease("$url/$template/state", ['paused' => false])->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertInertia(fn (Page $p) => $p->where('templates.0.next_billing_date', '2027-06-30')->has('runs', 3));
        $this->postWithWorkspaceLease("$url/$template", [...$data, 'due_days' => 2, 'items' => [['counter_account_id' => $counter, 'description' => 'Future edited charge', 'amount' => '20.00']]])->assertSessionHasNoErrors();
        $this->assertSame($run->snapshot, DB::table('association_invoice_template_runs')->where('id', $run->id)->value('snapshot'));
        $this->assertSame($before, DB::table('association_finance_lines')->where('voucher_id', $run->voucher_id)->orderBy('id')->get()->toJson());
        $this->get("/association-finance/$id/billing/documents/$run->voucher_id")->assertOk()->assertSee('Original monthly charge')->assertSee('10.01');
        CarbonImmutable::setTestNow('2027-06-30 12:00:00');
        $this->postWithWorkspaceLease("$url/generate")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('association_invoice_template_runs', 4);
        $this->assertDatabaseMissing('association_invoice_template_runs', ['template_id' => $template, 'period' => '2027-04-01']);
        $this->assertDatabaseMissing('association_invoice_template_runs', ['template_id' => $template, 'period' => '2027-05-01']);
        $this->assertDatabaseHas('association_bill_documents', ['due_date' => '2027-07-02']);
        $this->postWithWorkspaceLease("$url/$template/state", ['paused' => true]);
        $this->postWithWorkspaceLease("$url/$template/state", ['paused' => false]);
        $this->postWithWorkspaceLease("$url/generate")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('association_invoice_template_runs', 4);
        foreach ([['2028-02-01', 31, '2028-02-29'], ['2027-02-01', 29, '2027-02-28'], ['2027-04-01', 31, '2027-04-30'], ['2027-03-01', 31, '2027-03-31']] as [$period, $anchor, $expected]) {
            $this->assertSame($expected, RecurringMemberInvoices::date($period, $anchor));
        }
        [$other, $otherId] = $this->association(true);
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->postWithWorkspaceLease("$url/generate")->assertNotFound();
        $this->postWithWorkspaceLease("/association-finance/$otherId/billing/templates", $data)->assertSessionHasErrors('finance');
        $this->postWithWorkspaceLease("/association-finance/$otherId/billing/templates/$template", $data)->assertNotFound();
        $this->postWithWorkspaceLease("/association-finance/$otherId/billing/templates/$template/state", ['paused' => true])->assertNotFound();
        $this->postWithWorkspaceLease("/association-finance/$otherId/billing/templates/generate")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('association_invoice_template_runs', 4);
    }

    public function test_scheduled_recurring_generation_reuses_manual_drafts_skips_paused_and_retries_failed_templates(): void
    {
        [$owner, $id] = $this->association(true);
        [$member] = $this->member($owner, $id);
        [$other, $otherId] = $this->association(true);
        [$otherMember] = $this->member($other, $otherId);
        $counter = DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', 'yearly')->value('id');
        $otherCounter = DB::table('association_finance_accounts')->where('association_id', $otherId)->where('system_key', 'yearly')->value('id');
        $badCounter = DB::table('association_finance_accounts')->where('association_id', $otherId)->where('system_key', 'other')->value('id');
        $data = ['name' => 'Scheduled synthetic charges', 'party_account_id' => $member, 'starts_on' => '2027-01-31', 'due_days' => 7, 'items' => [['counter_account_id' => $counter, 'description' => 'Monthly scheduled line', 'amount' => '10.01']]];
        $manual = RecurringMemberInvoices::save($id, $owner, $data);
        $otherData = [...$data, 'party_account_id' => $otherMember, 'items' => [['counter_account_id' => $otherCounter, 'description' => 'Other tenant monthly charge', 'amount' => '20.02']]];
        $good = RecurringMemberInvoices::save($otherId, $other, $otherData);
        $paused = RecurringMemberInvoices::save($otherId, $other, $otherData);
        $bad = RecurringMemberInvoices::save($otherId, $other, [...$otherData, 'items' => [['counter_account_id' => $badCounter, 'description' => 'Retryable charge', 'amount' => '3.03']]]);
        RecurringMemberInvoices::pause($otherId, $other, $paused, true);
        DB::table('association_finance_accounts')->where('id', $badCounter)->update(['active' => false]);
        CarbonImmutable::setTestNow('2027-01-31 12:00:00');
        $this->actingAs($owner)->postWithWorkspaceLease("/association-finance/$id/billing/templates/generate")->assertSessionHasNoErrors();
        $manualRun = DB::table('association_invoice_template_runs')->where('template_id', $manual)->first();
        $this->artisan('filmunity:generate-recurring-invoices')->assertExitCode(1);
        $this->assertSame(1, DB::table('association_invoice_template_runs')->where('template_id', $manual)->count());
        $this->assertSame(1, DB::table('association_invoice_template_runs')->where('template_id', $good)->count());
        $this->assertSame(0, DB::table('association_invoice_template_runs')->whereIn('template_id', [$bad, $paused])->count());
        $this->assertDatabaseHas('association_invoice_templates', ['id' => $bad, 'next_period' => '2027-01-01']);
        $outcomes = DB::table('association_finance_events')->where('association_id', $otherId)->where('action', 'recurring_scheduled_outcome')->pluck('note')->map(fn ($note) => json_decode($note, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('failed', $outcomes->firstWhere('template', $bad)['outcome']);
        $this->assertSame('skipped', $outcomes->firstWhere('template', $paused)['outcome']);
        $this->assertSame('paused', $outcomes->firstWhere('template', $paused)['reason']);
        $this->assertSame('generated', $outcomes->firstWhere('template', $good)['outcome']);
        DB::table('association_finance_accounts')->where('id', $badCounter)->update(['active' => true]);
        $this->artisan('filmunity:generate-recurring-invoices')->assertExitCode(0);
        $this->artisan('filmunity:generate-recurring-invoices')->assertExitCode(0);
        $this->actingAs($other)->postWithWorkspaceLease("/association-finance/$otherId/billing/templates/generate")->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('association_invoice_template_runs')->where('template_id', $bad)->count());
        $this->assertSame(1, DB::table('association_invoice_template_runs')->where('template_id', $good)->count());
        $this->assertSame($manualRun->snapshot, DB::table('association_invoice_template_runs')->where('id', $manualRun->id)->value('snapshot'));
        $this->assertSame(0, DB::table('association_finance_vouchers')->whereIn('association_id', [$id, $otherId])->where('status', '<>', 'draft')->count());
        CarbonImmutable::setTestNow('2027-02-28 12:00:00');
        $this->artisan('filmunity:generate-recurring-invoices')->assertExitCode(0);
        $this->assertDatabaseHas('association_finance_vouchers', ['association_id' => $id, 'entry_date' => '2027-02-28', 'status' => 'draft']);
        $this->assertSame(0, DB::table('association_invoice_template_runs')->where('template_id', $paused)->count());
        $this->get("/association-finance/$otherId/billing/templates")->assertInertia(fn (Page $p) => $p->has('activity')->has('runs', 4));
    }
}
