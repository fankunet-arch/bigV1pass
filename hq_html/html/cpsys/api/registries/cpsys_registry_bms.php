<?php
/**
 * Toptea HQ - CPSYS API 注册表 (BMS - POS Management)
 */

require_once realpath(__DIR__ . '/../../../../app/helpers/kds_helper.php');
require_once realpath(__DIR__ . '/../../../../app/helpers/auth_helper.php');
require_once realpath(__DIR__ . '/../../../../app/helpers/datetime_helper.php');

// [REPO SPLIT] Handlers moved to dedicated files.
require_once __DIR__ . '/cpsys_registry_bms_menu_a.php';
require_once __DIR__ . '/cpsys_registry_bms_menu_b.php';
require_once __DIR__ . '/cpsys_registry_bms_member.php';
require_once __DIR__ . '/cpsys_registry_bms_pass_plan.php';

// --- 注册表 ---

return [

    'pos_categories' => [
        'table' => 'pos_categories', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [ 'get' => 'handle_pos_category_get', 'save' => 'handle_pos_category_save', 'delete' => 'handle_pos_category_delete', ],
    ],
    'pos_menu_items' => [
        'table' => 'pos_menu_items', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_menu_item_get',
            'save' => 'handle_menu_item_save',
            'delete' => 'handle_menu_item_delete',
            // --- [新功能] START ---
            'get_with_materials' => 'handle_menu_get_with_materials',
            'toggle_active' => 'handle_menu_toggle_active',
            'get_material_usage_report' => 'handle_menu_get_material_usage_report', // <-- [新功能] 注册
            // --- [新功能] END ---
        ],
    ],
    'pos_item_variants' => [
        'table' => 'pos_item_variants', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [ 'get' => 'handle_variant_get', 'save' => 'handle_variant_save', 'delete' => 'handle_variant_delete', ],
    ],
    'pos_addons' => [
        'table' => 'pos_addons', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [ 
            'get' => 'handle_addon_get', 
            'save' => 'handle_addon_save', 
            'delete' => 'handle_addon_delete',
            // --- [GEMINI REFACTOR 2025-11-14] START: 注册新动作 ---
            'get_global_settings' => 'handle_addon_get_global_settings',
            'save_global_settings' => 'handle_addon_save_global_settings',
            // --- [GEMINI REFACTOR 2025-11-14] END ---
        ],
    ],
    
    // --- [R2.1] START: 注册 pos_tags ---
    'pos_tags' => [
        'table' => 'pos_tags', 'pk' => 'tag_id', 'soft_delete_col' => null, 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_pos_tag_get',
            'save' => 'handle_pos_tag_save',
            'delete' => 'handle_pos_tag_delete',
        ],
    ],
    // --- [R2.1] END ---
    
    // --- [R2.4] START: 注册 topup_orders ---
    'topup_orders' => [
        'table' => 'topup_orders', 'pk' => 'topup_order_id', // [R2.4 FIX] 修正主键
        'soft_delete_col' => null, // 假设没有软删除，或使用 status='refunded'
        'auth_role' => ROLE_SUPER_ADMIN, // 假设 R2.4 视图需要
        'custom_actions' => [
		'review' => 'handle_topup_order_review',
			],
		],
		// --- [R2.4] END ---

		// --- [P1 修复] START: 注册 pos_pass_plans ---
		'pos_pass_plans' => [
			'table' => 'pass_plans',
			'pk' => 'pass_plan_id',
			'soft_delete_col' => null, // 注意：此表使用硬删除或 is_active=0
			'auth_role' => ROLE_ADMIN, // 与 index.php 页面权限匹配
			'custom_actions' => [
				'get' => 'handle_pass_plan_get',
				'save' => 'handle_pass_plan_save',
				'delete' => 'handle_pass_plan_delete',
			],
		],
		// --- [P1 修复] END ---

		'pos_member_levels' => [
        'table' => 'pos_member_levels', 'pk' => 'id', 'soft_delete_col' => null, 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [ 'get' => 'handle_member_level_get', 'save' => 'handle_member_level_save', 'delete' => 'handle_member_level_delete', ],
    ],
    'pos_members' => [
        'table' => 'pos_members', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [ 'get' => 'handle_member_get', 'save' => 'handle_member_save', 'delete' => 'handle_member_delete', ],
    ],
    'pos_redemption_rules' => [
        'table' => 'pos_point_redemption_rules', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [ 'get' => 'handle_redemption_rule_get', 'save' => 'handle_redemption_rule_save', 'delete' => 'handle_redemption_rule_delete', ],
    ],
    'pos_settings' => [
        'table' => 'pos_settings', 'pk' => 'setting_key', 'soft_delete_col' => null, 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'load' => 'handle_settings_load', // (已修改)
            'save' => 'handle_settings_save', // (已修改)
            'load_sif' => 'handle_sif_load',     // <--  SIF 动作
            'save_sif' => 'handle_sif_save',     // <--  SIF 动作
        ],
    ],
    'pos_promotions' => [
        'table' => 'pos_promotions', 'pk' => 'id', 'soft_delete_col' => null, 'auth_role' => ROLE_PRODUCT_MANAGER,
        'custom_actions' => [ 'get' => 'handle_promo_get', 'save' => 'handle_promo_save', 'delete' => 'handle_promo_delete', ],
    ],
    'invoices' => [
        'table' => 'pos_invoices', 'pk' => 'id', 'soft_delete_col' => null, 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [ 'cancel' => 'handle_invoice_cancel', 'correct' => 'handle_invoice_correct', ],
    ],
    'shifts' => [
        'table' => 'pos_shifts', 'pk' => 'id', 'soft_delete_col' => null, 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [ 'review' => 'handle_shift_review', ],
    ],
];