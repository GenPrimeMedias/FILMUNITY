<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\Accounting\AccountingMasters;
use App\Services\Accounting\BillSettlementService as Bills;
use App\Support\AssociationFinance as Finance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;
class BillSettlementTest extends TestCase {
 use DatabaseTransactions;
 protected function setUp():void{parent::setUp();$this->withoutVite();Mail::fake();Storage::fake('local');config(['association-registration.reviewer_ids'=>[]]);\Carbon\Carbon::setTestNow('2026-11-20 12:00:00');\Carbon\CarbonImmutable::setTestNow('2026-11-20 12:00:00');}
 protected function tearDown():void{\Carbon\Carbon::setTestNow();\Carbon\CarbonImmutable::setTestNow();parent::tearDown();}
 public function test_billing_reads_and_mutations_are_owner_scoped():void{
  [$owner,$id]=$this->association(true);$this->actingAs(User::factory()->create())->get("/association-finance/$id/billing")->assertNotFound();$this->post("/association-finance/$id/billing/allocations",[])->assertNotFound();$this->actingAs($owner)->get("/association-finance/$id/billing")->assertOk()->assertInertia(fn(Page $p)=>$p->component('association-billing'));
 }
 public function test_partial_settlement_advance_and_idempotent_allocation():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);$invoice=$this->entry($owner,$id,$party,'invoice','10000.00');$receipt=$this->entry($owner,$id,$party,'receipt','6000.00');$data=$this->allocation($receipt,$invoice,'6000.00');Bills::allocate($id,$owner,$data);Bills::allocate($id,$owner,$data);$this->assertSame('4000.00',Bills::remaining($invoice));$this->assertSame('0.00',Bills::remaining($receipt));
  $second=$this->entry($owner,$id,$party,'receipt','5000.00');Bills::allocate($id,$owner,$this->allocation($second,$invoice,'4000.00'));$this->assertSame('1000.00',Bills::remaining($second));$later=$this->entry($owner,$id,$party,'invoice','1000.00');Bills::allocate($id,$owner,$this->allocation($second,$later,'1000.00'));$this->assertSame('0.00',Bills::remaining($second));$this->assertSame(3,DB::table('association_bill_allocations')->where('association_id',$id)->count());
 }
 public function test_vendor_bill_and_payment_have_correct_sides():void{
  [$owner,$id]=$this->association(true);AccountingMasters::vendor($id,$owner,['name'=>'Printer','reference'=>'PRINTER','email'=>null]);$party=DB::table('association_finance_accounts')->where('association_id',$id)->whereNotNull('vendor_id')->value('id');$bill=$this->entry($owner,$id,$party,'bill','10000.00');$payment=$this->entry($owner,$id,$party,'payment','3000.00');Bills::allocate($id,$owner,$this->allocation($payment,$bill,'3000.00'));$this->assertSame('7000.00',Bills::remaining($bill));$this->assertDatabaseHas('association_finance_lines',['voucher_id'=>$bill,'account_id'=>$party,'credit_minor'=>1000000]);$this->assertDatabaseHas('association_finance_lines',['voucher_id'=>$payment,'account_id'=>$party,'debit_minor'=>300000]);
 }
 public function test_over_allocation_cross_party_and_drafts_are_rejected():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);[$other]=$this->member($owner,$id);$inv=$this->entry($owner,$id,$party,'invoice','100.00');$receipt=$this->entry($owner,$id,$party,'receipt','101.00');$foreign=$this->entry($owner,$id,$other,'invoice','100.00');$draft=$this->entry($owner,$id,$party,'receipt','100.00',false);$this->actingAs($owner);
  foreach([$this->allocation($receipt,$inv,'100.01'),$this->allocation($receipt,$foreign,'1.00'),$this->allocation($draft,$inv,'1.00')] as $data)$this->post("/association-finance/$id/billing/allocations",$data)->assertSessionHasErrors('finance');
 }
 public function test_bill_draft_edits_retain_party_and_number_and_evidence_is_private():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);$data=$this->data($id,$party,'invoice','100.00');$data['evidence']=UploadedFile::fake()->create('bill.pdf',20,'application/pdf');$this->actingAs($owner)->post("/association-finance/$id/billing/documents",$data)->assertSessionHasNoErrors();$v=DB::table('association_bill_documents')->where('client_token',$data['client_token'])->value('voucher_id');$number=DB::table('association_finance_vouchers')->where('id',$v)->value('number');$this->assertStringStartsWith('INV-2026-27-',$number);$this->get("/association-finance/$id/vouchers/$v/evidence")->assertDownload();
  unset($data['evidence']);$data['amount']='125.00';$this->post("/association-finance/$id/billing/documents/$v",$data)->assertSessionHasNoErrors();$this->assertSame($number,DB::table('association_finance_vouchers')->where('id',$v)->value('number'));$this->assertSame('125.00',Bills::remaining($v));
  $old=['entry_date'=>'2026-11-10','kind'=>'journal','description'=>'Bypass','lines'=>[['account_id'=>$party,'debit'=>'1','credit'=>'0'],['account_id'=>$this->account($id,'yearly'),'debit'=>'0','credit'=>'1']]];$this->post("/association-finance/$id/vouchers/$v",$old)->assertSessionHasErrors('finance');
  $this->actingAs(User::factory()->create())->get("/association-finance/$id/vouchers/$v/evidence")->assertNotFound();
 }
 public function test_posted_billing_query_returns_exact_aging_and_available_advances():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);$inv=$this->entry($owner,$id,$party,'invoice','100.00');$receipt=$this->entry($owner,$id,$party,'receipt','30.00');Bills::allocate($id,$owner,$this->allocation($receipt,$inv,'30.00'));
  $this->actingAs($owner)->get("/association-finance/$id/billing?open=yes&sort=due_date&direction=asc")->assertOk()->assertInertia(fn(Page $p)=>$p->where('records.total',1)->where('records.data.0.remaining','70.00')->where('aging.0.outstanding','70.00')->where('aging.0.days_1_30','70.00'));
 }
 public function test_help_worked_invoice_example_reconciles_to_ledger_and_aging():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);
  $invoice=$this->entry($owner,$id,$party,'invoice','5000.00');$receipt=$this->entry($owner,$id,$party,'receipt','2000.00');
  Bills::allocate($id,$owner,$this->allocation($receipt,$invoice,'2000.00'));
  $this->assertSame('3000.00',Bills::remaining($invoice));$this->assertSame('0.00',Bills::remaining($receipt));
  $ledger=DB::table('association_finance_lines')->where('account_id',$party)->selectRaw('sum(debit_minor) as debit, sum(credit_minor) as credit')->first();
  $this->assertSame(500000,(int)$ledger->debit);$this->assertSame(200000,(int)$ledger->credit);
  $this->actingAs($owner)->get("/association-finance/$id/billing?flow=invoice&open=yes")->assertOk()->assertInertia(fn(Page $p)=>$p->where('records.data.0.remaining','3000.00')->where('records.data.0.allocations.0.amount','2000.00')->where('aging.0.outstanding','3000.00'));
 }
 public function test_billing_filters_accept_missing_sort_and_retain_explicit_sort():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);$invoice=$this->entry($owner,$id,$party,'invoice','100.00');$this->entry($owner,$id,$party,'receipt','30.00');
  $number=DB::table('association_finance_vouchers')->where('id',$invoice)->value('number');
  foreach(['q'=> $number,'flow'=>'invoice','status'=>'posted','open'=>'yes'] as $key=>$value){
   foreach([[],['sort'=>'number','direction'=>'asc']] as $ordering){
    $this->actingAs($owner)->get("/association-finance/$id/billing?".http_build_query([$key=>$value,...$ordering]))->assertOk()->assertInertia(function(Page $page)use($key,$value,$ordering){$page->component('association-billing')->where('filters.'.$key,$value);if($ordering)$page->where('filters.sort','number')->where('filters.direction','asc');});
   }
  }
 }
 public function test_invoice_reminder_preview_rechecks_partial_balances_and_prevents_duplicate_fake_delivery():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);
  $open=$this->entry($owner,$id,$party,'invoice','100.00');
  $partial=$this->entry($owner,$id,$party,'invoice','100.00');
  $paid=$this->entry($owner,$id,$party,'invoice','100.00');
  $draft=$this->entry($owner,$id,$party,'invoice','100.00',false);
  $reversed=$this->entry($owner,$id,$party,'invoice','100.00');Finance::decide($id,$reversed,$owner,'reverse','Reviewed reversal','2026-11-20');
  $receipt=$this->entry($owner,$id,$party,'receipt','300.00');
  Bills::allocate($id,$owner,$this->allocation($receipt,$partial,'30.00'));Bills::allocate($id,$owner,$this->allocation($receipt,$paid,'100.00'));
  $base="/association-finance/$id/billing/reminders";
  $this->actingAs($owner)->get($base)->assertOk()->assertInertia(fn(Page $p)=>$p->component('billing-invoice-reminders')->has('preview',2));
  $rows=collect(\App\Services\Accounting\BillingInvoiceReminders::preview($id));
  $this->assertSame('70.00',$rows->firstWhere('id',$partial)->outstanding);
  $this->assertStringContainsString('partially paid',$rows->firstWhere('id',$partial)->reason);
  foreach([$draft,$paid,$reversed] as $excluded)$this->post($base,['voucher_id'=>$excluded,'confirmed'=>true])->assertSessionHasErrors('finance');
  \App\Services\Accounting\BillingInvoiceReminders::dispatch($id,$owner,\App\Services\Accounting\BillingInvoiceReminders::request($id,$owner,$open));
  $this->assertDatabaseHas('association_invoice_reminders',['voucher_id'=>$open,'status'=>'disabled','attempts'=>1]);Mail::assertNothingSent();
  config(['billing-reminders.testing_delivery'=>true]);
  $notice=\App\Services\Accounting\BillingInvoiceReminders::request($id,$owner,$partial);
  $this->assertSame($notice,\App\Services\Accounting\BillingInvoiceReminders::request($id,$owner,$partial));
  Bills::allocate($id,$owner,$this->allocation($receipt,$partial,'10.00'));
  \App\Services\Accounting\BillingInvoiceReminders::dispatch($id,$owner,$notice);
  \App\Services\Accounting\BillingInvoiceReminders::dispatch($id,$owner,$notice);
  $this->post($base,['voucher_id'=>$partial,'confirmed'=>true])->assertSessionHasNoErrors();
  Mail::assertSent(\App\Mail\BillingInvoiceReminder::class,fn($mail)=>$mail->invoice['outstanding']==='60.00');Mail::assertSentCount(1);
  $this->assertSame(1,DB::table('association_invoice_reminders')->where('voucher_id',$partial)->count());
  $this->assertDatabaseHas('association_invoice_reminders',['id'=>$notice,'status'=>'simulated','attempts'=>1]);
  $row=DB::table('association_invoice_reminders')->where('id',$notice)->first();
  try{DB::transaction(fn()=>DB::table('association_invoice_reminders')->insert([...((array)$row),'id'=>(string)Str::uuid()]));$this->fail('Duplicate daily occurrence must be rejected.');}catch(\Illuminate\Database\QueryException $e){$this->assertSame('23505',$e->errorInfo[0]);}
  $waiting=\App\Services\Accounting\BillingInvoiceReminders::request($id,$owner,$open);
  Bills::allocate($id,$owner,$this->allocation($receipt,$open,'100.00'));
  \App\Services\Accounting\BillingInvoiceReminders::dispatch($id,$owner,$waiting);
  $this->assertDatabaseHas('association_invoice_reminders',['id'=>$waiting,'status'=>'cancelled']);Mail::assertSentCount(1);
  $pending=$this->entry($owner,$id,$party,'invoice','9.99');$pendingNotice=\App\Services\Accounting\BillingInvoiceReminders::request($id,$owner,$pending);
  Finance::decide($id,$pending,$owner,'reverse','No longer due','2026-11-20');\App\Services\Accounting\BillingInvoiceReminders::dispatch($id,$owner,$pendingNotice);
  $this->assertDatabaseHas('association_invoice_reminders',['id'=>$pendingNotice,'status'=>'cancelled']);Mail::assertSentCount(1);
  [$other,$otherId]=$this->association(true);
  $this->actingAs($other)->get($base)->assertNotFound();$this->post($base,['voucher_id'=>$partial,'confirmed'=>true])->assertNotFound();
  $this->post("/association-finance/$otherId/billing/reminders",['voucher_id'=>$partial,'confirmed'=>true])->assertSessionHasErrors('finance');
  $this->post("/association-finance/$otherId/billing/reminders/$notice/retry",['confirmed'=>true])->assertNotFound();
  $this->get("/association-finance/$otherId/billing/reminders")->assertInertia(fn(Page $p)=>$p->has('preview',0)->has('reminders',0));
 }
 public function test_invoice_reminder_failed_fake_retry_recipient_change_and_no_live_transport():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);
  $invoice=$this->entry($owner,$id,$party,'invoice','10.01');
  config(['billing-reminders.testing_delivery'=>true]);
  $fake=new class(Mail::getFacadeRoot()->manager) extends \Illuminate\Support\Testing\Fakes\MailFake {
   public bool $failNext=true;
   public function send($view,array $data=[],$callback=null){if($this->failNext){$this->failNext=false;throw new \App\Exceptions\BillingReminderNotSent('Synthetic fake delivery failure');}parent::send($view,$data,$callback);}
  };
  Mail::swap($fake);
  $notice=\App\Services\Accounting\BillingInvoiceReminders::request($id,$owner,$invoice);
  \App\Services\Accounting\BillingInvoiceReminders::dispatch($id,$owner,$notice);
  $this->assertDatabaseHas('association_invoice_reminders',['id'=>$notice,'status'=>'failed','attempts'=>1]);Mail::assertNothingSent();
  \App\Services\Accounting\BillingInvoiceReminders::dispatch($id,$owner,$notice);
  \App\Services\Accounting\BillingInvoiceReminders::dispatch($id,$owner,$notice);
  Mail::assertSentCount(1);$this->assertDatabaseHas('association_invoice_reminders',['id'=>$notice,'status'=>'simulated','attempts'=>2]);
  $pending=$this->entry($owner,$id,$party,'invoice','9.99');
  $waiting=\App\Services\Accounting\BillingInvoiceReminders::request($id,$owner,$pending);
  $membership=DB::table('association_finance_accounts')->where('id',$party)->value('membership_id');
  $memberId=DB::table('association_memberships')->where('id',$membership)->value('member_id');
  DB::table('users')->where('id',$memberId)->update(['email'=>'changed-synthetic@example.test']);
  \App\Services\Accounting\BillingInvoiceReminders::dispatch($id,$owner,$waiting);
  $this->assertDatabaseHas('association_invoice_reminders',['id'=>$waiting,'status'=>'cancelled']);Mail::assertSentCount(1);
  $guarded=\App\Services\Accounting\BillingInvoiceReminders::request($id,$owner,$pending);
  $this->assertNotSame($waiting,$guarded);
  Mail::swap($fake->manager);
  \App\Services\Accounting\BillingInvoiceReminders::dispatch($id,$owner,$guarded);
  Mail::swap($fake);
  $this->assertDatabaseHas('association_invoice_reminders',['id'=>$guarded,'status'=>'disabled']);Mail::assertSentCount(1);
  DB::table('users')->where('id',$memberId)->update(['email_verified_at'=>null]);
  $this->assertFalse(collect(\App\Services\Accounting\BillingInvoiceReminders::preview($id))->firstWhere('id',$pending)->eligible);
 }
 public function test_database_queue_reminder_delivery_retry_paid_after_queue_and_completed_job_replay():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);
  config(['billing-reminders.testing_delivery'=>true,'queue.connections.database.connection'=>'pgsql']);
  $base="/association-finance/$id/billing/reminders";
  $invoice=$this->entry($owner,$id,$party,'invoice','10.01');
  $this->actingAs($owner)->post($base,['voucher_id'=>$invoice,'confirmed'=>true])->assertSessionHasNoErrors();
  $notice=DB::table('association_invoice_reminders')->where('voucher_id',$invoice)->first();
  $this->assertSame('queued',$notice->status);Mail::assertNothingSent();
  $this->post($base,['voucher_id'=>$invoice,'confirmed'=>true])->assertSessionHasNoErrors();
  $this->assertSame(1,DB::table('jobs')->where('queue','billing-reminders')->count());
  $work=fn()=> $this->artisan('queue:work',['connection'=>'database','--queue'=>'billing-reminders','--once'=>true,'--tries'=>1,'--sleep'=>0])->assertExitCode(0);
  $work();
  $this->assertDatabaseHas('association_invoice_reminders',['id'=>$notice->id,'status'=>'sent','attempts'=>1]);Mail::assertSentCount(1);
  (new \App\Jobs\DeliverBillingInvoiceReminder($id,$notice->id,$notice->queue_token))->handle();
  $this->post("$base/$notice->id/retry",['confirmed'=>true])->assertSessionHasNoErrors();Mail::assertSentCount(1);
  $this->assertSame(0,DB::table('jobs')->where('queue','billing-reminders')->count());
  $fake=new class(Mail::getFacadeRoot()->manager) extends \Illuminate\Support\Testing\Fakes\MailFake{
   public bool $failNext=true;
   public function send($view,array $data=[],$callback=null){if($this->failNext){$this->failNext=false;throw new \App\Exceptions\BillingReminderNotSent('Synthetic no delivery');}parent::send($view,$data,$callback);}
  };Mail::swap($fake);
  $retryInvoice=$this->entry($owner,$id,$party,'invoice','20.02');
  $this->post($base,['voucher_id'=>$retryInvoice,'confirmed'=>true])->assertSessionHasNoErrors();
  $retry=DB::table('association_invoice_reminders')->where('voucher_id',$retryInvoice)->first();
  $work();$this->assertDatabaseHas('association_invoice_reminders',['id'=>$retry->id,'status'=>'failed','attempts'=>1]);Mail::assertNothingSent();
  $this->post("$base/$retry->id/retry",['confirmed'=>true])->assertSessionHasNoErrors();$work();
  $this->assertDatabaseHas('association_invoice_reminders',['id'=>$retry->id,'status'=>'sent','attempts'=>2]);Mail::assertSentCount(1);
  (new \App\Jobs\DeliverBillingInvoiceReminder($id,$retry->id,$retry->queue_token))->handle();Mail::assertSentCount(1);
  $paid=$this->entry($owner,$id,$party,'invoice','30.03');
  $this->post($base,['voucher_id'=>$paid,'confirmed'=>true])->assertSessionHasNoErrors();
  $receipt=$this->entry($owner,$id,$party,'receipt','30.03');Bills::allocate($id,$owner,$this->allocation($receipt,$paid,'30.03'));
  $work();$this->assertDatabaseHas('association_invoice_reminders',['voucher_id'=>$paid,'status'=>'skipped']);Mail::assertSentCount(1);
  $this->get($base)->assertInertia(fn(Page $p)=>$p->has('activity')->has('reminders',3));
 }
 public function test_uncertain_reminder_requires_authorized_review_and_stale_claim_cannot_resend():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);
  config(['billing-reminders.testing_delivery'=>true,'queue.connections.database.connection'=>'pgsql']);
  $base="/association-finance/$id/billing/reminders";
  $fake=new class(Mail::getFacadeRoot()->manager) extends \Illuminate\Support\Testing\Fakes\MailFake{
   public function send($view,array $data=[],$callback=null){parent::send($view,$data,$callback);throw new \RuntimeException('Synthetic acknowledgement lost');}
  };Mail::swap($fake);
  $invoice=$this->entry($owner,$id,$party,'invoice','10.01');
  $this->actingAs($owner)->post($base,['voucher_id'=>$invoice,'confirmed'=>true])->assertSessionHasNoErrors();
  $row=DB::table('association_invoice_reminders')->where('voucher_id',$invoice)->first();
  $this->artisan('queue:work',['connection'=>'database','--queue'=>'billing-reminders','--once'=>true,'--tries'=>1,'--sleep'=>0])->assertExitCode(0);
  Mail::assertSentCount(1);$this->assertDatabaseHas('association_invoice_reminders',['id'=>$row->id,'status'=>'uncertain']);
  $this->post("$base/$row->id/retry",['confirmed'=>true])->assertSessionHasErrors('finance');
  (new \App\Jobs\DeliverBillingInvoiceReminder($id,$row->id,$row->queue_token))->handle();Mail::assertSentCount(1);
  $this->get($base)->assertInertia(fn(Page $p)=>$p->where('reminders.0.needs_review',true));
  [$other,$otherId]=$this->association(true);
  $this->actingAs($other)->post("/association-finance/$otherId/billing/reminders/$row->id/resolve",['allow_retry'=>true,'reason'=>'Foreign review'])->assertNotFound();
  $this->actingAs($owner)->post("$base/$row->id/resolve",['allow_retry'=>false,'reason'=>'Confirmed fake accepted; do not resend'])->assertSessionHasNoErrors();
  $this->assertDatabaseHas('association_invoice_reminders',['id'=>$row->id,'status'=>'skipped']);
  $pending=$this->entry($owner,$id,$party,'invoice','5.00');
  $pendingId=\App\Services\Accounting\BillingInvoiceReminders::request($id,$owner,$pending);
  DB::table('association_invoice_reminders')->where('id',$pendingId)->update(['status'=>'sending','queue_token'=>(string)Str::uuid(),'attempted_at'=>now()->subMinutes(6)]);
  $this->post("$base/$pendingId/retry",['confirmed'=>true])->assertSessionHasErrors('finance');
  $this->post("$base/$pendingId/resolve",['allow_retry'=>true,'reason'=>'Verified worker stopped before fake acceptance'])->assertSessionHasNoErrors();
  $this->assertDatabaseHas('association_invoice_reminders',['id'=>$pendingId,'status'=>'failed','queue_token'=>null]);
  Mail::fake();
  $this->post("$base/$pendingId/retry",['confirmed'=>true])->assertSessionHasNoErrors();
  $this->artisan('queue:work',['connection'=>'database','--queue'=>'billing-reminders','--once'=>true,'--tries'=>1,'--sleep'=>0])->assertExitCode(0);
  $this->assertDatabaseHas('association_invoice_reminders',['id'=>$pendingId,'status'=>'sent']);Mail::assertSentCount(1);
 }
 public function test_release_reopens_bill_and_reversal_requires_allocations_removed():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);$inv=$this->entry($owner,$id,$party,'invoice','100.00');$receipt=$this->entry($owner,$id,$party,'receipt','100.00');Bills::allocate($id,$owner,$this->allocation($receipt,$inv,'100.00'));$x=DB::table('association_bill_allocations')->where('settlement_id',$receipt)->value('id');
  $this->actingAs($owner)->post("/association-finance/$id/vouchers/$inv/decision",['action'=>'reverse','entry_date'=>'2026-11-20','note'=>'Correction','confirmed'=>true])->assertSessionHasErrors('finance');Bills::release($id,$owner,$x,'Wrong allocation');$this->assertSame('100.00',Bills::remaining($inv));$this->assertSame('100.00',Bills::remaining($receipt));Finance::decide($id,$inv,$owner,'reverse','Cancel invoice','2026-11-20');$this->assertDatabaseHas('association_finance_vouchers',['id'=>$inv,'status'=>'reversed']);
 }
 public function test_closed_period_allocation_cannot_be_released():void{
  [$owner,$id]=$this->association(true);[$party]=$this->member($owner,$id);$inv=$this->entry($owner,$id,$party,'invoice','100.00');$receipt=$this->entry($owner,$id,$party,'receipt','100.00');Bills::allocate($id,$owner,$this->allocation($receipt,$inv,'100.00'));$x=DB::table('association_bill_allocations')->where('settlement_id',$receipt)->value('id');DB::table('association_finance_settings')->where('association_id',$id)->update(['locked_through'=>'2026-11-15']);$this->actingAs($owner)->post("/association-finance/$id/billing/allocations/$x/release",['note'=>'Too late','confirmed'=>true])->assertSessionHasErrors('finance');
 }
 public function test_membership_receipt_reclassification_does_not_duplicate_income_or_cash():void{
  [$owner,$id]=$this->association(true);[$party,$receipt]=$this->member($owner,$id);DB::table('association_finance_settings')->where('association_id',$id)->update(['enabled'=>true,'yearly_account_id'=>$this->account($id,'yearly'),'lifetime_account_id'=>$this->account($id,'lifetime')]);$source=DB::transaction(fn()=>Finance::collect($receipt,$owner));$invoice=$this->entry($owner,$id,$party,'invoice','100.00');$bridge=Bills::bridge($id,$owner,['source_id'=>$source,'entry_date'=>'2026-11-15','note'=>'Match already recorded membership receipt']);Finance::decide($id,$bridge,$owner,'submit','Reviewed');Finance::decide($id,$bridge,$owner,'post','Reviewed');Bills::allocate($id,$owner,$this->allocation($bridge,$invoice,'100.00'));
  $this->assertSame($bridge,Bills::bridge($id,$owner,['source_id'=>$source,'entry_date'=>'2026-11-15','note'=>'Retry']));$income=Finance::totals($id,'2026-11-20')->firstWhere('id',$this->account($id,'yearly'));$this->assertSame(10000,(int)$income->credit_minor-(int)$income->debit_minor);$this->assertDatabaseHas('association_finance_lines',['voucher_id'=>$source,'account_id'=>$this->account($id,'clearing'),'debit_minor'=>10000]);$this->assertSame('0.00',Bills::remaining($invoice));
  $x=DB::table('association_bill_allocations')->where('settlement_id',$bridge)->whereNull('voided_at')->value('id');Bills::release($id,$owner,$x,'Correct reclassification');Finance::decide($id,$bridge,$owner,'reverse','Replace reclassification','2026-11-20');$replacement=Bills::bridge($id,$owner,['source_id'=>$source,'entry_date'=>'2026-11-20','note'=>'Reviewed replacement']);$this->assertNotSame($bridge,$replacement);Finance::decide($id,$replacement,$owner,'submit','Reviewed');Finance::decide($id,$replacement,$owner,'post','Reviewed');$allocation=$this->allocation($replacement,$invoice,'100.00');$allocation['allocation_date']='2026-11-20';Bills::allocate($id,$owner,$allocation);$this->assertSame('0.00',Bills::remaining($invoice));
 }
 private function account(string $id,string $key):string{return DB::table('association_finance_accounts')->where('association_id',$id)->where('system_key',$key)->value('id');}
 private function data(string $id,string $party,string $flow,string $amount):array{return ['client_token'=>(string)Str::uuid(),'flow'=>$flow,'party_account_id'=>$party,'counter_account_id'=>$this->account($id,match($flow){'invoice'=>'yearly','bill'=>'expense',default=>'bank'}),'amount'=>$amount,'entry_date'=>'2026-11-10','due_date'=>in_array($flow,['invoice','bill'],true)?'2026-11-15':null,'description'=>'Billing test','reference'=>'TEST'];}
 private function entry(User $owner,string $id,string $party,string $flow,string $amount,bool $post=true):string{$v=Bills::save($id,$owner,$this->data($id,$party,$flow,$amount));if($post){Finance::decide($id,$v,$owner,'submit','Reviewed');Finance::decide($id,$v,$owner,'post','Reviewed');}return $v;}
 private function allocation(string $source,string $target,string $amount):array{return ['client_token'=>(string)Str::uuid(),'settlement_id'=>$source,'document_id'=>$target,'amount'=>$amount,'allocation_date'=>'2026-11-15','note'=>'Settlement'];}
 private function member(User $owner,string $id):array{
  $u=User::factory()->create();$app=(string)Str::uuid();$payment=(string)Str::uuid();$membership=(string)Str::uuid();$receipt=(string)Str::uuid();
  DB::table('association_membership_applications')->insert(['id'=>$app,'association_id'=>$id,'member_id'=>$u->id,'statement'=>'Experience','status'=>'accepted_for_payment','form_snapshot'=>'{}','created_at'=>now(),'updated_at'=>now()]);
  DB::table('association_payment_requests')->insert(['id'=>$payment,'application_id'=>$app,'association_id'=>$id,'member_id'=>$u->id,'created_by'=>$owner->id,'membership_type'=>'yearly','amount_minor'=>10000,'currency'=>'INR','instructions'=>'Bank transfer','status'=>'acknowledged','snapshot'=>'{}','acknowledged_at'=>'2026-11-10 10:00:00+00','created_at'=>now(),'updated_at'=>now()]);
  DB::table('association_memberships')->insert(['id'=>$membership,'payment_id'=>$payment,'association_id'=>$id,'member_id'=>$u->id,'number'=>'MEM-'.Str::random(12),'membership_type'=>'yearly','status'=>'active','starts_at'=>'2026-11-10 10:00:00+00','ends_at'=>'2027-11-10 10:00:00+00','created_at'=>now(),'updated_at'=>now()]);
  DB::table('association_payment_receipts')->insert(['id'=>$receipt,'payment_id'=>$payment,'membership_id'=>$membership,'association_id'=>$id,'number'=>'REC-'.Str::random(12),'snapshot'=>'{}','issued_at'=>'2026-11-10 10:00:00+00']);
  DB::transaction(function()use($id,$membership):void{Finance::lock($id);AccountingMasters::syncMembers($id,$membership);});return [DB::table('association_finance_accounts')->where('membership_id',$membership)->value('id'),$receipt];
 }
 private function association(bool $finance=false):array {
  $owner=User::factory()->create(['association_signup'=>true]);$id=(string)Str::uuid();DB::table('association_registration_applications')->insert(['id'=>$id,'applicant_id'=>$owner->id,'name'=>'Finance Test Association','name_key'=>$id,'organisation_type'=>'Association','industry'=>'Tamil','city'=>'Chennai','representative_role'=>'Secretary','status'=>'approved','official_contact_email'=>'office@example.org','created_at'=>now(),'updated_at'=>now()]);DB::table('association_workspace_settings')->insert(['association_id'=>$id,'display_name'=>'Finance Test Association','accent'=>'#b9d66b','fields'=>'[]','version'=>0,'yearly_fee_minor'=>10000,'lifetime_fee_minor'=>100000,'payment_instructions'=>'Bank transfer']);if($finance)Finance::setup($id,$owner,'2026-11-01');return[$owner,$id];
 }
}
