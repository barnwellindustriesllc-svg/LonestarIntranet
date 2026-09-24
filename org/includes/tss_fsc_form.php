<div class="border rounded p-3 my-3">
<h3 class="h5">TSS Fuel Surcharge Rate Key</h3>
<?php if (!empty($tssFscMessage)): ?><div class="alert alert-success"><?= h($tssFscMessage) ?></div><?php endif; ?>
<p class="small text-muted">Add dated rules for each week. Dates and mileage limits are inclusive. Exact uses the minimum mileage only. FSC Rate is dollars per ton or per mile. Saved loads retain their rates when rules change.</p>
<p class="small text-muted">Include <strong>FSC Type</strong> (Tonnage, Mileage, or None) in every LS Detail row. Delivery Date and Mileage select the rule. Blank types and unmatched loads stop the upload for correction before any loads are imported. Spreadsheet FSC rates are replaced by the matching key rate.</p>
<?php foreach (array_merge(array_values(array_filter(tss_fsc_rules($mysqli), static fn($r) => (int)$r['id'] !== (int)($tssFscRetry['id'] ?? 0))), [$tssFscRetry ?? ['id'=>0,'start_date'=>'','end_date'=>'','fsc_type'=>'tonnage','mileage_match'=>'range','min_miles'=>'','max_miles'=>'','rate'=>'']]) as $rule): $key = 'tssFsc' . (int)($rule['id'] ?? 0); ?>
<form method="post" class="row g-2 align-items-end border-bottom pb-3 mb-3">
<input type="hidden" name="tss_fsc_csrf" value="<?= h($_SESSION['tss_fsc_csrf']) ?>">
<input type="hidden" name="rule_id" value="<?= (int)($rule['id'] ?? 0) ?>">
<?php foreach (['start_date'=>'Start Date','end_date'=>'End Date'] as $field=>$label): ?>
<div class="col-md-2"><label for="<?= h($key.$field) ?>" class="form-label"><?= h($label) ?></label><input id="<?= h($key.$field) ?>" type="date" name="<?= h($field) ?>" value="<?= h($rule[$field]) ?>" class="form-control" required></div>
<?php endforeach; ?>
<div class="col-md-2"><label for="<?= h($key) ?>Type" class="form-label">FSC Type</label><select id="<?= h($key) ?>Type" name="fsc_type" class="form-select"><?php foreach (['tonnage'=>'Tonnage','mileage'=>'Mileage'] as $value=>$label): ?><option value="<?= $value ?>" <?= $rule['fsc_type'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label for="<?= h($key) ?>Match" class="form-label">Mileage Match</label><select id="<?= h($key) ?>Match" name="mileage_match" class="form-select" onchange="const max=this.form.elements.max_miles; max.disabled=this.value==='exact'; max.required=this.value==='range';"><?php foreach (['exact'=>'Exact','range'=>'Range'] as $value=>$label): ?><option value="<?= $value ?>" <?= $rule['mileage_match'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<?php foreach (['min_miles'=>'Minimum / Exact Miles','max_miles'=>'Maximum Miles','rate'=>'FSC Rate ($/ton or $/mile)'] as $field=>$label): ?>
<div class="col-md-2"><label for="<?= h($key.$field) ?>" class="form-label"><?= h($label) ?></label><input id="<?= h($key.$field) ?>" name="<?= $field ?>" type="number" min="0" max="99999999.9999" step="<?= $field === 'rate' ? '0.0001' : '0.01' ?>" value="<?= h((string)$rule[$field]) ?>" class="form-control" <?= $field === 'max_miles' && $rule['mileage_match'] === 'exact' ? 'disabled' : 'required' ?>></div>
<?php endforeach; ?>
<div class="col-auto"><button name="action" value="save_tss_fsc_rule" class="btn btn-outline-primary"><?= empty($rule['id']) ? 'Add FSC Rule' : 'Save Rule' ?></button>
<?php if (!empty($rule['id'])): ?><button name="action" value="delete_tss_fsc_rule" class="btn btn-outline-danger" formnovalidate onclick="return confirm('Delete this rule? Saved loads keep their FSC rates.');">Delete</button><?php endif; ?></div>
</form>
<?php endforeach; ?>
</div>
