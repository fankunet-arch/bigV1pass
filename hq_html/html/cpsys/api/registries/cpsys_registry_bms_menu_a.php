<?php
/**
 * CPSYS BMS Registry - Menu & POS Handlers
 * Extracted from cpsys_registry_bms.php
 */
// --- 这里是分类/菜单/规格/加料/标签 ---
// --- 处理器: POS 分类 (pos_categories) ---
function handle_pos_category_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("SELECT * FROM pos_categories WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([(int)$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $data ? json_ok($data) : json_error('未找到分类', 404);
}

function handle_pos_category_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $code = trim($data['category_code'] ?? '');
    $name_zh = trim($data['name_zh'] ?? '');
    $name_es = trim($data['name_es'] ?? '');
    $sort = (int)($data['sort_order'] ?? 99);
    if (empty($code) || empty($name_zh) || empty($name_es)) json_error('分类编码和双语名称均为必填项。', 400);
    
    // [R2] 审计
    $data_before = null;
    $action_name = 'rms.category.create';
    if ($id) {
        if (!function_exists('getPosCategoryById')) json_error('审计失败: 缺少 getPosCategoryById 助手。', 500);
        $data_before = getPosCategoryById($pdo, $id);
        $action_name = 'rms.category.update';
    }

    // --- [GEMINI REPAIR START] ---
    // 1. 检查是否存在 *活动* 记录 (用于 409 冲突)
    $sql_check_active = "SELECT id FROM pos_categories WHERE category_code = ? AND deleted_at IS NULL" . ($id ? " AND id != ?" : "");
    $params_check_active = $id ? [$code, $id] : [$code];
    $stmt_check_active = $pdo->prepare($sql_check_active);
    $stmt_check_active->execute($params_check_active);
    if ($stmt_check_active->fetch()) {
        json_error('分类编码 "' . htmlspecialchars($code) . '" 已被使用。', 409);
    }

    if ($id) {
        // 更新现有记录 (ID 已知)
        $stmt = $pdo->prepare("UPDATE pos_categories SET category_code = ?, name_zh = ?, name_es = ?, sort_order = ? WHERE id = ?");
        $stmt->execute([$code, $name_zh, $name_es, $sort, $id]);
        $message = '分类已成功更新！';
    } else {
        // 2. 检查是否存在 *已删除* 记录 (用于恢复)
        $stmt_check_deleted = $pdo->prepare("SELECT id FROM pos_categories WHERE category_code = ? AND deleted_at IS NOT NULL");
        $stmt_check_deleted->execute([$code]);
        $deleted_id = $stmt_check_deleted->fetchColumn();

        if ($deleted_id) {
            // 3. 恢复 (Update) 软删除的记录
            $id = (int)$deleted_id; // 获取要恢复的 ID
            $stmt = $pdo->prepare("UPDATE pos_categories SET name_zh = ?, name_es = ?, sort_order = ?, deleted_at = NULL WHERE id = ?");
            $stmt->execute([$name_zh, $name_es, $sort, $id]);
            $message = '分类已成功恢复！';
            $action_name = 'rms.category.restore'; // [R2] 审计动词
        } else {
            // 4. 插入 (Insert) 全新记录
            $stmt = $pdo->prepare("INSERT INTO pos_categories (category_code, name_zh, name_es, sort_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([$code, $name_zh, $name_es, $sort]);
            $id = (int)$pdo->lastInsertId();
            $message = '新分类已成功创建！';
            // $action_name 默认为 'rms.category.create'
        }
    }
    // --- [GEMINI REPAIR END] ---

    // [R2] 审计：写入日志
    if (!function_exists('getPosCategoryById')) json_error('审计失败: 缺少 getPosCategoryById 助手。', 500);
    $data_after = getPosCategoryById($pdo, $id);
    log_audit_action($pdo, $action_name, 'pos_categories', $id, $data_before, $data_after);

    json_ok(['id' => $id], $message);
}

function handle_pos_category_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $id = (int)$id;

    // [R2] 审计：获取变更前数据
    if (!function_exists('getPosCategoryById')) json_error('审计失败: 缺少 getPosCategoryById 助手。', 500);
    $data_before = getPosCategoryById($pdo, $id);
    if (!$data_before) {
        json_error('未找到要删除的分类。', 404);
    }

    // [A2.2 UTC FIX] 
    // pos_categories.deleted_at 是 timestamp(0)。必须使用 'Y-m-d H:i:s'
    // [GEMINI V3 FIX] 数据库为 datetime(6)，使用 .u
    $now_utc_str = utc_now()->format('Y-m-d H:i:s.u');
    $stmt = $pdo->prepare("UPDATE pos_categories SET deleted_at = ? WHERE id = ?");
    $stmt->execute([$now_utc_str, $id]);

    // [R2] 审计：写入日志
    log_audit_action($pdo, 'rms.category.delete', 'pos_categories', $id, $data_before, null);

    json_ok(null, '分类已成功删除。');
}

/**
 * [内部助手] 检查一个 menu_item_id 或 product_code 是否被次卡系统锁定
 */
function is_pass_product_locked(PDO $pdo, ?int $menu_item_id, ?string $product_code): bool {
    if ($menu_item_id === null && $product_code === null) {
        return false;
    }
    
    $sql = "SELECT pp.pass_plan_id 
            FROM pass_plans pp
            JOIN pos_menu_items mi ON pp.sale_sku = mi.product_code
            WHERE pp.is_active = 1 AND mi.deleted_at IS NULL";
    
    $params = [];
    if ($menu_item_id !== null) {
        $sql .= " AND mi.id = ?";
        $params[] = $menu_item_id;
    } else {
        $sql .= " AND mi.product_code = ?";
        $params[] = $product_code;
    }
    
    $stmt = $pdo->prepare($sql . " LIMIT 1");
    $stmt->execute($params);
    
    // 如果能查到记录，说明它是一个激活的次卡售卖商品，应被锁定
    return $stmt->fetchColumn() !== false;
}


// --- 处理器: POS 菜单商品 (pos_menu_items) ---
function handle_menu_item_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    
    // [R2] 审计依赖
    if (!function_exists('getPosMenuItemById')) json_error('审计失败: 缺少 getPosMenuItemById 助手。', 500);
    $data = getPosMenuItemById($pdo, (int)$id);
    
    $data ? json_ok($data) : json_error('未找到商品', 404);
}
function handle_menu_item_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    
    // [冲突解决] 检查此商品是否为“次卡商品”
    if ($id && is_pass_product_locked($pdo, $id, null)) {
        json_error(
            '操作被禁止：此商品是一个激活的“次卡售卖商品”。请前往 [POS 管理] -> [会员次卡] -> [次卡方案管理] 页面进行修改，以确保数据一致性。',
            403 // 403 Forbidden
        );
    }

    $params = [
        ':name_zh' => trim($data['name_zh']),
        ':name_es' => trim($data['name_es']),
        ':pos_category_id' => (int)$data['pos_category_id'],
        ':description_zh' => trim($data['description_zh']) ?: null,
        ':description_es' => trim($data['description_es']) ?: null,
        ':sort_order' => (int)($data['sort_order'] ?? 99),
        ':is_active' => (int)($data['is_active'] ?? 0)
    ];
    if (empty($params[':name_zh']) || empty($params[':name_es']) || empty($params[':pos_category_id'])) json_error('双语名称和POS分类均为必填项。', 400);
    
    // [R2.2] 标签
    $tag_ids = $data['tag_ids'] ?? [];
    
    // [R2] 审计
    $data_before = null;
    $action_name = 'rms.product.create';
    if ($id) {
        if (!function_exists('getPosMenuItemById')) json_error('审计失败: 缺少 getPosMenuItemById 助手。', 500);
        $data_before = getPosMenuItemById($pdo, $id);
        $action_name = 'rms.product.update';
    }

    $pdo->beginTransaction();
    try {
        if ($id) {
            $params[':id'] = $id;
            $sql = "UPDATE pos_menu_items SET name_zh = :name_zh, name_es = :name_es, pos_category_id = :pos_category_id, description_zh = :description_zh, description_es = :description_es, sort_order = :sort_order, is_active = :is_active WHERE id = :id";
            $pdo->prepare($sql)->execute($params);
            $message = '商品信息已成功更新！';
        } else {
            $sql = "INSERT INTO pos_menu_items (name_zh, name_es, pos_category_id, description_zh, description_es, sort_order, is_active) VALUES (:name_zh, :name_es, :pos_category_id, :description_zh, :description_es, :sort_order, :is_active)";
            $pdo->prepare($sql)->execute($params);
            $id = (int)$pdo->lastInsertId();
            $message = '新商品已成功创建！';
        }

        // [R2.2] START: 更新 Tag 关联
        $stmt_del_tags = $pdo->prepare("DELETE FROM pos_product_tag_map WHERE product_id = ?");
        $stmt_del_tags->execute([$id]);

        if (!empty($tag_ids)) {
            $sql_ins_tags = "INSERT INTO pos_product_tag_map (product_id, tag_id) VALUES (?, ?)";
            $stmt_ins_tags = $pdo->prepare($sql_ins_tags);
            foreach ($tag_ids as $tag_id) {
                if (filter_var($tag_id, FILTER_VALIDATE_INT)) {
                    $stmt_ins_tags->execute([$id, (int)$tag_id]);
                }
            }
        }
        // [R2.2] END: 更新 Tag 关联
        
        $pdo->commit();

        // [R2] 审计：写入日志
        if (!function_exists('getPosMenuItemById')) json_error('审计失败: 缺少 getPosMenuItemById 助手。', 500);
        $data_after = getPosMenuItemById($pdo, $id);
        log_audit_action($pdo, $action_name, 'pos_menu_items', $id, $data_before, $data_after);
        
        json_ok(['id' => $id], $message);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('保存商品时出错。', 500, ['debug' => $e->getMessage()]);
    }
}
function handle_menu_item_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $id = (int)$id;

    // [R2] 审计：获取变更前数据
    if (!function_exists('getPosMenuItemById')) json_error('审计失败: 缺少 getPosMenuItemById 助手。', 500);
    $data_before = getPosMenuItemById($pdo, $id);
    if (!$data_before) {
        json_error('未找到要删除的商品。', 404);
    }
    
    // [冲突解决] 检查此商品是否为“次卡商品”
    if (is_pass_product_locked($pdo, $id, $data_before['product_code'] ?? null)) {
        json_error(
            '操作被禁止：此商品是一个激活的“次卡售卖商品”。请前往 [POS 管理] -> [会员次卡] -> [次卡方案管理] 页面将其“下架”或“删除”，以确保数据一致性。',
            403 // 403 Forbidden
        );
    }

    // [A2.2 UTC FIX] 
    // pos_menu_items.deleted_at 和 pos_item_variants.deleted_at 都是 timestamp(0)。
    // [GEMINI V3 FIX] 数据库为 datetime(6)，使用 .u
    $now_utc_str = utc_now()->format('Y-m-d H:i:s.u');
    
    $pdo->beginTransaction();
    $stmt_variants = $pdo->prepare("UPDATE pos_item_variants SET deleted_at = ? WHERE menu_item_id = ?");
    $stmt_variants->execute([$now_utc_str, $id]);
    $stmt_item = $pdo->prepare("UPDATE pos_menu_items SET deleted_at = ? WHERE id = ?");
    $stmt_item->execute([$now_utc_str, $id]);
    
    // [R2.2] 删除关联 (虽然 FK 已设置 CASCADE，但显式执行更安全)
    $stmt_tags = $pdo->prepare("DELETE FROM pos_product_tag_map WHERE product_id = ?");
    $stmt_tags->execute([$id]);

    $pdo->commit();

    // [R2] 审计：写入日志
    log_audit_action($pdo, 'rms.product.delete', 'pos_menu_items', $id, $data_before, null);

    json_ok(null, '商品及其所有规格已成功删除。');
}

/**
* [新功能] Handler: 获取带物料清单的产品列表 (L1+L3)
*/
function handle_menu_get_with_materials(PDO $pdo, array $config, array $input_data): void {
    $search_material_id = $_GET['material_id'] ?? null;

    // 1. 获取所有 POS 菜单项及其关联的 KDS Product ID
    $sql = "
        SELECT
            mi.id, mi.product_code, mi.name_zh, mi.name_es, mi.is_active,
            p.id AS kds_product_id
        FROM pos_menu_items mi
        LEFT JOIN kds_products p ON mi.product_code = p.product_code AND p.deleted_at IS NULL
        WHERE mi.deleted_at IS NULL
        ORDER BY mi.sort_order ASC, mi.id ASC
    ";
    $products = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

    // 2. 准备物料查询语句
    $sql_materials = "
        (SELECT DISTINCT material_id FROM kds_product_recipes WHERE product_id = ?)
        UNION
        (SELECT DISTINCT material_id FROM kds_recipe_adjustments WHERE product_id = ?)
    ";
    $stmt_materials = $pdo->prepare($sql_materials);

    // 3. 准备物料名称查询语句 [FIX START: HY093]
    $sql_material_names_base = "
        SELECT m.id, mt.material_name AS name_zh
        FROM kds_materials m
        JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
        WHERE m.deleted_at IS NULL AND m.id IN
    ";
    // [FIX END]

    $results = [];
    $material_name_cache = []; // 缓存物料名称

    foreach ($products as $pid => $product) {
        
        // --- [CRITICAL FIX V1.2.004] ---
        // 将 $pid (即 mi.id) 重新注入到 $product 数组中
        $product['id'] = $pid;
        // --- [END FIX] ---

        $kds_pid = $product['kds_product_id'];
        $product['materials'] = [];
        $material_ids = [];

        if ($kds_pid) {
            $stmt_materials->execute([$kds_pid, $kds_pid]);
            $material_ids = $stmt_materials->fetchAll(PDO::FETCH_COLUMN);
        }

        // 4. (可选) 如果按物料搜索，则过滤
        if ($search_material_id !== null && !in_array($search_material_id, $material_ids)) {
            continue; // 跳过不包含该物料的产品
        }

        if (!empty($material_ids)) {
            // 批量获取物料名称
            $ids_to_fetch = array_diff($material_ids, array_keys($material_name_cache));
            if (!empty($ids_to_fetch)) {
                
                // [FIX START: HY093]
                // 1. 创建占位符
                $in_placeholders = implode(',', array_fill(0, count($ids_to_fetch), '?'));
                // 2. 安全地构建 SQL
                $sql_to_prepare = $sql_material_names_base . " ( " . $in_placeholders . " )";
                // 3. 准备新语句
                $stmt_material_names_dynamic = $pdo->prepare($sql_to_prepare);
                
                // 4. [CRITICAL FIX] 使用 array_values() 重置键名
                $stmt_material_names_dynamic->execute(array_values($ids_to_fetch));
                
                // 5. 抓取 (fetch)
                while ($mat = $stmt_material_names_dynamic->fetch(PDO::FETCH_ASSOC)) {
                    $material_name_cache[$mat['id']] = $mat['name_zh'];
                }
                $stmt_material_names_dynamic->closeCursor();
                // [FIX END]
            }

            // 组装物料列表
            foreach ($material_ids as $mid) {
                $product['materials'][] = [
                    'id' => $mid,
                    'name_zh' => $material_name_cache[$mid] ?? 'ID:'.$mid.' (已删除)'
                ];
            }
        }

        $results[] = $product;
    }

    json_ok($results, '产品物料清单加载成功。');
}

/**
* [新功能] Handler: 切换 POS 菜单项的上架状态
*/
function handle_menu_toggle_active(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? null;
    $is_active = isset($input_data['is_active']) ? (int)$input_data['is_active'] : null;

    if ($id === null || $is_active === null) {
        json_error('缺少 "id" 或 "is_active" 参数。', 400);
    }

    $stmt = $pdo->prepare("UPDATE pos_menu_items SET is_active = ? WHERE id = ?");
    $stmt->execute([$is_active, (int)$id]);

    json_ok(['id' => $id, 'is_active' => $is_active], '产品状态已更新。');
}

/**
* [新功能] Handler: 获取物料使用总览
*/
function handle_menu_get_material_usage_report(PDO $pdo, array $config, array $input_data): void {
    try {
        // 1. 获取在售产品使用的物料
        $sql_on_sale = "
            SELECT DISTINCT m.id, m.material_code, mt.material_name AS name_zh
            FROM kds_materials m
            JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
            JOIN (
                SELECT DISTINCT material_id FROM kds_product_recipes r
                JOIN kds_products p ON r.product_id = p.id
                JOIN pos_menu_items mi ON p.product_code = mi.product_code
                WHERE mi.deleted_at IS NULL AND p.deleted_at IS NULL AND mi.is_active = 1
                UNION
                SELECT DISTINCT material_id FROM kds_recipe_adjustments ra
                JOIN kds_products p ON ra.product_id = p.id
                JOIN pos_menu_items mi ON p.product_code = mi.product_code
                WHERE mi.deleted_at IS NULL AND p.deleted_at IS NULL AND mi.is_active = 1
            ) AS used_materials ON m.id = used_materials.material_id
            WHERE m.deleted_at IS NULL
            ORDER BY m.material_code;
        ";
        $on_sale_list = $pdo->query($sql_on_sale)->fetchAll(PDO::FETCH_ASSOC);
        $on_sale_ids = array_column($on_sale_list, 'id');
        $on_sale_ids_placeholders = !empty($on_sale_ids) ? implode(',', array_fill(0, count($on_sale_ids), '?')) : 'NULL';

        // 2. 获取已下架产品使用的物料 (且未在在售产品中使用)
        $sql_off_sale = "
            SELECT DISTINCT m.id, m.material_code, mt.material_name AS name_zh
            FROM kds_materials m
            JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
            JOIN (
                SELECT DISTINCT material_id FROM kds_product_recipes r
                JOIN kds_products p ON r.product_id = p.id
                JOIN pos_menu_items mi ON p.product_code = mi.product_code
                WHERE mi.deleted_at IS NULL AND p.deleted_at IS NULL AND mi.is_active = 0
                UNION
                SELECT DISTINCT material_id FROM kds_recipe_adjustments ra
                JOIN kds_products p ON ra.product_id = p.id
                JOIN pos_menu_items mi ON p.product_code = mi.product_code
                WHERE mi.deleted_at IS NULL AND p.deleted_at IS NULL AND mi.is_active = 0
            ) AS used_off_materials ON m.id = used_off_materials.material_id
            WHERE m.deleted_at IS NULL
            AND m.id NOT IN ({$on_sale_ids_placeholders})
            ORDER BY m.material_code;
        ";
        $stmt_off_sale = $pdo->prepare($sql_off_sale);
        $stmt_off_sale->execute($on_sale_ids);
        $off_sale_list = $stmt_off_sale->fetchAll(PDO::FETCH_ASSOC);

        // 3. 获取所有已使用的物料 ID (用于查询未使用)
        $all_used_ids = array_merge($on_sale_ids, array_column($off_sale_list, 'id'));
        $all_used_ids_placeholders = !empty($all_used_ids) ? implode(',', array_fill(0, count($all_used_ids), '?')) : 'NULL';

        // 4. 获取未被任何产品使用的物料
        $sql_unused = "
            SELECT m.id, m.material_code, mt.material_name AS name_zh
            FROM kds_materials m
            JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
            WHERE m.deleted_at IS NULL
            AND m.id NOT IN ({$all_used_ids_placeholders})
            ORDER BY m.material_code;
        ";
        $stmt_unused = $pdo->prepare($sql_unused);
        $stmt_unused->execute($all_used_ids);
        $unused_list = $stmt_unused->fetchAll(PDO::FETCH_ASSOC);

        $response_data = [
            'on_sale'  => $on_sale_list,
            'off_sale' => $off_sale_list,
            'unused'   => $unused_list
        ];

        json_ok($response_data, '物料使用报告已生成。');

    } catch (Throwable $e) {
        json_error('生成物料报告时出错。', 500, ['debug' => $e->getMessage()]);
    }
}


// --- 处理器: POS 规格 (pos_item_variants) ---
function handle_variant_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $sql = "SELECT v.id, v.menu_item_id, v.variant_name_zh, v.variant_name_es, v.price_eur, v.sort_order, v.is_default, mi.product_code, p.id AS product_id
            FROM pos_item_variants v
            INNER JOIN pos_menu_items mi ON v.menu_item_id = mi.id
            LEFT JOIN kds_products p ON mi.product_code = p.product_code AND p.deleted_at IS NULL
            WHERE v.id = ? AND v.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $row ? json_ok($row) : json_error('记录不存在', 404);
}
function handle_variant_save(PDO $pdo, array $config, array $input_data): void {
    $d = $input_data['data'] ?? json_error('缺少 data', 400);
    $id              = isset($d['id']) ? (int)$d['id'] : null;
    $menu_item_id    = isset($d['menu_item_id']) ? (int)$d['menu_item_id'] : 0;
    $variant_name_zh = trim($d['variant_name_zh'] ?? '');
    $variant_name_es = trim($d['variant_name_es'] ?? '');
    $price_eur       = isset($d['price_eur']) ? (float)$d['price_eur'] : 0.0;
    $sort_order      = isset($d['sort_order']) ? (int)$d['sort_order'] : 99;
    $is_default      = !empty($d['is_default']) ? 1 : 0;
    $product_id      = isset($d['product_id']) && $d['product_id'] !== '' ? (int)$d['product_id'] : null;
    if ($menu_item_id <= 0 || $variant_name_zh === '' || $variant_name_es === '' || $price_eur <= 0) json_error('缺少必填项或价格无效', 400);
    
    // [冲突解决] 检查此商品是否为“次卡商品”
    if (is_pass_product_locked($pdo, $menu_item_id, null)) {
        json_error(
            '操作被禁止：此规格所属的商品是一个激活的“次卡售卖商品”。请前往 [POS 管理] -> [会员次卡] -> [次卡方案管理] 页面进行修改。',
            403 // 403 Forbidden
        );
    }
    
    $pdo->beginTransaction();
    if ($product_id) {
        $stmt = $pdo->prepare("SELECT product_code FROM kds_products WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$product_id]);
        $pc = $stmt->fetchColumn();
        if ($pc) {
            $stmt2 = $pdo->prepare("UPDATE pos_menu_items SET product_code = ? WHERE id = ? AND deleted_at IS NULL");
            $stmt2->execute([$pc, $menu_item_id]);
        }
    }
    if ($id) {
        $sql = "UPDATE pos_item_variants SET variant_name_zh = ?, variant_name_es = ?, price_eur = ?, sort_order = ?, is_default = ? WHERE id = ? AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$variant_name_zh, $variant_name_es, $price_eur, $sort_order, $is_default, $id]);
    } else {
        $sql = "INSERT INTO pos_item_variants (menu_item_id, variant_name_zh, variant_name_es, price_eur, is_default, sort_order) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$menu_item_id, $variant_name_zh, $variant_name_es, $price_eur, $is_default, $sort_order]);
        $id = (int)$pdo->lastInsertId();
    }
    if ($is_default === 1) {
        $stmt = $pdo->prepare("UPDATE pos_item_variants SET is_default = 0 WHERE menu_item_id = ? AND id <> ?");
        $stmt->execute([$menu_item_id, $id]);
    }
    $pdo->commit();
    json_ok(['id' => $id], '规格已保存');
}
function handle_variant_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    
    // [冲突解决] 检查此商品是否为“次卡商品”
    $stmt_find = $pdo->prepare("SELECT menu_item_id FROM pos_item_variants WHERE id = ?");
    $stmt_find->execute([(int)$id]);
    $menu_item_id = $stmt_find->fetchColumn();
    
    if ($menu_item_id && is_pass_product_locked($pdo, $menu_item_id, null)) {
        json_error(
            '操作被禁止：此规格所属的商品是一个激活的“次卡售卖商品”。请前往 [POS 管理] -> [会员次卡] -> [次卡方案管理] 页面进行修改。',
            403 // 403 Forbidden
        );
    }

    // [A2.2 UTC FIX] 
    // pos_item_variants.deleted_at 是 timestamp(0)。必须使用 'Y-m-d H:i:s'
    // [GEMINI V3 FIX] 数据库为 datetime(6)，使用 .u
    $now_utc_str = utc_now()->format('Y-m-d H:i:s.u');
    $stmt = $pdo->prepare("UPDATE pos_item_variants SET deleted_at = ? WHERE id = ?");
    $stmt->execute([$now_utc_str, (int)$id]);
    json_ok(null, '规格已删除');
}

// --- 处理器: POS 加料 (pos_addons) ---
function handle_addon_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    
    // [R2] 审计依赖
    if (!function_exists('getPosAddonById')) json_error('审计失败: 缺少 getPosAddonById 助手。', 500);
    $data = getPosAddonById($pdo, (int)$id);
    
    $data ? json_ok($data) : json_error('未找到加料', 404);
}
function handle_addon_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $params = [
        ':addon_code' => trim($data['addon_code']),
        ':name_zh' => trim($data['name_zh']),
        ':name_es' => trim($data['name_es']),
        ':price_eur' => (float)($data['price_eur'] ?? 0),
        ':material_id' => !empty($data['material_id']) ? (int)$data['material_id'] : null,
        ':sort_order' => (int)($data['sort_order'] ?? 99),
        ':is_active' => (int)($data['is_active'] ?? 0)
    ];
    if (empty($params[':addon_code']) || empty($params[':name_zh']) || empty($params[':name_es'])) json_error('编码和双语名称均为必填项。', 400);
    
    // [R2.2] 标签
    $tag_ids = $data['tag_ids'] ?? [];

    // [R2] 审计
    $data_before = null;
    $action_name = 'rms.addon.create';
    if ($id) {
        if (!function_exists('getPosAddonById')) json_error('审计失败: 缺少 getPosAddonById 助手。', 500);
        $data_before = getPosAddonById($pdo, $id);
        $action_name = 'rms.addon.update';
    }
    
    // 检查 code 唯一性
    $sql_check = "SELECT id FROM pos_addons WHERE addon_code = ? AND deleted_at IS NULL" . ($id ? " AND id != ?" : "");
    $params_check = $id ? [$params[':addon_code'], $id] : [$params[':addon_code']];
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute($params_check);
    if ($stmt_check->fetch()) json_error('此编码 (KEY)已被使用。', 409);
    
    $pdo->beginTransaction();
    try {
        if ($id) {
            $params[':id'] = $id;
            $sql = "UPDATE pos_addons SET addon_code = :addon_code, name_zh = :name_zh, name_es = :name_es, price_eur = :price_eur, material_id = :material_id, sort_order = :sort_order, is_active = :is_active WHERE id = :id";
            $pdo->prepare($sql)->execute($params);
            $message = '加料已成功更新！';
        } else {
            $sql = "INSERT INTO pos_addons (addon_code, name_zh, name_es, price_eur, material_id, sort_order, is_active) VALUES (:addon_code, :name_zh, :name_es, :price_eur, :material_id, :sort_order, :is_active)";
            $pdo->prepare($sql)->execute($params);
            $id = (int)$pdo->lastInsertId();
            $message = '新加料已成功创建！';
        }

        // [R2.2] START: 更新 Tag 关联
        $stmt_del_tags = $pdo->prepare("DELETE FROM pos_addon_tag_map WHERE addon_id = ?");
        $stmt_del_tags->execute([$id]);

        if (!empty($tag_ids)) {
            $sql_ins_tags = "INSERT INTO pos_addon_tag_map (addon_id, tag_id) VALUES (?, ?)";
            $stmt_ins_tags = $pdo->prepare($sql_ins_tags);
            foreach ($tag_ids as $tag_id) {
                // 确保 tag_id 是有效的整数
                if (filter_var($tag_id, FILTER_VALIDATE_INT)) {
                    $stmt_ins_tags->execute([$id, (int)$tag_id]);
                }
            }
        }
        // [R2.2] END: 更新 Tag 关联

        $pdo->commit();
        
        // [R2] 审计：写入日志
        if (!function_exists('getPosAddonById')) json_error('审计失败: 缺少 getPosAddonById 助手。', 500);
        $data_after = getPosAddonById($pdo, $id);
        log_audit_action($pdo, $action_name, 'pos_addons', $id, $data_before, $data_after);

        json_ok(['id' => $id], $message);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('保存加料时出错。', 500, ['debug' => $e->getMessage()]);
    }
}
function handle_addon_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $id = (int)$id;

    // [R2] 审计：获取变更前数据
    if (!function_exists('getPosAddonById')) json_error('审计失败: 缺少 getPosAddonById 助手。', 500);
    $data_before = getPosAddonById($pdo, $id);
    if (!$data_before) {
        json_error('未找到要删除的加料。', 404);
    }

    // [A2.2 UTC FIX] 
    // pos_addons.deleted_at 是 timestamp(0)。必须使用 'Y-m-d H:i:s'
    // [GEMINI V3 FIX] 数据库为 datetime(6)，使用 .u
    $now_utc_str = utc_now()->format('Y-m-d H:i:s.u');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE pos_addons SET deleted_at = ? WHERE id = ?");
        $stmt->execute([$now_utc_str, (int)$id]);
        
        // [R2.2] 删除关联 (虽然 FK 已设置 CASCADE，但显式执行更安全)
        $stmt_tags = $pdo->prepare("DELETE FROM pos_addon_tag_map WHERE addon_id = ?");
        $stmt_tags->execute([(int)$id]);
        
        $pdo->commit();
        
        // [R2] 审计：写入日志
        log_audit_action($pdo, 'rms.addon.delete', 'pos_addons', $id, $data_before, null);

        json_ok(null, '加料已成功删除。');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('删除加料时出错。', 500, ['debug' => $e->getMessage()]);
    }
}

// --- [GEMINI REFACTOR 2025-11-14] START: 新增的加料全局设置处理器 ---
const GLOBAL_ADDON_LIMIT_KEY = 'global_free_addon_limit';
function handle_addon_get_global_settings(PDO $pdo, array $config, array $input_data): void {
    $stmt = $pdo->prepare("SELECT setting_value FROM pos_settings WHERE setting_key = ?");
    $stmt->execute([GLOBAL_ADDON_LIMIT_KEY]);
    $value = $stmt->fetchColumn();
    
    $limit = ($value === false || $value === null) ? '0' : (string)$value;
    
    json_ok([GLOBAL_ADDON_LIMIT_KEY => $limit], 'Settings loaded.');
}
function handle_addon_save_global_settings(PDO $pdo, array $config, array $input_data): void {
    // 这个处理器不希望有 'data' 包装
    $limit_str = $input_data[GLOBAL_ADDON_LIMIT_KEY] ?? null;
    
    $intVal = filter_var($limit_str, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($intVal === false) { 
        json_error('“免费加料上限”必须是一个大于等于0的整数。', 400); 
    }
    $value = (string)$intVal;

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO pos_settings (setting_key, setting_value, description) VALUES (:key, :value, :desc) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([
        ':key' => GLOBAL_ADDON_LIMIT_KEY,
        ':value' => $value,
        ':desc' => 'Global limit for free addons per item (0=unlimited)'
    ]);
    $pdo->commit();
    
    json_ok(null, '全局加料设置已保存！');
}
// --- [GEMINI REFACTOR 2025-11-14] END ---


// --- [R2.1] START: 处理器: POS 标签 (pos_tags) ---
function handle_pos_tag_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $data = getTagById($pdo, (int)$id);
    $data ? json_ok($data) : json_error('未找到标签', 404);
}
function handle_pos_tag_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $code = trim($data['tag_code'] ?? '');
    $name = trim($data['tag_name'] ?? '');
    
    if (empty($code) || empty($name)) json_error('标签编码和名称均为必填项。', 400);
    
    // [R2] 审计
    $data_before = null;
    $action_name = 'rms.tag.create';
    if ($id) {
        $data_before = getTagById($pdo, $id); // 获取变更前数据
        $action_name = 'rms.tag.update';
    }

    // 检查 code 唯一性
    $sql_check = "SELECT tag_id FROM pos_tags WHERE tag_code = ?" . ($id ? " AND tag_id != ?" : "");
    $params_check = $id ? [$code, $id] : [$code];
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute($params_check);
    if ($stmt_check->fetch()) json_error('标签编码 "' . htmlspecialchars($code) . '" 已被使用。', 409);

    if ($id) {
        $stmt = $pdo->prepare("UPDATE pos_tags SET tag_code = ?, tag_name = ? WHERE tag_id = ?");
        $stmt->execute([$code, $name, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO pos_tags (tag_code, tag_name) VALUES (?, ?)");
        $stmt->execute([$code, $name]);
        $id = (int)$pdo->lastInsertId();
    }
    
    // [R2] 审计：写入日志
    $data_after = getTagById($pdo, $id);
    log_audit_action($pdo, $action_name, 'pos_tags', $id, $data_before, $data_after);

    json_ok(['id' => $id], $id ? '标签已成功更新！' : '新标签已成功创建！');
}
function handle_pos_tag_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $id = (int)$id;

    // [R2] 审计：获取变更前数据
    $data_before = getTagById($pdo, $id);
    if (!$data_before) {
        json_error('未找到要删除的标签。', 404);
    }
    
    // 硬删除 (Hard Delete)，因为此表没有 deleted_at
    // [R2.2] 增加事务，确保 map 表也被删除 (虽然 FK 已设置 CASCADE)
    $pdo->beginTransaction();
    try {
        // [代码清晰度] 无需手动删除 pos_addon_tag_map, 
        // 数据库 FK (fk_addon_map_tag) 已设置 ON DELETE CASCADE。
        // $stmt_map = $pdo->prepare("DELETE FROM pos_addon_tag_map WHERE tag_id = ?");
        // $stmt_map->execute([(int)$id]);
        
        $stmt_map_prod = $pdo->prepare("DELETE FROM pos_product_tag_map WHERE tag_id = ?");
        $stmt_map_prod->execute([(int)$id]);
        
        $stmt = $pdo->prepare("DELETE FROM pos_tags WHERE tag_id = ?");
        $stmt->execute([(int)$id]);
        
        $pdo->commit();

        // [R2] 审计：写入日志
        log_audit_action($pdo, 'rms.tag.delete', 'pos_tags', $id, $data_before, null);

        json_ok(null, '标签已成功删除。');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // 检查是否为 FK 约束失败
        if ($e instanceof PDOException && $e->errorInfo[1] == 1451) {
             json_error('删除失败：此标签可能仍被其他系统组件使用 (例如：次卡方案)，请先解除关联。', 409);
        }
        json_error('删除标签时出错。', 500, ['debug' => $e->getMessage()]);
    }
}
// --- [R2.1] END: 处理器: POS 标签 (pos_tags) ---