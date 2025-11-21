<?php
/**
 * CPSYS BMS Registry - Member & Topup Handlers
 * Extracted from cpsys_registry_bms.php
 */
// --- [R2.4] START: 处理器: 售卡订单 (topup_orders) ---
function handle_topup_order_review(PDO $pdo, array $config, array $input_data): void {
    $order_id = (int)($input_data['order_id'] ?? 0);
    $action = trim($input_data['action'] ?? '');
    $reviewer_id = (int)($_SESSION['user_id'] ?? 0);

    if ($order_id <= 0 || !in_array($action, ['APPROVE', 'REJECT']) || $reviewer_id <= 0) {
        json_error('无效的请求参数 (order_id, action) 或未登录。', 400);
    }
    
    // [R2/B1] 审计：获取变更前数据
    if (!function_exists('getTopupOrderById')) json_error('审计失败: 缺少 getTopupOrderById 助手。', 500);
    $data_before = getTopupOrderById($pdo, $order_id);
    if (!$data_before) {
        json_error("未找到订单 (ID: {$order_id})。", 404);
    }
    if ($data_before['review_status'] !== 'pending') {
        json_error("订单 (ID: {$order_id}) 状态为 '{$data_before['review_status']}'，无法重复处理。", 409);
    }
    
    // A2 UTC: 确保所有时间戳使用 0 精度 (Y-m-d H:i:s.u)
    // topup_orders.reviewed_at 和 member_passes.activated_at/expires_at 均为 timestamp(0)
    $now_utc_str = utc_now()->format('Y-m-d H:i:s.u');
    
    $pdo->beginTransaction();
    try {
        $audit_action_name = '';
        
        if ($action === 'APPROVE') {
            $audit_action_name = 'bms.pass_vr.approve';
            // 调用辅助函数激活次卡
            $result = activate_member_pass($pdo, $order_id, $reviewer_id, $now_utc_str);
            if ($result !== true) {
                $pdo->rollBack();
                json_error($result, 409); // 409 Conflict (e.g., already processed)
            }
        } else {
            // 仅拒绝
            $audit_action_name = 'bms.pass_vr.reject';
            
            // (在 activate_member_pass 中已包含 FOR UPDATE 和 status 检查，此处为 REJECT 补充)
            $stmt_check = $pdo->prepare("SELECT review_status FROM topup_orders WHERE topup_order_id = ? FOR UPDATE");
            $stmt_check->execute([$order_id]);
            $status = $stmt_check->fetchColumn();
            
            if ($status !== 'pending') {
                 $pdo->rollBack();
                 json_error("订单状态不是 PENDING (而是 {$status})，无法拒绝。", 409);
            }

            $sql = "UPDATE topup_orders SET review_status = 'rejected', reviewed_by_user_id = ?, reviewed_at = ? WHERE topup_order_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$reviewer_id, $now_utc_str, $order_id]);
        }

        $pdo->commit();
        
        // [R2/B1] 审计：写入日志
        $data_after = getTopupOrderById($pdo, $order_id);
        log_audit_action($pdo, $audit_action_name, 'topup_orders', $order_id, $data_before, $data_after);

        json_ok(null, '订单 (ID: ' . $order_id . ') 已成功处理为: ' . $action);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('审核订单时发生严重错误。', 500, ['debug' => $e->getMessage()]);
    }
}
// --- [R2.4] END: 处理器: 售卡订单 (topup_orders) ---


// --- 处理器: 会员等级 (pos_member_levels) ---
function handle_member_level_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $data = getMemberLevelById($pdo, (int)$id);
    $data ? json_ok($data) : json_error('未找到等级', 404);
}
function handle_member_level_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $params = [
        ':level_name_zh' => trim($data['level_name_zh']),
        ':level_name_es' => trim($data['level_name_es']),
        ':points_threshold' => (float)($data['points_threshold'] ?? 0),
        ':sort_order' => (int)($data['sort_order'] ?? 99),
        ':level_up_promo_id' => !empty($data['level_up_promo_id']) ? (int)$data['level_up_promo_id'] : null,
    ];
    if (empty($params[':level_name_zh']) || empty($params[':level_name_es'])) json_error('双语等级名称均为必填项。', 400);
    if ($id) {
        $params[':id'] = $id;
        $sql = "UPDATE pos_member_levels SET level_name_zh = :level_name_zh, level_name_es = :level_name_es, points_threshold = :points_threshold, sort_order = :sort_order, level_up_promo_id = :level_up_promo_id WHERE id = :id";
        $pdo->prepare($sql)->execute($params);
        json_ok(['id' => $id], '会员等级已成功更新！');
    } else {
        $sql = "INSERT INTO pos_member_levels (level_name_zh, level_name_es, points_threshold, sort_order, level_up_promo_id) VALUES (:level_name_zh, :level_name_es, :points_threshold, :sort_order, :level_up_promo_id)";
        $pdo->prepare($sql)->execute($params);
        json_ok(['id' => (int)$pdo->lastInsertId()], '新会员等级已成功创建！');
    }
}
function handle_member_level_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("DELETE FROM pos_member_levels WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, '会员等级已成功删除。');
}

// --- 处理器: 会员 (pos_members) ---
function handle_member_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $data = getMemberById($pdo, (int)$id);
    $data ? json_ok($data) : json_error('未找到会员', 404);
}
function handle_member_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $phone = trim($data['phone_number'] ?? '');
    if (empty($phone)) json_error('手机号为必填项。', 400);
    $stmt_check = $pdo->prepare("SELECT id FROM pos_members WHERE phone_number = ? AND deleted_at IS NULL" . ($id ? " AND id != ?" : ""));
    $params_check = $id ? [$phone, $id] : [$phone];
    $stmt_check->execute($params_check);
    if ($stmt_check->fetch()) json_error('此手机号已被其他会员使用。', 409);
    $params = [
        ':phone_number' => $phone,
        ':first_name' => !empty($data['first_name']) ? trim($data['first_name']) : null,
        ':last_name' => !empty($data['last_name']) ? trim($data['last_name']) : null,
        ':email' => !empty($data['email']) ? trim($data['email']) : null,
        ':birthdate' => !empty($data['birthdate']) ? trim($data['birthdate']) : null,
        ':points_balance' => (float)($data['points_balance'] ?? 0),
        ':member_level_id' => !empty($data['member_level_id']) ? (int)$data['member_level_id'] : null,
        ':is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1
    ];
    if ($id) {
        $params[':id'] = $id;
        $sql = "UPDATE pos_members SET phone_number = :phone_number, first_name = :first_name, last_name = :last_name, email = :email, birthdate = :birthdate, points_balance = :points_balance, member_level_id = :member_level_id, is_active = :is_active WHERE id = :id";
        $pdo->prepare($sql)->execute($params);
        json_ok(['id' => $id], '会员信息已成功更新！');
    } else {
        $params[':member_uuid'] = bin2hex(random_bytes(16));
        $sql = "INSERT INTO pos_members (member_uuid, phone_number, first_name, last_name, email, birthdate, points_balance, member_level_id, is_active) VALUES (:member_uuid, :phone_number, :first_name, :last_name, :email, :birthdate, :points_balance, :member_level_id, :is_active)";
        $pdo->prepare($sql)->execute($params);
        json_ok(['id' => (int)$pdo->lastInsertId()], '新会员已成功创建！');
    }
}
function handle_member_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    // [A2.2 UTC FIX] 
    // pos_members.deleted_at 是 timestamp(0)。必须使用 'Y-m-d H:i:s.u'
    // [GEMINI V3 FIX] 数据库为 datetime(6)，使用 .u
    $now_utc_str = utc_now()->format('Y-m-d H:i:s.u');
    $stmt = $pdo->prepare("UPDATE pos_members SET deleted_at = ? WHERE id = ?");
    $stmt->execute([$now_utc_str, (int)$id]);
    json_ok(null, '会员已成功删除。');
}

// --- 处理器: 积分兑换规则 (pos_point_redemption_rules) ---
function handle_redemption_rule_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("SELECT * FROM pos_point_redemption_rules WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([(int)$id]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
    $rule ? json_ok($rule) : json_error('未找到指定的规则。', 404);
}
function handle_redemption_rule_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $name_zh = trim($data['rule_name_zh'] ?? ''); $name_es = trim($data['rule_name_es'] ?? '');
    $points = filter_var($data['points_required'] ?? null, FILTER_VALIDATE_INT);
    $reward_type = $data['reward_type'] ?? '';
    $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 0;
    $reward_value_decimal = null; $reward_promo_id = null;
    if (empty($name_zh) || empty($name_es) || $points === false || $points <= 0) json_error('规则名称和所需积分为必填项，且积分必须大于0。', 400);
    if (!in_array($reward_type, ['DISCOUNT_AMOUNT', 'SPECIFIC_PROMOTION'])) json_error('无效的奖励类型。', 400);
    if ($reward_type === 'DISCOUNT_AMOUNT') {
        $reward_value_decimal = filter_var($data['reward_value_decimal'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($reward_value_decimal === false || $reward_value_decimal <= 0) json_error('选择减免金额时，必须提供一个大于0的有效金额。', 400);
        $reward_value_decimal = number_format($reward_value_decimal, 2, '.', '');
    } elseif ($reward_type === 'SPECIFIC_PROMOTION') {
        $reward_promo_id = filter_var($data['reward_promo_id'] ?? null, FILTER_VALIDATE_INT);
        if ($reward_promo_id === false || $reward_promo_id <= 0) json_error('选择赠送活动时，必须选择一个有效的活动。', 400);
    }
    $params = [
        ':rule_name_zh' => $name_zh, ':rule_name_es' => $name_es, ':points_required' => $points,
        ':reward_type' => $reward_type, ':reward_value_decimal' => $reward_value_decimal,
        ':reward_promo_id' => $reward_promo_id, ':is_active' => $is_active
    ];
    if ($id) {
        $params[':id'] = $id;
        $sql = "UPDATE pos_point_redemption_rules SET rule_name_zh = :rule_name_zh, rule_name_es = :rule_name_es, points_required = :points_required, reward_type = :reward_type, reward_value_decimal = :reward_value_decimal, reward_promo_id = :reward_promo_id, is_active = :is_active WHERE id = :id AND deleted_at IS NULL";
        $pdo->prepare($sql)->execute($params);
        json_ok(['id' => $id], '兑换规则已成功更新！');
    } else {
        $sql = "INSERT INTO pos_point_redemption_rules (rule_name_zh, rule_name_es, points_required, reward_type, reward_value_decimal, reward_promo_id, is_active) VALUES (:rule_name_zh, :rule_name_es, :points_required, :reward_type, :reward_value_decimal, :reward_promo_id, :is_active)";
        $pdo->prepare($sql)->execute($params);
        json_ok(['id' => (int)$pdo->lastInsertId()], '新兑换规则已成功创建！');
    }
}
function handle_redemption_rule_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    // [A2.2 UTC FIX] 
    // pos_point_redemption_rules.deleted_at 是 timestamp(0)。必须使用 'Y-m-d H:i:s'
    // [GEMINI V3 FIX] 数据库为 datetime(6)，使用 .u
    $now_utc_str = utc_now()->format('Y-m-d H:i:s.u');
    $stmt = $pdo->prepare("UPDATE pos_point_redemption_rules SET deleted_at = ? WHERE id = ?");
    $stmt->execute([$now_utc_str, (int)$id]);
    json_ok(null, '兑换规则已成功删除。');
}