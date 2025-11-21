<?php
/**
 * CPSYS Base Registry - Helpers
 * Extracted from cpsys_registry_base.php
 */
/* ===== Fallback helpers for Units & Next-Code (only if missing) ===== */
if (!function_exists('getUnitById')) {
    function getUnitById(PDO $pdo, int $id): ?array {
        $sql = "
            SELECT
                u.*,
                zh.unit_name AS name_zh,
                es.unit_name AS name_es
            FROM kds_units u
            LEFT JOIN kds_unit_translations zh
                ON zh.unit_id = u.id AND zh.language_code = 'zh-CN'
            LEFT JOIN kds_unit_translations es
                ON es.unit_id = u.id AND es.language_code = 'es-ES'
            WHERE u.id = ? AND u.deleted_at IS NULL
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
/* ===== end fallback ===== */

/**
 * [V2] 助手: 将 V1 规则配置转为 V2 (用于前端编辑)
 */
function convert_v1_config_to_v2_for_editing(array $data): array {
    $config_json = $data['config_json'] ?? '{}';
    $config = json_decode($config_json, true);
    if (json_last_error() !== JSON_ERROR_NONE) $config = [];

    // 已经是 V2 (或无法识别)，直接返回
    if (isset($config['template']) || empty($config)) {
         $data['config'] = $config; // JS 读取 .config
         unset($data['config_json']);
         return $data;
    }

    $v2_config = [
        'template' => '',
        'mapping' => [ 'p' => 'P', 'a' => 'A', 'm' => 'M', 't' => 'T', 'ord' => 'ORD' ] // 默认映射
    ];

    if ($data['extractor_type'] === 'DELIMITER') {
        $format = $config['format'] ?? 'P-A-M-T';
        $separator = $config['separator'] ?? '-';
        $prefix = $config['prefix'] ?? '';
        
        $template_string = str_replace(
            ['P', 'A', 'M', 'T'],
            ['{P}', '{A}', '{M}', '{T}'],
            $format
        );
        $v2_config['template'] = $prefix . $template_string;
        // Delimiter 模式使用 V2 默认映射即可
        
    } elseif ($data['extractor_type'] === 'KEY_VALUE') {
        // V1 的 KeyValue 格式: { "P_key": "p", "A_key": "c", ... }
        // V2 的 模板格式: { "template": "?p={P}&c={A}", "mapping": { "p": "P", "a": "A" } }
        
        $p_key = $config['P_key'] ?? 'p'; // V1 key
        $a_key = $config['A_key'] ?? '';
        $m_key = $config['M_key'] ?? '';
        $t_key = $config['T_key'] ?? '';
        
        // V2 占位符 (P/A/M/T)
        $p_placeholder = 'P';
        $a_placeholder = 'A';
        $m_placeholder = 'M';
        $t_placeholder = 'T';

        $template_parts = [];
        $v2_mapping = [];
        
        if ($p_key) {
            $template_parts[] = "{$p_key}={{{$p_placeholder}}}";
            $v2_mapping['p'] = $p_placeholder;
        }
        if ($a_key) {
            $template_parts[] = "{$a_key}={{{$a_placeholder}}}";
            $v2_mapping['a'] = $a_placeholder;
        }
        if ($m_key) {
            $template_parts[] = "{$m_key}={{{$m_placeholder}}}";
            $v2_mapping['m'] = $m_placeholder;
        }
        if ($t_key) {
            $template_parts[] = "{$t_key}={{{$t_placeholder}}}";
            $v2_mapping['t'] = $t_placeholder;
        }

        $v2_config['template'] = '?' . implode('&', $template_parts);
        $v2_config['mapping'] = $v2_mapping;
    }
    
    $data['config'] = $v2_config;
    unset($data['config_json']);
    return $data;
}