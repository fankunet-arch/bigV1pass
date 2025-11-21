<?php
/**
 * CPSYS Base Registry - Core Handlers
 * Extracted from cpsys_registry_base.php
 */
/**
 * 处理器: KDS SOP 解析规则 (kds_sop_query_rules)
 */
function handle_kds_rule_get_list(PDO $pdo, array $config, array $input_data): void {
    // 按门店优先、优先级排序
    $sql = "
        SELECT
            r.id, r.store_id, r.rule_name, r.priority, r.is_active, r.config_json,
            s.store_name
        FROM kds_sop_query_rules r
        LEFT JOIN kds_stores s ON r.store_id = s.id
        ORDER BY
            r.store_id IS NOT NULL DESC, -- 门店专属规则优先 (NULLs last)
            s.store_name ASC,
            r.priority ASC
    ";
    $stmt = $pdo->query($sql);
    json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function handle_kds_rule_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("SELECT * FROM kds_sop_query_rules WHERE id = ?");
    $stmt->execute([(int)$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($data) {
        // [V2] 自动转换 V1/V2 格式
        $data = convert_v1_config_to_v2_for_editing($data);
        json_ok($data);
    } else {
        json_error('未找到规则', 404);
    }
}

function handle_kds_rule_save(PDO $pdo, array $config, array $input_data): void {
    // V2: JS 直接提交了所有字段，不再需要 'data' 包装器
    $data = $input_data;
    
    $id = $data['id'] ? (int)$data['id'] : null;

    // 1. 基础字段
    $rule_name = trim($data['rule_name'] ?? '');
    $priority = (int)($data['priority'] ?? 100);
    $is_active = (int)($data['is_active'] ?? 0);
    $store_id = !empty($data['store_id']) ? (int)$data['store_id'] : null; // 0 或 '' 视为 NULL

    if (empty($rule_name)) {
        json_error('规则名称不能为空。', 400);
    }

    // 2. V2 模板配置
    // V2 JS 提交的是 config_json (字符串) 和 extractor_type (TEMPLATE_V2)
    $config_json_string = $data['config_json'] ?? null;
    $extractor_type = $data['extractor_type'] ?? 'TEMPLATE_V2'; // 默认为 V2
    
    // 校验 V2 JSON
    if (empty($config_json_string)) {
        json_error('配置 JSON 不能为空。', 400);
    }
    $config_decoded = json_decode($config_json_string, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
         json_error('配置 JSON 格式无效。', 400);
    }
    if (empty($config_decoded['template']) || empty($config_decoded['mapping'])) {
         json_error('V2 配置必须包含 "template" 和 "mapping"。', 400);
    }
    
    // 3. 准备 SQL 参数
    $params = [
        ':store_id' => $store_id,
        ':rule_name' => $rule_name,
        ':priority' => $priority,
        ':is_active' => $is_active,
        ':extractor_type' => $extractor_type, // 存储 "TEMPLATE_V2"
        ':config_json' => $config_json_string // 存储 V2 JSON 字符串
    ];

    if ($id) {
        $params[':id'] = $id;
        $sql = "UPDATE kds_sop_query_rules SET
                    store_id = :store_id, rule_name = :rule_name, priority = :priority,
                    is_active = :is_active, extractor_type = :extractor_type, config_json = :config_json
                WHERE id = :id";
        $message = 'SOP 解析规则已更新。';
    } else {
        $sql = "INSERT INTO kds_sop_query_rules
                    (store_id, rule_name, priority, is_active, extractor_type, config_json)
                VALUES
                    (:store_id, :rule_name, :priority, :is_active, :extractor_type, :config_json)";
        $message = 'SOP 解析规则已创建。';
    }

    $pdo->prepare($sql)->execute($params);
    json_ok(['id' => $id ?? $pdo->lastInsertId()], $message);
}

function handle_kds_rule_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $id = (int)$id;

    if ($id === 1) {
        json_error('删除失败：无法删除 ID 为 1 的 KDS 内部标准规则。', 403);
    }

    $stmt = $pdo->prepare("DELETE FROM kds_sop_query_rules WHERE id = ?");
    $stmt->execute([$id]);
    json_ok(null, 'SOP 解析规则已删除。');
}

// --- START: 缺失的处理器 (Handlers for missing resources) ---

// --- 处理器: HQ 用户 (users) ---
function handle_user_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $data = getUserById($pdo, (int)$id);
    $data ? json_ok($data) : json_error('未找到用户', 404);
}
function handle_user_save(PDO $pdo, array $config, array $input_data): void {
    // 兼容 {user:{...}} 或 {data:{...}}
    $u = $input_data['user'] ?? $input_data['data'] ?? json_error('缺少 data', 400);

    $id           = (int)($u['id'] ?? 0);
    $username     = trim((string)($u['username'] ?? ''));
    $display_name = trim((string)($u['display_name'] ?? ''));
    $email        = trim((string)($u['email'] ?? ''));
    $role_id      = (int)($u['role_id'] ?? 0);
    $is_active    = isset($u['is_active']) ? (int)!!$u['is_active'] : 1;

    // 新密码字段可能叫 new_password 或 password（兼容旧前端）
    $new_password = (string)($u['new_password'] ?? $u['password'] ?? '');

    // 基本校验
    if ($id <= 0) {
        if ($username === '' || $new_password === '' || $role_id <= 0) {
            json_error('新增用户：username / password / role 为必填。', 400);
        }
    } else {
        if ($username === '' || $role_id <= 0) {
            json_error('更新用户：username / role 为必填。', 400);
        }
    }

    // 规范化：空字符串转 NULL（避免严格模式写入错误）
    $email        = ($email === '') ? null : $email;
    $display_name = ($display_name === '') ? null : $display_name;

    $pdo->beginTransaction();
    try {
        // 唯一性（username）
        if ($id > 0) {
            $q = $pdo->prepare("SELECT id FROM cpsys_users WHERE username=? AND id<>? AND deleted_at IS NULL");
            $q->execute([$username, $id]);
            if ($q->fetchColumn()) { $pdo->rollBack(); json_error('用户名已存在：'.$username, 409); }
        } else {
            $q = $pdo->prepare("SELECT id FROM cpsys_users WHERE username=? AND deleted_at IS NULL");
            $q->execute([$username]);
            if ($q->fetchColumn()) { $pdo->rollBack(); json_error('用户名已存在：'.$username, 409); }
        }

        if ($id > 0) {
            // 更新：只有在给了新密码时才更新 password_hash
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_BCRYPT);
                $sql = "UPDATE cpsys_users
                           SET username=?, display_name=?, email=?, role_id=?, is_active=?, password_hash=?
                         WHERE id=?";
                $pdo->prepare($sql)->execute([
                    $username, $display_name, $email, $role_id, $is_active, $hash, $id
                ]);
            } else {
                $sql = "UPDATE cpsys_users
                           SET username=?, display_name=?, email=?, role_id=?, is_active=?
                         WHERE id=?";
                $pdo->prepare($sql)->execute([
                    $username, $display_name, $email, $role_id, $is_active, $id
                ]);
            }
            $pdo->commit();
            json_ok(['id'=>$id], '用户已更新。');
        } else {
            // 新增
            $hash = password_hash($new_password, PASSWORD_BCRYPT);
            $sql = "INSERT INTO cpsys_users (username, password_hash, email, display_name, is_active, role_id)
                    VALUES (?,?,?,?,?,?)";
            $pdo->prepare($sql)->execute([$username, $hash, $email, $display_name, $is_active, $role_id]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();
            json_ok(['id'=>$newId], '用户已创建。');
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('数据库错误（users）', 500, ['debug' => $e->getMessage()]);
    }
}

function handle_user_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("UPDATE cpsys_users SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, '用户已成功删除。');
}

// --- 处理器: 门店 (stores) ---
function handle_store_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    // [GEMINI V17.0 REFACTOR] getStoreById 已经存在于 kds_repo_a.php
    $data = getStoreById($pdo, (int)$id);
    $data ? json_ok($data) : json_error('未找到门店', 404);
}
function handle_store_save(PDO $pdo, array $config, array $input_data): void {
    $s = $input_data['store'] ?? $input_data['data'] ?? json_error('缺少 data', 400);

    $id         = (int)($s['id'] ?? 0);
    $store_code = trim((string)($s['store_code'] ?? ''));
    $store_name = trim((string)($s['store_name'] ?? ''));
    // [MODIFIED 1.3] 读取新字段
    $invoice_prefix = trim((string)($s['invoice_prefix'] ?? ''));

    // 基本校验
    if ($store_code === '' || $store_name === '') {
        json_error('门店编码与名称为必填。', 400);
    }
    // [MODIFIED 1.3] 添加新校验
    if ($invoice_prefix === '' || !preg_match('/^[A-Za-z0-9]+$/', $invoice_prefix)) {
        json_error('票号前缀为必填项，且只能包含字母和数字。', 400);
    }

    // 允许为空的字段 → NULL
    $tax_id       = ($s['tax_id'] ?? '') === '' ? null : trim((string)$s['tax_id']);
    $store_city   = ($s['store_city'] ?? '') === '' ? null : trim((string)$s['store_city']);
    $store_addr   = ($s['store_address'] ?? '') === '' ? null : (string)$s['store_address'];
    $store_phone  = ($s['store_phone'] ?? '') === '' ? null : trim((string)$s['store_phone']);
    $store_cif    = ($s['store_cif'] ?? '') === '' ? null : trim((string)$s['store_cif']);

    // NOT NULL 数字字段（给默认）
    $default_vat_rate = (string)($s['default_vat_rate'] ?? '') === '' ? 10.00 : (float)$s['default_vat_rate'];
    $eod_cutoff_hour  = (string)($s['eod_cutoff_hour'] ?? '') === '' ? 3 : (int)$s['eod_cutoff_hour'];

    // ENUM 规范化
    $billing_allowed = ['TICKETBAI','VERIFACTU','NONE'];
    $billing_system = strtoupper(trim((string)($s['billing_system'] ?? 'NONE')));
    if (!in_array($billing_system, $billing_allowed, true)) $billing_system = 'NONE';

    // [MODIFIED 1.3] 读取所有12个新打印机字段
    $printer_allowed = ['NONE','WIFI','BLUETOOTH','USB'];
    
    $pr_receipt_type = strtoupper(trim((string)($s['pr_receipt_type'] ?? 'NONE')));
    $pr_receipt_type = in_array($pr_receipt_type, $printer_allowed, true) ? $pr_receipt_type : 'NONE';
    $pr_receipt_ip   = ($pr_receipt_type === 'WIFI') ? ($s['pr_receipt_ip'] ?? null) : null;
    $pr_receipt_port = ($pr_receipt_type === 'WIFI') ? ($s['pr_receipt_port'] ?? null) : null;
    $pr_receipt_mac  = ($pr_receipt_type === 'BLUETOOTH') ? ($s['pr_receipt_mac'] ?? null) : null;
    
    $pr_sticker_type = strtoupper(trim((string)($s['pr_sticker_type'] ?? 'NONE')));
    $pr_sticker_type = in_array($pr_sticker_type, $printer_allowed, true) ? $pr_sticker_type : 'NONE';
    $pr_sticker_ip   = ($pr_sticker_type === 'WIFI') ? ($s['pr_sticker_ip'] ?? null) : null;
    $pr_sticker_port = ($pr_sticker_type === 'WIFI') ? ($s['pr_sticker_port'] ?? null) : null;
    $pr_sticker_mac  = ($pr_sticker_type === 'BLUETOOTH') ? ($s['pr_sticker_mac'] ?? null) : null;
    
    $pr_kds_type = strtoupper(trim((string)($s['pr_kds_type'] ?? 'NONE')));
    $pr_kds_type = in_array($pr_kds_type, $printer_allowed, true) ? $pr_kds_type : 'NONE';
    $pr_kds_ip   = ($pr_kds_type === 'WIFI') ? ($s['pr_kds_ip'] ?? null) : null;
    $pr_kds_port = ($pr_kds_type === 'WIFI') ? ($s['pr_kds_port'] ?? null) : null;
    $pr_kds_mac  = ($pr_kds_type === 'BLUETOOTH') ? ($s['pr_kds_mac'] ?? null) : null;

    $is_active = isset($s['is_active']) ? (int)!!$s['is_active'] : 1;

    $pdo->beginTransaction();
    try {
        // 唯一性（store_code）
        if ($id > 0) {
            $q = $pdo->prepare("SELECT id FROM kds_stores WHERE store_code=? AND id<>? AND deleted_at IS NULL");
            $q->execute([$store_code, $id]);
            if ($q->fetchColumn()) { $pdo->rollBack(); json_error('门店编码已存在：'.$store_code, 409); }
        } else {
            $q = $pdo->prepare("SELECT id FROM kds_stores WHERE store_code=? AND deleted_at IS NULL");
            $q->execute([$store_code]);
            if ($q->fetchColumn()) { $pdo->rollBack(); json_error('门店编码已存在：'.$store_code, 409); }
        }

        // [MODIFIED 1.3] 唯一性（invoice_prefix）
        if ($id > 0) {
            $q = $pdo->prepare("SELECT id FROM kds_stores WHERE invoice_prefix=? AND id<>? AND deleted_at IS NULL");
            $q->execute([$invoice_prefix, $id]);
            if ($q->fetchColumn()) { $pdo->rollBack(); json_error('票号前缀已存在：'.$invoice_prefix, 409); }
        } else {
            $q = $pdo->prepare("SELECT id FROM kds_stores WHERE invoice_prefix=? AND deleted_at IS NULL");
            $q->execute([$invoice_prefix]);
            if ($q->fetchColumn()) { $pdo->rollBack(); json_error('票号前缀已存在：'.$invoice_prefix, 409); }
        }

        if ($id > 0) {
            // [MODIFIED 1.3] 更新 UPDATE 语句
            $sql = "UPDATE kds_stores
                       SET store_code=?, store_name=?, invoice_prefix=?, tax_id=?, default_vat_rate=?,
                           store_city=?, store_address=?, store_phone=?, store_cif=?, is_active=?,
                           billing_system=?, eod_cutoff_hour=?, 
                           pr_receipt_type=?, pr_receipt_ip=?, pr_receipt_port=?, pr_receipt_mac=?,
                           pr_sticker_type=?, pr_sticker_ip=?, pr_sticker_port=?, pr_sticker_mac=?,
                           pr_kds_type=?, pr_kds_ip=?, pr_kds_port=?, pr_kds_mac=?
                     WHERE id=?";
            $pdo->prepare($sql)->execute([
                $store_code, $store_name, $invoice_prefix, $tax_id, $default_vat_rate,
                $store_city, $store_addr, $store_phone, $store_cif, $is_active,
                $billing_system, $eod_cutoff_hour,
                $pr_receipt_type, $pr_receipt_ip, $pr_receipt_port, $pr_receipt_mac,
                $pr_sticker_type, $pr_sticker_ip, $pr_sticker_port, $pr_sticker_mac,
                $pr_kds_type, $pr_kds_ip, $pr_kds_port, $pr_kds_mac,
                $id
            ]);
            $pdo->commit();
            json_ok(['id'=>$id], '门店已更新。');
        } else {
            // [MODIFIED 1.3] 更新 INSERT 语句
            $sql = "INSERT INTO kds_stores
                        (store_code, store_name, invoice_prefix, tax_id, default_vat_rate,
                         store_city, store_address, store_phone, store_cif, is_active,
                         billing_system, eod_cutoff_hour, 
                         pr_receipt_type, pr_receipt_ip, pr_receipt_port, pr_receipt_mac,
                         pr_sticker_type, pr_sticker_ip, pr_sticker_port, pr_sticker_mac,
                         pr_kds_type, pr_kds_ip, pr_kds_port, pr_kds_mac)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $pdo->prepare($sql)->execute([
                $store_code, $store_name, $invoice_prefix, $tax_id, $default_vat_rate,
                $store_city, $store_addr, $store_phone, $store_cif, $is_active,
                $billing_system, $eod_cutoff_hour,
                $pr_receipt_type, $pr_receipt_ip, $pr_receipt_port, $pr_receipt_mac,
                $pr_sticker_type, $pr_sticker_ip, $pr_sticker_port, $pr_sticker_mac,
                $pr_kds_type, $pr_kds_ip, $pr_kds_port, $pr_kds_mac
            ]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();
            json_ok(['id'=>$newId], '门店已创建。');
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        // [MODIFIED 1.3] 捕获唯一键冲突
        if ($e instanceof PDOException && $e->errorInfo[1] == 1062) {
             if (strpos($e->getMessage(), 'uniq_invoice_prefix') !== false) {
                 json_error('票号前缀 "' . htmlspecialchars($invoice_prefix) . '" 已被占用。', 409);
             }
             if (strpos($e->getMessage(), 'store_code') !== false) {
                 json_error('门店编码 "' . htmlspecialchars($store_code) . '" 已被占用。', 409);
             }
        }
        json_error('数据库错误（stores）', 500, ['debug' => $e->getMessage()]);
    }
}

function handle_store_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("UPDATE kds_stores SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, '门店已成功删除。');
}

// --- 处理器: KDS 用户 (kds_users) ---
function handle_kds_user_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $data = getKdsUserById($pdo, (int)$id);
    $data ? json_ok($data) : json_error('未找到KDS用户', 404);
}
function handle_kds_user_save(PDO $pdo, array $config, array $input_data): void {
    // 前端发的是 { data: {...} }
    $data = $input_data['data'] ?? json_error('缺少 data', 400);

    $id         = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : 0;
    $store_id   = (int)($data['store_id'] ?? 0);
    $username   = trim((string)($data['username'] ?? ''));
    $display    = trim((string)($data['display_name'] ?? ''));
    $is_active  = (int)($data['is_active'] ?? 0);
    $password   = (string)($data['password'] ?? '');

    if ($store_id <= 0 || $username === '') {
        json_error('用户名和门店ID不能为空。', 400);
    }
    if ($display === '') {
        json_error('显示名称为必填项。', 400);
    }

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            // 更新：仅当传了新密码才更新 password_hash
            $params = [
                ':store_id'     => $store_id,
                ':id'           => $id,
                ':display_name' => $display,
                ':is_active'    => $is_active,
            ];

            if ($password !== '') {
                // [SECURITY FIX 2025-11-17] Use secure Bcrypt hashing instead of SHA256
                $params[':password_hash'] = password_hash($password, PASSWORD_BCRYPT);
                $sql = "UPDATE kds_users
                           SET display_name = :display_name,
                               is_active    = :is_active,
                               password_hash = :password_hash
                         WHERE id = :id AND store_id = :store_id";
            } else {
                $sql = "UPDATE kds_users
                           SET display_name = :display_name,
                               is_active    = :is_active
                         WHERE id = :id AND store_id = :store_id";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $pdo->commit();
            json_ok(['id' => $id], 'KDS用户已成功更新！');
        } else {
            // 新增：需要校验同门店下的用户名唯一，并且必须提供密码
            if ($password === '') {
                $pdo->rollBack();
                json_error('创建新用户时必须设置密码。', 400);
            }

            $chk = $pdo->prepare("SELECT id FROM kds_users
                                   WHERE username = ? AND store_id = ? AND deleted_at IS NULL
                                   LIMIT 1");
            $chk->execute([$username, $store_id]);
            if ($chk->fetchColumn()) {
                $pdo->rollBack();
                json_error('用户名 \"' . htmlspecialchars($username) . '\" 在此门店已被使用。', 409);
            }

            $params = [
                ':store_id'     => $store_id,
                ':username'     => $username,
                ':display_name' => $display,
                ':is_active'    => $is_active,
                // [SECURITY FIX 2025-11-17] Use secure Bcrypt hashing instead of SHA256
                ':password_hash'=> password_hash($password, PASSWORD_BCRYPT),
            ];

            $sql = "INSERT INTO kds_users
                        (store_id, username, display_name, is_active, password_hash)
                    VALUES (:store_id, :username, :display_name, :is_active, :password_hash)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();
            json_ok(['id' => $newId], '新KDS用户已成功创建！');
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('数据库错误（kds_users）', 500, ['debug' => $e->getMessage()]);
    }
}

function handle_kds_user_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("UPDATE kds_users SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, 'KDS用户已成功删除。');
}

// --- 处理器: 个人资料 (profile) ---
function handle_profile_save(PDO $pdo, array $config, array $input_data): void {
    @session_start();
    $user_id = $_SESSION['user_id'] ?? 0;
    if ($user_id <= 0) json_error('会话无效或已过期，请重新登录。', 401);

    $display_name = trim($input_data['display_name'] ?? '');
    $email = trim($input_data['email'] ?? null);
    $current_password = $input_data['current_password'] ?? '';
    $new_password = $input_data['new_password'] ?? '';

    if (empty($display_name)) json_error('显示名称不能为空。', 400);

    // 检查是否需要验证当前密码
    $user = getUserById($pdo, $user_id);
    if ($user['email'] !== $email || !empty($new_password)) {
        if (empty($current_password)) json_error('修改邮箱或密码时，必须提供当前密码。', 403);
        
        // [GEMINI SECURITY FIX V1.0] Verify against the DB hash using password_verify()
        $stmt_check = $pdo->prepare("SELECT password_hash FROM cpsys_users WHERE id = ?");
        $stmt_check->execute([$user_id]);
        $current_hash_db = $stmt_check->fetchColumn();

        if (!$current_hash_db || !password_verify($current_password, $current_hash_db)) {
            json_error('当前密码不正确。', 403);
        }
    }

    $params = [':display_name' => $display_name, ':email' => $email, ':id' => $user_id];
    $password_sql = "";
    if (!empty($new_password)) {
        // [GEMINI SECURITY FIX V1.0] Store new password using Bcrypt
        $params[':password_hash'] = password_hash($new_password, PASSWORD_BCRYPT);
        $password_sql = ", password_hash = :password_hash";
    }

    $sql = "UPDATE cpsys_users SET display_name = :display_name, email = :email {$password_sql} WHERE id = :id";
    $pdo->prepare($sql)->execute($params);

    // 更新会话
    $_SESSION['display_name'] = $display_name;
    json_ok(null, '个人资料已成功更新！');
}

// --- 处理器: 打印模板 (print_templates) ---
function handle_template_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("SELECT * FROM pos_print_templates WHERE id = ?");
    $stmt->execute([(int)$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $data ? json_ok($data) : json_error('未找到模板', 404);
}
function handle_template_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $params = [
        ':template_name' => trim($data['template_name'] ?? ''),
        ':template_type' => $data['template_type'] ?? null,
        ':physical_size' => $data['physical_size'] ?? null,
        ':template_content' => $data['template_content'] ?? '[]',
        ':is_active' => (int)($data['is_active'] ?? 0),
        ':store_id' => null // 暂时只支持全局
    ];
    if (empty($params[':template_name']) || empty($params[':template_type']) || empty($params[':physical_size'])) json_error('模板名称、类型和物理尺寸为必填项。', 400);
    if ($id) {
        $params[':id'] = $id;
        $sql = "UPDATE pos_print_templates SET store_id = :store_id, template_name = :template_name, template_type = :template_type, template_content = :template_content, physical_size = :physical_size, is_active = :is_active WHERE id = :id";
        $message = '模板已成功更新。';
    } else {
        $sql = "INSERT INTO pos_print_templates (store_id, template_name, template_type, template_content, physical_size, is_active) VALUES (:store_id, :template_name, :template_type, :template_content, :physical_size, :is_active)";
        $message = '新模板已成功创建。';
    }
    $pdo->prepare($sql)->execute($params);
    json_ok(['id' => $id ?? $pdo->lastInsertId()], $message);
}
function handle_template_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("DELETE FROM pos_print_templates WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, '模板已删除。');
}
