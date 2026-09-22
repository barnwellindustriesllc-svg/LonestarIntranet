<?php if (!$rtexReviewRows): ?>
  <div class="alert alert-info">No RTEX load-based rows were found for this week.</div>
<?php else: ?>
<div class="d-flex gap-2 mb-3">
  <form method="post">
    <input type="hidden" name="action" value="export_rtex_load_invoices">
    <input type="hidden" name="rtex_csrf" value="<?= h($_SESSION['rtex_csrf']) ?>">
    <input type="hidden" name="rtex_mode" value="load">
    <input type="hidden" name="rtex_week_start" value="<?= h($rtexReviewWeekStart) ?>">
    <button class="btn btn-outline-success">Export Excel Invoices by Job (ZIP)</button>
  </form>
  <form method="post" id="rtexLoadDeleteForm">
    <input type="hidden" name="action" value="delete_rtex_loads">
    <input type="hidden" name="rtex_csrf" value="<?= h($_SESSION['rtex_csrf']) ?>">
    <input type="hidden" name="rtex_mode" value="load">
    <input type="hidden" name="rtex_week_start" value="<?= h($rtexReviewWeekStart) ?>">
    <button class="btn btn-outline-danger" onclick="return confirm('Delete selected RTEX loads from invoices and driver payouts?');">Delete Selected</button>
  </form>
</div>
<div class="table-responsive">
<table class="table table-striped table-bordered align-middle">
  <thead><tr><th><input type="checkbox" id="rtexSelectAllLoads" aria-label="Select all RTEX loads"></th><th>Date</th><th>Ticket / BOL</th><th>Job</th><th>Driver</th><th>Truck No.</th><th>Net US Tons</th><th>Rate</th><th>Total</th><th>Actions</th></tr></thead>
  <tbody>
    <?php foreach ($rtexReviewRows as $row): ?>
    <tr>
      <td><input type="checkbox" form="rtexLoadDeleteForm" name="rtex_row_ids[]" class="rtex-load-checkbox" value="<?= (int)$row['id'] ?>" aria-label="Select BOL <?= h($row['ticket_number']) ?>"></td>
      <td><?= h($row['work_date']) ?></td><td><?= h($row['ticket_number']) ?></td><td><?= h($row['job_name']) ?></td>
      <td><?= h($row['matched_driver_name'] ?: $row['driver_name']) ?></td>
      <td><?= h($row['truck_raw']) ?></td><td><?= number_format((float)$row['tons'], 2) ?></td>
      <td>$<?= number_format((float)$row['rate'],2) ?>/<?= $row['rate_basis'] === 'mileage' ? 'mile' : 'ton' ?></td>
      <td>$<?= number_format((float)$row['total_amount'],2) ?></td>
      <td><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#rtexLoadEdit<?= (int)$row['id'] ?>">Edit</button></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr><th colspan="6">Totals</th><th><?= number_format(array_sum(array_column($rtexReviewRows,'tons')),2) ?></th><th></th><th>$<?= number_format(array_sum(array_column($rtexReviewRows,'total_amount')),2) ?></th><th></th></tr></tfoot>
</table>
</div>
<?php foreach ($rtexReviewRows as $row): ?>
<div class="modal fade" id="rtexLoadEdit<?= (int)$row['id'] ?>" tabindex="-1" aria-labelledby="rtexLoadTitle<?= (int)$row['id'] ?>" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <form method="post" style="display:flex;flex-direction:column;min-height:0;overflow:hidden">
      <div class="modal-header"><h3 class="modal-title h5" id="rtexLoadTitle<?= (int)$row['id'] ?>">Edit RTEX BOL <?= h($row['ticket_number']) ?></h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save_rtex_load">
        <input type="hidden" name="rtex_week_start" value="<?= h($rtexReviewWeekStart) ?>">
        <?php render_rtex_load_fields($row, $rtexJobRates, $driverOptions, 'rtexEdit' . $row['id'], true); ?>
        <?php if ($row['source_file_name'] !== ''): ?><p class="small text-muted mt-2">Imported from <?= h($row['source_file_name']) ?></p><?php endif; ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Load</button></div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
<?php endif; ?>
