<?php
/**
 * Toptea HQ - CPSYS API 注册表 (Base System)
 * 注册核心系统资源 (用户, 门店, 字典, 打印模板等)
 *
 * Revision: 1.3.0 (R2 Audit Implementation)
 *
 * [R2] Added:
 * - Injected log_audit_action() into handle_unit_save
 * - Injected log_audit_action() into handle_unit_delete
 *
 * [GEMINI SECURITY FIX V1.0 - 2025-11-10]
 * - Fixed handle_profile_save() to use password_verify() and password_hash(..., PASSWORD_BCRYPT)
 * - This resolves the critical hash mismatch with login_handler and user_management.
 */

// 确保助手已加载 (网关会处理，但作为保险)
require_once realpath(__DIR__ . '/../../../../app/helpers/kds_helper.php');
require_once realpath(__DIR__ . '/../../../../app/helpers/auth_helper.php');

// [REPO SPLIT] Core & dictionary handlers moved to dedicated files.
require_once __DIR__ . '/cpsys_registry_base_helpers.php';
require_once __DIR__ . '/cpsys_registry_base_core.php';
require_once __DIR__ . '/cpsys_registry_base_dicts.php';

// --- 注册表 ---
return [

    // KDS SOP Rules (V2 Refactor)
    'kds_sop_rules' => [
        'table' => 'kds_sop_query_rules', 'pk' => 'id', 'soft_delete_col' => null, 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get_list' => 'handle_kds_rule_get_list', 'get' => 'handle_kds_rule_get',
            'save' => 'handle_kds_rule_save', 'delete' => 'handle_kds_rule_delete',
        ],
    ],

    // --- START: 缺失的注册条目 ---

    'users' => [
        'table' => 'cpsys_users', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_user_get', 'save' => 'handle_user_save', 'delete' => 'handle_user_delete',
        ],
    ],

    'stores' => [
        'table' => 'kds_stores', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_store_get', 'save' => 'handle_store_save', 'delete' => 'handle_store_delete',
        ],
    ],

    'kds_users' => [
        'table' => 'kds_users', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_kds_user_get', 'save' => 'handle_kds_user_save', 'delete' => 'handle_kds_user_delete',
        ],
    ],

    'profile' => [
        'table' => 'cpsys_users', 'pk' => 'id', 'auth_role' => ROLE_PRODUCT_MANAGER, // 允许所有登录用户
        'custom_actions' => [
            'save' => 'handle_profile_save',
        ],
    ],

    'print_templates' => [
        'table' => 'pos_print_templates', 'pk' => 'id', 'soft_delete_col' => null, 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_template_get', 'save' => 'handle_template_save', 'delete' => 'handle_template_delete',
        ],
    ],

    // --- 字典 Dictionaries ---
    'cups' => [
        'table' => 'kds_cups', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_cup_get', 'save' => 'handle_cup_save', 'delete' => 'handle_cup_delete',
        ],
    ],

    'ice_options' => [
        'table' => 'kds_ice_options', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_ice_get', 'save' => 'handle_ice_save', 'delete' => 'handle_ice_delete', 'get_next_code' => 'handle_ice_get_next_code',
        ],
    ],

    'sweetness_options' => [
        'table' => 'kds_sweetness_options', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_sweetness_get', 'save' => 'handle_sweetness_save', 'delete' => 'handle_sweetness_delete', 'get_next_code' => 'handle_sweetness_get_next_code',
        ],
    ],

    'units' => [
        'table' => 'kds_units', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_unit_get', 'save' => 'handle_unit_save', 'delete' => 'handle_unit_delete', 'get_next_code' => 'handle_unit_get_next_code',
        ],
    ],

    'product_statuses' => [
        'table' => 'kds_product_statuses', 'pk' => 'id', 'soft_delete_col' => 'deleted_at', 'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_status_get', 'save' => 'handle_status_save', 'delete' => 'handle_status_delete',
        ],
    ],

    // --- END: 缺失的注册条目 ---

];