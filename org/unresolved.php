<?php
// unresolved.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php'; // provides $mysqli

// ---------------------------
// Helpers
// ---------------------------
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }
function digits_only($s) { return preg_replace('/\D+/', '', (string)$s); }
function norm_name($first, $last) {
  return strtolower(trim(preg_replace('/\s+/', ' ', trim(($first ?? '') . ' ' . ($last ?? '')))));
}
function lev($a,$b){ return levenshtein($a,$b); }

$errors = [];
$success = false;

// ---------------------------
// Handle DB-backed resolution (from modal)
// ---------------------------
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'resolve')) {
  $u_upload   = $_POST['upload_date']   ?? null;
  $u_delivery = $_POST['delivery_date'] ?? null;
  $u_ticket   = $_POST['truckload_id']  ?? null;
  $u_role     = in_array($_POST['use_role'] ?? 'pickup', ['pickup','delivery'], true) ? $_POST['use_role'] : 'pickup';
  $chosenId   = isset($_POST['contact_id']) ? (int)$_POST['contact_id'] : 0;
  $markAsNew  = isset($_POST['mark_new']);

  if ($u_upload && $u_delivery && $u_ticket) {
    // Pull unresolved row from DB
    $stmt = $mysqli->prepare("\n      SELECT *\n        FROM ls_unresolved_matches\n       WHERE upload_date=? AND delivery_date=? AND truckload_id=? AND use_role=? AND `status`='unresolved'\n       LIMIT 1\n    ");
    $stmt->bind_param('ssss', $u_upload, $u_delivery, $u_ticket, $u_role);
    $stmt->execute();
    $rowU = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rowU) {
      $errors[] = 'Unresolved record not found or already resolved.';
    } else {
      $useRole = ($rowU['use_role'] === 'delivery') ? 'delivery' : 'pickup';
      $driverFirst = $useRole === 'delivery' ? ($rowU['delivery_first_name'] ?? '') : ($rowU['pickup_first_name'] ?? '');
      $driverLast  = $useRole === 'delivery' ? ($rowU['delivery_last_name']  ?? '') : ($rowU['pickup_last_name']  ?? '');
      $driverName  = trim($driverFirst . ' ' . $driverLast);
      $vendor      = 'TSS';

      $payout_date  = $rowU['delivery_date'];
      $ticketNumber = $u_ticket;
      $tss_pay      = $rowU['calc_rate'];
      $upload_date  = $u_upload;
      $cid          = $markAsNew ? null : ($chosenId ?: null);
      $splitVendor = ($useRole === 'delivery') ? 'TSS Split (Delivery)' : 'TSS Split (Pickup)';

      // For split unresolved rows, update the existing split payout row contact assignment.
      // For non-split unresolved rows, keep existing insert behavior.
      $splitId = null;
      $qSplit = $mysqli->prepare(
        "SELECT id
           FROM driver_payouts
          WHERE payout_date=? AND ticket_number=? AND upload_date=? AND vendor_name=?
          ORDER BY id DESC
          LIMIT 1"
      );
      if ($qSplit) {
        $qSplit->bind_param('ssss', $payout_date, $ticketNumber, $upload_date, $splitVendor);
        $qSplit->execute();
        $qSplit->bind_result($sid);
        if ($qSplit->fetch()) $splitId = (int)$sid;
        $qSplit->close();
      }

      if ($splitId) {
        $updP = $mysqli->prepare("UPDATE driver_payouts SET driver_contact_id=? WHERE id=? LIMIT 1");
        if (!$updP) {
          $errors[] = 'Split payout update prepare error: ' . $mysqli->error;
        } else {
          $updP->bind_param('ii', $cid, $splitId);
          if (!$updP->execute()) {
            $errors[] = 'Split payout update error: ' . $updP->error;
          }
          $updP->close();
        }
      } else {
        $ins = $mysqli->prepare("\n        INSERT IGNORE INTO driver_payouts\n        (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)\n        VALUES (?,?,?,?,?,?,?)\n      ");
        if (!$ins) {
          $errors[] = 'Insert prepare error: ' . $mysqli->error;
        } else {
          $ins->bind_param('ssssssi', $payout_date, $ticketNumber, $driverName, $vendor, $tss_pay, $upload_date, $cid);
          if (!$ins->execute()) {
            $errors[] = 'Insert payout error: ' . $ins->error;
          }
          $ins->close();
        }
      }

      if (empty($errors)) {
        // Save alias for matched contact (if not new)
        if (!$markAsNew && $chosenId && $driverFirst !== '' && $driverLast !== '') {
          $insAlias = $mysqli->prepare(
            "INSERT IGNORE INTO driver_name_aliases (driver_contact_id, alias_first_name, alias_last_name)
             VALUES (?,?,?)"
          );
          if ($insAlias) {
            $insAlias->bind_param('iss', $chosenId, $driverFirst, $driverLast);
            $insAlias->execute();
            $insAlias->close();
          }
        }
        // Update ls_detail_raw for auditing if we matched an existing contact
        if (!$markAsNew && $chosenId) {
          $updR = $mysqli->prepare("\n            UPDATE ls_detail_raw\n               SET matched_contact_id = ?\n             WHERE `Truckload ID` = ? AND `Delivery Date` = ? AND `upload_date` = ?\n             LIMIT 1\n          ");
          if ($updR) {
            $updR->bind_param('isss', $chosenId, $u_ticket, $payout_date, $upload_date);
            $updR->execute();
            $updR->close();
          }
        }
        // Mark unresolved as resolved
        $updU = $mysqli->prepare("\n          UPDATE ls_unresolved_matches\n             SET matched_contact_id=?, `status`='resolved', resolved_at=NOW()\n           WHERE upload_date=? AND delivery_date=? AND truckload_id=? AND use_role=?\n           LIMIT 1\n        ");
        if ($updU) {
          $updU->bind_param('issss', $cid, $upload_date, $payout_date, $ticketNumber, $useRole);
          $updU->execute();
          $updU->close();
        }

        // Auto-resolve other identical matches (same driver name + same truck digits, any date)
        if (!$markAsNew && $chosenId) {
          $truckDigits = digits_only($rowU['truck_digits'] ?? ($rowU['truck_raw'] ?? ''));
          $firstLower = strtolower(trim($driverFirst));
          $lastLower  = strtolower(trim($driverLast));
          if ($truckDigits !== '' && $firstLower !== '' && $lastLower !== '') {
            $role = $useRole === 'delivery' ? 'delivery' : 'pickup';
            if ($role === 'delivery') {
              $sel = $mysqli->prepare(
                "SELECT upload_date, delivery_date, truckload_id, calc_rate,
                        pickup_first_name, pickup_last_name, delivery_first_name, delivery_last_name, use_role
                   FROM ls_unresolved_matches
                  WHERE `status`='unresolved'
                    AND use_role='delivery'
                    AND LOWER(delivery_first_name)=?
                    AND LOWER(delivery_last_name)=?
                    AND (truck_digits = ? OR truck_raw LIKE ?)"
              );
            } else {
              $sel = $mysqli->prepare(
                "SELECT upload_date, delivery_date, truckload_id, calc_rate,
                        pickup_first_name, pickup_last_name, delivery_first_name, delivery_last_name, use_role
                   FROM ls_unresolved_matches
                  WHERE `status`='unresolved'
                    AND use_role='pickup'
                    AND LOWER(pickup_first_name)=?
                    AND LOWER(pickup_last_name)=?
                    AND (truck_digits = ? OR truck_raw LIKE ?)"
              );
            }
            if ($sel) {
              $like = '%' . $truckDigits . '%';
              $sel->bind_param('ssss', $firstLower, $lastLower, $truckDigits, $like);
              $sel->execute();
              $resMatch = $sel->get_result();
              while ($m = $resMatch->fetch_assoc()) {
                $autoFirst = ($m['use_role'] === 'delivery') ? ($m['delivery_first_name'] ?? '') : ($m['pickup_first_name'] ?? '');
                $autoLast  = ($m['use_role'] === 'delivery') ? ($m['delivery_last_name'] ?? '') : ($m['pickup_last_name'] ?? '');
                $autoName  = trim($autoFirst . ' ' . $autoLast);
                $autoPay   = $m['calc_rate'] ?? '';

                $insAuto = $mysqli->prepare(
                  "INSERT IGNORE INTO driver_payouts
                   (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
                   VALUES (?,?,?,?,?,?,?)"
                );
                $mRole = ($m['use_role'] === 'delivery') ? 'delivery' : 'pickup';
                $mSplitVendor = ($mRole === 'delivery') ? 'TSS Split (Delivery)' : 'TSS Split (Pickup)';
                $splitIdAuto = null;
                $qSplitAuto = $mysqli->prepare(
                  "SELECT id
                     FROM driver_payouts
                    WHERE payout_date=? AND ticket_number=? AND upload_date=? AND vendor_name=?
                    ORDER BY id DESC
                    LIMIT 1"
                );
                if ($qSplitAuto) {
                  $qSplitAuto->bind_param('ssss', $m['delivery_date'], $m['truckload_id'], $m['upload_date'], $mSplitVendor);
                  $qSplitAuto->execute();
                  $qSplitAuto->bind_result($sidAuto);
                  if ($qSplitAuto->fetch()) $splitIdAuto = (int)$sidAuto;
                  $qSplitAuto->close();
                }
                if ($splitIdAuto) {
                  $updPAuto = $mysqli->prepare("UPDATE driver_payouts SET driver_contact_id=? WHERE id=? LIMIT 1");
                  if ($updPAuto) {
                    $updPAuto->bind_param('ii', $chosenId, $splitIdAuto);
                    $updPAuto->execute();
                    $updPAuto->close();
                  }
                } elseif ($insAuto) {
                  $insAuto->bind_param('ssssssi', $m['delivery_date'], $m['truckload_id'], $autoName, $vendor, $autoPay, $m['upload_date'], $chosenId);
                  $insAuto->execute();
                  $insAuto->close();
                }

                if ($autoFirst !== '' && $autoLast !== '') {
                  $insAliasAuto = $mysqli->prepare(
                    "INSERT IGNORE INTO driver_name_aliases (driver_contact_id, alias_first_name, alias_last_name)
                     VALUES (?,?,?)"
                  );
                  if ($insAliasAuto) {
                    $insAliasAuto->bind_param('iss', $chosenId, $autoFirst, $autoLast);
                    $insAliasAuto->execute();
                    $insAliasAuto->close();
                  }
                }

                $updAuto = $mysqli->prepare(
                  "UPDATE ls_unresolved_matches
                      SET matched_contact_id=?, `status`='resolved', resolved_at=NOW()
                    WHERE upload_date=? AND delivery_date=? AND truckload_id=? AND use_role=?
                    LIMIT 1"
                );
                if ($updAuto) {
                  $updAuto->bind_param('issss', $chosenId, $m['upload_date'], $m['delivery_date'], $m['truckload_id'], $mRole);
                  $updAuto->execute();
                  $updAuto->close();
                }
                $updAutoRaw = $mysqli->prepare(
                  "UPDATE ls_detail_raw
                      SET matched_contact_id = ?
                    WHERE `Truckload ID` = ? AND `Delivery Date` = ? AND `upload_date` = ?
                    LIMIT 1"
                );
                if ($updAutoRaw) {
                  $updAutoRaw->bind_param('isss', $chosenId, $m['truckload_id'], $m['delivery_date'], $m['upload_date']);
                  $updAutoRaw->execute();
                  $updAutoRaw->close();
                }
              }
              $sel->close();
            }
          }
        }
        $success = true;
      }
    }
  } else {
    $errors[] = 'Missing identifiers for resolution (upload, delivery date, ticket).';
  }
}

// ---------------------------
// Fetch unresolved rows (after possible updates above)
// ---------------------------
$unresolved = [];
$qr = $mysqli->query("SELECT * FROM ls_unresolved_matches WHERE `status`='unresolved' ORDER BY upload_date DESC, delivery_date DESC, truckload_id ASC");
while ($r = $qr->fetch_assoc()) { $unresolved[] = $r; }
$qr->close();

// ---------------------------
// Build contacts index for suggestions
// ---------------------------
$contacts = [];
$byTruck  = []; // digits => [contacts]
$namesForId = []; // id => [norm names]

$qc = $mysqli->query("SELECT id, first_name, last_name, truck_no, alt_truck_no FROM driver_contacts");
while ($c = $qc->fetch_assoc()) {
  $c['first_name'] = $c['first_name'] ?? '';
  $c['last_name']  = $c['last_name']  ?? '';
  $c['truck_norm']     = digits_only($c['truck_no'] ?? '');
  $c['alt_truck_norm'] = digits_only($c['alt_truck_no'] ?? '');
  $contacts[] = $c;
  foreach (array_filter([$c['truck_norm'], $c['alt_truck_norm']]) as $d) {
    $byTruck[$d][] = $c;
  }
  $namesForId[(int)$c['id']] = [ norm_name($c['first_name'], $c['last_name']) ];
}
$qc->close();

$qa = $mysqli->query("SELECT driver_contact_id, alias_full_norm FROM driver_name_aliases");
while ($a = $qa->fetch_assoc()) {
  $cid = (int)$a['driver_contact_id'];
  $alias = strtolower(trim($a['alias_full_norm'] ?? ''));
  if ($alias !== '') $namesForId[$cid][] = $alias;
}
$qa->close();

// Suggestion builder per unresolved row
function build_suggestions($row, $contacts, $byTruck, $namesForId) {
  $useRole = ($row['use_role'] === 'delivery') ? 'delivery' : 'pickup';
  $first   = $useRole === 'delivery' ? ($row['delivery_first_name'] ?? '') : ($row['pickup_first_name'] ?? '');
  $last    = $useRole === 'delivery' ? ($row['delivery_last_name']  ?? '') : ($row['pickup_last_name']  ?? '');
  $nameNorm   = norm_name($first, $last);
  $truckDigits = digits_only($row['truck_digits'] ?? ($row['truck_raw'] ?? ''));

  $candsTruck = $byTruck[$truckDigits] ?? [];
  $exact = array_values(array_filter($candsTruck, function($c) use ($nameNorm, $namesForId){
    foreach ($namesForId[(int)$c['id']] ?? [] as $cand) { if ($cand !== '' && $cand === $nameNorm) return true; }
    return false;
  }));

  if (count($exact) === 1) {
    return $exact; // single exact match
  }

  $sug = [];
  foreach ($candsTruck as $c) { $sug[$c['id']] = $c; }
  foreach ($contacts as $c) {
    $bestDist = 999;
    foreach ($namesForId[(int)$c['id']] ?? [] as $cand) {
      if ($cand === '') continue;
      $bestDist = min($bestDist, lev($nameNorm, $cand));
    }
    if ($bestDist <= 3) $sug[$c['id']] = $c;
  }
  $scored = [];
  foreach ($sug as $c) {
    $sameTruck = ($truckDigits !== '' && ($c['truck_norm'] === $truckDigits || $c['alt_truck_norm'] === $truckDigits)) ? 0 : 1;
    $bestDist = 999;
    foreach ($namesForId[(int)$c['id']] ?? [] as $cand) {
      if ($cand === '') continue;
      $bestDist = min($bestDist, lev($nameNorm, $cand));
    }
    $scored[] = ['c' => $c, 'score' => [$sameTruck, $bestDist]];
  }
  usort($scored, fn($a,$b) => $a['score'] <=> $b['score']);
  return array_slice(array_map(fn($x) => $x['c'], $scored), 0, 10);
}

// Enrich unresolved rows with computed fields and suggestions
foreach ($unresolved as &$u) {
  $useRole = ($u['use_role'] === 'delivery') ? 'delivery' : 'pickup';
  $first   = $useRole === 'delivery' ? ($u['delivery_first_name'] ?? '') : ($u['pickup_first_name'] ?? '');
  $last    = $useRole === 'delivery' ? ($u['delivery_last_name']  ?? '') : ($u['pickup_last_name']  ?? '');
  $u['driver_display'] = trim($first . ' ' . $last);
  $u['truck_digits_display'] = digits_only($u['truck_digits'] ?? ($u['truck_raw'] ?? ''));
  $u['suggestions'] = build_suggestions($u, $contacts, $byTruck, $namesForId);
}
unset($u);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Unresolved Driver Matches</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* ========= Base / Layout ========= */
    :root { --sidebar-w: 250px; }
    html, body { height: 100%; }
    body { margin:0; font-family:sans-serif; min-height:100vh; overflow:auto; background:#fff; color:#111; }
    /* ========= Sidebar ========= */
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:var(--sidebar-w); background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); left:0; overflow:auto; transition:transform .3s ease; will-change: transform; }
    .sidebar.collapsed { transform: translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    /* ========= Main Pane (scrolls) ========= */
    .main { margin-left:var(--sidebar-w); padding:20px; flex:1; min-width:0; height:calc(100vh - var(--banner-h)); overflow:auto; -webkit-overflow-scrolling:touch; }
    .sidebar.collapsed + .main { margin-left:0; }
    /* ========= Responsive ========= */
    @media (max-width: 768px) { .sidebar { transform: translateX(-250px); } .sidebar.open { transform: translateX(0); } .main { margin:0; } body { overflow:auto; } }
    /* ========= Components / Utilities ========= */
    .candidate { border:1px solid #e5e5e5; border-radius:6px; padding:.5rem .75rem; margin-bottom:.5rem; background:#fff; }
    .candidate.form-check { padding-left: 2rem; }
    .candidate.form-check .form-check-input { margin-left: 0; }
    .nowrap { white-space: nowrap; }
    .table-preview { font-size:.9rem; }
    /* ========= Modal (Bootstrap 5) ========= */
    .modal-dialog.modal-lg.modal-dialog-scrollable { max-width: 900px; }
    .modal-dialog-scrollable .modal-content { max-height: calc(100dvh - 1rem); }
    .modal-dialog-scrollable .modal-body { max-height: calc(100dvh - 7.5rem); overflow:auto; -webkit-overflow-scrolling: touch; }
    #resCandidates { max-height:60vh; overflow:auto; -webkit-overflow-scrolling:touch; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">

    <div class="d-flex align-items-center justify-content-between">
      <h1 class="mb-0">Unresolved Driver Matches</h1>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger mt-3">
        <strong>Errors:</strong>
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= h($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php elseif ($success): ?>
      <div class="alert alert-success mt-3">Driver matched and saved.</div>
    <?php endif; ?>

    <?php if (empty($unresolved)): ?>
      <div class="alert alert-info mt-4">No unresolved rows 🎉</div>
    <?php else: ?>
      <div class="table-responsive mt-3">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Upload Date</th>
              <th>Delivery Date</th>
              <th>Ticket #</th>
              <th>Use Role</th>
              <th>Driver (From Report)</th>
              <th class="nowrap">Truck Digits</th>
              <th>Quick Candidates</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($unresolved as $i => $u): ?>
              <?php
                $candList = $u['suggestions'] ?? [];
                $candJson = json_encode($candList, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
              ?>
              <tr>
                <td><?= (int)($i+1) ?></td>
                <td><?= h($u['upload_date'] ?? '') ?></td>
                <td><?= h($u['delivery_date'] ?? '') ?></td>
                <td><?= h($u['truckload_id'] ?? '') ?></td>
                <td><span class="badge bg-secondary"><?= h($u['use_role'] ?? 'pickup') ?></span></td>
                <td><?= h($u['driver_display'] ?? '') ?></td>
                <td class="nowrap"><code><?= h($u['truck_digits_display'] ?: '—') ?></code></td>
                <td>
                  <?php if (!empty($candList)): ?>
                    <div class="small">
                      <?php foreach ($candList as $c): ?>
                        <div class="candidate">
                          <div><strong><?= h(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))) ?></strong></div>
                          <div class="small">Truck: <?= h($c['truck_no'] ?? '') ?><?php if (!empty($c['alt_truck_no'])): ?> · Alt: <?= h($c['alt_truck_no']) ?><?php endif; ?> · ID: <?= (int)$c['id'] ?></div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-muted">No obvious candidates</span>
                  <?php endif; ?>
                </td>
                <td>
                  <button
                    class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#resolveModal"
                    data-upload="<?= h($u['upload_date'] ?? '') ?>"
                    data-delivery="<?= h($u['delivery_date'] ?? '') ?>"
                    data-ticket="<?= h($u['truckload_id'] ?? '') ?>"
                    data-role="<?= h($u['use_role'] ?? 'pickup') ?>"
                    data-driver="<?= h($u['driver_display'] ?? '') ?>"
                    data-truckraw="<?= h($u['truck_raw'] ?? '') ?>"
                    data-truckdigits="<?= h($u['truck_digits_display'] ?? '') ?>"
                    data-cands='<?= h($candJson) ?>'
                  >Resolve</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Resolve Modal (DB-backed) -->
  <div class="modal fade" id="resolveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <form method="post" id="resolveForm">
          <input type="hidden" name="action" value="resolve">
          <input type="hidden" name="upload_date"  id="resUploadDate" value="">
          <input type="hidden" name="delivery_date" id="resDeliveryDate" value="">
          <input type="hidden" name="truckload_id" id="resTruckloadId" value="">
          <input type="hidden" name="use_role" id="resUseRole" value="">

          <div class="modal-header">
            <h5 class="modal-title">Resolve Driver Match</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body">
            <div id="resDriverDetails" class="mb-3"></div>
            <h6 class="mb-2">Select a matching contact</h6>
            <div id="resCandidates" class="candidate-list"></div>
            <div class="form-check mt-3">
              <input class="form-check-input" type="checkbox" id="markNew" name="mark_new" value="1">
              <label for="markNew" class="form-check-label">This is a new driver (not in contacts)</label>
            </div>
            <div class="mt-2">
              <a id="prefillContactLink" href="#" target="_blank" class="small d-none">Open driver form prefilled</a>
            </div>
          </div>

          <div class="modal-footer">
            <a href="driver_contacts.php" class="btn btn-outline-secondary">Open Contacts</a>
            <button type="submit" class="btn btn-primary" id="resSaveBtn" disabled>Match Driver</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Modal logic
    const resolveModal = document.getElementById('resolveModal');
    const saveBtn = document.getElementById('resSaveBtn');

    function escapeHtml(str) {
      return (str ?? '').toString()
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function updateSubmitEnabled() {
      const anySelected = !!document.querySelector('#resolveModal input[name="contact_id"]:checked');
      const markNew = document.getElementById('markNew')?.checked;
      saveBtn.disabled = !(anySelected || markNew);
    }

    function candidateHtml(c) {
      const id = Number(c.id || 0);
      const radioId = `cand_${id}_${Math.random().toString(36).slice(2)}`;
      const name = ((c.first_name || '') + ' ' + (c.last_name || '')).trim();
      const truck = c.truck_no || '';
      const alt = c.alt_truck_no || '';
      return `
        <div class="candidate form-check">
          <input class="form-check-input" type="radio" name="contact_id" id="${radioId}" value="${id}"> &nbsp;
          <label class="form-check-label" for="${radioId}">
            <strong>${escapeHtml(name)}</strong>
            <div class="small">Truck: ${escapeHtml(truck)}${alt ? ' · Alt: ' + escapeHtml(alt) : ''} · ID: ${id}</div>
          </label>
        </div>`;
    }

    resolveModal?.addEventListener('show.bs.modal', (ev) => {
      const btn = ev.relatedTarget;
      const upload = btn?.getAttribute('data-upload') || '';
      const delivery = btn?.getAttribute('data-delivery') || '';
      const ticket = btn?.getAttribute('data-ticket') || '';
      const useRole = btn?.getAttribute('data-role') || 'pickup';
      const driver = btn?.getAttribute('data-driver') || '';
      const truckRaw = btn?.getAttribute('data-truckraw') || '';
      const truckDigits = btn?.getAttribute('data-truckdigits') || (truckRaw || '').replace(/\D+/g,'');

      document.getElementById('resUploadDate').value = upload;
      document.getElementById('resDeliveryDate').value = delivery;
      document.getElementById('resTruckloadId').value = ticket;
      document.getElementById('resUseRole').value = useRole;

      const detailsDiv = document.getElementById('resDriverDetails');
      detailsDiv.innerHTML = `
        <div><strong>Report Driver:</strong> ${escapeHtml(driver)}</div>
        <div><strong>Truck # (raw):</strong> ${escapeHtml(truckRaw)} &nbsp; <strong>Digits:</strong> <code>${escapeHtml(truckDigits)}</code></div>
        <div><strong>Delivery Date:</strong> ${escapeHtml(delivery)}</div>
        <div><strong>Ticket #:</strong> ${escapeHtml(ticket)}</div>
        <div><strong>Role:</strong> ${escapeHtml(useRole)}</div>
      `;

      const candsDiv = document.getElementById('resCandidates');
      candsDiv.innerHTML = '';
      let cands = [];
      try { cands = JSON.parse(btn?.getAttribute('data-cands') || '[]'); } catch(e) { cands = []; }
      if (!Array.isArray(cands) || !cands.length) {
        candsDiv.innerHTML = '<div class="text-muted">No candidates found. You can mark as a new driver or add a contact.</div>';
      } else {
        candsDiv.innerHTML = cands.map(candidateHtml).join('');
      }

      // Prefill link for quick-adding contact
      const prefill = document.getElementById('prefillContactLink');
      const parts = driver.split(' ');
      const first = parts.shift() || '';
      const last  = parts.join(' ');
      const qs = new URLSearchParams({ first_name:first, last_name:last, truck_no:truckDigits }).toString();
      prefill.href = 'driver_contacts.php?' + qs;
      prefill.classList.toggle('d-none', false);

      // Reset controls
      document.getElementById('markNew').checked = false;
      updateSubmitEnabled();
    });

    // Delegate change events for radios
    resolveModal?.addEventListener('change', (e) => {
      if (e.target && e.target.matches('input[name="contact_id"]')) {
        document.getElementById('markNew').checked = false;
        updateSubmitEnabled();
      }
    });
    document.getElementById('markNew')?.addEventListener('change', (e) => {
      if (e.target.checked) {
        document.querySelectorAll('#resolveModal input[name="contact_id"]').forEach(r => { r.checked = false; });
      }
      updateSubmitEnabled();
    });

    document.getElementById('resolveForm')?.addEventListener('submit', (e) => {
      const selected = document.querySelector('#resolveModal input[name="contact_id"]:checked');
      const markNew = document.getElementById('markNew')?.checked;
      if (!selected && !markNew) {
        e.preventDefault();
        saveBtn.disabled = true;
      }
    });
  </script>
</body>
</html>
