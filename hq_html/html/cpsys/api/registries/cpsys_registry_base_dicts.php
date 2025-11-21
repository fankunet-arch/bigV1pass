<?php
/**
 * CPSYS Base Registry - Dictionary Handlers
 * Extracted from cpsys_registry_base.php
 */
// --- 处理器: 杯型 (cups) ---
function handle_cup_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $data = getCupById($pdo, (int)$id);
    $data ? json_ok($data) : json_error('未找到杯型', 404);
}
function handle_cup_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $code = trim($data['cup_code'] ?? ''); $name = trim($data['cup_name'] ?? '');
    $sop_zh = trim($data['sop_zh'] ?? ''); $sop_es = trim($data['sop_es'] ?? '');
    if (empty($code) || empty($name) || empty($sop_zh) || empty($sop_es)) json_error('编号、名称和双语SOP描述均为必填项。', 400);
    $stmt_check = $pdo->prepare("SELECT id FROM kds_cups WHERE cup_code = ? AND deleted_at IS NULL" . ($id ? " AND id != ?" : ""));
    $params_check = $id ? [$code, $id] : [$code];
    $stmt_check->execute($params_check);
    if ($stmt_check->fetch()) json_error('此编号已被使用。', 409);
    $params = [':code' => $code, ':name' => $name, ':sop_zh' => $sop_zh, ':sop_es' => $sop_es];
    if ($id) {
        $params[':id'] = $id;
        $sql = "UPDATE kds_cups SET cup_code = :code, cup_name = :name, sop_description_zh = :sop_zh, sop_description_es = :sop_es WHERE id = :id";
        $pdo->prepare($sql)->execute($params);
        json_ok(['id' => $id], '杯型已更新。');
    } else {
        $sql = "INSERT INTO kds_cups (cup_code, cup_name, sop_description_zh, sop_description_es) VALUES (:code, :name, :sop_zh, :sop_es)";
        $pdo->prepare($sql)->execute($params);
        json_ok(['id' => (int)$pdo->lastInsertId()], '新杯型已创建。');
    }
}
function handle_cup_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("UPDATE kds_cups SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, '杯型已删除。');
}

// --- 处理器: 冰量 (ice_options) ---
function handle_ice_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $data = getIceOptionById($pdo, (int)$id);
    $data ? json_ok($data) : json_error('未找到选项', 404);
}
function handle_ice_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $code = trim($data['code'] ?? ''); $name_zh = trim($data['name_zh'] ?? ''); $name_es = trim($data['name_es'] ?? '');
    $sop_zh = trim($data['sop_zh'] ?? ''); $sop_es = trim($data['sop_es'] ?? '');
    if (empty($code) || empty($name_zh) || empty($name_es) || empty($sop_zh) || empty($sop_es)) json_error('编号、双语名称和双语SOP描述均为必填项。', 400);
    $pdo->beginTransaction();
    if ($id) {
        $stmt_check = $pdo->prepare("SELECT id FROM kds_ice_options WHERE ice_code = ? AND id != ? AND deleted_at IS NULL");
        $stmt_check->execute([$code, $id]);
        if ($stmt_check->fetch()) { $pdo->rollBack(); json_error('此编号已被使用。', 409); }
        $pdo->prepare("UPDATE kds_ice_options SET ice_code = ? WHERE id = ?")->execute([$code, $id]);
    } else {
        $stmt_check = $pdo->prepare("SELECT id FROM kds_ice_options WHERE ice_code = ? AND deleted_at IS NULL");
        $stmt_check->execute([$code]);
        if ($stmt_check->fetch()) { $pdo->rollBack(); json_error('此编号已被使用。', 409); }
        $pdo->prepare("INSERT INTO kds_ice_options (ice_code) VALUES (?)")->execute([$code]);
        $id = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO kds_ice_option_translations (ice_option_id, language_code, ice_option_name, sop_description) VALUES (?, 'zh-CN', ?, ?) ON DUPLICATE KEY UPDATE ice_option_name = VALUES(ice_option_name), sop_description = VALUES(sop_description)")->execute([$id, $name_zh, $sop_zh]);
    $pdo->prepare("INSERT INTO kds_ice_option_translations (ice_option_id, language_code, ice_option_name, sop_description) VALUES (?, 'es-ES', ?, ?) ON DUPLICATE KEY UPDATE ice_option_name = VALUES(ice_option_name), sop_description = VALUES(sop_description)")->execute([$id, $name_es, $sop_es]);
    $pdo->commit();
    json_ok(['id' => $id], '冰量选项已保存。');
}
function handle_ice_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("UPDATE kds_ice_options SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, '冰量选项已删除。');
}
function handle_ice_get_next_code(PDO $pdo, array $config, array $input_data): void {
    $next = getNextAvailableCustomCode($pdo, 'kds_ice_options', 'ice_code');
    json_ok(['next_code' => $next]);
}

// --- 处理器: 甜度 (sweetness_options) ---
function handle_sweetness_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $data = getSweetnessOptionById($pdo, (int)$id);
    $data ? json_ok($data) : json_error('未找到选项', 404);
}
function handle_sweetness_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $code = trim($data['code'] ?? ''); $name_zh = trim($data['name_zh'] ?? ''); $name_es = trim($data['name_es'] ?? '');
    $sop_zh = trim($data['sop_zh'] ?? ''); $sop_es = trim($data['sop_es'] ?? '');
    if (empty($code) || empty($name_zh) || empty($name_es) || empty($sop_zh) || empty($sop_es)) json_error('编号、双语名称和双语SOP描述均为必填项。', 400);
    $pdo->beginTransaction();
    if ($id) {
        $stmt_check = $pdo->prepare("SELECT id FROM kds_sweetness_options WHERE sweetness_code = ? AND id != ? AND deleted_at IS NULL");
        $stmt_check->execute([$code, $id]);
        if ($stmt_check->fetch()) { $pdo->rollBack(); json_error('此编号已被使用。', 409); }
        $pdo->prepare("UPDATE kds_sweetness_options SET sweetness_code = ? WHERE id = ?")->execute([$code, $id]);
    } else {
        $stmt_check = $pdo->prepare("SELECT id FROM kds_sweetness_options WHERE sweetness_code = ? AND deleted_at IS NULL");
        $stmt_check->execute([$code]);
        if ($stmt_check->fetch()) { $pdo->rollBack(); json_error('此编号已被使用。', 409); }
        $pdo->prepare("INSERT INTO kds_sweetness_options (sweetness_code) VALUES (?)")->execute([$code]);
        $id = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO kds_sweetness_option_translations (sweetness_option_id, language_code, sweetness_option_name, sop_description) VALUES (?, 'zh-CN', ?, ?) ON DUPLICATE KEY UPDATE sweetness_option_name = VALUES(sweetness_option_name), sop_description = VALUES(sop_description)")->execute([$id, $name_zh, $sop_zh]);
    $pdo->prepare("INSERT INTO kds_sweetness_option_translations (sweetness_option_id, language_code, sweetness_option_name, sop_description) VALUES (?, 'es-ES', ?, ?) ON DUPLICATE KEY UPDATE sweetness_option_name = VALUES(sweetness_option_name), sop_description = VALUES(sop_description)")->execute([$id, $name_es, $sop_es]);
    $pdo->commit();
    json_ok(['id' => $id], '甜度选项已保存。');
}
function handle_sweetness_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("UPDATE kds_sweetness_options SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, '甜度选项已删除。');
}
function handle_sweetness_get_next_code(PDO $pdo, array $config, array $input_data): void {
    $next = getNextAvailableCustomCode($pdo, 'kds_sweetness_options', 'sweetness_code');
    json_ok(['next_code' => $next]);
}

// --- 处理器: 单位 (units) ---
function handle_unit_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $data = getUnitById($pdo, (int)$id);
    $data ? json_ok($data) : json_error('未找到单位', 404);
}
function handle_unit_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $code = trim($data['unit_code'] ?? ''); $name_zh = trim($data['name_zh'] ?? ''); $name_es = trim($data['name_es'] ?? '');
    if (empty($code) || empty($name_zh) || empty($name_es)) json_error('编号和双语名称均为必填项。', 400);
    
    // [R2] 审计
    $data_before = null;
    $action_name = 'rms.unit.create';
    if ($id) {
        $data_before = getUnitById($pdo, $id); // 获取变更前数据
        $action_name = 'rms.unit.update';
    }

    $pdo->beginTransaction();
    if ($id) {
        $stmt_check = $pdo->prepare("SELECT id FROM kds_units WHERE unit_code = ? AND id != ? AND deleted_at IS NULL");
        $stmt_check->execute([$code, $id]);
        if ($stmt_check->fetch()) { $pdo->rollBack(); json_error('此编号已被使用。', 409); }
        $pdo->prepare("UPDATE kds_units SET unit_code = ? WHERE id = ?")->execute([$code, $id]);
    } else {
        $stmt_check = $pdo->prepare("SELECT id FROM kds_units WHERE unit_code = ? AND deleted_at IS NULL");
        $stmt_check->execute([$code]);
        if ($stmt_check->fetch()) { $pdo->rollBack(); json_error('此编号已被使用。', 409); }
        $pdo->prepare("INSERT INTO kds_units (unit_code) VALUES (?)")->execute([$code]);
        $id = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO kds_unit_translations (unit_id, language_code, unit_name) VALUES (?, 'zh-CN', ?) ON DUPLICATE KEY UPDATE unit_name = VALUES(unit_name)")->execute([$id, $name_zh]);
    $pdo->prepare("INSERT INTO kds_unit_translations (unit_id, language_code, unit_name) VALUES (?, 'es-ES', ?) ON DUPLICATE KEY UPDATE unit_name = VALUES(unit_name)")->execute([$id, $name_es]);
    
    $pdo->commit();
    
    // [R2] 写入审计日志
    $data_after = getUnitById($pdo, $id);
    log_audit_action($pdo, $action_name, 'kds_units', $id, $data_before, $data_after);
    
    json_ok(['id' => $id], '单位已保存。');
}
function handle_unit_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $id = (int)$id;

    // [R2] 审计
    $data_before = getUnitById($pdo, $id);
    if (!$data_before) {
        json_error('未找到要删除的单位。', 404);
    }
    
    $stmt = $pdo->prepare("UPDATE kds_units SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$id]);
    
    // [R2] 写入审计日志
    log_audit_action($pdo, 'rms.unit.delete', 'kds_units', $id, $data_before, null);

    json_ok(null, '单位已删除。');
}
function handle_unit_get_next_code(PDO $pdo, array $config, array $input_data): void {
    $next = getNextAvailableCustomCode($pdo, 'kds_units', 'unit_code');
    json_ok(['next_code' => $next]);
}

// --- 处理器: 产品状态 (product_statuses) ---
function handle_status_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("SELECT * FROM kds_product_statuses WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([(int)$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $data ? json_ok($data) : json_error('未找到状态', 404);
}
function handle_status_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $code = trim($data['status_code'] ?? '');
    $name_zh = trim($data['status_name_zh'] ?? ''); $name_es = trim($data['status_name_es'] ?? '');
    if (empty($code) || empty($name_zh) || empty($name_es)) json_error('编号和双语名称均为必填项。', 400);
    $stmt_check = $pdo->prepare("SELECT id FROM kds_product_statuses WHERE status_code = ? AND deleted_at IS NULL" . ($id ? " AND id != ?" : ""));
    $params_check = $id ? [$code, $id] : [$code];
    $stmt_check->execute($params_check);
    if ($stmt_check->fetch()) json_error('此编号已被使用。', 409);
    $params = [':code' => $code, ':name_zh' => $name_zh, ':name_es' => $name_es];
    if ($id) {
        $params[':id'] = $id;
        $sql = "UPDATE kds_product_statuses SET status_code = :code, status_name_zh = :name_zh, status_name_es = :name_es WHERE id = :id";
        $pdo->prepare($sql)->execute($params);
        json_ok(['id' => $id], '状态已更新。');
    } else {
        $sql = "INSERT INTO kds_product_statuses (status_code, status_name_zh, status_name_es) VALUES (:code, :name_zh, :name_es)";
        $pdo->prepare($sql)->execute($params);
        json_ok(['id' => (int)$pdo->lastInsertId()], '新状态已创建。');
    }
}
function handle_status_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("UPDATE kds_product_statuses SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, '状态已删除。');
}
// --- END: 缺失的处理器 ---
