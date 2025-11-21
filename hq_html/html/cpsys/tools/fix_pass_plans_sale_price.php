<?php
/**
 * Data Patch: 修复 pass_plans.sale_price 历史数据
 *
 * 问题背景：
 * - 在 POS-CPSYS-PASS-VR-PRICE-MINI 修复前，CPSYS 保存次卡方案时只写入了
 *   pos_item_variants.price_eur，没有写入 pass_plans.sale_price
 * - 导致 pass_plans.sale_price 保持默认值 0.00
 * - POS 读取 sale_price 展示 0.00，VR 订单金额也为 0.00
 *
 * 修复策略：
 * - 将 pass_plans.sale_price = 0.00 的记录，根据其 sale_sku 对应的
 *   pos_item_variants.price_eur 进行回填
 * - 只更新满足以下条件的记录：
 *   1. pass_plans.sale_price = 0.00
 *   2. 对应的 pos_item_variants.price_eur > 0.00
 *   3. 对应的商品和规格未删除
 *
 * 使用方法：
 * 1. 在测试环境执行：php fix_pass_plans_sale_price.php
 * 2. 检查输出的统计信息
 * 3. 在数据库中验证结果
 * 4. 确认无误后在生产环境执行
 *
 * 幂等性：可重复执行，不会修改已正确的数据
 *
 * @author TopTea Backend Team
 * @date 2025-11-21
 * @ticket POS-CPSYS-PASS-VR-PRICE-MINI
 */

// 引入数据库配置
require_once __DIR__ . '/../../config/database.php';

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "\n=== TopTea Pass Plans Sale Price Fix Tool ===\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 获取数据库连接
    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 步骤 1: 统计需要修复的记录数
    echo "[步骤 1] 统计需要修复的记录...\n";

    $sql_count = "
        SELECT COUNT(*) as total
        FROM pass_plans pp
        JOIN pos_menu_items mi ON pp.sale_sku = mi.product_code
        JOIN pos_item_variants v ON mi.id = v.menu_item_id
        WHERE pp.sale_price = 0.00
          AND v.price_eur > 0.00
          AND mi.deleted_at IS NULL
          AND v.deleted_at IS NULL
    ";

    $stmt_count = $pdo->query($sql_count);
    $count_result = $stmt_count->fetch(PDO::FETCH_ASSOC);
    $total_to_fix = $count_result['total'];

    echo "需要修复的记录数: {$total_to_fix}\n\n";

    if ($total_to_fix == 0) {
        echo "✓ 没有需要修复的记录，所有数据已正确。\n";
        exit(0);
    }

    // 步骤 2: 显示将要修复的记录详情
    echo "[步骤 2] 预览将要修复的记录:\n";
    echo str_repeat('-', 100) . "\n";
    printf("%-8s %-30s %-15s %-15s %-15s\n",
        "Plan ID", "Plan Name", "Sale SKU", "当前价格", "修复后价格");
    echo str_repeat('-', 100) . "\n";

    $sql_preview = "
        SELECT
            pp.pass_plan_id,
            pp.name,
            pp.sale_sku,
            pp.sale_price as current_price,
            v.price_eur as new_price
        FROM pass_plans pp
        JOIN pos_menu_items mi ON pp.sale_sku = mi.product_code
        JOIN pos_item_variants v ON mi.id = v.menu_item_id
        WHERE pp.sale_price = 0.00
          AND v.price_eur > 0.00
          AND mi.deleted_at IS NULL
          AND v.deleted_at IS NULL
        ORDER BY pp.pass_plan_id
    ";

    $stmt_preview = $pdo->query($sql_preview);
    $records = $stmt_preview->fetchAll(PDO::FETCH_ASSOC);

    foreach ($records as $record) {
        printf("%-8d %-30s %-15s %-15.2f %-15.2f\n",
            $record['pass_plan_id'],
            mb_substr($record['name'], 0, 28),
            $record['sale_sku'],
            $record['current_price'],
            $record['new_price']
        );
    }

    echo str_repeat('-', 100) . "\n\n";

    // 步骤 3: 执行修复
    echo "[步骤 3] 执行数据修复...\n";

    // 开始事务
    $pdo->beginTransaction();

    try {
        $sql_update = "
            UPDATE pass_plans pp
            JOIN pos_menu_items mi ON pp.sale_sku = mi.product_code
            JOIN pos_item_variants v ON mi.id = v.menu_item_id
            SET pp.sale_price = v.price_eur
            WHERE pp.sale_price = 0.00
              AND v.price_eur > 0.00
              AND mi.deleted_at IS NULL
              AND v.deleted_at IS NULL
        ";

        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute();
        $affected_rows = $stmt_update->rowCount();

        // 提交事务
        $pdo->commit();

        echo "✓ 修复完成！受影响的行数: {$affected_rows}\n\n";

        // 步骤 4: 验证修复结果
        echo "[步骤 4] 验证修复结果...\n";

        $sql_verify = "
            SELECT COUNT(*) as remaining
            FROM pass_plans pp
            WHERE pp.sale_price = 0.00
              AND pp.sale_sku IS NOT NULL
              AND pp.sale_sku != ''
        ";

        $stmt_verify = $pdo->query($sql_verify);
        $verify_result = $stmt_verify->fetch(PDO::FETCH_ASSOC);
        $remaining = $verify_result['remaining'];

        if ($remaining > 0) {
            echo "⚠ 警告: 仍有 {$remaining} 条记录的 sale_price 为 0.00\n";
            echo "   这些记录可能是：\n";
            echo "   - 关联的 pos_item_variants.price_eur 也是 0.00\n";
            echo "   - 对应的商品或规格已被删除\n";
            echo "   - sale_sku 未关联到有效的商品\n";
            echo "   请手动检查这些记录。\n\n";

            // 显示这些记录
            $sql_remaining = "
                SELECT
                    pp.pass_plan_id,
                    pp.name,
                    pp.sale_sku,
                    pp.sale_price
                FROM pass_plans pp
                WHERE pp.sale_price = 0.00
                  AND pp.sale_sku IS NOT NULL
                  AND pp.sale_sku != ''
                ORDER BY pp.pass_plan_id
            ";

            $stmt_remaining = $pdo->query($sql_remaining);
            $remaining_records = $stmt_remaining->fetchAll(PDO::FETCH_ASSOC);

            echo str_repeat('-', 80) . "\n";
            printf("%-8s %-40s %-20s\n", "Plan ID", "Plan Name", "Sale SKU");
            echo str_repeat('-', 80) . "\n";

            foreach ($remaining_records as $record) {
                printf("%-8d %-40s %-20s\n",
                    $record['pass_plan_id'],
                    mb_substr($record['name'], 0, 38),
                    $record['sale_sku']
                );
            }
            echo str_repeat('-', 80) . "\n\n";
        } else {
            echo "✓ 所有次卡方案的 sale_price 已正确设置！\n\n";
        }

    } catch (Exception $e) {
        // 回滚事务
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    echo "结束时间: " . date('Y-m-d H:i:s') . "\n";
    echo "=== 修复完成 ===\n\n";

} catch (Exception $e) {
    echo "\n[错误] 执行失败: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

exit(0);
