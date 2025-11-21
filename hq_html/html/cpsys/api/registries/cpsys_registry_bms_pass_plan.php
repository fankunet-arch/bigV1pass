<?php
/**
 * CPSYS BMS Registry - Pass Plan Handlers (P)
 * [ 修复 ] 补全缺失的 'pos_pass_plans' 资源处理器
 * - 修复了 handle_pass_plan_save 函数在 (INSERT) 
 * 分支中 $plan_params 数组缺少 :auto_activate 参数的问题。
 * - 修复了 sync_tags 函数中的逻辑 Bug，
 * 该 Bug 会导致所有关联项被删除，而不是仅删除当前方案的关联项。
 */

/**
 * 内部助手：根据标签代码和 ID 列表，同步标签表
 * [GEMINI LOGIC BUG FIX v1.1]
 * 修复了此函数会删除 *所有* 关联此标签（如 pass_eligible_beverage）
 * 的项目，而不是仅删除此方案关联的项目。
 *
 * @param PDO $pdo
 * @param string $map_table (e.g., pos_product_tag_map)
 * @param string $pk_column (e.g., product_id)
 * @param string $tag_column (e.g., tag_id)
 * @param int $tag_id (e.g., 2)
 * @param array $item_ids (e.g., [1, 2, 5])
 * @return void
 */
function sync_tags(PDO $pdo, string $map_table, string $pk_column, string $tag_column, $tag_id, array $item_ids) {
    if (!$tag_id) return; // 如果关键标签不存在，则跳过

    // 1. [FIX] 仅删除此标签在此 ID 列表 *之外* 的旧关联 (如果它们之前被错误关联了)
    // 并且仅删除此标签的
    $placeholders = !empty($item_ids) ? implode(',', array_fill(0, count($item_ids), '?')) : 'NULL';
    
    // 删除不再列表中的
    $sql_del = "DELETE FROM {$map_table} 
                WHERE {$tag_column} = ? 
                AND {$pk_column} NOT IN ({$placeholders})";
                
    $del_params = array_merge([$tag_id], $item_ids);
    $pdo->prepare($sql_del)->execute($del_params);

    // 2. 插入新关联 (使用 INSERT IGNORE 避免重复)
    if (!empty($item_ids)) {
        $sql_ins = "INSERT IGNORE INTO {$map_table} ({$pk_column}, {$tag_column}) VALUES (?, ?)";
        $stmt_ins = $pdo->prepare($sql_ins);
        foreach ($item_ids as $item_id) {
            if (filter_var($item_id, FILTER_VALIDATE_INT)) {
                $stmt_ins->execute([$item_id, $tag_id]);
            }
        }
    }
}


/**
 * 处理器: [P] 获取单个次卡方案的完整配置
 */
function handle_pass_plan_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $plan_id = (int)$id;

    $response = [ 'plan' => null, 'pos_item' => null, 'pos_variant' => null, 'rules' => [] ];

    // 1. 获取方案详情
    $stmt_plan = $pdo->prepare("SELECT * FROM pass_plans WHERE pass_plan_id = ?");
    $stmt_plan->execute([$plan_id]);
    $response['plan'] = $stmt_plan->fetch(PDO::FETCH_ASSOC);
    if (!$response['plan']) {
        json_error('未找到次卡方案', 404);
    }

    // [修复] 从 pass_plans 表中获取 sale_sku
    $sale_sku = $response['plan']['sale_sku'] ?? null;
    if (!$sale_sku) {
         json_ok($response, '方案已加载（但未关联销售SKU）');
         return;
    }

    // 2. 获取关联的 POS 售卖商品 (使用 product_code 关联)
    $stmt_item = $pdo->prepare("SELECT * FROM pos_menu_items WHERE product_code = ? AND deleted_at IS NULL LIMIT 1");
    $stmt_item->execute([$sale_sku]);
    $response['pos_item'] = $stmt_item->fetch(PDO::FETCH_ASSOC);
    $menu_item_id = $response['pos_item']['id'] ?? null;

    // 3. 获取关联的 POS 价格
    if ($menu_item_id) {
        $stmt_var = $pdo->prepare("SELECT * FROM pos_item_variants WHERE menu_item_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt_var->execute([$menu_item_id]);
        $response['pos_variant'] = $stmt_var->fetch(PDO::FETCH_ASSOC);
    }

    // 4. 获取核销规则 (标签)
    $response['rules'] = [
        'eligible_beverage_ids' => [],
        'free_addon_ids' => [],
        'paid_addon_ids' => []
    ];

    // 4.1 可核销饮品 (pos_product_tag_map)
    $stmt_bev = $pdo->prepare("SELECT ptm.product_id FROM pos_product_tag_map ptm JOIN pos_tags t ON ptm.tag_id = t.tag_id WHERE t.tag_code = 'pass_eligible_beverage'");
    $stmt_bev->execute();
    $response['rules']['eligible_beverage_ids'] = array_map('intval', $stmt_bev->fetchAll(PDO::FETCH_COLUMN));

    // 4.2 免费加料 (pos_addon_tag_map)
    $stmt_free = $pdo->prepare("SELECT atm.addon_id FROM pos_addon_tag_map atm JOIN pos_tags t ON atm.tag_id = t.tag_id WHERE t.tag_code = 'free_addon'");
    $stmt_free->execute();
    $response['rules']['free_addon_ids'] = array_map('intval', $stmt_free->fetchAll(PDO::FETCH_COLUMN));

    // 4.3 付费加料 (pos_addon_tag_map)
    $stmt_paid = $pdo->prepare("SELECT atm.addon_id FROM pos_addon_tag_map atm JOIN pos_tags t ON atm.tag_id = t.tag_id WHERE t.tag_code = 'paid_addon'");
    $stmt_paid->execute();
    $response['rules']['paid_addon_ids'] = array_map('intval', $stmt_paid->fetchAll(PDO::FETCH_COLUMN));

    json_ok($response);
}

/**
 * 处理器: [P] 保存次卡方案 (复杂事务)
 */
function handle_pass_plan_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data 包装器', 400);

    $plan_details = $data['plan_details'] ?? null;
    $sale_settings = $data['sale_settings'] ?? null;
    $rules = $data['rules'] ?? null;

    if (!$plan_details || !$sale_settings || !$rules) {
        json_error('请求数据不完整 (缺少 plan_details, sale_settings 或 rules)', 400);
    }

    $plan_id = !empty($plan_details['id']) ? (int)$plan_details['id'] : null;
    $sale_sku = trim((string)($sale_settings['sku'] ?? ''));
    
    // [修改] 读取双语名称
    $name_zh = trim($plan_details['name_zh'] ?? '');
    $name_es = trim($plan_details['name_es'] ?? $name_zh); // 如果西语为空，回退到中文

    if (empty($sale_sku)) {
        json_error('售卖 SKU (P-Code) 不能为空。', 400);
    }
    if (empty($name_zh)) {
        json_error('方案名称 (ZH) 不能为空。', 400);
    }

    // [需求3] 校验：禁止将"优惠卡"商品作为可核销饮品（防止卡买卡）
    $eligible_beverage_ids = $rules['eligible_beverage_ids'] ?? [];
    if (!empty($eligible_beverage_ids)) {
        // 查询这些商品ID中是否包含"优惠卡"商品（具有 pass_product 标签）
        $placeholders = implode(',', array_fill(0, count($eligible_beverage_ids), '?'));
        $sql_check_pass = "
            SELECT pmi.name_zh
            FROM pos_menu_items pmi
            JOIN pos_product_tag_map ptm ON pmi.id = ptm.product_id
            JOIN pos_tags pt ON ptm.tag_id = pt.tag_id
            WHERE pmi.id IN ($placeholders)
            AND pt.tag_code = 'pass_product'
            AND pmi.deleted_at IS NULL
            LIMIT 1
        ";
        $stmt_check_pass = $pdo->prepare($sql_check_pass);
        $stmt_check_pass->execute($eligible_beverage_ids);
        $pass_product_name = $stmt_check_pass->fetchColumn();

        if ($pass_product_name !== false) {
            json_error('操作被禁止：不能将"优惠卡"商品（如：' . htmlspecialchars($pass_product_name) . '）设置为可核销商品。优惠卡只能用于核销普通商品，不能用于购买其他优惠卡。', 400);
        }
    }

    $pdo->beginTransaction();
    try {
        // --- 1. 保存 pass_plans (方案详情) ---
        $plan_params = [
            ':name' => $name_zh, // pass_plans.name 存储中文名
            ':total_uses' => $plan_details['total_uses'],
            ':validity_days' => $plan_details['validity_days'],
            ':max_uses_per_order' => $plan_details['max_uses_per_order'],
            ':max_uses_per_day' => $plan_details['max_uses_per_day'],
            ':is_active' => $plan_details['is_active'],
            ':auto_activate' => $plan_details['auto_activate'] ?? 0, // [GEMINI HY093 FIX]
            ':sale_sku' => $sale_sku
        ];

        if ($plan_id) {
            // 更新
            $plan_params[':id'] = $plan_id;
            $sql_plan = "UPDATE pass_plans SET 
                            name = :name, total_uses = :total_uses, validity_days = :validity_days, 
                            max_uses_per_order = :max_uses_per_order, max_uses_per_day = :max_uses_per_day,
                            is_active = :is_active, auto_activate = :auto_activate, sale_sku = :sale_sku
                         WHERE pass_plan_id = :id";
        } else {
            // 新增
            $sql_plan = "INSERT INTO pass_plans 
                            (name, total_uses, validity_days, max_uses_per_order, max_uses_per_day, is_active, auto_activate, sale_sku) 
                         VALUES 
                            (:name, :total_uses, :validity_days, :max_uses_per_order, :max_uses_per_day, :is_active, :auto_activate, :sale_sku)";
        }
        $pdo->prepare($sql_plan)->execute($plan_params);
        if (!$plan_id) {
            $plan_id = (int)$pdo->lastInsertId();
        }

        // --- 2. 保存 pos_menu_items (售卖商品) ---
        $stmt_find_item = $pdo->prepare("SELECT id FROM pos_menu_items WHERE product_code = ? AND deleted_at IS NULL");
        $stmt_find_item->execute([$sale_sku]);
        $menu_item_id = $stmt_find_item->fetchColumn();

        $item_params = [
            ':product_code' => $sale_sku,
            ':name_zh' => $name_zh, // [修改] 同步中文名称
            ':name_es' => $name_es, // [修改] 同步西班牙语名称
            ':pos_category_id' => $sale_settings['category_id'],
            ':is_active' => $plan_details['is_active'], // 同步状态
        ];

        if ($menu_item_id) {
            $item_params[':id'] = $menu_item_id;
            $sql_item = "UPDATE pos_menu_items SET 
                            name_zh = :name_zh, name_es = :name_es, pos_category_id = :pos_category_id, is_active = :is_active, product_code = :product_code
                         WHERE id = :id";
        } else {
            $sql_item = "INSERT INTO pos_menu_items 
                            (product_code, name_zh, name_es, pos_category_id, is_active, sort_order) 
                         VALUES 
                            (:product_code, :name_zh, :name_es, :pos_category_id, :is_active, 999)";
        }
        $pdo->prepare($sql_item)->execute($item_params);
        if (!$menu_item_id) {
            $menu_item_id = (int)$pdo->lastInsertId();
        }

        // 2.1 确保售卖商品被打上 'pass_product' 标签
        $tag_id_pass_product = $sale_settings['tag_id_pass_product'] ?? null;
        if ($tag_id_pass_product) {
            $stmt_tag = $pdo->prepare("INSERT INTO pos_product_tag_map (product_id, tag_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE product_id = product_id");
            $stmt_tag->execute([$menu_item_id, $tag_id_pass_product]);
        }

        // --- 3. 保存 pos_item_variants (价格) ---
        $stmt_find_var = $pdo->prepare("SELECT id FROM pos_item_variants WHERE menu_item_id = ? AND deleted_at IS NULL");
        $stmt_find_var->execute([$menu_item_id]);
        $variant_id = $stmt_find_var->fetchColumn();

        if ($variant_id) {
            // 更新现有规格
            $var_params = [
                ':price_eur' => $sale_settings['price'],
                ':name_zh' => '次卡',
                ':name_es' => 'Pase',
                ':id' => $variant_id
            ];
            $sql_var = "UPDATE pos_item_variants SET price_eur = :price_eur, variant_name_zh = :name_zh, variant_name_es = :name_es WHERE id = :id";
        } else {
            // 创建新规格
            $var_params = [
                ':menu_item_id' => $menu_item_id,
                ':price_eur' => $sale_settings['price'],
                ':name_zh' => '次卡',
                ':name_es' => 'Pase'
            ];
            $sql_var = "INSERT INTO pos_item_variants (menu_item_id, price_eur, variant_name_zh, variant_name_es, is_default, sort_order) VALUES (:menu_item_id, :price_eur, :name_zh, :name_es, 1, 1)";
        }
        $pdo->prepare($sql_var)->execute($var_params);

        // --- 4. 同步核销规则 (标签) ---

        // 4.1 可核销饮品 (pk_column = product_id)
        sync_tags($pdo, 'pos_product_tag_map', 'product_id', 'tag_id', $rules['tag_id_eligible_bev'] ?? null, $rules['eligible_beverage_ids'] ?? []);

        // 4.2 免费加料 (pk_column = addon_id)
        sync_tags($pdo, 'pos_addon_tag_map', 'addon_id', 'tag_id', $rules['tag_id_free_addon'] ?? null, $rules['free_addon_ids'] ?? []);

        // 4.3 付费加料 (pk_column = addon_id)
        sync_tags($pdo, 'pos_addon_tag_map', 'addon_id', 'tag_id', $rules['tag_id_paid_addon'] ?? null, $rules['paid_addon_ids'] ?? []);

        $pdo->commit();
        json_ok(['id' => $plan_id], '次卡方案已成功保存。');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('保存失败: ' . $e->getMessage(), 500);
    }
}

/**
 * 处理器: [P] 删除次卡方案 (软删除方案，硬删除关联商品)
 */
function handle_pass_plan_delete(PDO $pdo, array $config, array $input_data): void {
    $plan_id = $input_data['id'] ?? json_error('缺少 id', 400);
    $plan_id = (int)$plan_id;

    $pdo->beginTransaction();
    try {
        // 1. 查找关联的 SKU
        $stmt_find = $pdo->prepare("SELECT sale_sku FROM pass_plans WHERE pass_plan_id = ?");
        $stmt_find->execute([$plan_id]);
        $sale_sku = $stmt_find->fetchColumn();

        // 2. 软删除 pass_plan (设置为 inactive, 并添加 deleted_at)
        // [修复] 检查 pass_plans 表结构，它没有 deleted_at，所以我们只设置 is_active = 0
        $stmt_del_plan = $pdo->prepare("UPDATE pass_plans SET is_active = 0 WHERE pass_plan_id = ?");
        $stmt_del_plan->execute([$plan_id]);

        // 3. 软删除关联的 pos_menu_item (如果存在)
        if ($sale_sku) {
            $stmt_find_item = $pdo->prepare("SELECT id FROM pos_menu_items WHERE product_code = ? AND deleted_at IS NULL");
            $stmt_find_item->execute([$sale_sku]);
            $menu_item_id = $stmt_find_item->fetchColumn();

            if ($menu_item_id) {
                // [GEMINI V3 FIX] 使用 .u 匹配 datetime(6)
                $now_utc_str = utc_now()->format('Y-m-d H:i:s.u'); 

                // 软删除 variant
                $stmt_del_var = $pdo->prepare("UPDATE pos_item_variants SET deleted_at = ? WHERE menu_item_id = ?");
                $stmt_del_var->execute([$now_utc_str, $menu_item_id]);

                // 软删除 menu item
                $stmt_del_item = $pdo->prepare("UPDATE pos_menu_items SET deleted_at = ? WHERE id = ?");
                $stmt_del_item->execute([$now_utc_str, $menu_item_id]);
            }
        }

        $pdo->commit();
        json_ok(null, '次卡方案已成功删除（下架）。');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // 检查是否为 FK 约束失败 (例如：该方案已被购买)
        if ($e instanceof PDOException && $e->errorInfo[1] == 1451) {
             json_error('删除失败：此方案已被会员购买，无法删除。请将其设为“下架”。', 409);
        }
        json_error('删除时发生数据库错误: ' . $e->getMessage(), 500);
    }
}