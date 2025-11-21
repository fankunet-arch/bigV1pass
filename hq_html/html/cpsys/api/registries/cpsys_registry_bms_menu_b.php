<?php
/**
 * CPSYS BMS Registry - Menu & POS Handlers
 * Extracted from cpsys_registry_bms.php
 */
// --- 这里是设置/营销/发票/班次 ---
// --- 处理器: POS 设置 (pos_settings) ---
function handle_settings_load(PDO $pdo, array $config, array $input_data): void {
    // [GEMINI REFACTOR] 修改 SQL (移除 global_free_addon_limit)
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM pos_settings WHERE setting_key LIKE 'points_%'");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // 默认值
    if (!isset($settings['points_euros_per_point'])) $settings['points_euros_per_point'] = '1.00';
    // [GEMINI REFACTOR] 移除 global_free_addon_limit 的默认值
    
    json_ok($settings, 'Settings loaded.');
}
function handle_settings_save(PDO $pdo, array $config, array $input_data): void {
    $settings_data = $input_data['settings'] ?? json_error('No settings data provided.', 400);
    
    // [GEMINI REFACTOR] 修改白名单
    $allowed_keys = ['points_euros_per_point'];

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO pos_settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    
    foreach ($settings_data as $key => $value) {
        
        // 确保只保存白名单内的键
        if (!in_array($key, $allowed_keys)) {
            continue;
        }

        // [GEMINI REFACTOR] 移除 global_free_addon_limit 的验证
        if ($key === 'points_euros_per_point') {
            $floatVal = filter_var($value, FILTER_VALIDATE_FLOAT);
            if ($floatVal === false || $floatVal <= 0) { $pdo->rollBack(); json_error('“每积分所需欧元”必须是一个大于0的数字。', 400); }
            $value = number_format($floatVal, 2, '.', '');
        } 
        
        $stmt->execute([':key' => $key, ':value' => $value]);
    }
    
    $pdo->commit();
    json_ok(null, '设置已成功保存！');
}

// --- [新增] 处理器: SIF 声明 (pos_settings 的特殊动作) ---
const SIF_SETTING_KEY = 'sif_declaracion_responsable';
function handle_sif_load(PDO $pdo, array $config, array $input_data): void {
    $stmt = $pdo->prepare("SELECT setting_value FROM pos_settings WHERE setting_key = ?");
    $stmt->execute([SIF_SETTING_KEY]);
    $value = $stmt->fetchColumn();
    if ($value === false) $value = null; // 区分 '未找到' (null) 和 '空字符串' ('')
    json_ok(['declaration_text' => $value], 'Declaración cargada.');
}
function handle_sif_save(PDO $pdo, array $config, array $input_data): void {
    // SIF handler 不使用 'data' 包装器
    $declaration_text = $input_data['declaration_text'] ?? null;
    if ($declaration_text === null) json_error('No se proporcionó texto de declaración.', 400);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO pos_settings (setting_key, setting_value, description) VALUES (:key, :value, :desc) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([
        ':key' => SIF_SETTING_KEY,
        ':value' => $declaration_text,
        ':desc' => 'Declaración Responsable (SIF Compliance Statement)'
    ]);
    $pdo->commit();
    json_ok(null, 'Declaración Responsable guardada con éxito.');
}


// --- 处理器: 营销活动 (pos_promotions) ---
function handle_promo_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('无效的ID。', 400);
    $stmt = $pdo->prepare("SELECT * FROM pos_promotions WHERE id = ?");
    $stmt->execute([(int)$id]);
    $promo = $stmt->fetch(PDO::FETCH_ASSOC);
    $promo ? json_ok($promo, '活动已加载。') : json_error('未找到指定的活动。', 404);
}
function handle_promo_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id   = !empty($data['id']) ? (int)$data['id'] : null;
    $promo_name         = trim((string)($data['promo_name'] ?? ''));
    $promo_priority     = (int)($data['promo_priority'] ?? 0);
    $promo_exclusive    = (int)($data['promo_exclusive'] ?? 0);
    $promo_is_active    = (int)($data['promo_is_active'] ?? 0);
    $promo_trigger_type = trim((string)($data['promo_trigger_type'] ?? 'AUTO_APPLY'));
    $promo_code         = trim((string)($data['promo_code'] ?? ''));
    $promo_start_date   = trim((string)($data['promo_start_date'] ?? ''));
    $promo_end_date     = trim((string)($data['promo_end_date'] ?? ''));
    $promo_conditions   = json_encode($data['promo_conditions'] ?? [], JSON_UNESCAPED_UNICODE);
    $promo_actions      = json_encode($data['promo_actions'] ?? [], JSON_UNESCAPED_UNICODE);
    if ($promo_name === '') json_error('活动名称不能为空。', 400);
    if ($promo_trigger_type === 'COUPON_CODE' && $promo_code === '') json_error('优惠码类型的活动，优惠码不能为空。', 400);
    if ($promo_trigger_type === 'COUPON_CODE' && $promo_code !== '') {
        $sql = "SELECT id FROM pos_promotions WHERE LOWER(TRIM(promo_code)) = LOWER(TRIM(?))";
        $params = [$promo_code];
        if ($id) { $sql .= " AND id != ?"; $params[] = $id; }
        $dup = $pdo->prepare($sql);
        $dup->execute($params);
        if ($dup->fetch()) json_error('此优惠码已被其他活动使用。', 409);
    }
    // [A2.2 UTC FIX] promo_start/end_date 是 DATETIME, 不是 TIMESTAMP(6)。
    // 移除 .u
    $startDate = ($promo_start_date !== '' ? str_replace('T',' ', $promo_start_date) : null);
    $endDate = ($promo_end_date   !== '' ? str_replace('T',' ', $promo_end_date)   : null);
    
    $codeValue = ($promo_trigger_type === 'COUPON_CODE' ? $promo_code : null);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE pos_promotions SET promo_name = ?, promo_priority = ?, promo_exclusive = ?, promo_is_active = ?, promo_trigger_type = ?, promo_code = ?, promo_conditions = ?, promo_actions = ?, promo_start_date = ?, promo_end_date = ? WHERE id = ?");
        $stmt->execute([$promo_name, $promo_priority, $promo_exclusive, $promo_is_active, $promo_trigger_type, $codeValue, $promo_conditions, $promo_actions, $startDate, $endDate, $id]);
        json_ok(['id' => $id], '活动已成功更新！');
    } else {
        $stmt = $pdo->prepare("INSERT INTO pos_promotions (promo_name, promo_priority, promo_exclusive, promo_is_active, promo_trigger_type, promo_code, promo_conditions, promo_actions, promo_start_date, promo_end_date) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$promo_name, $promo_priority, $promo_exclusive, $promo_is_active, $promo_trigger_type, $codeValue, $promo_conditions, $promo_actions, $startDate, $endDate]);
        json_ok(['id' => (int)$pdo->lastInsertId()], '新活动已成功创建！');
    }
}
function handle_promo_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('无效的ID。', 400);
    $stmt = $pdo->prepare("DELETE FROM pos_promotions WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, '活动已成功删除。');
}

// --- 处理器: 票据操作 (invoices) ---
function handle_invoice_cancel(PDO $pdo, array $config, array $input_data): void {
    $original_invoice_id = (int)($input_data['id'] ?? 0);
    $cancellation_reason = trim($input_data['reason'] ?? 'Error en la emisión');
    if ($original_invoice_id <= 0) json_error('无效的原始票据ID。', 400);
    $pdo->beginTransaction();
    try {
        $stmt_original = $pdo->prepare("SELECT * FROM pos_invoices WHERE id = ? FOR UPDATE");
        $stmt_original->execute([$original_invoice_id]);
        $original_invoice = $stmt_original->fetch();
        if (!$original_invoice) { $pdo->rollBack(); json_error("原始票据不存在。", 404); }
        if ($original_invoice['status'] === 'CANCELLED') { $pdo->rollBack(); json_error("此票据已被作废，无法重复操作。", 409); }
        $compliance_system = $original_invoice['compliance_system'];
        $store_id = $original_invoice['store_id'];
        $handler_path = realpath(__DIR__ . "/../../../app/helpers/compliance/{$compliance_system}Handler.php");
        if (!$handler_path || !file_exists($handler_path)) {
             // Fallback path for different server structure (e.g., store vs hq)
             $handler_path = realpath(__DIR__ . "/../../../../../../store/store_html/pos_backend/compliance/{$compliance_system}Handler.php");
             if (!$handler_path || !file_exists($handler_path)) {
                throw new Exception("Compliance handler for '{$compliance_system}' not found at either path.");
             }
        }
        require_once $handler_path;
        $handler_class = "{$compliance_system}Handler";
        $handler = new $handler_class();
        $series = $original_invoice['series'];
        // [A2.2 UTC FIX] 
        // pos_invoices.issued_at 是 timestamp(6)，必须使用 .u
        $issued_at = utc_now()->format('Y-m-d H:i:s.u');
        $stmt_store = $pdo->prepare("SELECT tax_id FROM kds_stores WHERE id = ?");
        $stmt_store->execute([$store_id]);
        $store_config = $stmt_store->fetch();
        $issuer_nif = $store_config['tax_id'];
        $stmt_prev = $pdo->prepare("SELECT compliance_data FROM pos_invoices WHERE compliance_system = ? AND series = ? AND issuer_nif = ? ORDER BY `number` DESC LIMIT 1");
        $stmt_prev->execute([$compliance_system, $series, $issuer_nif]);
        $prev_invoice = $stmt_prev->fetch();
        $previous_hash = $prev_invoice ? (json_decode($prev_invoice['compliance_data'], true)['hash'] ?? null) : null;
        $cancellationData = ['cancellation_reason' => $cancellation_reason, 'issued_at' => $issued_at];
        $compliance_data = $handler->generateCancellationData($pdo, $original_invoice, $cancellationData, $previous_hash);
        $next_number = 1 + ($pdo->query("SELECT IFNULL(MAX(number), 0) FROM pos_invoices WHERE compliance_system = '{$compliance_system}' AND series = '{$series}' AND issuer_nif = '{$issuer_nif}'")->fetchColumn());
        $sql_cancel = "INSERT INTO pos_invoices (invoice_uuid, store_id, user_id, issuer_nif, series, `number`, issued_at, invoice_type, status, cancellation_reason, references_invoice_id, compliance_system, compliance_data, taxable_base, vat_amount, final_total) VALUES ( ?, ?, ?, ?, ?, ?, ?, 'R5', 'ISSUED', ?, ?, ?, ?, 0.00, 0.00, 0.00 )";
        $stmt_cancel = $pdo->prepare($sql_cancel);
        $stmt_cancel->execute([ uniqid('can-', true), $store_id, $_SESSION['user_id'] ?? 1, $issuer_nif, $series, $next_number, $issued_at, $cancellation_reason, $original_invoice_id, $compliance_system, json_encode($compliance_data) ]);
        $cancellation_invoice_id = $pdo->lastInsertId();
        $stmt_update_original = $pdo->prepare("UPDATE pos_invoices SET status = 'CANCELLED', cancellation_reason = ? WHERE id = ?");
        $stmt_update_original->execute([$cancellation_reason, $original_invoice_id]);
        $pdo->commit();
        json_ok(['cancellation_invoice_id' => $cancellation_invoice_id], '票据已成功作废并生成作废记录。');
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); json_error('作废票据失败。', 500, ['debug' => $e->getMessage()]); }
}
function handle_invoice_correct(PDO $pdo, array $config, array $input_data): void {
    $original_invoice_id = (int)($input_data['id'] ?? 0);
    $correction_type = $input_data['type'] ?? '';
    $new_total_str = $input_data['new_total'] ?? null;
    $reason = trim($input_data['reason'] ?? '');
    if ($original_invoice_id <= 0 || !in_array($correction_type, ['S', 'I']) || empty($reason)) json_error('请求参数无效 (ID, 类型, 原因)。', 400);
    if ($correction_type === 'I' && ($new_total_str === null || !is_numeric($new_total_str) || (float)$new_total_str < 0)) json_error('按差额更正时，必须提供一个有效的、非负的最终总额。', 400);
    $pdo->beginTransaction();
    try {
        $stmt_original = $pdo->prepare("SELECT * FROM pos_invoices WHERE id = ? FOR UPDATE");
        $stmt_original->execute([$original_invoice_id]);
        $original_invoice = $stmt_original->fetch();
        if (!$original_invoice) { $pdo->rollBack(); json_error("原始票据不存在。", 404); }
        if ($original_invoice['status'] === 'CANCELLED') { $pdo->rollBack(); json_error("已作废的票据不能被更正。", 409); }
        $compliance_system = $original_invoice['compliance_system'];
        $store_id = $original_invoice['store_id'];
        $handler_path = realpath(__DIR__ . "/../../../app/helpers/compliance/{$compliance_system}Handler.php");
        if (!$handler_path || !file_exists($handler_path)) {
             // Fallback path
             $handler_path = realpath(__DIR__ . "/../../../../../../store/store_html/pos_backend/compliance/{$compliance_system}Handler.php");
             if (!$handler_path || !file_exists($handler_path)) {
                 throw new Exception("合规处理器 '{$compliance_system}' 未找到。");
             }
        }
        require_once $handler_path;
        $handler_class = "{$compliance_system}Handler";
        $handler = new $handler_class();
        $stmt_store = $pdo->prepare("SELECT tax_id, default_vat_rate FROM kds_stores WHERE id = ?");
        $stmt_store->execute([$store_id]);
        $store_config = $stmt_store->fetch();
        $issuer_nif = $store_config['tax_id'];
        $vat_rate = $store_config['default_vat_rate'];
        if ($correction_type === 'S') { $final_total = -$original_invoice['final_total']; }
        else { $new_total = (float)$new_total_str; $final_total = $new_total - (float)$original_invoice['final_total']; }
        $taxable_base = round($final_total / (1 + ($vat_rate / 100)), 2);
        $vat_amount = $final_total - $taxable_base;
        $series = $original_invoice['series'];
        // [A2.2 UTC FIX] 
        // pos_invoices.issued_at 是 timestamp(6)，必须使用 .u
        $issued_at = utc_now()->format('Y-m-d H:i:s.u');
        $stmt_prev = $pdo->prepare("SELECT compliance_data FROM pos_invoices WHERE compliance_system = ? AND series = ? AND issuer_nif = ? ORDER BY `number` DESC LIMIT 1");
        $stmt_prev->execute([$compliance_system, $series, $issuer_nif]);
        $prev_invoice = $stmt_prev->fetch();
        $previous_hash = $prev_invoice ? (json_decode($prev_invoice['compliance_data'], true)['hash'] ?? null) : null;
        $next_number = 1 + ($pdo->query("SELECT IFNULL(MAX(number), 0) FROM pos_invoices WHERE compliance_system = '{$compliance_system}' AND series = '{$series}' AND issuer_nif = '{$issuer_nif}'")->fetchColumn());
        $invoiceData = ['series' => $series, 'number' => $next_number, 'issued_at' => $issued_at, 'final_total' => $final_total];
        $compliance_data = $handler->generateComplianceData($pdo, $invoiceData, $previous_hash);
        $sql_corrective = "INSERT INTO pos_invoices (invoice_uuid, store_id, user_id, issuer_nif, series, `number`, issued_at, invoice_type, status, correction_type, references_invoice_id, compliance_system, compliance_data, taxable_base, vat_amount, final_total) VALUES (?, ?, ?, ?, ?, ?, ?, 'R5', 'ISSUED', ?, ?, ?, ?, ?, ?, ?)";
        $stmt_corrective = $pdo->prepare($sql_corrective);
        $stmt_corrective->execute([ uniqid('cor-', true), $store_id, $_SESSION['user_id'] ?? 1, $issuer_nif, $series, $next_number, $issued_at, $correction_type, $original_invoice_id, $compliance_system, json_encode($compliance_data), $taxable_base, $vat_amount, $final_total ]);
        $corrective_invoice_id = $pdo->lastInsertId();
        $pdo->commit();
        json_ok(['corrective_invoice_id' => $corrective_invoice_id], '更正票据已成功生成。');
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); json_error('生成更正票据失败。', 500, ['debug' => $e->getMessage()]); }
}

// --- 处理器: 班次复核 (shifts) ---
function handle_shift_review(PDO $pdo, array $config, array $input_data): void {
    $shift_id = (int)($input_data['shift_id'] ?? 0);
    $counted_cash_str = $input_data['counted_cash'] ?? null;
    if ($shift_id <= 0 || $counted_cash_str === null || !is_numeric($counted_cash_str)) json_error('无效的参数 (shift_id or counted_cash)。', 400);
    $counted_cash = (float)$counted_cash_str;
    
    // [A2.2 UTC FIX] 
    // pos_shifts.updated_at 是 timestamp(0)。必须使用 'Y-m-d H:i:s'
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');
    
    $pdo->beginTransaction();
    try {
        $stmt_get = $pdo->prepare("SELECT id, expected_cash FROM pos_shifts WHERE id = ? AND status = 'FORCE_CLOSED' AND admin_reviewed = 0 FOR UPDATE");
        $stmt_get->execute([$shift_id]);
        $shift = $stmt_get->fetch(PDO::FETCH_ASSOC);
        if (!$shift) { $pdo->rollBack(); json_error('未找到待复核的班次，或该班次已被他人处理。', 404); }
        $expected_cash = (float)$shift['expected_cash'];
        $cash_diff = $counted_cash - $expected_cash;
        $stmt_update = $pdo->prepare("UPDATE pos_shifts SET counted_cash = ?, cash_variance = ?, admin_reviewed = 1, updated_at = ? WHERE id = ?");
        $stmt_update->execute([$counted_cash, $cash_diff, $now_utc_str, $shift_id]);
        
        // ================== [GEMINI HEALTH CHECK FIX V2.0] ==================
        // 移除了对 `pos_eod_records.notes` 字段的写入，因为该字段不存在。
        try {
            $stmt_eod = $pdo->prepare("UPDATE pos_eod_records SET counted_cash = ?, cash_diff = ? WHERE shift_id = ?");
            $stmt_eod->execute([$counted_cash, $cash_diff, $shift_id]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') { throw $e; }
            error_log("Warning: pos_eod_records table not found during shift review. Skipping update.");
        }
        // ================== [GEMINI HEALTH CHECK FIX V2.0] ==================

        $pdo->commit();
        json_ok(null, '班次复核成功！');
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); json_error('班次复核失败', 500, ['debug' => $e->getMessage()]); }
}