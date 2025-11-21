<?php
/**
 * Toptea HQ - CPSYS API 注册表 (RMS - Recipe/Stock Management)
 * 注册物料、库存、配方等资源
 * Version: 1.2.1 (R2 Audit Implementation)
 * Date: 2025-11-13
 *
 * [R2] Added:
 * - Injected log_audit_action() into cprms_product_save
 * - Injected log_audit_action() into cprms_product_delete
 * - Added helper cprms_product_get_details_snapshot() for auditing
 *
 * - Injected log_audit_action() into cprms_material_save
 * - Injected log_audit_action() into cprms_material_delete
 *
 * - cprms_products_get_list() handler for rms.products.list
 * - cprms_recipes_get() handler for rms.recipes.get
 * - cprms_tags_get_list() handler for rms.tags.list
 * - Registered 'rms_products', 'rms_recipes', 'rms_tags' resources.
 *
 * 关键修复：
 * - [V1.1.0] 修复 cprms_product_get_details 中 'sweets_option_id' 的拼写错误。
 * - [V1.1.0] 修复文件末尾多余的 '}' 语法错误。
 * - [V1.1.0] 为顶层 getMaterialById 兜底函数添加 image_url 字段。
 * - [V1.1.0] 为 cprms_material_save 添加 image_url 清理 (parse_url/basename)。
 */


// [REPO SPLIT] Helpers & handlers moved to separate files.
require_once __DIR__ . '/cpsys_registry_rms_helpers.php';
require_once __DIR__ . '/cpsys_registry_rms_handlers.php';


/* =========================  注册表  ========================= */
return [

    'materials' => [
        'table' => 'kds_materials',
        'pk' => 'id',
        'soft_delete_col' => 'deleted_at',
        'auth_role' => ROLE_PRODUCT_MANAGER,
        'custom_actions' => [
            'get'            => 'cprms_material_get',
            'save'           => 'cprms_material_save',
            'delete'         => 'cprms_material_delete',
            'get_next_code'  => 'cprms_material_get_next_code',
        ],
    ],

    'stock' => [
        'table' => 'expsys_warehouse_stock', // 占位
        'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'add_warehouse_stock' => 'cprms_stock_actions',
            'allocate_to_store'   => 'cprms_stock_actions',
        ],
    ],

    'rms_global_rules' => [
        'table' => 'kds_global_adjustment_rules',
        'pk' => 'id',
        'soft_delete_col' => null, // 硬删除
        'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get'    => 'cprms_global_rule_get',
            'save'   => 'cprms_global_rule_save',
            'delete' => 'cprms_global_rule_delete',
        ],
    ],

    'rms_products' => [
        'table' => 'kds_products',
        'pk' => 'id',
        'soft_delete_col' => 'deleted_at',
        'auth_role' => ROLE_PRODUCT_MANAGER,
        'custom_actions' => [
            // [R1] 新增
            'get_list'              => 'cprms_products_get_list',
            // (旧)
            'get_product_details'   => 'cprms_product_get_details',
            'get_next_product_code' => 'cprms_product_get_next_code',
            'save_product'          => 'cprms_product_save',
            'delete_product'        => 'cprms_product_delete',
        ],
    ],

    // [R1] 新增 rms_recipes 资源
    'rms_recipes' => [
        'table' => 'kds_product_recipes', // 占位
        'pk' => 'id',
        'auth_role' => ROLE_PRODUCT_MANAGER, // POS/KDS 也可访问 (只读)
        'custom_actions' => [
            'get' => 'cprms_recipes_get', // 按 code 获取
        ],
    ],

    // [R1] 新增 rms_tags 资源
    'rms_tags' => [
        'table' => 'pos_tags', // 占位
        'pk' => 'tag_id',
        'auth_role' => ROLE_PRODUCT_MANAGER, // POS/KDS 也可访问 (只读)
        'custom_actions' => [
            'get_list' => 'cprms_tags_get_list',
        ],
    ],

];