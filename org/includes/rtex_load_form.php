<?php
function render_rtex_load_fields(array $row, array $jobs, array $drivers, string $key, bool $compact = false): void {
    $jobId = (int)($row['job_rate_id'] ?? 0);
    $saved = isset($row['id']);
    $jobMap = array_column($jobs, null, 'id');
    $pricing = $saved ? $row : ($jobMap[$jobId] ?? []);
    $basis = (string)($pricing['rate_basis'] ?? 'tonnage');
    $rate = (float)($pricing['rate'] ?? 0);
    ?>
    <?php if ($compact): ?><input type="hidden" name="compact_entry" value="1"><?php endif; ?>
    <input type="hidden" name="rtex_mode" value="load">
    <input type="hidden" name="rtex_csrf" value="<?= h($_SESSION['rtex_csrf']) ?>">
    <input type="hidden" name="rtex_row_id" value="<?= (int)($row['id'] ?? 0) ?>">
    <input type="hidden" name="rtex_edit_existing" value="<?= $saved ? '1' : '0' ?>">
    <input type="hidden" name="draft_id" value="<?= h($row['draft_id'] ?? '') ?>">
    <div class="row g-3 rtex-load-fields" data-original-job="<?= $saved ? $jobId : 0 ?>" data-original-basis="<?= h($basis) ?>" data-original-rate="<?= h((string)$rate) ?>" data-original-order="<?= h($pricing['work_order'] ?? '') ?>">
      <div class="col-md-6">
        <label for="<?= h($key) ?>Job" class="form-label">Job Rate Key</label>
        <select id="<?= h($key) ?>Job" name="job_rate_id" class="form-select rtex-job-select" required>
          <option value="">Select a job</option>
          <?php if ($saved && !isset($jobMap[$jobId])): ?>
            <option value="<?= $jobId ?>" selected data-basis="<?= h($basis) ?>" data-rate="<?= h((string)$rate) ?>" data-order="<?= h($row['work_order']) ?>"><?= h($row['job_name']) ?> (saved rate; key deleted)</option>
          <?php endif; ?>
          <?php foreach ($jobs as $job): ?>
            <option value="<?= (int)$job['id'] ?>" <?= $jobId === (int)$job['id'] ? 'selected' : '' ?> data-basis="<?= h($job['rate_basis']) ?>" data-rate="<?= h($job['rate']) ?>" data-order="<?= h($job['work_order']) ?>"><?= h($job['job_name']) ?> — $<?= number_format((float)$job['rate'], 2) ?>/<?= $job['rate_basis'] === 'mileage' ? 'mile' : 'ton' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label for="<?= h($key) ?>Driver" class="form-label">Matched Driver</label>
        <select id="<?= h($key) ?>Driver" name="matched_contact_id" class="form-select" required>
          <option value="">Select driver</option>
          <?php foreach ($drivers as $driver): ?>
            <option value="<?= (int)$driver['id'] ?>" data-truck-no="<?= h($driver['truck_no'] ?? '') ?>" <?= (int)($row['matched_contact_id'] ?? 0) === (int)$driver['id'] ? 'selected' : '' ?>><?= h($driver['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php
      $fields = [
          'work_date'=>['BOL Date','date',10,true],
          'ticket_number'=>['Ticket / BOL #','text',80,true],
          'provider_name'=>['Location / Quarry / Provider','text',255,false],
          'customer_name'=>['Customer','text',255,false],
          'job_number'=>['Order / Job # on BOL','text',50,false],
          'product_name'=>['Product / Material','text',255,false],
          'truck_raw'=>['Truck No.','text',50,false],
          'work_order'=>['P.O. / Work Order','text',80,false],
      ];
      if ($compact) $fields = array_intersect_key($fields, array_flip(['work_date', 'ticket_number', 'truck_raw', 'work_order']));
      foreach ($fields as $field => [$label,$type,$max,$required]):
          $value = $row[$field] ?? ($field === 'work_order' ? ($pricing['work_order'] ?? '') : '');
      ?>
        <div class="col-md-6">
          <label for="<?= h($key . $field) ?>" class="form-label"><?= h($label) ?></label>
          <input id="<?= h($key . $field) ?>" type="<?= h($type) ?>" name="<?= h($field) ?>" value="<?= h((string)$value) ?>" maxlength="<?= $max ?>" class="form-control" <?= $required ? 'required' : '' ?>>
        </div>
      <?php endforeach; ?>
      <?php if ($compact): ?>
      <div class="col-md-6">
        <label for="<?= h($key) ?>Quantity" class="form-label rtex-quantity-label">Quantity (<?= $basis === 'mileage' ? 'miles' : 'net US tons' ?>)</label>
        <input id="<?= h($key) ?>Quantity" name="quantity" type="number" min="0.01" step="0.01" value="<?= h((string)($row['quantity'] ?? $row[$basis === 'mileage' ? 'miles' : 'tons'] ?? '')) ?>" class="form-control" required>
      </div>
      <?php else: ?>
      <div class="col-md-3">
        <label for="<?= h($key) ?>Tons" class="form-label">Net US Tons</label>
        <input id="<?= h($key) ?>Tons" name="tons" type="number" min="0" step="0.01" value="<?= h((string)($row['tons'] ?? '')) ?>" class="form-control">
      </div>
      <div class="col-md-3">
        <label for="<?= h($key) ?>Miles" class="form-label">Billable Miles</label>
        <input id="<?= h($key) ?>Miles" name="miles" type="number" min="0" step="0.01" value="<?= h((string)($row['miles'] ?? '')) ?>" class="form-control">
      </div>
      <?php endif; ?>
      <div class="col-md-3">
        <label for="<?= h($key) ?>Rate" class="form-label rtex-rate-label">Rate / <?= $basis === 'mileage' ? 'mile' : 'ton' ?></label>
        <input id="<?= h($key) ?>Rate" class="form-control rtex-rate" value="<?= number_format($rate, 2, '.', '') ?>" readonly>
      </div>
      <div class="col-md-3">
        <label for="<?= h($key) ?>Total" class="form-label">Calculated Total</label>
        <input id="<?= h($key) ?>Total" class="form-control rtex-total" readonly aria-live="polite">
      </div>
      <?php if ($saved): ?>
        <div class="col-12 form-check ms-2">
          <input id="<?= h($key) ?>Reprice" type="checkbox" name="apply_current_rate" value="1" class="form-check-input" <?= !empty($row['apply_current_rate']) ? 'checked' : '' ?>>
          <label for="<?= h($key) ?>Reprice" class="form-check-label">Apply current Job Rate Key base and FSC rates to this load</label>
        </div>
      <?php endif; ?>
      <p class="small text-muted mb-0">Pay = net US tons × per-ton rate, or billable miles × per-mile rate. Use the ticket's US tons, not pounds or metric tons.</p>
    </div>
    <?php
}
?>
<div class="border rounded p-3 my-3">
  <h3 class="h5">RTEX Job Rate Key</h3>
  <p class="small text-muted">Choose how each job is billed. Base and FSC rate changes apply to new loads; saved loads keep their historical rates unless you choose to reapply them. Driver FSC is paid separately without broker fees. Invoice FSC applies only to invoice exports.</p>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Job</th><th>Calculation</th><th>Rate</th><th>Driver FSC Rate (%)</th><th>RTEX Invoice FSC Rate (%)</th><th>Default P.O. / Work Order</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($rtexJobRates as $job): $formId = 'rtexJob' . (int)$job['id']; ?>
          <tr>
            <td><form id="<?= h($formId) ?>" method="post">
              <input type="hidden" name="rtex_csrf" value="<?= h($_SESSION['rtex_csrf']) ?>">
              <input type="hidden" name="rtex_mode" value="load">
              <input type="hidden" name="job_rate_id" value="<?= (int)$job['id'] ?>">
            </form><input aria-label="Job name" form="<?= h($formId) ?>" name="job_name" value="<?= h($job['job_name']) ?>" maxlength="255" class="form-control" required></td>
            <td><select aria-label="Rate basis" form="<?= h($formId) ?>" name="rate_basis" class="form-select">
              <option value="tonnage" <?= $job['rate_basis'] === 'tonnage' ? 'selected' : '' ?>>Tonnage ($/ton)</option>
              <option value="mileage" <?= $job['rate_basis'] === 'mileage' ? 'selected' : '' ?>>Mileage ($/mile)</option>
            </select></td>
            <td><input aria-label="Job rate" form="<?= h($formId) ?>" name="rate" type="number" min="0.01" step="0.01" value="<?= h($job['rate']) ?>" class="form-control" required></td>
            <?php foreach (['driver_fsc_rate'=>'Driver FSC Rate (%)','invoice_fsc_rate'=>'RTEX Invoice FSC Rate (%)'] as $field=>$label): ?>
            <td><input aria-label="<?= h($label) ?>" form="<?= h($formId) ?>" name="<?= h($field) ?>" type="number" min="0" max="100" step="0.01" value="<?= h($job[$field]) ?>" class="form-control" required></td>
            <?php endforeach; ?>
            <td><input aria-label="Work order" form="<?= h($formId) ?>" name="work_order" value="<?= h($job['work_order']) ?>" maxlength="80" class="form-control"></td>
            <td class="text-nowrap">
              <button form="<?= h($formId) ?>" name="action" value="save_rtex_job_rate" class="btn btn-sm btn-outline-primary">Save</button>
              <button form="<?= h($formId) ?>" name="action" value="delete_rtex_job_rate" class="btn btn-sm btn-outline-danger" formnovalidate onclick="return confirm('Delete this job rate? Saved loads will retain their rate and job name.');">Delete</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rtexJobRates): ?><tr><td colspan="7">Add a job rate to begin entering or importing loads.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <form method="post" class="row g-2 align-items-end">
    <input type="hidden" name="action" value="save_rtex_job_rate">
    <input type="hidden" name="rtex_mode" value="load">
    <input type="hidden" name="rtex_csrf" value="<?= h($_SESSION['rtex_csrf']) ?>">
    <div class="col-md-3"><label for="rtexNewJob" class="form-label">Job Name</label><input id="rtexNewJob" name="job_name" maxlength="255" class="form-control" required></div>
    <div class="col-md-3"><label for="rtexNewBasis" class="form-label">Calculate By</label><select id="rtexNewBasis" name="rate_basis" class="form-select"><option value="tonnage">Tonnage ($/ton)</option><option value="mileage">Mileage ($/mile)</option></select></div>
    <div class="col-md-2"><label for="rtexNewRate" class="form-label">Rate</label><input id="rtexNewRate" name="rate" type="number" min="0.01" step="0.01" class="form-control" required></div>
    <div class="col-md-2"><label for="rtexNewOrder" class="form-label">P.O. / Work Order</label><input id="rtexNewOrder" name="work_order" maxlength="80" class="form-control"></div>
    <?php foreach (['driver_fsc_rate'=>'Driver FSC Rate (%)','invoice_fsc_rate'=>'RTEX Invoice FSC Rate (%)'] as $field=>$label): ?>
    <div class="col-md-3"><label for="rtexNew<?= h($field) ?>" class="form-label"><?= h($label) ?></label><input id="rtexNew<?= h($field) ?>" name="<?= h($field) ?>" type="number" min="0" max="100" step="0.01" value="0" class="form-control" required></div>
    <?php endforeach; ?>
    <div class="col-md-2"><button class="btn btn-primary">Add Job</button></div>
  </form>
</div>
<div class="modal fade" id="rtexLoadEntryModal" tabindex="-1" aria-labelledby="rtexLoadEntryTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <form method="post" style="display:flex;flex-direction:column;min-height:0;overflow:hidden">
      <div class="modal-header"><h3 class="modal-title h5" id="rtexLoadEntryTitle">Add RTEX Row — BOL</h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save_rtex_load">
        <?php render_rtex_load_fields(['work_date'=>$todayDate], $rtexJobRates, $driverOptions, 'rtexAdd', true); ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add Load</button></div>
    </form>
  </div></div>
</div>
<?php if ($rtexLoadForm !== null):
    $retryRow = $rtexLoadForm;
    if (!empty($retryRow['rtex_edit_existing']) || !empty($retryRow['rtex_row_id'])) {
        $savedRow = rtex_load_row($mysqli, (int)$retryRow['rtex_row_id']);
        if ($savedRow) $retryRow = array_merge($savedRow, $retryRow);
    }
?>
<div class="border border-warning rounded p-3 my-3">
  <h3 class="h5">Correct the RTEX Load</h3>
  <form method="post">
    <input type="hidden" name="action" value="save_rtex_load">
    <?php render_rtex_load_fields($retryRow, $rtexJobRates, $driverOptions, 'rtexRetry', !empty($retryRow['compact_entry'])); ?>
    <button class="btn btn-primary mt-3">Save Load</button>
  </form>
</div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" class="border rounded p-3 my-3">
  <h3 class="h5">Import RTEX BOLs</h3>
  <input type="hidden" name="action" value="import_rtex_bols">
  <input type="hidden" name="rtex_mode" value="load">
  <input type="hidden" name="rtex_csrf" value="<?= h($_SESSION['rtex_csrf']) ?>">
  <div class="row g-3 align-items-end">
    <div class="col-md-5"><label for="rtexBolFiles" class="form-label">BOL Images / PDFs</label><input id="rtexBolFiles" type="file" name="rtex_bol_files[]" accept=".jpg,.jpeg,.png,.tif,.tiff,.bmp,.webp,.pdf" multiple class="form-control" required></div>
    <div class="col-md-4"><label for="rtexImportJob" class="form-label">Job Rate Key</label><select id="rtexImportJob" name="job_rate_id" class="form-select" required><option value="">Select job for this batch</option><?php foreach ($rtexJobRates as $job): ?><option value="<?= (int)$job['id'] ?>"><?= h($job['job_name']) ?> ($<?= number_format((float)$job['rate'],2) ?>/<?= $job['rate_basis'] === 'mileage' ? 'mile' : 'ton' ?>)</option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label for="rtexImportDriver" class="form-label">Driver for This Batch</label><select id="rtexImportDriver" name="matched_contact_id" class="form-select"><option value="">Match by truck</option><?php foreach ($driverOptions as $driver): ?><option value="<?= (int)$driver['id'] ?>" data-truck-no="<?= h($driver['truck_no'] ?? '') ?>"><?= h($driver['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><button class="btn btn-primary">Import BOLs</button><span class="small text-muted ms-2">One ticket per image/PDF page, up to 25 MB per file and 50 pending drafts. Review before saving to payouts.</span></div>
  </div>
</form>
<?php foreach ($rtexLoadWarnings as $warning): ?><div class="alert alert-warning"><?= h($warning) ?></div><?php endforeach; ?>
<?php foreach ($_SESSION['rtex_bol_drafts'] ?? [] as $draftId => $draft): ?>
<details class="border rounded p-3 my-2" open>
  <summary>Review BOL: <?= h($draft['source_file_name']) ?></summary>
  <form method="post" class="mt-3">
    <?php render_rtex_load_fields($draft, $rtexJobRates, $driverOptions, 'draft' . $draftId); ?>
    <button name="action" value="save_rtex_load" class="btn btn-primary mt-3">Save Reviewed Load</button>
    <button name="action" value="discard_rtex_draft" class="btn btn-outline-secondary mt-3" formnovalidate>Discard Draft</button>
  </form>
</details>
<?php endforeach; ?>
