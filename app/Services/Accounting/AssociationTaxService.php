<?php
namespace App\Services\Accounting;
use App\Support\AssociationFinance as Finance;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class AssociationTaxService {
 public static function percent(string $base,string $rate,string $divisor='100'):string {return bcadd(bcdiv(bcmul($base,$rate,8),$divisor,8),'0.005',2);}
 public static function calculate(array $data):array {
  $base=AccountingMoney::decimal($data['taxable_base']);$exempt=AccountingMoney::decimal($data['exempt_base']);$rate=AccountingMoney::decimal($data['gst_rate']);$tr=AccountingMoney::decimal($data['tds_rate']);$tb=AccountingMoney::decimal($data['tds_base']);$mode=$data['mode'];
  Finance::check(bccomp(bcadd($base,$exempt,2),'0',2)>0,'Enter a positive charge.');Finance::check(bccomp($tb,bcadd($base,$exempt,2),2)<=0,'TDS base cannot exceed the pre-GST charge.');
  Finance::check($mode!=='none'||bccomp($rate,'0',2)===0,'No GST mode requires a zero GST rate.');
  $cg=in_array($mode,['cgst','utgst'],true)?self::percent($base,$rate,'200'):'0.00';$ig=$mode==='igst'?self::percent($base,$rate):'0.00';$tds=self::percent($tb,$tr);$gross=bcadd(bcadd(bcadd($base,$exempt,2),bcmul($cg,'2',2),2),$ig,2);$net=bcsub($gross,$tds,2);Finance::check(bccomp($net,'0',2)>0,'Net payable must be positive.');
  return ['taxable_base'=>$base,'exempt_base'=>$exempt,'gst_rate'=>$rate,'mode'=>$mode,'cgst'=>$cg,'sgst'=>$cg,'igst'=>$ig,'tds_base'=>$tb,'tds_rate'=>$tr,'tds'=>$tds,'gross'=>$gross,'net'=>$net];
 }
 public static function calculateItems(array $data):array {
  Finance::check(isset($data['items'])&&is_array($data['items'])&&count($data['items'])>=1&&count($data['items'])<=20,'Use between 1 and 20 charge lines.');
  $items=[];$total=array_fill_keys(['taxable_base','exempt_base','cgst','sgst','igst','gross','tds_base'],'0.00');
  foreach($data['items'] as $index=>$item){
   Finance::check(in_array($item['treatment'],['taxable','exempt','non_gst'],true),'Choose a valid GST treatment for each charge.');
   $taxable=$item['treatment']==='taxable';$amount=AccountingMoney::decimal($item['amount']);$rate=AccountingMoney::decimal($item['gst_rate']);$tdsBase=AccountingMoney::decimal($item['tds_base']);
   Finance::check(bccomp($amount,'0',2)>0&&bccomp($rate,'40',2)<=0,'Each charge must have a positive amount and a GST rate between 0 and 40.');
   Finance::check($taxable||($rate==='0.00'&&!empty(trim($item['exemption_reason']??''))),'Exempt/non-GST charges need zero rate and a reviewed reason.');
   Finance::check($rate==='0.00'||!empty($item['hsn_sac']),'HSN/SAC is required for each positive-rate charge.');
   Finance::check(($data['flow']!=='invoice'||$tdsBase==='0.00')&&bccomp($tdsBase,$amount,2)<=0,'Review each line TDS base; invoices require zero and vendor bases cannot exceed the line amount.');
   $tax=self::calculate(['taxable_base'=>$taxable?$amount:'0.00','exempt_base'=>$taxable?'0.00':$amount,'gst_rate'=>$rate,'mode'=>$data['mode'],'tds_base'=>'0.00','tds_rate'=>'0.00']);
   $items[]=[...$item,'amount'=>$amount,'gst_rate'=>$rate,'tds_base'=>$tdsBase,'line_no'=>$index+1,'taxable_base'=>$tax['taxable_base'],'exempt_base'=>$tax['exempt_base'],'cgst'=>$tax['cgst'],'sgst'=>$tax['sgst'],'igst'=>$tax['igst'],'gross'=>$tax['gross']];
   foreach(['taxable_base','exempt_base','cgst','sgst','igst','gross'] as $key)$total[$key]=bcadd($total[$key],$tax[$key],2);$total['tds_base']=bcadd($total['tds_base'],$tdsBase,2);
  }
  $total['tds_rate']=AccountingMoney::decimal($data['tds_rate']);Finance::check(bccomp($total['tds_rate'],'40',2)<=0,'Review the document TDS rate.');$total['tds']=self::percent($total['tds_base'],$total['tds_rate']);$total['net']=bcsub($total['gross'],$total['tds'],2);Finance::check(bccomp($total['net'],'0',2)>0,'Net party amount must be positive.');$total['gst_rate']='0.00';$total['mode']=$data['mode'];return ['items'=>$items,'totals'=>$total];
 }
 public static function settings(string $association,Authenticatable $actor,array $data):void { DB::transaction(function()use($association,$actor,$data){Finance::lock($association);Finance::check(Finance::settings($association)!==null,'Initialize finance first.');Finance::check(!$data['gst_registered']||(!empty($data['gstin'])&&substr($data['gstin'],0,2)===$data['state_code']),'GSTIN must match your configured state code.');Finance::check(!$data['tds_enabled']||!empty($data['tan']),'TAN is required for TDS.');unset($data['confirmed']);TaxConfigurationVersions::record($association,$actor,$data);DB::table('association_finance_tax_settings')->updateOrInsert(['association_id'=>$association],[...$data,'updated_by'=>$actor->getAuthIdentifier(),'updated_at'=>now()]);Finance::event($association,$actor,'tax_settings_updated',note:$data['policy_note']);}); }
 private static function taxAccount(string $association,string $key,string $name,string $type):string {
  $code='TAX-'.$key;$old=DB::table('association_finance_accounts')->where('association_id',$association)->where('code',$code)->first();
  if($old){Finance::check($old->type===$type&&!$old->is_money&&!$old->membership_id&&!$old->vendor_id&&$old->active,'Reserved tax account conflicts with an existing account: '.$code);return $old->id;}
  $id=(string)Str::uuid();DB::table('association_finance_accounts')->insert(['id'=>$id,'association_id'=>$association,'code'=>$code,'name'=>$name,'type'=>$type,'is_money'=>false,'active'=>true,'group_id'=>AccountingMasters::classification($association,$type,false,$type==='liability'?'taxes':'assets'),'created_at'=>now(),'updated_at'=>now()]);return $id;
 }
 public static function save(string $association,Authenticatable $actor,array $data):string {return DB::transaction(function()use($association,$actor,$data){
  Finance::lock($association);$settings=Finance::settings($association);Finance::check($settings!==null,'Initialize finance first.');Finance::editableDate($settings,$data['entry_date']);
  if($old=DB::table('association_bill_documents')->where('association_id',$association)->where('client_token',$data['client_token'])->value('voucher_id')){Finance::check(DB::table('association_finance_tax_documents')->where('voucher_id',$old)->exists(),'Request token belongs to another entry.');return $old;}
  $profile=TaxConfigurationVersions::effective($association,$data['entry_date']);Finance::check($profile!==null&&$data['entry_date']>=$profile->effective_from,'Save tax configuration with a suitable effective date first.');
  Finance::check(!isset($data['tax_configuration_id'])||$data['tax_configuration_id']===$profile->configuration_version_id,'The applicable reviewed tax configuration changed. Reload its context and explicitly review this draft again.');
  $invoice=$data['flow']==='invoice';$party=Finance::account($association,$data['party_account_id']);Finance::check($invoice?$party->membership_id!==null:$party->vendor_id!==null,'Choose the correct member/vendor ledger.');
  $multi=array_key_exists('items',$data);$prepared=$multi?self::calculateItems($data):null;$items=$prepared['items']??[];$counterRows=[];
  unset($data['vendor_tds_review']);
  unset($data['vendor_tds_certificate']);
  if(!empty($data['vendor_tds_certificate_id'])){
   Finance::check(!$invoice && !empty($data['confirmed']),'Explicitly approve certificate use on a vendor bill.');
   $data['vendor_tds_certificate']=VendorTdsReview::certificate($association,$party->vendor_id,$data['vendor_tds_certificate_id'],$data['entry_date']);
  }
  if(!empty($data['vendor_tds_review_id'])){
   Finance::check(!$invoice && !empty($data['confirmed']),'Explicitly approve the vendor bill review.');
   $vendorReview=VendorTdsReview::effective($association,$party->vendor_id,$data['entry_date']);
   Finance::check($vendorReview!==null && $vendorReview->id===$data['vendor_tds_review_id'],'The selected vendor review is not effective for this vendor and book date. Reload and review.');
   $data['vendor_tds_review']=(array)$vendorReview;
   unset($data['vendor_tds_review']['evidence_path']);
  }
  foreach($multi?$items:[['counter_account_id'=>$data['counter_account_id']]] as $index=>$item){$account=Finance::account($association,$item['counter_account_id']);Finance::check(!$account->is_money&&!$account->membership_id&&!$account->vendor_id&&in_array($account->type,$invoice?['income','fund','liability']:['expense','asset'],true),'Choose an appropriate classification account for every charge.');Finance::check(!str_starts_with($account->code,'TAX-'),'Tax control ledgers cannot classify charges.');$counterRows[]=$account;if($multi)$items[$index]['counter_name']=$account->name;}
  $counter=$counterRows[0];
  Finance::check($invoice||$data['place_state']===$profile->state_code,'Vendor bill place of supply must be the configured association state in this phase.');
  Finance::check(!$invoice||$data['supplier_state']===$profile->state_code,'Invoice supplier state must match the association profile.');
  Finance::check($invoice||!empty($data['reference']),'Vendor source bill reference is required.');
  if(in_array($data['mode'],['cgst','utgst'],true))Finance::check(($data['mode']==='utgst')===in_array($data['place_state'],['04','26','31','35','38'],true),'Choose SGST or UTGST for the reviewed place-of-supply jurisdiction.');
  $tax=$multi?$prepared['totals']:self::calculate($data);$hasGst=$multi?count(array_filter($items,fn($i)=>bccomp($i['gst_rate'],'0',2)>0))>0:bccomp($tax['gst_rate'],'0',2)>0;$hasTds=bccomp($tax['tds'],'0',2)>0;
  Finance::check(!$invoice||!$hasGst||$profile->gst_registered,'An unregistered association cannot charge output GST.');
  Finance::check(!$hasGst||$multi||!empty($data['hsn_sac']),'HSN/SAC is required for GST.');
  Finance::check($data['mode']==='none'||(($data['mode']==='igst')===($invoice?($data['place_state']!==$profile->state_code):($data['supplier_state']!==$data['place_state']))),'GST split must match the reviewed place of supply and association state.');
  Finance::check(!$hasGst||$invoice||!empty($data['party_gstin']),'Supplier GSTIN is required when recording vendor GST.');
  Finance::check(empty($data['party_gstin'])||substr($data['party_gstin'],0,2)===($invoice?$data['place_state']:$data['supplier_state']),'Party GSTIN does not match the supplied party state.');
  Finance::check(!$hasTds||(!$invoice&&$profile->tds_enabled&&!empty($data['tds_reference'])&&!empty($data['party_pan'])),'TDS requires an enabled TAN, vendor PAN and reviewed legal reference.');
  Finance::check(!$invoice||($tax['tds']==='0.00'&&$tax['tds_base']==='0.00'&&$tax['tds_rate']==='0.00'),'Member invoices do not support TDS deductions in this phase.');
  Finance::check(!$hasTds||$data['tds_event_date']===$data['entry_date'],'Earlier payment/advance TDS needs a separate reconciliation; this bill records deduction at book date only.');
  if($hasTds){$modern=$data['tds_event_date']>='2026-04-01';Finance::check($modern?str_starts_with($data['tds_reference'],'393'):preg_match('/^194[A-Z0-9]+$/D',$data['tds_reference'])===1,'Use a section 393 table reference from April 2026, or the applicable legacy section for earlier events.');}
  Finance::check(!$data['claim_itc']||(!$invoice&&$profile->gst_registered&&$hasGst),'Input GST classification requires registered status and a vendor GST bill.');
  Finance::check($data['due_date']>=$data['entry_date'],'Due date cannot precede book date.');
  $lines=[];$add=function($account,$amount,$dr)use(&$lines){if(bccomp($amount,'0',2)<=0)return;$key=$account.($dr?'DR':'CR');if(!isset($lines[$key]))$lines[$key]=['account_id'=>$account,'debit'=>'0.00','credit'=>'0.00'];$side=$dr?'debit':'credit';$lines[$key][$side]=bcadd($lines[$key][$side],$amount,2);};
  if($multi){foreach($items as $index=>$item){$cost=(!$invoice&&!$data['claim_itc'])?$item['gross']:$item['amount'];$add($counterRows[$index]->id,$cost,!$invoice);}}else{
  $base=bcadd($tax['taxable_base'],$tax['exempt_base'],2);$expenseTax=(!$invoice&&!$data['claim_itc'])?bcsub($tax['gross'],$base,2):'0.00';$add($counter->id,bcadd($base,$expenseTax,2),!$invoice);}

  if($invoice||$data['claim_itc'])foreach(['cgst','sgst','igst'] as $component){if(bccomp($tax[$component],'0',2)<=0)continue;$label=$component==='sgst'&&$data['mode']==='utgst'?'UTGST':strtoupper($component);$key=($invoice?'OUT-':'IN-').$label;$id=self::taxAccount($association,$key,($invoice?'Output ':'Input ').$label,$invoice?'liability':'asset');$add($id,$tax[$component],!$invoice);}
  if($hasTds){$key='TDS-'.substr(hash('sha256',$data['tds_reference']),0,16);$tdsAccount=self::taxAccount($association,$key,'TDS payable — '.$data['tds_reference'],'liability');$add($tdsAccount,$tax['tds'],false);}
  $add($party->id,$tax['net'],$invoice);$lines=Finance::lines($association,array_values($lines),$data['flow']);
  $id=Finance::insert($association,$actor,['kind'=>$data['flow'],'entry_date'=>$data['entry_date'],'description'=>$data['description'],'party'=>$party->name,'reference'=>$data['reference']??null,'evidence_path'=>$data['evidence_path']??null],$lines);
  DB::table('association_bill_documents')->insert(['voucher_id'=>$id,'association_id'=>$association,'party_account_id'=>$party->id,'flow'=>$data['flow'],'due_date'=>$data['due_date'],'client_token'=>$data['client_token'],'created_at'=>now()]);
  $year=(int)substr($data['entry_date'],0,4)-((int)substr($data['entry_date'],5,2)<4?1:0);$keys=['association_id'=>$association,'fy_start'=>"$year-04-01"];DB::table('association_finance_tax_counters')->insertOrIgnore([...$keys,'next_number'=>1]);$q=DB::table('association_finance_tax_counters')->where($keys);$sequence=(int)$q->value('next_number');Finance::check($sequence<=9999999,'Tax document sequence exhausted.');$q->increment('next_number');$serial=($invoice?'TI':'TB').substr((string)$year,-2).substr((string)($year+1),-2).'-'.str_pad((string)$sequence,7,'0',STR_PAD_LEFT);
  $expected=$lines;usort($expected,fn($a,$b)=>strcmp($a['account_id'],$b['account_id']));
  $snapshot=[...$data,...$tax,'counter_account_id'=>$counter->id,'hsn_sac'=>$multi?'See charge lines':($data['hsn_sac']??null),'branding'=>\App\Support\AssociationBranding::data($association),'party_ledger_name'=>$party->name,'counter_ledger_name'=>$multi?'Multiple charge lines — see breakdown':$counter->name,'supplier_profile'=>(array)$profile,'effective_from'=>$profile->effective_from];if($multi)$snapshot['items']=$items;unset($snapshot['client_token'],$snapshot['evidence_path']);
  DB::table('association_finance_tax_documents')->insert(['voucher_id'=>$id,'association_id'=>$association,'serial'=>$serial,'calculation_version'=>$multi?2:1,...$tax,'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'expected_lines'=>json_encode($expected,JSON_THROW_ON_ERROR),'created_at'=>now()]);if($multi)foreach($items as $item)DB::table('association_finance_tax_items')->insert(['voucher_id'=>$id,'association_id'=>$association,'line_no'=>$item['line_no'],'counter_account_id'=>$item['counter_account_id'],'description'=>$item['description'],'treatment'=>$item['treatment'],'exemption_reason'=>$item['exemption_reason']??null,'hsn_sac'=>$item['hsn_sac']??null,'amount'=>$item['amount'],'gst_rate'=>$item['gst_rate'],'tds_base'=>$item['tds_base'],'cgst'=>$item['cgst'],'sgst'=>$item['sgst'],'igst'=>$item['igst'],'gross'=>$item['gross']]);Finance::event($association,$actor,'tax_draft_created',$id,$serial.' '.$data['review_note']);return $id;
 });}
}
