<?php
// pos_helper.php
// 核心助手函数

/**
 * 确保当前有一个活动的班次，否则抛出错误。
 * 这是所有销售和资金操作的保护锁。
 */
function ensure_active_shift_or_fail(PDO $pdo): int {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $shift_id = (int)($_SESSION['pos_shift_id'] ?? 0);
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    $user_id = (int)($_SESSION['pos_user_id'] ?? 0);

    // [FIX 2025-11-20] 启用数据库验证，不再仅信任 session
    if ($shift_id > 0 && $store_id > 0 && $user_id > 0) {
        // 验证 shift_id 是否在数据库中且未关闭
        $stmt = $pdo->prepare("SELECT 1 FROM pos_shifts WHERE id = ? AND store_id = ? AND user_id = ? AND status = 'ACTIVE' AND end_time IS NULL");
        $stmt->execute([$shift_id, $store_id, $user_id]);
        if ($stmt->fetchColumn()) {
            // 班次有效，返回
            return $shift_id;
        }

        // [FIX 2025-11-20] 如果数据库中班次无效，清除 session
        error_log("[SHIFT_GUARD] Session shift_id={$shift_id} is invalid or ended, clearing session");
        unset($_SESSION['pos_shift_id']);
    }

    // 如果 session 中没有，或数据库验证失败，触发班次保护
    json_error('No active shift found. Please start a shift.', 403, ['error_code' => 'NO_ACTIVE_SHIFT']);
    exit;
}


/**
 * [GEMINI FIX 2025-11-16]
 * 添加 pos_registry_member_pass.php 所需的缺失函数 gen_uuid_v4。
 * 这是导致“创建会员”失败的根本原因。
 *
 * 生成一个符合 RFC 4122 标准的 Version 4 UUID。
 *
 * @return string 格式为 "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx" 的 UUID。
 * @throws Exception 如果无法收集到足够的加密随机数据。
 */
function gen_uuid_v4(): string {
    // 1. 生成 16 字节 (128 位) 的随机数据
    $data = random_bytes(16);

    // 2. 设置 UUID 版本 (Version 4)
    // 字节 6 (索引 6) 的高 4 位必须是 0100 (binary)
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);

    // 3. 设置 UUID 变体 (Variant 10xx)
    // 字节 8 (索引 8) 的高 2 位必须是 10 (binary)
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    // 4. 将 16 字节的二进制数据格式化为 36 个字符的十六进制字符串
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}