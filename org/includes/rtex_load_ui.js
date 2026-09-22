document.querySelectorAll('.rtex-load-fields').forEach((fields) => {
  const driver = fields.querySelector('[name="matched_contact_id"]');
  const truck = fields.querySelector('[name="truck_raw"]');
  driver?.addEventListener('change', () => {
    if (truck) truck.value = driver.selectedOptions[0]?.dataset.truckNo || '';
  });
  const job = fields.querySelector('[name="job_rate_id"]');
  const tons = fields.querySelector('[name="tons"]');
  const quantityInput = fields.querySelector('[name="quantity"]');
  const miles = fields.querySelector('[name="miles"]');
  const applyCurrent = fields.querySelector('[name="apply_current_rate"]');
  const order = fields.querySelector('[name="work_order"]');
  function refresh(changeOrder = false) {
    const selected = job.selectedOptions[0];
    const useSaved = Number(fields.dataset.originalJob) > 0 &&
      job.value === fields.dataset.originalJob && !applyCurrent?.checked;
    const basis = useSaved ? fields.dataset.originalBasis : (selected?.dataset.basis || 'tonnage');
    const rate = Number(useSaved ? fields.dataset.originalRate : (selected?.dataset.rate || 0));
    fields.querySelector('.rtex-rate').value = rate.toFixed(2);
    fields.querySelector('.rtex-rate-label').textContent = basis === 'mileage' ? 'Rate / mile' : 'Rate / ton';
    if (quantityInput) {
      const previousBasis = quantityInput.dataset.basis;
      if (previousBasis && previousBasis !== basis) quantityInput.value = '';
      quantityInput.dataset.basis = basis;
      fields.querySelector('.rtex-quantity-label').textContent = basis === 'mileage' ? 'Quantity (miles)' : 'Quantity (net US tons)';
    } else {
      tons.required = basis === 'tonnage';
      tons.min = basis === 'tonnage' ? '0.01' : '0';
      miles.required = basis === 'mileage';
      miles.min = basis === 'mileage' ? '0.01' : '0';
    }
    const quantity = Number((quantityInput || (basis === 'mileage' ? miles : tons)).value || 0);
    fields.querySelector('.rtex-total').value = '$' + (quantity * rate).toFixed(2);
    if (changeOrder && !order.value) order.value = selected?.dataset.order || '';
  }
  job.addEventListener('change', () => refresh(true));
  applyCurrent?.addEventListener('change', () => refresh());
  quantityInput?.addEventListener('input', () => refresh());
  tons?.addEventListener('input', () => refresh());
  miles?.addEventListener('input', () => refresh());
  refresh();
});
document.getElementById('rtexSelectAllLoads')?.addEventListener('change', (event) => {
  document.querySelectorAll('.rtex-load-checkbox').forEach((input) => { input.checked = event.target.checked; });
});
// Keep the selected billing view through every RTEX action, including broker fees.
document.querySelectorAll('#rtex-section form').forEach((form) => {
  if (!form.querySelector('[name="rtex_mode"]')) {
    const mode = document.createElement('input');
    mode.type = 'hidden';
    mode.name = 'rtex_mode';
    mode.value = document.getElementById('rtex-section').dataset.mode;
    form.appendChild(mode);
  }
});
