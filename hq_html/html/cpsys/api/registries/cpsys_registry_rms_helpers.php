<?php
/**
 * CPSYS RMS Registry Helpers
 * Extracted from cpsys_registry_rms.php
 */
/* =========================  仅当缺失时的兜底函数  ========================= */
if (!function_exists('getMaterialById')) {
    function getMaterialById(PDO $pdo, int $id): ?array {
        $sql = "
            SELECT
                m.*,
                m.image_url,
                mt_zh.material_name AS name_zh,
                mt_es.material_name AS name_es
            FROM kds_materials m
            LEFT JOIN kds_material_translations mt_zh
                ON mt_zh.material_id = m.id AND mt_zh.language_code = 'zh-CN'
            LEFT JOIN kds_material_translations mt_es
                ON mt_es.material_id = m.id AND mt_es.language_code = 'es-ES'
            WHERE m.id = ? AND m.deleted_at IS NULL
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('getNextAvailableCustomCode')) {
    function getNextAvailableCustomCode(PDO $pdo, string $table, string $column, int $start = 1): string {
        $max = $start - 1;
        try {
            $val = $pdo->query("SELECT MAX(CAST($column AS UNSIGNED)) FROM $table WHERE deleted_at IS NULL")->fetchColumn();
            if ($val !== false && $val !== null) {
                $max = max($max, (int)$val);
            } else {
                $s = $pdo->query("SELECT $column FROM $table ORDER BY $column DESC LIMIT 1")->fetchColumn();
                if (is_string($s) && preg_match('/\d+/', $s, $m)) {
                    $max = max($max, (int)$m[0]);
                }
            }
        } catch (Throwable $e) {
            // 可按需记录：error_log($e->getMessage());
        }
        return (string)($max + 1);
    }
}

// [R1] 确保 kds_helper.php 已加载 (依赖 repo_a/b/c)
require_once realpath(__DIR__ . '/../../../../app/helpers/kds_helper.php');

/* =========================  兜底函数结束  ========================= */

/* ====== 公共换算函数（唯一命名，防冲突） ====== */
if (!function_exists('cprms_get_base_quantity')) {
    function cprms_get_base_quantity(PDO $pdo, int $material_id, float $quantity_input, int $unit_id): float {
        if ($material_id <= 0 || $quantity_input <= 0 || $unit_id <= 0) {
            json_error('物料、数量和单位均为必填项。', 400);
        }

        $material = getMaterialById($pdo, $material_id);
        if (!$material) json_error('找不到指定的物料。', 404);

        if ((int)$material['base_unit_id'] === $unit_id) {
            return $quantity_input; // 基础单位
        }
        if (!empty($material['medium_unit_id']) && (int)$material['medium_unit_id'] === $unit_id) {
            $med_rate = (float)($material['medium_conversion_rate'] ?? 0);
            if ($med_rate <= 0) json_error('该物料的“中级单位”换算率未设置或无效。', 400);
            return $quantity_input * $med_rate;
        }
        if (!empty($material['large_unit_id']) && (int)$material['large_unit_id'] === $unit_id) {
            $med_rate   = (float)($material['medium_conversion_rate'] ?? 0);
            $large_rate = (float)($material['large_conversion_rate'] ?? 0);
            if ($med_rate <= 0 || $large_rate <= 0) {
                json_error('该物料的“中级单位”或“大单位”换算率未设置或无效。', 400);
            }
            return $quantity_input * $large_rate * $med_rate; // 大 -> 中 -> 基础
        }

        json_error('选择的单位与该物料不匹配。', 400);
        return 0.0;
    }
}