<?php
namespace App\Support;
use Carbon\CarbonImmutable;
use App\Services\Accounting\AccountingMasters;
use App\Services\Accounting\AccountingMoney;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AssociationFinance {
 public static function owner(Authenticatable $user, string $association): void {
  abort_unless(AssociationWorkspace::forAdmin($user)->where('id', $association)->exists(), 404);
 }
 public static function check(bool $ok, string $message): void {
  if (!$ok) throw ValidationException::withMessages(['finance' => $message]);
 }
 public static function lock(string $association): object {
  $row = DB::table('association_registration_applications')->where('id', $association)->where('status','approved')->lockForUpdate()->first();
  abort_if($row === null, 404); return $row;
 }
 public static function event(string $association, Authenticatable $actor, string $action, ?string $voucher = null, ?string $note = null): void {
  DB::table('association_finance_events')->insert(['association_id'=>$association,'voucher_id'=>$voucher,'actor_id'=>$actor->getAuthIdentifier(),'action'=>$action,'note'=>$note,'created_at'=>now()->toIso8601String()]);
 }
 public static function setup(string $association, Authenticatable $actor, string $date): void {
  DB::transaction(function () use ($association,$actor,$date): void {
   self::lock($association);
   self::check(!DB::table('association_finance_settings')->where('association_id',$association)->exists(),'Finance is already initialized.');
   DB::table('association_finance_settings')->insert(['association_id'=>$association,'starts_on'=>$date,'enabled'=>false,'next_number'=>1,'created_at'=>now(),'updated_at'=>now()]);
   AccountingMasters::seed($association);
   foreach ([['1000','Cash','asset',true,'cash'],['1010','Bank','asset',true,'bank'],['1090','Membership collection clearing','asset',false,'clearing'],['2000','Deferred / unclassified membership receipts','liability',false,'deferred'],['3000','Accumulated / opening fund','fund',false,'capital'],['3010','Life membership fund','fund',false,'lifetime'],['4000','Yearly membership income','income',false,'yearly'],['4010','General donations / other income','income',false,'other'],['5000','General administration expense','expense',false,'expense']] as [$code,$name,$type,$money,$key]) {
    DB::table('association_finance_accounts')->insert(['id'=>(string)Str::uuid(),'association_id'=>$association,'code'=>$code,'name'=>$name,'type'=>$type,'is_money'=>$money,'system_key'=>$key,'group_id'=>AccountingMasters::classification($association,$type,$money,$key==='cash'?'cash':null),'created_at'=>now(),'updated_at'=>now()]);
   }
   AccountingMasters::syncMembers($association);
   self::event($association,$actor,'initialized',note:$date);
  });
 }
 public static function settings(string $association): ?object { return DB::table('association_finance_settings')->where('association_id',$association)->first(); }
 public static function editableDate(object $settings, string $date): void {
  self::check($date >= $settings->starts_on && ($settings->locked_through === null || $date > $settings->locked_through),'This date is outside the open books. Use an open-period correction, or ask your accountant.');
 }
 public static function account(string $association, string $id): object {
  $account=DB::table('association_finance_accounts')->where('association_id',$association)->where('id',$id)->where('active',true)->first();
  self::check($account !== null,'Choose an active account belonging to this association.'); return $account;
 }
 public static function lines(string $association, array $input, string $kind): array {
  self::check(count($input)>=2 && count($input)<=40,'Use between 2 and 40 entry lines.'); $dr='0.00';$cr='0.00';$result=[];$moneyDr='0.00';$moneyCr='0.00';
  foreach($input as $line) {
   $account=self::account($association,$line['account_id']);
   $d=AccountingMoney::minor((string)($line['debit'] ?? '0')); $c=AccountingMoney::minor((string)($line['credit'] ?? '0'));
   self::check(($d>0 && $c===0)||($c>0 && $d===0),'Each line must have one positive debit or credit.');
   $dr=AccountingMoney::add($dr,AccountingMoney::fromMinor($d));$cr=AccountingMoney::add($cr,AccountingMoney::fromMinor($c));if($account->is_money){$moneyDr=AccountingMoney::add($moneyDr,AccountingMoney::fromMinor($d));$moneyCr=AccountingMoney::add($moneyCr,AccountingMoney::fromMinor($c));}
   $result[]=['account_id'=>$account->id,'debit_minor'=>$d,'credit_minor'=>$c];
  }
  self::check(AccountingMoney::equal($dr,$cr) && bccomp($dr,'0',2)>0,'Debits and credits must balance exactly.');
  if($kind==='receipt')self::check(AccountingMoney::equal($moneyDr,$dr) && AccountingMoney::equal($moneyCr,'0'),'Receipt: debit cash/bank and credit the appropriate income, fund or liability.');
  if($kind==='payment')self::check(AccountingMoney::equal($moneyCr,$cr) && AccountingMoney::equal($moneyDr,'0'),'Payment: credit cash/bank and debit the appropriate expense, asset or liability.');
  if($kind==='transfer')self::check(AccountingMoney::equal($moneyDr,$dr) && AccountingMoney::equal($moneyCr,$cr),'Transfer: both sides must be cash/bank accounts.');
  self::check(count(array_unique(array_column($result,'account_id')))>=2,'Use at least two different accounts.');
  return $result;
 }
 // Caller holds association lock. Journal numbers are never reused.
 public static function insert(string $association, Authenticatable $actor, array $data, array $lines): string {
  $settings=self::settings($association);self::check($settings!==null,'Initialize finance first.'); self::editableDate($settings,$data['entry_date']);
  $id=(string)Str::uuid();$number=AccountingMasters::number($association,$data['entry_date'],$data['kind']);
  DB::table('association_finance_settings')->where('association_id',$association)->increment('next_number');
  DB::table('association_finance_vouchers')->insert(['id'=>$id,'association_id'=>$association,'number'=>$number,'entry_date'=>$data['entry_date'],'kind'=>$data['kind'],'status'=>'draft','description'=>$data['description'],'party'=>$data['party']??null,'reference'=>$data['reference']??null,'source_type'=>$data['source_type']??null,'source_id'=>$data['source_id']??null,'reversal_of'=>$data['reversal_of']??null,'evidence_path'=>$data['evidence_path']??null,'decision_note'=>$data['decision_note']??null,'created_by'=>$actor->getAuthIdentifier(),'posted_by'=>($data['status']??'draft')==='posted'?$actor->getAuthIdentifier():null,'posted_at'=>($data['status']??'draft')==='posted'?now():null,'created_at'=>now(),'updated_at'=>now()]);
  foreach($lines as $line)DB::table('association_finance_lines')->insert(['voucher_id'=>$id,...$line]);
  if(($data['status']??'draft')==='posted')DB::table('association_finance_vouchers')->where('id',$id)->update(['status'=>'posted']);
  self::event($association,$actor,($data['status']??'draft')==='posted'?'posted':'draft_created',$id,$data['decision_note']??null);return $id;
 }
 public static function collect(string $receipt, Authenticatable $actor): ?string {
  $record=DB::table('association_payment_receipts as r')->join('association_payment_requests as p','p.id','=','r.payment_id')->where('r.id',$receipt)->first(['r.*','p.amount_minor','p.membership_type','p.status as payment_status']);
  self::check($record!==null && $record->payment_status==='acknowledged','Only acknowledged receipts can be posted.');
  self::lock($record->association_id);$settings=self::settings($record->association_id);
  if(!$settings || !$settings->enabled)return null;
  $date=CarbonImmutable::parse($record->issued_at)->setTimezone(config('app.timezone','UTC'))->toDateString();
  if($date<$settings->starts_on || (int)$record->amount_minor===0)return null;
  $existing=DB::table('association_finance_vouchers')->where('association_id',$record->association_id)->where('source_type','membership_receipt')->where('source_id',$receipt)->value('id');
  if($existing)return $existing;
  self::editableDate($settings,$date);
  $credit=self::account($record->association_id,$record->membership_type==='yearly'?$settings->yearly_account_id:$settings->lifetime_account_id);
  self::check(in_array($credit->type,['income','fund','liability'],true),'Configure membership receipt classifications first.');
  $clearing=DB::table('association_finance_accounts')->where('association_id',$record->association_id)->where('system_key','clearing')->first();
  return self::insert($record->association_id,$actor,['entry_date'=>$date,'kind'=>'membership','status'=>'posted','description'=>'Membership receipt '.$record->number,'source_type'=>'membership_receipt','source_id'=>$receipt,'reference'=>$record->number], [['account_id'=>$clearing->id,'debit_minor'=>(int)$record->amount_minor,'credit_minor'=>0],['account_id'=>$credit->id,'debit_minor'=>0,'credit_minor'=>(int)$record->amount_minor]]);
 }
 public static function decide(string $association,string $id,Authenticatable $actor,string $action,string $note,?string $date=null): void {
  DB::transaction(function()use($association,$id,$actor,$action,$note,$date):void{
   self::lock($association);$row=DB::table('association_finance_vouchers')->where('association_id',$association)->where('id',$id)->lockForUpdate()->first();abort_if(!$row,404);
   $settings=self::settings($association);
   if($action==='reverse'){
    self::check($row->status==='posted' && $row->kind!=='reversal','Only a posted original entry can be reversed once.');
    self::check(!DB::table('association_tds_matches as m')->join('association_tds_challans as c','c.id','=','m.challan_id')->join('association_finance_lines as l','l.id','=','c.remittance_line_id')->where('m.association_id',$association)->whereNull('m.released_at')->where(fn($q)=>$q->where('m.document_id',$id)->orWhere('l.voucher_id',$id))->exists(),'Release active TDS matches before reversing the liability or remittance.');
    self::check($date!==null && $date >= $row->entry_date,'Reversal cannot predate the original entry.');self::editableDate($settings,$date);
    self::check(!DB::table('association_finance_notes as note')->join('association_finance_vouchers as child','child.id','=','note.voucher_id')->where('note.original_document_id',$id)->where('child.status','posted')->exists(),'Reverse posted correction notes before their original document.');
    self::check(!DB::table('association_bill_allocations')->whereNull('voided_at')->where(fn($q)=>$q->where('settlement_id',$id)->orWhere('document_id',$id))->exists(),'Release active bill allocations before reversing. Closed-period allocations need an accountant correction.');
    self::check(!DB::table('association_bill_documents as d')->join('association_finance_vouchers as v','v.id','=','d.voucher_id')->where('d.bridge_source_id',$id)->where('v.status','posted')->exists(),'Reverse the posted membership reclassification before its original receipt.');
    $lines=DB::table('association_finance_lines')->where('voucher_id',$id)->get()->map(fn($l)=>['account_id'=>$l->account_id,'debit_minor'=>(int)$l->credit_minor,'credit_minor'=>(int)$l->debit_minor])->all();
    self::insert($association,$actor,['entry_date'=>$date,'kind'=>'reversal','status'=>'posted','description'=>'Reversal of '.$row->number,'reversal_of'=>$id,'decision_note'=>$note],$lines);
    DB::table('association_finance_vouchers')->where('id',$id)->update(['status'=>'reversed','updated_at'=>now()]);
   }else{
    $expected=$action==='submit'?'draft':'submitted';self::check($row->status===$expected,'The record changed. Reload before continuing.');self::editableDate($settings,$row->entry_date);
    if($action==='post'){
       \App\Services\Accounting\TaxConfigurationVersions::validatePosting($association,$id,$row->entry_date);
     if(in_array($row->kind,['credit_note','debit_note'],true))\App\Services\Accounting\CorrectionNoteService::validate($association,$id);
     if($row->kind==='debit_note'){
      $tdsNote=DB::table('association_finance_notes')->where('association_id',$association)->where('voucher_id',$id)->first();
      if($tdsNote?->tax_correction){$plan=json_decode($tdsNote->tax_correction,true,flags:JSON_THROW_ON_ERROR);$liability=collect(\App\Services\Accounting\TdsReconciliation::liabilities($association))->firstWhere('id',$tdsNote->original_document_id);if($liability)self::check(\App\Services\Accounting\AccountingMoney::minor((string)$plan['tds'])<=$liability->outstanding_minor,'Release TDS matches before reducing a reconciled liability.');}
     }
     $bill=DB::table('association_bill_documents')->where('voucher_id',$id)->first();
     if($bill && $bill->bridge_source_id)self::check(DB::table('association_finance_vouchers')->where('id',$bill->bridge_source_id)->where('association_id',$association)->where('status','posted')->exists(),'The original membership receipt was reversed. Reject this reclassification draft and review the source.');
     $input=DB::table('association_finance_lines')->where('voucher_id',$id)->get()->map(fn($l)=>['account_id'=>$l->account_id,'debit'=>AssociationMembershipForm::formatFee($l->debit_minor),'credit'=>AssociationMembershipForm::formatFee($l->credit_minor)])->all();self::lines($association,$input,$row->kind);
    }
    DB::table('association_finance_vouchers')->where('id',$id)->update(['status'=>match($action){'submit'=>'submitted','post'=>'posted','reject'=>'rejected'},'decision_note'=>$note,'posted_by'=>$action==='post'?$actor->getAuthIdentifier():null,'posted_at'=>$action==='post'?now():null,'updated_at'=>now()]);
   }
   self::event($association,$actor,$action,$id,$note);
  });
 }
 public static function totals(string $association,string $to,?string $from=null,?string $account=null) {
  $q=DB::table('association_finance_lines as l')->join('association_finance_vouchers as v','v.id','=','l.voucher_id')->join('association_finance_accounts as a','a.id','=','l.account_id')->where('v.association_id',$association)->whereIn('v.status',['posted','reversed'])->where('v.entry_date','<=',$to);
  if($from)$q->where('v.entry_date','>=',$from);if($account)$q->where('a.id',$account);
  return $q->select('a.id','a.code','a.name','a.type','a.is_money')->selectRaw('SUM(l.debit_minor) as debit_minor, SUM(l.credit_minor) as credit_minor')->groupBy('a.id','a.code','a.name','a.type','a.is_money')->orderBy('a.code')->get();
 }
}
