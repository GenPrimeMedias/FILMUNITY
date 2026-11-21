<?php
namespace App\Http\Controllers;
use App\Services\Accounting\BillSettlementService as Bills;
use App\Services\Accounting\TaxCorrectionService;
use App\Support\AssociationFinance as Finance;
use App\Support\AssociationWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
final class BillSettlementController extends Controller {
 public function index(Request $request,string $association) {
  Finance::owner($request->user(),$association);Finance::check(Finance::settings($association)!==null,'Initialize finance first.');
  $f=$request->validate(['scope'=>['nullable',Rule::in(['corrections'])],'q'=>['nullable','string','max:120'],'flow'=>['nullable',Rule::in(['invoice','bill','receipt','payment','credit_note','debit_note'])],'status'=>['nullable',Rule::in(['draft','submitted','posted','reversed','rejected'])],'open'=>['nullable',Rule::in(['yes'])],'sort'=>['nullable',Rule::in(['number','entry_date','due_date','name'])],'direction'=>['nullable',Rule::in(['asc','desc'])],'entry'=>['nullable',Rule::in(['invoice','bill'])]]);
  $q=Bills::documents($association);if(($f['scope']??'')==='corrections')$q->whereIn('v.kind',['credit_note','debit_note']);if(!empty($f['flow'])){if(in_array($f['flow'],['credit_note','debit_note'],true))$q->where('v.kind',$f['flow']);else{$q->where('d.flow',$f['flow']);if(in_array($f['flow'],['receipt','payment'],true))$q->whereNotIn('v.kind',['credit_note','debit_note']);}}if(!empty($f['status']))$q->where('v.status',$f['status']);
  if(!empty($f['q']))$q->where(fn($w)=>$w->whereRaw('strpos(lower(v.number),lower(?))>0',[$f['q']])->orWhereRaw('strpos(lower(p.name),lower(?))>0',[$f['q']])->orWhereRaw("strpos(lower(coalesce(v.reference,'')),lower(?))>0",[$f['q']]));
  $remaining="filmunity_bill_amount(v.id)-COALESCE((SELECT sum(x.amount) FROM association_bill_allocations x WHERE (x.settlement_id=v.id OR x.document_id=v.id) AND x.voided_at IS NULL),0)";
  if(!empty($f['open']))$q->where('v.status','posted')->whereRaw("($remaining)>0");
  $column=match($f['sort']??'entry_date'){'number'=>'v.number','due_date'=>'d.due_date','name'=>'p.name',default=>'v.entry_date'};
  $records=$q->select('v.id','v.number','v.kind','v.entry_date','v.status','v.description','v.reference','v.decision_note','d.flow','d.due_date','d.party_account_id','d.bridge_source_id','p.name')->selectRaw("filmunity_bill_amount(v.id)::text AS amount,($remaining)::text AS remaining")->orderBy($column,$f['direction']??'desc')->orderBy('v.id')->paginate(15)->withQueryString()->through(function($row)use($association){
   $row->note=DB::table('association_finance_notes as note')->join('association_finance_vouchers as original','original.id','=','note.original_document_id')->where('note.voucher_id',$row->id)->first(['note.original_document_id','note.reason','note.tax_correction','original.number as original_number','original.entry_date as original_date']);
   if($row->note?->tax_correction!==null){
    $tax=json_decode($row->note->tax_correction,true,flags:JSON_THROW_ON_ERROR);
    unset($tax['lines']);
    $row->note->tax_correction=$tax;
   }
   $row->evidence_url=DB::table('association_finance_vouchers')->where('id',$row->id)->value('evidence_path')?"/association-finance/$association/vouchers/$row->id/evidence":null;
   $row->lines=DB::table('association_finance_lines as l')->join('association_finance_accounts as a','a.id','=','l.account_id')->where('l.voucher_id',$row->id)->get(['l.account_id','a.name','l.debit','l.credit']);
   $row->allocations=DB::table('association_bill_allocations as x')->join('association_finance_vouchers as sv','sv.id','=','x.settlement_id')->join('association_finance_vouchers as tv','tv.id','=','x.document_id')->where(fn($w)=>$w->where('x.settlement_id',$row->id)->orWhere('x.document_id',$row->id))->orderBy('x.created_at')->get(['x.*','sv.number as settlement_number','tv.number as bill_number']);
   $row->events=DB::table('association_finance_events')->where('voucher_id',$row->id)->orderBy('id')->get(['action','note','actor_id','created_at']);return $row;
  });
  $available=Bills::documents($association)->where('v.status','posted')->whereRaw("($remaining)>0")->orderBy('v.entry_date')->orderBy('v.id')->get(['v.id','v.number','p.name as party_name','p.code as party_code','d.party_account_id','d.flow','v.kind',DB::raw("($remaining)::text AS remaining")]);
  $asOf=today()->toDateString();
  $aging=Bills::documents($association)->where('v.status','posted')->whereIn('d.flow',['invoice','bill'])->whereRaw("($remaining)>0")->select('d.party_account_id','p.name','d.flow')->selectRaw("SUM($remaining)::text AS outstanding, SUM(CASE WHEN d.due_date>=DATE '$asOf' THEN ($remaining) ELSE 0 END)::text AS not_due, SUM(CASE WHEN DATE '$asOf'-d.due_date BETWEEN 1 AND 30 THEN ($remaining) ELSE 0 END)::text AS days_1_30, SUM(CASE WHEN DATE '$asOf'-d.due_date BETWEEN 31 AND 60 THEN ($remaining) ELSE 0 END)::text AS days_31_60, SUM(CASE WHEN DATE '$asOf'-d.due_date BETWEEN 61 AND 90 THEN ($remaining) ELSE 0 END)::text AS days_61_90, SUM(CASE WHEN DATE '$asOf'-d.due_date>90 THEN ($remaining) ELSE 0 END)::text AS days_90_plus")->groupBy('d.party_account_id','p.name','d.flow')->orderBy('p.name')->get();
  $legacy=DB::table('association_finance_vouchers as v')->join('association_payment_receipts as r','r.id','=','v.source_id')->join('association_finance_accounts as a','a.membership_id','=','r.membership_id')->where('v.association_id',$association)->where('a.association_id',$association)->where('v.status','posted')->where('v.source_type','membership_receipt')->whereNotExists(fn($w)=>$w->selectRaw('1')->from('association_bill_documents as d')->join('association_finance_vouchers as bv','bv.id','=','d.voucher_id')->whereIn('bv.status',['draft','submitted','posted'])->whereColumn('d.bridge_source_id','v.id'))->orderBy('v.entry_date')->get(['v.id','v.number','a.name']);
  $noteAvailable="filmunity_bill_amount(v.id)-COALESCE((SELECT sum(filmunity_bill_amount(n.voucher_id)) FROM association_finance_notes n JOIN association_finance_vouchers nv ON nv.id=n.voucher_id WHERE n.original_document_id=v.id AND nv.status='posted'),0)";
  $originals=Bills::documents($association)->where('v.status','posted')->whereIn('d.flow',['invoice','bill'])->orderBy('v.entry_date')->orderBy('v.id')->select('v.id','v.number','v.entry_date','d.flow','p.name','p.code')->selectRaw("filmunity_bill_amount(v.id)::text AS amount, ($noteAvailable)::text AS note_available")->get();
  return Inertia::render('association-billing',['association'=>AssociationWorkspace::forAdmin($request->user())->where('id',$association)->first(['id','name']),'filters'=>$f,'originals'=>$originals,'taxSources'=>TaxCorrectionService::sources($association),'asOf'=>$asOf,'records'=>$records,'available'=>$available,'aging'=>$aging,'legacy'=>$legacy,'accounts'=>DB::table('association_finance_accounts as a')->leftJoin('association_finance_vendors as vendor','vendor.id','=','a.vendor_id')->leftJoin('association_memberships as member','member.id','=','a.membership_id')->where('a.association_id',$association)->where('a.active',true)->orderBy('a.name')->get(['a.id','a.code','a.name','a.type','a.is_money','a.membership_id','a.vendor_id','vendor.reference as vendor_reference','member.number as member_number'])]);
 }
 private function data(Request $request):array {
  return $request->validate(['client_token'=>['required','uuid'],'flow'=>['required',Rule::in(['invoice','bill','receipt','payment'])],'party_account_id'=>['required','uuid'],'counter_account_id'=>['required','uuid'],'entry_date'=>['required','date_format:Y-m-d','before_or_equal:today'],'due_date'=>['nullable','date_format:Y-m-d'],'amount'=>['required','regex:/^\d{1,7}(?:\.\d{1,2})?$/D'],'description'=>['required','string','max:2000'],'reference'=>['nullable','string','max:160'],'evidence'=>['nullable','file','mimes:pdf,png,jpg,jpeg','extensions:pdf,png,jpg,jpeg','max:5120']]);
 }
 public function save(Request $request,string $association,?string $voucher=null) {
  Finance::owner($request->user(),$association);$data=$this->data($request);$old=$voucher?DB::table('association_finance_vouchers')->where('association_id',$association)->where('id',$voucher)->value('evidence_path'):null;
  $path=$request->file('evidence')?->store('association-finance-evidence','local');Finance::check($path!==false,'Evidence could not be stored.');
  try{$id=Bills::save($association,$request->user(),[...$data,'evidence_path'=>$path],$voucher);}catch(\Throwable $e){if($path)Storage::disk('local')->delete($path);throw $e;}
  if($path&&DB::table('association_finance_vouchers')->where('id',$id)->value('evidence_path')!==$path)Storage::disk('local')->delete($path);elseif($path&&$old)Storage::disk('local')->delete($old);return back()->with('status','Billing draft saved. Submit and review before posting.');
 }
 public function allocate(Request $request,string $association) {
  Finance::owner($request->user(),$association);$d=$request->validate(['client_token'=>['required','uuid'],'settlement_id'=>['required','uuid'],'document_id'=>['required','uuid'],'amount'=>['required','regex:/^\d{1,13}(?:\.\d{1,2})?$/D'],'allocation_date'=>['required','date_format:Y-m-d','before_or_equal:today'],'note'=>['required','string','max:2000']]);Bills::allocate($association,$request->user(),$d);return back()->with('status','Settlement allocated. Remaining amount stays available as an advance.');
 }
 public function release(Request $request,string $association,string $allocation) {
  Finance::owner($request->user(),$association);$d=$request->validate(['note'=>['required','string','max:2000'],'confirmed'=>['required','accepted']]);Bills::release($association,$request->user(),$allocation,$d['note']);return back()->with('status','Allocation released with its audit history retained.');
 }
 public function bridge(Request $request,string $association) {
  Finance::owner($request->user(),$association);$d=$request->validate(['source_id'=>['required','uuid'],'entry_date'=>['required','date_format:Y-m-d','before_or_equal:today'],'note'=>['required','string','max:1600'],'confirmed'=>['required','accepted']]);Bills::bridge($association,$request->user(),$d);return back()->with('status','Membership reclassification draft created. Review its two offsetting lines before posting or allocating.');
 }
}
