<?php
$rtexMode = (string)($_POST['rtex_mode'] ?? $_GET['rtex_mode'] ?? $_SESSION['rtex_mode'] ?? 'hourly');
$rtexMode = $rtexMode === 'load' ? 'load' : 'hourly';
if (($_POST['upload_mode'] ?? '') === 'rtex_payout') $rtexMode = 'hourly';
$_SESSION['rtex_mode'] = $rtexMode;
if (empty($_SESSION['rtex_csrf'])) $_SESSION['rtex_csrf'] = bin2hex(random_bytes(24));
$rtexLoadForm = null;
$rtexLoadWarnings = [];
$rtexAction = (string)($_POST['action'] ?? '');
$rtexLoadActions = ['save_rtex_load_settings','save_rtex_job_rate','delete_rtex_job_rate','import_rtex_bols','save_rtex_load',
    'delete_rtex_loads','discard_rtex_draft','export_rtex_load_invoices'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($rtexAction, $rtexLoadActions, true)) {
    $lastUploadType = 'rtex_review';
    $rtexMode = $_SESSION['rtex_mode'] = 'load';
    try {
        if (!hash_equals($_SESSION['rtex_csrf'], (string)($_POST['rtex_csrf'] ?? ''))) throw new RuntimeException('The form expired. Reload the page and try again.');
        if ($rtexAction === 'save_rtex_load_settings') {
            $week = rtex_fsc_week((string)($_POST['rtex_week_start'] ?? ''));
            $driverRate = rtex_fsc_percent($_POST['driver_fsc_rate'] ?? '');
            $invoiceRate = rtex_fsc_percent($_POST['invoice_fsc_rate'] ?? '');
            $feeMode = (string)($_POST['fee_mode'] ?? '');
            $rawFee = trim((string)($_POST['fee_value'] ?? ''));
            if (!in_array($feeMode,['flat','percentage'],true) || !is_numeric($rawFee)
                || !is_finite((float)$rawFee) || (float)$rawFee < 0
                || ($feeMode === 'percentage' && (float)$rawFee > 100)) {
                throw new InvalidArgumentException('Enter a valid broker fee.');
            }
            $before = rtex_fsc_settings($mysqli,$week);
            $beforeBroker = lonestar_vendor_broker_fee_settings($mysqli,'rtex');
            $mysqli->begin_transaction();
            try {
                rtex_save_fsc_settings($mysqli,$week,$driverRate,$invoiceRate);
                $stmt = rtex_stmt($mysqli, "UPDATE vendor_broker_fees SET fee_mode=?,fee_value=?,updated_at=NOW() WHERE vendor_scope='rtex'",
                    'sd',[$feeMode,round((float)$rawFee,2)]);
                $stmt->close();
                $mysqli->commit();
            } catch (Throwable $e) {
                $mysqli->rollback();
                throw $e;
            }
            audit_log_change($mysqli,'update','upload_rtex_fsc_settings',$week,'Updated RTEX broker fee and weekly FSC rates',
                $before + ['fee_mode'=>$beforeBroker['fee_mode'],'fee_value'=>$beforeBroker['fee_value']],
                ['driver_fsc_rate'=>$driverRate,'invoice_fsc_rate'=>$invoiceRate,'fee_mode'=>$feeMode,'fee_value'=>(float)$rawFee]);
            $success = true;
            $rtexPayoutSummary = ['review_message'=>'RTEX broker fee saved. FSC rates apply to load-based work for the week of ' . $week . '.'];
        } elseif ($rtexAction === 'save_rtex_job_rate') {
            $id = (int)($_POST['job_rate_id'] ?? 0);
            $name = trim((string)($_POST['job_name'] ?? ''));
            $basis = (string)($_POST['rate_basis'] ?? '');
            $rate = round(parse_money($_POST['rate'] ?? 0), 2);
            $order = trim((string)($_POST['work_order'] ?? ''));
            if ($name === '' || strlen($name) > 255 || strlen($order) > 80) throw new InvalidArgumentException('Enter a job name (up to 255 characters) and work order (up to 80).');
            rtex_load_total($basis, 1, 1, $rate);
            $stmt = $id > 0
                ? rtex_stmt($mysqli, 'UPDATE rtex_job_rates SET job_name=?,rate_basis=?,rate=?,work_order=? WHERE id=?', 'ssdsi', [$name,$basis,$rate,$order,$id])
                : rtex_stmt($mysqli, 'INSERT INTO rtex_job_rates (job_name,rate_basis,rate,work_order) VALUES (?,?,?,?)', 'ssds', [$name,$basis,$rate,$order]);
            $stmt->close();
            $success = true;
            $rtexPayoutSummary = ['review_message'=>'RTEX job rate saved. Existing loads retain their saved rates.'];
        } elseif ($rtexAction === 'delete_rtex_job_rate') {
            $stmt = rtex_stmt($mysqli, 'DELETE FROM rtex_job_rates WHERE id=?', 'i', [(int)($_POST['job_rate_id'] ?? 0)]);
            $stmt->close();
            $success = true;
            $rtexPayoutSummary = ['review_message'=>'RTEX job rate deleted. Historical loads remain unchanged.'];
        } elseif ($rtexAction === 'save_rtex_load') {
            $id = !empty($_POST['rtex_edit_existing']) || (int)($_POST['rtex_row_id'] ?? 0) > 0 ? (int)$_POST['rtex_row_id'] : null;
            $draftId = (string)($_POST['draft_id'] ?? '');
            $input = $_POST;
            $input['source_file_name'] = $_SESSION['rtex_bol_drafts'][$draftId]['source_file_name'] ?? '';
            $rtexLoadForm = $input; // Keep user corrections if validation fails.
            if ($draftId !== '' && !isset($_SESSION['rtex_bol_drafts'][$draftId])) throw new InvalidArgumentException('This import draft was already saved or discarded.');
            rtex_save_load($mysqli, $input, $id);
            if ($draftId !== '') unset($_SESSION['rtex_bol_drafts'][$draftId]);
            $rtexLoadForm = null;
            $_POST['rtex_week_start'] = business_sunday_week_start((string)$input['work_date']);
            $success = true;
            $rtexPayoutSummary = ['review_message'=>'RTEX load saved and synchronized to driver payouts.'];
        } elseif ($rtexAction === 'delete_rtex_loads') {
            $ids = $_POST['rtex_row_ids'] ?? [];
            if (!is_array($ids) || !$ids) throw new InvalidArgumentException('Select at least one RTEX load.');
            $deleted = rtex_delete_loads($mysqli, $ids);
            $success = true;
            $rtexPayoutSummary = ['review_message'=>"{$deleted} RTEX loads deleted from invoices and driver payouts."];
        } elseif ($rtexAction === 'discard_rtex_draft') {
            unset($_SESSION['rtex_bol_drafts'][(string)($_POST['draft_id'] ?? '')]);
            $success = true;
            $rtexPayoutSummary = ['review_message'=>'BOL draft discarded.'];
        } elseif ($rtexAction === 'import_rtex_bols') {
            $jobId = (int)($_POST['job_rate_id'] ?? 0);
            $jobs = array_column(rtex_job_rates($mysqli), null, 'id');
            if (!isset($jobs[$jobId])) throw new InvalidArgumentException('Select a job from the RTEX Job Rate Key.');
            $files = $_FILES['rtex_bol_files'] ?? [];
            if (empty($files['name']) || !is_array($files['name'])) throw new InvalidArgumentException('Select BOL images or PDFs.');
            $count = 0;
            $ctx = build_driver_match_context($mysqli);
            foreach ($files['name'] as $i => $name) {
                try {
                    $tmp = (string)($files['tmp_name'][$i] ?? '');
                    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) throw new RuntimeException('Upload failed.');
                    if ((int)($files['size'][$i] ?? 0) > 25 * 1024 * 1024) throw new RuntimeException('Maximum BOL file size is 25 MB.');
                    $pages = rtex_bol_pages($tmp, (string)$name, $rtexLoadWarnings);
                    foreach ($pages as $pageIndex => $text) {
                        if (trim($text) === '') continue;
                        if (count($_SESSION['rtex_bol_drafts'] ?? []) >= 50) throw new RuntimeException('Save or discard the pending drafts before importing more (50 maximum).');
                        $draft = rtex_parse_bol_text($text);
                        $draft['job_rate_id'] = $jobId;
                        $draft['matched_contact_id'] = (int)($_POST['matched_contact_id'] ?? 0);
                        if (!$draft['matched_contact_id']) $draft['matched_contact_id'] = resolve_driver_contact_from_context($ctx, '', digits_only($draft['truck_raw'])) ?: 0;
                        $draft['source_file_name'] = basename((string)$name) . (count($pages) > 1 ? ' (page ' . ($pageIndex + 1) . ')' : '');
                        $draftId = bin2hex(random_bytes(12));
                        $draft['draft_id'] = $draftId;
                        $_SESSION['rtex_bol_drafts'][$draftId] = $draft;
                        $count++;
                    }
                } catch (Throwable $e) {
                    $rtexLoadWarnings[] = basename((string)$name) . ': ' . $e->getMessage();
                }
            }
            if ($count === 0) throw new RuntimeException('No BOL drafts could be read. ' . implode(' ', $rtexLoadWarnings));
            $success = true;
            $rtexPayoutSummary = ['review_message'=>"{$count} BOL drafts ready to review. Confirm the ticket, date, US tons or miles, job, and matched driver, then save each load."];
        } elseif ($rtexAction === 'export_rtex_load_invoices') {
            $week = (string)($_POST['rtex_week_start'] ?? '');
            if (!rtex_valid_date($week)) throw new InvalidArgumentException('Select a valid invoice week.');
            $invoiceFscSettings = rtex_fsc_settings($mysqli,$week);
            $exportStamp = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
            $groups = [];
            foreach (get_rtex_review_rows($mysqli, $week) as $row) {
                if (($row['billing_mode'] ?? 'hourly') === 'load') $groups[$row['job_name']][] = $row;
            }
            if (!$groups) throw new InvalidArgumentException('No RTEX loads found for this week.');
            $path = tempnam(sys_get_temp_dir(), 'rtex_invoices_');
            if ($path === false) throw new RuntimeException('Cannot create invoice archive.');
            try {
                $zip = new ZipArchive();
                if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot open invoice archive.');
                $index = 0;
                foreach ($groups as $job => $rows) {
                    $safeName = substr(trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', $job), '_'), 0, 100) ?: 'Job';
                    $filename = 'rtex_' . (++$index) . '_' . $safeName . '_' . str_replace('-', '', $week) . '_' . $exportStamp . '.xlsx';
                    if (!$zip->addFromString($filename, build_rtex_load_invoice_xlsx($rows,(float)$invoiceFscSettings['invoice_fsc_rate'], $week, $index))) throw new RuntimeException('Cannot write invoice workbook.');
                }
                $zip->close();
                $content = file_get_contents($path);
                if ($content === false) throw new RuntimeException('Cannot read invoice archive.');
            } finally {
                @unlink($path);
            }
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename=rtex_load_invoices_' . str_replace('-', '', $week) . '_' . $exportStamp . '.zip');
            echo $content;
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = 'RTEX: ' . $e->getMessage();
    }
}

// Hourly forms cannot reuse a BOL identity already saved in load mode.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($rtexAction, ['add_rtex_row','save_rtex_row'], true)) {
    $stmt = rtex_stmt($mysqli, "SELECT id FROM rtex_payout_rows WHERE billing_mode='load' AND work_date=? AND ticket_number=? LIMIT 1",
        'ss', [(string)($_POST['work_date'] ?? ''), (string)($_POST['ticket_number'] ?? '')]);
    $conflict = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($conflict) {
        $errors[] = 'This RTEX ticket/date is already saved as a load. Use the load-based view to edit it.';
        $lastUploadType = 'rtex_review';
        $_POST['action'] = 'rtex_conflicting_hourly_row';
    }
}
