<?php
/**
 * KDS Repo C - Dashboard & Reports Helpers
 * 从原 kds_repo_c.php 中拆分出来的仪表盘 / 报表函数合集
 * 仅提供查询，不修改任何业务数据。
 */


/** ========== Dashboard KPIs（修正版） ========== */

if (!function_exists('getSeasonsPassDashboardKpis')) {
    function getSeasonsPassDashboardKpis(PDO $pdo): array {
        $now_utc_str = utc_now()->format('Y-m-d H:i:s');

        try {
            // 1) 活跃次卡
            $stmt_active = $pdo->prepare("
                SELECT COUNT(member_pass_id) 
                FROM member_passes 
                WHERE status = 'active' 
                  AND remaining_uses > 0
                  AND (expires_at IS NULL OR expires_at > ?)
            ");
            $stmt_active->execute([$now_utc_str]);
            $active_passes_count = (int)$stmt_active->fetchColumn();
        } catch (Exception $e) { $active_passes_count = 0; error_log("KPI Error (active_passes): ". $e->getMessage()); }

        try {
            // 2) 累计核销次数（按批次求和）
            $stmt_red = $pdo->query("SELECT COALESCE(SUM(redeemed_uses), 0) FROM pass_redemption_batches");
            $total_redemptions_count = (int)($stmt_red ? $stmt_red->fetchColumn() : 0);
        } catch (Exception $e) { $total_redemptions_count = 0; error_log("KPI Error (total_redemptions): ". $e->getMessage()); }

        try {
            // 3) 累计售卡金额（使用 review_status 口径）
            $stmt_sales = $pdo->query("
                SELECT COALESCE(SUM(amount_total), 0) 
                FROM topup_orders 
                WHERE review_status = 'confirmed'
            ");
            $total_sales_amount = (float)($stmt_sales ? $stmt_sales->fetchColumn() : 0);
        } catch (Exception $e) { $total_sales_amount = 0.0; error_log("KPI Error (total_sales): ". $e->getMessage()); }

        return [
            'active_passes_count'     => $active_passes_count,
            'total_redemptions_count' => $total_redemptions_count,
            'total_sales_amount'      => $total_sales_amount
        ];
    }
}

/** ========== Dashboard 示例 ========== */

// [GEMINI FIX] 确保此函数在 getTopSellingProductsToday 之前定义
if (!function_exists('getTodayTopItems')) {
    function getTodayTopItems(PDO $pdo, string $tzLocal = 'Europe/Madrid'): array {
        try {
            $tz = new DateTimeZone($tzLocal);
            $todayLocal = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
            $start_local = new DateTimeImmutable($todayLocal . ' 00:00:00', $tz);
            $end_local   = new DateTimeImmutable($todayLocal . ' 23:59:59', $tz);
            $utc = new DateTimeZone('UTC');
            $start = $start_local->setTimezone($utc)->format('Y-m-d H:i:s.u'); // 修复：使用 .u 匹配 issued_at(6)
            $end   = $end_local->setTimezone($utc)->format('Y-m-d H:i:s.u'); // 修复：使用 .u

            $sql = "
                SELECT 
                    pi.item_name_zh,
                    SUM(pi.quantity) AS total_quantity
                FROM pos_invoice_items pi
                JOIN pos_invoices p ON pi.invoice_id = p.id
                WHERE p.issued_at BETWEEN :start AND :end AND p.status = 'ISSUED'
                GROUP BY pi.item_name_zh
                ORDER BY total_quantity DESC
                LIMIT 5
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':start' => $start, ':end' => $end]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
             error_log("Error getTodayTopItems: " . $e->getMessage());
            return [];
        }
    }
}


// [GEMINI FATAL FIX - 2025-11-12] 
// 批量添加 index.php (Dashboard / Shift Review) 所需的缺失函数

if (!function_exists('getPendingShiftReviewCount')) {
    /**
     * [FIX] 获取待处理的强制关闭班次数 (用于侧边栏徽章)
     * (index.php:114)
     */
    function getPendingShiftReviewCount(PDO $pdo): int {
        try {
            $stmt = $pdo->query("SELECT COUNT(id) FROM pos_shifts WHERE status = 'FORCE_CLOSED' AND admin_reviewed = 0");
            return (int)($stmt ? $stmt->fetchColumn() : 0);
        } catch (PDOException $e) {
            error_log("Error getPendingShiftReviewCount: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('getPendingShiftReviews')) {
    /**
     * [FIX] 获取待处理的班次复核列表
     * (index.php:316, page=pos_shift_review)
     */
    function getPendingShiftReviews(PDO $pdo): array {
        try {
            // 查询基于 pos_shift_review_view.php 的显示需求
            $sql = "
                SELECT 
                    s.id, s.start_time, s.end_time, s.expected_cash,
                    st.store_name,
                    u.display_name AS user_name
                FROM pos_shifts s
                JOIN kds_stores st ON s.store_id = st.id
                JOIN kds_users u ON s.user_id = u.id
                WHERE s.status = 'FORCE_CLOSED' AND s.admin_reviewed = 0
                ORDER BY s.end_time ASC
            ";
            $stmt = $pdo->query($sql);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException $e) {
            error_log("Error getPendingShiftReviews: " . $e->getMessage());
            return [];
        }
    }
}


if (!function_exists('getDashboardKpis')) {
    /**
     * [FIX] 获取仪表盘KPI
     * (index.php:121, page=dashboard)
     */
    function getDashboardKpis(PDO $pdo, string $tzLocal = 'Europe/Madrid'): array {
        try {
            // 1. 获取本地化今天的 UTC 时间范围
            $tz = new DateTimeZone($tzLocal);
            $todayLocal = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
            $start_local = new DateTimeImmutable($todayLocal . ' 00:00:00', $tz);
            $end_local   = new DateTimeImmutable($todayLocal . ' 23:59:59', $tz);
            $utc = new DateTimeZone('UTC');
            $start_utc = $start_local->setTimezone($utc)->format('Y-m-d H:i:s.u'); // 使用 .u 匹配 issued_at(6)
            $end_utc   = $end_local->setTimezone($utc)->format('Y-m-d H:i:s.u');

            // 2. 查询今日销售额和订单数
            $sql_sales = "SELECT COALESCE(SUM(final_total), 0) AS total_sales, COUNT(id) AS total_orders
                          FROM pos_invoices
                          WHERE status = 'ISSUED' AND issued_at BETWEEN ? AND ?";
            $stmt_sales = $pdo->prepare($sql_sales);
            $stmt_sales->execute([$start_utc, $end_utc]);
            $sales_data = $stmt_sales->fetch(PDO::FETCH_ASSOC);

            // 3. 查询今日新增会员
            $sql_members = "SELECT COUNT(id) FROM pos_members WHERE created_at BETWEEN ? AND ?";
            $stmt_members = $pdo->prepare($sql_members);
            $stmt_members->execute([$start_utc, $end_utc]);
            $new_members = (int)$stmt_members->fetchColumn();
            
            // 4. 查询活跃门店数
            $active_stores = (int)$pdo->query("SELECT COUNT(id) FROM kds_stores WHERE is_active = 1 AND deleted_at IS NULL")->fetchColumn();

            return [
                'total_sales' => (float)($sales_data['total_sales'] ?? 0),
                'total_orders' => (int)($sales_data['total_orders'] ?? 0),
                'new_members' => $new_members,
                'active_stores' => $active_stores
            ];
        } catch (Exception $e) {
            error_log("Error getDashboardKpis: " . $e->getMessage());
            return ['total_sales' => 0, 'total_orders' => 0, 'new_members' => 0, 'active_stores' => 0];
        }
    }
}

if (!function_exists('getLowStockAlerts')) {
    /**
     * [FIX] 获取低库存预警
     * (index.php:122, page=dashboard)
     */
    function getLowStockAlerts(PDO $pdo, int $threshold = 10): array {
        try {
            $sql = "
                SELECT 
                    m.id AS material_id, 
                    mt.material_name, 
                    w.quantity, 
                    ut.unit_name AS base_unit_name
                FROM expsys_warehouse_stock w
                JOIN kds_materials m ON w.material_id = m.id
                JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
                JOIN kds_units u ON m.base_unit_id = u.id
                JOIN kds_unit_translations ut ON u.id = ut.unit_id AND ut.language_code = 'zh-CN'
                WHERE w.quantity < ? AND m.deleted_at IS NULL
                ORDER BY w.quantity ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$threshold]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getLowStockAlerts: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getSalesTrendLast7Days')) {
    /**
     * [FIX] 获取近7日销售趋势
     * (index.php:123, page=dashboard)
     */
    function getSalesTrendLast7Days(PDO $pdo, string $tzLocal = 'Europe/Madrid'): array {
        $labels = [];
        $data = [];
        try {
            $tz = new DateTimeZone($tzLocal);
            $utc = new DateTimeZone('UTC');
            
            for ($i = 6; $i >= 0; $i--) {
                $date = (new DateTimeImmutable("now", $tz))->sub(new DateInterval("P{$i}D"));
                $labels[] = $date->format('Y-m-d');
                
                $start_local = new DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00', $tz);
                $end_local   = new DateTimeImmutable($date->format('Y-m-d') . ' 23:59:59', $tz);
                $start_utc = $start_local->setTimezone($utc)->format('Y-m-d H:i:s.u');
                $end_utc   = $end_local->setTimezone($utc)->format('Y-m-d H:i:s.u');

                $sql = "SELECT COALESCE(SUM(final_total), 0) FROM pos_invoices 
                        WHERE status = 'ISSUED' AND issued_at BETWEEN ? AND ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$start_utc, $end_utc]);
                $data[] = (float)$stmt->fetchColumn();
            }
        } catch (Exception $e) {
            error_log("Error getSalesTrendLast7Days: " . $e->getMessage());
        }
        return ['labels' => $labels, 'data' => $data];
    }
}

if (!function_exists('getTopSellingProductsToday')) {
    /**
     * [FIX] 别名函数，用于修复 index.php:124 的函数名错误
     */
    function getTopSellingProductsToday(PDO $pdo, string $tzLocal = 'Europe/Madrid'): array {
        // index.php 错误地调用了 getTopSellingProductsToday
        // 实际在 kds_repo_c.php 中定义的函数是 getTodayTopItems
        return getTodayTopItems($pdo, $tzLocal);
    }
}

// --- (Fix 2: 补充其他缺失函数) ---

if (!function_exists('getWarehouseStock')) {
    /**
     * [FIX] 获取总仓库存 (index.php:439, page=warehouse_stock_management)
     */
    function getWarehouseStock(PDO $pdo): array {
        try {
            $sql = "
                SELECT 
                    m.id AS material_id, 
                    m.material_type,
                    mt.material_name, 
                    COALESCE(w.quantity, 0) AS quantity,
                    ut.unit_name AS base_unit_name
                FROM kds_materials m
                JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
                JOIN kds_units u ON m.base_unit_id = u.id
                JOIN kds_unit_translations ut ON u.id = ut.unit_id AND ut.language_code = 'zh-CN'
                LEFT JOIN expsys_warehouse_stock w ON m.id = w.material_id
                WHERE m.deleted_at IS NULL
                ORDER BY m.material_code ASC
            ";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getWarehouseStock: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getAllExpiryItems')) {
    /**
     * [FIX] 获取效期物品 (index.php:455, page=expiry_management)
     */
    function getAllExpiryItems(PDO $pdo): array {
        try {
            $sql = "
                SELECT 
                    e.*,
                    s.store_name,
                    mt.material_name,
                    u.display_name AS handler_name
                FROM kds_material_expiries e
                JOIN kds_stores s ON e.store_id = s.id
                JOIN kds_materials m ON e.material_id = m.id
                JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
                LEFT JOIN kds_users u ON e.handler_id = u.id
                ORDER BY e.status ASC, e.expires_at ASC
            ";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getAllExpiryItems: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getAllInvoices')) {
    /**
     * [FIX] 获取票据列表 (index.php:375, page=pos_invoice_list)
     */
    function getAllInvoices(PDO $pdo): array {
        try {
            $sql = "
                SELECT 
                    i.id, i.series, i.number, i.issued_at, i.final_total, 
                    i.status, i.compliance_system,
                    s.store_name
                FROM pos_invoices i
                LEFT JOIN kds_stores s ON i.store_id = s.id
                ORDER BY i.issued_at DESC
                LIMIT 500
            ";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getAllInvoices: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getInvoiceDetails')) {
    /**
     * [FIX] 获取单张票据详情 (index.php:387, page=pos_invoice_detail)
     */
    function getInvoiceDetails(PDO $pdo, int $invoice_id): ?array {
        try {
            $sql_main = "
                SELECT i.*, s.store_name, u.display_name AS cashier_name
                FROM pos_invoices i
                JOIN kds_stores s ON i.store_id = s.id
                LEFT JOIN kds_users u ON i.user_id = u.id
                WHERE i.id = ?
            ";
            $stmt_main = $pdo->prepare($sql_main);
            $stmt_main->execute([$invoice_id]);
            $invoice = $stmt_main->fetch(PDO::FETCH_ASSOC);

            if ($invoice) {
                // 解码 JSON 字段
                $invoice['compliance_data_decoded'] = json_decode($invoice['compliance_data'] ?? '{}', true);
                $invoice['payment_summary_decoded'] = json_decode($invoice['payment_summary'] ?? '{}', true);
                
                // 获取 items
                $sql_items = "SELECT * FROM pos_invoice_items WHERE invoice_id = ?";
                $stmt_items = $pdo->prepare($sql_items);
                $stmt_items->execute([$invoice_id]);
                $invoice['items'] = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
            }
            return $invoice;
        } catch (PDOException $e) {
            error_log("Error getInvoiceDetails: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('getAllEodReports')) {
    /**
     * [FIX] 获取 EOD 报告 (index.php:406, page=pos_eod_reports)
     */
    function getAllEodReports(PDO $pdo): array {
        try {
            $sql = "
                SELECT 
                    r.*,
                    s.store_name,
                    u.display_name AS user_name
                FROM pos_eod_reports r
                JOIN kds_stores s ON r.store_id = s.id
                LEFT JOIN kds_users u ON r.user_id = u.id
                ORDER BY r.report_date DESC, r.executed_at DESC
            ";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getAllEodReports: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getAllPosAddons')) {
    /**
     * [FIX] 别名函数 (index.php:274, page=pos_addon_management)
     * kds_repo_c.php 中定义的是 getAllAddons
     */
    function getAllPosAddons(PDO $pdo): array {
        try {
            // 视图 (pos_addon_management_view.php) 需要 material_name_zh
            $sql = "
                SELECT 
                    a.*,
                    mt.material_name AS material_name_zh
                FROM pos_addons a
                LEFT JOIN kds_materials m ON a.material_id = m.id
                LEFT JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
                WHERE a.deleted_at IS NULL
                ORDER BY a.sort_order ASC, a.id ASC
            ";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getAllPosAddons: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getAllStoreStock')) {
    /**
     * [FIX] 获取所有门店库存 (index.php:447, page=store_stock_view)
     */
    function getAllStoreStock(PDO $pdo): array {
        $grouped_stock = [];
        try {
            $sql = "
                SELECT 
                    s.store_name,
                    mt.material_name,
                    COALESCE(ss.quantity, 0) AS quantity,
                    ut.unit_name AS base_unit_name
                FROM expsys_store_stock ss
                JOIN kds_stores s ON ss.store_id = s.id
                JOIN kds_materials m ON ss.material_id = m.id
                JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
                JOIN kds_units u ON m.base_unit_id = u.id
                JOIN kds_unit_translations ut ON u.id = ut.unit_id AND ut.language_code = 'zh-CN'
                WHERE m.deleted_at IS NULL AND ss.quantity != 0
                ORDER BY s.store_name ASC, mt.material_name ASC
            ";
            $stmt = $pdo->query($sql);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $grouped_stock[$row['store_name']][] = $row;
            }
        } catch (PDOException $e) {
            error_log("Error getAllStoreStock: " . $e->getMessage());
        }
        return $grouped_stock;
    }
}

if (!function_exists('getAllPassPlans')) {
    /**
     * [FIX] 获取所有次卡方案 (P 阶段)
     * (index.php: page=pos_pass_plan_management)
     */
    function getAllPassPlans(PDO $pdo): array {
        try {
            $sql = "
                SELECT 
                    pp.*,
                    COUNT(tpo.topup_order_id) AS total_sold_count,
                    COUNT(CASE WHEN tpo.review_status = 'pending' THEN 1 END) AS pending_review_count
					FROM pass_plans pp
                    LEFT JOIN topup_orders tpo ON pp.pass_plan_id = tpo.pass_plan_id
                    GROUP BY pp.pass_plan_id
                    ORDER BY pp.name ASC
            ";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getAllPassPlans: " . $e->getMessage());
            return [];
        }
    }
}