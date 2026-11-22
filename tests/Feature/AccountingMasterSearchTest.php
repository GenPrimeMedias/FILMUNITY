<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\Accounting\AccountingMasters;
use App\Support\AssociationFinance as Finance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;
class AccountingMasterSearchTest extends TestCase {
 use \Tests\Support\WorkspaceMutationRequests;
 use DatabaseTransactions;
 protected function setUp():void{parent::setUp();$this->withoutVite();Mail::fake();config(['association-registration.reviewer_ids'=>[]]);}
 public function test_exact_ledger_code_5000_filters_the_http_listing():void{
  [$owner,$id]=$this->association(true);$this->actingAs($owner)->get("/association-finance/$id/masters?view=ledgers&q=5000")->assertOk()->assertInertia(fn(Page $p)=>$p->component('accounting-masters')->where('filters.q','5000')->where('records.total',1)->where('records.data.0.code','5000'));
 }
 public function test_partial_code_and_case_insensitive_name_are_supported():void{
  [$owner,$id]=$this->association(true);$this->actingAs($owner)->postWithWorkspaceLease("/association-finance/$id/account",['code'=>'5001','name'=>'Rent expense','type'=>'expense','is_money'=>false])->assertSessionHasNoErrors();
  $this->get("/association-finance/$id/masters?q=50")->assertInertia(fn(Page $p)=>$p->where('records.total',2));$this->get("/association-finance/$id/masters?q=RENT")->assertInertia(fn(Page $p)=>$p->where('records.total',1)->where('records.data.0.code','5001'));
 }
 public function test_code_and_name_tokens_can_be_combined():void{
  [$owner,$id]=$this->association(true);$this->actingAs($owner)->get("/association-finance/$id/masters?q=".rawurlencode(' 5000 expense '))->assertInertia(fn(Page $p)=>$p->where('filters.q','5000 expense')->where('records.total',1)->where('records.data.0.code','5000'));
 }
 public function test_zero_is_a_real_search_term_not_an_empty_filter():void{
  [$owner,$id]=$this->association(true);$this->actingAs($owner)->postWithWorkspaceLease("/association-finance/$id/account",['code'=>'ZZZ','name'=>'Volunteer support','type'=>'expense','is_money'=>false])->assertSessionHasNoErrors();$this->get("/association-finance/$id/masters?q=0")->assertInertia(fn(Page $p)=>$p->where('filters.q','0')->where('records.total',9));
 }
 public function test_vendor_reference_and_email_are_searchable():void{
  [$owner,$id]=$this->association(true);AccountingMasters::vendor($id,$owner,['name'=>'Supplier One','reference'=>'SISA-PRINT','email'=>'printer@example.org']);$this->actingAs($owner)->get("/association-finance/$id/masters?view=vendors&q=SISA-PRINT")->assertInertia(fn(Page $p)=>$p->where('records.total',1)->where('records.data.0.vendor_reference','SISA-PRINT'));$this->get("/association-finance/$id/masters?q=printer%40example.org")->assertInertia(fn(Page $p)=>$p->where('records.total',1));
 }
 public function test_group_keys_and_literal_nonmatches_are_handled():void{
  [$owner,$id]=$this->association(true);$this->actingAs($owner)->get("/association-finance/$id/masters?view=groups&q=subscriptions")->assertInertia(fn(Page $p)=>$p->where('records.total',1)->where('records.data.0.code','subscriptions'));$this->get("/association-finance/$id/masters?q=%25")->assertInertia(fn(Page $p)=>$p->where('records.total',0));
 }
 public function test_search_does_not_escape_the_association_scope():void{
  [$owner,$id]=$this->association(true);[, $foreign]=$this->association(true);$this->actingAs($owner)->get("/association-finance/$id/masters?q=5000")->assertInertia(fn(Page $p)=>$p->where('association.id',$id)->where('records.total',1));$this->get("/association-finance/$foreign/masters?q=5000")->assertNotFound();
 }
 private function association(bool $finance=false):array {
  $owner=User::factory()->create(['association_signup'=>true]);$id=(string)Str::uuid();DB::table('association_registration_applications')->insert(['id'=>$id,'applicant_id'=>$owner->id,'name'=>'Finance Test Association','name_key'=>$id,'organisation_type'=>'Association','industry'=>'Tamil','city'=>'Chennai','representative_role'=>'Secretary','status'=>'approved','official_contact_email'=>'office@example.org','created_at'=>now(),'updated_at'=>now()]);DB::table('association_workspace_settings')->insert(['association_id'=>$id,'display_name'=>'Finance Test Association','accent'=>'#b9d66b','fields'=>'[]','version'=>0,'yearly_fee_minor'=>10000,'lifetime_fee_minor'=>100000,'payment_instructions'=>'Bank transfer']);if($finance)Finance::setup($id,$owner,'2026-11-01');return[$owner,$id];
 }
}
