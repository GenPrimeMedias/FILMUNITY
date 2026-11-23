@if(!empty($snapshot['supplier_profile']))
<section><h2>Frozen tax configuration review</h2>
<p>Version: {{ $snapshot['supplier_profile']['configuration_version_id'] ?? 'Legacy snapshot — version reference not recorded' }}</p>
<p>Effective setup date: {{ $snapshot['supplier_profile']['effective_from'] ?? $snapshot['effective_from'] ?? 'Not retained' }} · Reviewer: {{ $snapshot['supplier_profile']['reviewed_by'] ?? $snapshot['supplier_profile']['updated_by'] ?? 'Not retained' }}</p>
<p>Review/evidence reference: {{ $snapshot['supplier_profile']['policy_note'] ?? 'Not retained' }}</p>
<p class="muted">These are the original frozen values, not the current configuration. Legal applicability remains subject to the recorded document-specific review.</p></section>
@endif
