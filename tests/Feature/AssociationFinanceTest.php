<?php
namespace Tests\Feature;
use App\Models\User;
use App\Support\AssociationFinance as Finance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class AssociationFinanceTest extends TestCase {
 use DatabaseTransactions;
 protected function setUp(): void { parent::setUp(); $this->withoutVite(); Mail::fake();Storage::fake('local');config(['association-registration.reviewer_ids'=>[]]);\Carbon\Carbon::setTestNow('2026-11-20 12:00:00');\Carbon\CarbonImmutable::setTestNow('2026-11-20 12:00:00'); }
 protected function tearDown(): void { \Carbon\Carbon::setTestNow();\Carbon\CarbonImmutable::setTestNow();parent::tearDown(); }
 public function test_only_the_association_owner_can_access_its_finance(): void {
  [$owner,$id]=$this->association();$stranger=User::factory()->create();$this->actingAs($stranger)->get("/association-finance/$id")->assertNotFound();$this->post("/association-finance/$id/initialize",['starts_on'=>'2026-11-01'])->assertNotFound();
  $this->actingAs($owner)->get("/association-finance/$id")->assertOk();$this->post("/association-finance/$id/initialize",['starts_on'=>'2026-11-01'])->assertSessionHasNoErrors();$this->assertSame(9,DB::table('association_finance_accounts')->where('association_id',$id)->count());
  $this->post("/association-finance/$id/initialize",['starts_on'=>'2026-11-01'])->assertSessionHasErrors('finance');
 }
 public function test_unbalanced_and_foreign_account_lines_cannot_be_saved(): void {
  [$owner,$id]=$this->association(true);[$other,$foreign]=$this->association(true);$this->actingAs($owner);$data=$this->voucher($id);$data['lines'][0]['debit']='99.00';$this->post("/association-finance/$id/vouchers",$data)->assertSessionHasErrors('finance');
  $data=$this->voucher($id);$data['lines'][0]['account_id']=$this->account($foreign,'bank');$this->post("/association-finance/$id/vouchers",$data)->assertSessionHasErrors('finance');$this->assertDatabaseMissing('association_finance_vouchers',['association_id'=>$id]);
 }
 public function test_drafts_do_not_affect_balances_and_posting_is_reviewed_once(): void {
  [$owner,$id]=$this->association(true);$this->actingAs($owner)->post("/association-finance/$id/vouchers",$this->voucher($id))->assertSessionHasNoErrors();$v=DB::table('association_finance_vouchers')->where('association_id',$id)->value('id');
  $this->assertCount(0,Finance::totals($id,'2026-11-20'));
  $this->decision($id,$v,'post')->assertSessionHasErrors('finance');$this->decision($id,$v,'submit')->assertSessionHasNoErrors();$this->decision($id,$v,'post')->assertSessionHasNoErrors();$this->assertCount(2,Finance::totals($id,'2026-11-20'));$this->decision($id,$v,'post')->assertSessionHasErrors('finance');
  $this->post("/association-finance/$id/vouchers/$v",$this->voucher($id))->assertSessionHasErrors('finance');
 }
 public function test_reversal_preserves_original_and_cancels_its_balance(): void {
  [$owner,$id]=$this->association(true);$v=$this->posted($owner,$id);$this->decision($id,$v,'reverse')->assertSessionHasNoErrors();$this->assertDatabaseHas('association_finance_vouchers',['id'=>$v,'status'=>'reversed']);$this->assertDatabaseHas('association_finance_vouchers',['reversal_of'=>$v,'status'=>'posted']);
  $this->assertSame(0,(int)Finance::totals($id,'2026-11-20')->sum(fn($r)=>$r->debit_minor-$r->credit_minor));foreach(Finance::totals($id,'2026-11-20') as $row)$this->assertSame((int)$row->debit_minor,(int)$row->credit_minor);
  $this->decision($id,$v,'reverse')->assertSessionHasErrors('finance');
 }
 public function test_closed_period_rejects_backdated_vouchers_but_accepts_open_period_correction(): void {
  [$owner,$id]=$this->association(true);$this->actingAs($owner)->post("/association-finance/$id/close",['locked_through'=>'2026-11-10','note'=>'Reviewed','confirmed'=>true])->assertSessionHasNoErrors();$data=$this->voucher($id);$data['entry_date']='2026-11-10';$this->post("/association-finance/$id/vouchers",$data)->assertSessionHasErrors('finance');$data['entry_date']='2026-11-11';$this->post("/association-finance/$id/vouchers",$data)->assertSessionHasNoErrors();
  $this->post("/association-finance/$id/close",['locked_through'=>'2026-11-09','note'=>'Undo','confirmed'=>true])->assertSessionHasErrors('finance');
 }
 public function test_cash_bank_clearance_is_scoped_and_audited(): void {
  [$owner,$id]=$this->association(true);$v=$this->posted($owner,$id);$line=DB::table('association_finance_lines')->where('voucher_id',$v)->where('account_id',$this->account($id,'bank'))->value('id');
  $this->post("/association-finance/$id/lines/$line/clear",['cleared_on'=>'2026-11-19','statement_reference'=>'Bank row 5'])->assertSessionHasNoErrors();$this->assertDatabaseHas('association_finance_events',['voucher_id'=>$v,'action'=>'clearance_updated']);
  [$other,$otherId]=$this->association(true);$this->actingAs($other)->post("/association-finance/$otherId/lines/$line/clear",['cleared_on'=>'2026-11-19','statement_reference'=>'Wrong tenant'])->assertNotFound();
 }
 public function test_evidence_download_is_private_and_owner_scoped(): void {
  [$owner,$id]=$this->association(true);$data=$this->voucher($id);$data['evidence']=UploadedFile::fake()->create('invoice.pdf',20,'application/pdf');$this->actingAs($owner)->post("/association-finance/$id/vouchers",$data)->assertSessionHasNoErrors();$v=DB::table('association_finance_vouchers')->where('association_id',$id)->value('id');$this->get("/association-finance/$id/vouchers/$v/evidence")->assertDownload();$this->actingAs(User::factory()->create())->get("/association-finance/$id/vouchers/$v/evidence")->assertNotFound();
 }
 public function test_acknowledged_membership_receipt_posts_once_using_adopted_policy(): void {
  [$owner,$id]=$this->association(true);$this->enable($owner,$id);$member=User::factory()->create();$app=(string)Str::uuid();DB::table('association_membership_applications')->insert(['id'=>$app,'association_id'=>$id,'member_id'=>$member->id,'statement'=>'Experience','status'=>'accepted_for_payment','form_snapshot'=>json_encode(['name'=>$member->name,'email'=>$member->email,'answers'=>[]]),'created_at'=>now(),'updated_at'=>now()]);
  $this->actingAs($member)->post("/membership-applications/$app/confirm-plan",['membership_type'=>'yearly','version'=>0])->assertSessionHasNoErrors();$this->actingAs($owner)->post("/membership-payments/request/$app",[])->assertSessionHasNoErrors();$payment=DB::table('association_payment_requests')->where('application_id',$app)->value('id');
  $this->actingAs($member)->post("/membership-payments/$payment/submissions",['reference'=>'TXN-123','paid_on'=>'2026-11-19','proof'=>UploadedFile::fake()->create('payment.pdf',20,'application/pdf')])->assertSessionHasNoErrors();$submission=DB::table('association_payment_submissions')->where('payment_id',$payment)->value('id');
  $this->actingAs($owner)->patch("/association-payments/$payment/submissions/$submission",['decision'=>'accept'])->assertSessionHasNoErrors();$receipt=DB::table('association_payment_receipts')->where('payment_id',$payment)->value('id');$this->assertDatabaseHas('association_finance_vouchers',['source_type'=>'membership_receipt','source_id'=>$receipt,'status'=>'posted']);
  $this->post("/association-finance/$id/sync")->assertSessionHasNoErrors();$this->post("/association-finance/$id/sync")->assertSessionHasNoErrors();$this->assertSame(1,DB::table('association_finance_vouchers')->where('source_id',$receipt)->count());
  $voucher=DB::table('association_finance_vouchers')->where('source_id',$receipt)->value('id');$this->assertDatabaseHas('association_finance_lines',['voucher_id'=>$voucher,'account_id'=>$this->account($id,'deferred'),'credit_minor'=>10000]);
 }
 public function test_raw_updates_cannot_change_posted_amounts(): void {
  [$owner,$id]=$this->association(true);$v=$this->posted($owner,$id);$line=DB::table('association_finance_lines')->where('voucher_id',$v)->first();$rejected=false;
  try{DB::transaction(fn()=>DB::table('association_finance_lines')->where('id',$line->id)->update(['debit_minor'=>999999]));}catch(\Illuminate\Database\QueryException){$rejected=true;}$this->assertTrue($rejected);$this->assertDatabaseHas('association_finance_lines',['id'=>$line->id,'debit_minor'=>$line->debit_minor]);
 }
 public function test_rejected_vouchers_do_not_post_and_cash_transfers_require_money_accounts(): void {
  [$owner,$id]=$this->association(true);$this->actingAs($owner);$data=$this->voucher($id);$data['kind']='transfer';$this->post("/association-finance/$id/vouchers",$data)->assertSessionHasErrors('finance');$data=$this->voucher($id);$this->post("/association-finance/$id/vouchers",$data)->assertSessionHasNoErrors();$v=DB::table('association_finance_vouchers')->where('association_id',$id)->value('id');$this->decision($id,$v,'submit')->assertSessionHasNoErrors();$this->decision($id,$v,'reject')->assertSessionHasNoErrors();$this->assertCount(0,Finance::totals($id,'2026-11-20'));$this->decision($id,$v,'post')->assertSessionHasErrors('finance');
 }
 public function test_tally_xml_masters_vouchers_signs_totals_boundaries_exclusions_and_tenant_denial():void{
  $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
  [$owner,$id]=$this->association(true);$this->actingAs($owner);
  $data=$this->voucher($id);$data['description']='Synthetic A & B <services> தமிழ்';$data['lines'][0]['debit']='100.01';$data['lines'][1]['credit']='100.01';
  $this->post("/association-finance/$id/vouchers",$data)->assertSessionHasNoErrors();
  $payment=DB::table('association_finance_vouchers')->where('association_id',$id)->value('id');$this->decision($id,$payment,'submit');$this->decision($id,$payment,'post');
  $receipt=DB::transaction(function()use($owner,$id){Finance::lock($id);return Finance::insert($id,$owner,['kind'=>'receipt','entry_date'=>'2026-11-20','description'=>'Synthetic receipt','status'=>'posted'],Finance::lines($id,[['account_id'=>$this->account($id,'bank'),'debit'=>'1.01'],['account_id'=>$this->account($id,'yearly'),'credit'=>'1.01']],'receipt'));});
  $this->decision($id,$payment,'reverse')->assertSessionHasNoErrors();
  $this->post("/association-finance/$id/vouchers",$this->voucher($id))->assertSessionHasNoErrors();
  DB::transaction(function()use($owner,$id){Finance::lock($id);Finance::insert($id,$owner,['kind'=>'opening','entry_date'=>'2026-11-01','description'=>'Unverified opening','status'=>'posted'],Finance::lines($id,[['account_id'=>$this->account($id,'bank'),'debit'=>'5.00'],['account_id'=>$this->account($id,'capital'),'credit'=>'5.00']],'opening'));});
  $url="/association-finance/$id/tally";
  $initial=\App\Services\Accounting\TallyXmlExport::preview($id,'2026-11-19','2026-11-20');$this->assertCount(3,$initial['unmapped']);
  $query=['from'=>'2026-11-19','to'=>'2026-11-20','dataset'=>'vouchers','fingerprint'=>$initial['fingerprint']];
  $this->getJson($url.'/download?'.http_build_query($query))->assertUnprocessable();
  foreach([['bank','Bank & Tamil தமிழ்','Bank Accounts'],['expense','Synthetic expenses','Indirect Expenses'],['yearly','Synthetic member income','Indirect Incomes']] as [$key,$name,$group]){
   $this->post($url.'/mappings',['account_id'=>$this->account($id,$key),'ledger_name'=>$name,'parent_group'=>$group,'confirmed'=>true])->assertSessionHasNoErrors();
  }
  $batch=\App\Services\Accounting\TallyXmlExport::preview($id,'2026-11-19','2026-11-20');
  $this->assertSame(3,$batch['summary']['voucher_count']);$this->assertSame('201.03',$batch['summary']['debit']);$this->assertSame('201.03',$batch['summary']['credit']);
  $this->assertCount(1,$batch['excluded']);$this->assertSame('Not posted.',$batch['excluded'][0]['reason']);
  $this->get($url.'?from=2026-11-19&to=2026-11-20')->assertOk()->assertInertia(fn(Page $p)=>$p->component('tally-xml-export')->where('batch.summary.voucher_count',3)->where('batch.summary.debit','201.03'));
  $bank=collect($batch['movement'])->firstWhere('account_id',$this->account($id,'bank'));$this->assertSame('1.01',$bank['net_debit']);
  $query['fingerprint']=$batch['fingerprint'];
  $response=$this->get($url.'/download?'.http_build_query($query))->assertOk();
  $document=new \DOMDocument();$this->assertTrue($document->loadXML($response->getContent(),LIBXML_NONET));$xpath=new \DOMXPath($document);
  $this->assertSame(3,$xpath->query('//VOUCHER')->length);$signed='0.00';$debits='0.00';$credits='0.00';
  foreach($xpath->query('//ALLLEDGERENTRIES.LIST') as $line){$amount=$xpath->evaluate('string(AMOUNT)',$line);$deemed=$xpath->evaluate('string(ISDEEMEDPOSITIVE)',$line);$signed=bcadd($signed,$amount,2);if($deemed==='Yes'){$this->assertSame(-1,bccomp($amount,'0',2));$debits=bcsub($debits,$amount,2);}else{$this->assertSame('No',$deemed);$this->assertSame(1,bccomp($amount,'0',2));$credits=bcadd($credits,$amount,2);}}
  $this->assertSame('0.00',$signed);$this->assertSame($batch['summary']['debit'],$debits);$this->assertSame($batch['summary']['credit'],$credits);
  $this->assertStringContainsString($payment,$response->getContent());$this->assertStringContainsString('A &amp; B &lt;services&gt; தமிழ்',$response->getContent());
  $this->assertSame($response->getContent(),$this->get($url.'/download?'.http_build_query($query))->getContent());
  $query['dataset']='masters';$masters=$this->get($url.'/download?'.http_build_query($query))->assertOk();$masterXml=simplexml_load_string($masters->getContent());$this->assertCount(3,$masterXml->xpath('//LEDGER'));$this->assertCount(0,$masterXml->xpath('//OPENINGBALANCE'));
  $query['dataset']='reconciliation';$this->get($url.'/download?'.http_build_query($query))->assertOk()->assertJsonPath('summary.debit','201.03');
  $this->assertSame(1,\App\Services\Accounting\TallyXmlExport::preview($id,'2026-11-19','2026-11-19')['summary']['voucher_count']);
  $this->assertSame(2,\App\Services\Accounting\TallyXmlExport::preview($id,'2026-11-20','2026-11-20')['summary']['voucher_count']);
  $this->assertSame(0,\App\Services\Accounting\TallyXmlExport::preview($id,'2026-11-21','2026-11-21')['summary']['voucher_count']);
  $all=\App\Services\Accounting\TallyXmlExport::preview($id,'2026-11-01','2026-11-20');$this->assertSame('opening',collect($all['excluded'])->firstWhere('kind','opening')['kind']);
  $this->post($url.'/mappings',['account_id'=>$this->account($id,'bank'),'ledger_name'=>'Changed mapping','parent_group'=>'Bank Accounts','confirmed'=>true])->assertSessionHasNoErrors();
  $this->get($url.'/download?'.http_build_query($query))->assertConflict();
  [$other,$otherId]=$this->association(true);$this->actingAs($other)->get($url)->assertNotFound();$this->get($url.'/download?'.http_build_query($query))->assertNotFound();
  $this->post("/association-finance/$otherId/tally/mappings",['account_id'=>$this->account($id,'bank'),'ledger_name'=>'Foreign','parent_group'=>'Bank Accounts','confirmed'=>true])->assertSessionHasErrors('finance');
 }
 public function test_tally_basic_journal_and_contra_and_unsupported_source_reversal():void{
  [$owner,$id]=$this->association(true);$this->actingAs($owner);
  foreach([['cash','Synthetic Cash','Cash-in-Hand'],['bank','Synthetic Bank','Bank Accounts'],['expense','Synthetic Expense','Indirect Expenses']] as [$key,$name,$group]){
   $this->post("/association-finance/$id/tally/mappings",['account_id'=>$this->account($id,$key),'ledger_name'=>$name,'parent_group'=>$group,'confirmed'=>true])->assertSessionHasNoErrors();
  }
  DB::transaction(function()use($owner,$id){Finance::lock($id);foreach([['transfer','cash','bank','0.01'],['journal','expense','bank','0.02']] as [$kind,$dr,$cr,$amount]){Finance::insert($id,$owner,['kind'=>$kind,'entry_date'=>'2026-11-20','description'=>'Synthetic '.$kind,'status'=>'posted'],Finance::lines($id,[['account_id'=>$this->account($id,$dr),'debit'=>$amount],['account_id'=>$this->account($id,$cr),'credit'=>$amount]],$kind));}});
  $opening=DB::transaction(function()use($owner,$id){Finance::lock($id);return Finance::insert($id,$owner,['kind'=>'opening','entry_date'=>'2026-11-01','description'=>'Synthetic opening','status'=>'posted'],Finance::lines($id,[['account_id'=>$this->account($id,'bank'),'debit'=>'1.00'],['account_id'=>$this->account($id,'capital'),'credit'=>'1.00']],'opening'));});
  Finance::decide($id,$opening,$owner,'reverse','Reviewed opening reversal','2026-11-20');
  $batch=\App\Services\Accounting\TallyXmlExport::preview($id,'2026-11-20','2026-11-20');
  $this->assertSame('0.03',$batch['summary']['debit']);$this->assertSame('0.03',$batch['summary']['credit']);
  $this->assertSame(['Contra','Journal'],collect($batch['included'])->pluck('type')->sort()->values()->all());
  $this->assertSame('Reversal of an unsupported source.',$batch['excluded'][0]['reason']);
  $xml=simplexml_load_string(\App\Services\Accounting\TallyXmlExport::xml($batch,'vouchers'));$this->assertCount(2,$xml->xpath('//VOUCHER'));
 }
 private function association(bool $finance=false):array {
  $owner=User::factory()->create(['association_signup'=>true]);$id=(string)Str::uuid();DB::table('association_registration_applications')->insert(['id'=>$id,'applicant_id'=>$owner->id,'name'=>'Finance Test Association','name_key'=>$id,'organisation_type'=>'Association','industry'=>'Tamil','city'=>'Chennai','representative_role'=>'Secretary','status'=>'approved','official_contact_email'=>'office@example.org','created_at'=>now(),'updated_at'=>now()]);DB::table('association_workspace_settings')->insert(['association_id'=>$id,'display_name'=>'Finance Test Association','accent'=>'#b9d66b','fields'=>'[]','version'=>0,'yearly_fee_minor'=>10000,'lifetime_fee_minor'=>100000,'payment_instructions'=>'Bank transfer']);if($finance)Finance::setup($id,$owner,'2026-11-01');return[$owner,$id];
 }
 private function account(string $id,string $key):string{return DB::table('association_finance_accounts')->where('association_id',$id)->where('system_key',$key)->value('id');}
 private function voucher(string $id):array{return['entry_date'=>'2026-11-19','kind'=>'payment','description'=>'Office expense','party'=>'Stationery shop','reference'=>'INV-123','lines'=>[['account_id'=>$this->account($id,'expense'),'debit'=>'100.00','credit'=>'0.00'],['account_id'=>$this->account($id,'bank'),'debit'=>'0.00','credit'=>'100.00']]];}
 private function decision(string $id,string $voucher,string $action){return $this->post("/association-finance/$id/vouchers/$voucher/decision",['action'=>$action,'note'=>'Reviewed test evidence','entry_date'=>'2026-11-20','confirmed'=>true]);}
 private function posted(User $owner,string $id):string{$this->actingAs($owner)->post("/association-finance/$id/vouchers",$this->voucher($id))->assertSessionHasNoErrors();$v=DB::table('association_finance_vouchers')->where('association_id',$id)->latest('created_at')->value('id');$this->decision($id,$v,'submit')->assertSessionHasNoErrors();$this->decision($id,$v,'post')->assertSessionHasNoErrors();return$v;}
 private function enable(User $owner,string $id):void{$this->actingAs($owner)->post("/association-finance/$id/settings",['yearly_account_id'=>$this->account($id,'deferred'),'lifetime_account_id'=>$this->account($id,'lifetime'),'policy_note'=>'Annual receipts deferred until earned. Life receipts in fund.','confirmed'=>true])->assertSessionHasNoErrors();}
}
