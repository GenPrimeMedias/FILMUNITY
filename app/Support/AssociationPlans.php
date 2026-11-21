<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
final class AssociationPlans {
    public static function choices(string $association): array {
        $settings = AssociationMembershipForm::settings($association);
        $result = [];
        foreach (['yearly' => 'Yearly', 'lifetime' => 'Life-Time'] as $term => $label) {
            $fee = $term === 'yearly' ? $settings?->yearly_fee_minor : $settings?->lifetime_fee_minor;
            if ($fee !== null) $result[] = ['key' => $term, 'id' => null, 'name' => $label, 'term' => $term, 'fee' => AssociationMembershipForm::formatFee($fee)];
        }
        foreach (DB::table('association_membership_plans')->where('association_id', $association)->whereNull('default_code')->where('active', true)->orderBy('name_key')->get() as $row) {
            $result[] = ['key' => $row->id, 'id' => $row->id, 'name' => $row->name, 'term' => $row->term, 'fee' => AssociationMembershipForm::formatFee($row->amount_minor)];
        }
        return $result;
    }
    public static function selected(string $association, array $data): array {
        $settings = AssociationMembershipForm::settings($association);
        $plan = !empty($data['plan_id']) ? DB::table('association_membership_plans')->where('association_id', $association)->where('id', $data['plan_id'])->where('active', true)->first() : null;
        if (!empty($data['plan_id']) && $plan === null) throw ValidationException::withMessages(['plan_id' => 'This plan is not available for this association. Reload the plan list.']);
        $term = $plan?->term ?? ($data['membership_type'] ?? null);
        if (!in_array($term, ['yearly', 'lifetime'], true)) throw ValidationException::withMessages(['membership_type' => 'Choose an available membership plan.']);
        $amount = $plan !== null && $plan->default_code === null ? $plan->amount_minor : ($term === 'yearly' ? $settings?->yearly_fee_minor : $settings?->lifetime_fee_minor);
        if ($amount === null) throw ValidationException::withMessages(['plan_id' => 'The association must configure its fees.']);
        return ['id' => $plan?->id, 'name' => $plan?->name ?? ($term === 'yearly' ? 'Yearly' : 'Life-Time'), 'term' => $term,
            'amount_minor' => (int) $amount, 'currency' => 'INR', 'version' => (int) ($settings?->version ?? 0)];
    }
    public static function renewal(object $membership): ?array {
        try {
            $plan = self::selected($membership->association_id, ['plan_id' => $membership->plan_id ?? null, 'membership_type' => $membership->membership_type]);
            return $plan['term'] === $membership->membership_type ? $plan : null;
        }
        catch (ValidationException) { return null; }
    }
    public static function custom(string $association): array {
        return DB::table('association_membership_plans')->where('association_id', $association)->whereNull('default_code')->orderBy('created_at')->get()
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'term' => $row->term, 'fee' => AssociationMembershipForm::formatFee($row->amount_minor), 'active' => (bool) $row->active])->all();
    }
    // The caller holds the association row lock and checks the workspace settings version.
    public static function save(string $association, array $data): void {
        foreach (['yearly' => ['Yearly', $data['yearly_fee']], 'lifetime' => ['Life-Time', $data['lifetime_fee']]] as $term => [$name, $fee]) {
            $existing = DB::table('association_membership_plans')->where('association_id', $association)->where('default_code', $term)->first();
            $values = ['name' => $name, 'name_key' => strtolower($name), 'term' => $term, 'amount_minor' => AssociationMembershipForm::feeMinor($fee), 'active' => true, 'updated_at' => now()->toIso8601String()];
            if ($existing) DB::table('association_membership_plans')->where('id', $existing->id)->update($values);
            else DB::table('association_membership_plans')->insert(['id' => (string) Str::uuid(), 'association_id' => $association, 'default_code' => $term, 'created_at' => now()->toIso8601String(), ...$values]);
        }
        if (!array_key_exists('custom_plans', $data)) return;
        $seen = ['yearly', 'life-time']; $ids = [];
        foreach ($data['custom_plans'] as $index => $plan) {
            $name = trim($plan['name']); $key = mb_strtolower($name);
            if ($name === '' || in_array($key, $seen, true)) throw ValidationException::withMessages(["custom_plans.$index.name" => 'Plan names must be distinct, including Yearly and Life-Time.']);
            $seen[] = $key;
            $existing = DB::table('association_membership_plans')->where('id', $plan['id'])->first();
            if ($existing && ($existing->association_id !== $association || $existing->default_code !== null)) throw ValidationException::withMessages(['custom_plans' => 'Invalid plan ownership.']);
            if ($existing && $existing->term !== $plan['term']) {
                foreach (['association_membership_applications', 'association_payment_requests', 'association_memberships'] as $table) {
                    if (DB::table($table)->where('plan_id', $plan['id'])->exists()) throw ValidationException::withMessages(["custom_plans.$index.term" => 'This plan is already in use. Create a new plan to change its term.']);
                }
            }
            $ids[] = $plan['id'];
            $values = ['name' => $name, 'name_key' => $key, 'term' => $plan['term'], 'amount_minor' => AssociationMembershipForm::feeMinor($plan['fee']), 'active' => $plan['active'], 'updated_at' => now()->toIso8601String()];
            if ($existing) DB::table('association_membership_plans')->where('id', $existing->id)->update($values);
            else DB::table('association_membership_plans')->insert(['id' => $plan['id'], 'association_id' => $association, 'created_at' => now()->toIso8601String(), ...$values]);
        }
        DB::table('association_membership_plans')->where('association_id', $association)->whereNull('default_code')->whereNotIn('id', $ids)->update(['active' => false, 'updated_at' => now()->toIso8601String()]);
    }
}
