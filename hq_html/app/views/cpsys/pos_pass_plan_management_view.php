<?php
/**
 * Toptea HQ - 次卡方案管理 (P)
 * Engineer: Gemini | Date: 2025-11-16
 */
 
// 辅助函数：查找标签 ID
function find_tag_id_by_code($code, $tags) {
    foreach ($tags as $tag) {
        if ($tag['tag_code'] === $code) return $tag['tag_id'];
    }
    return null;
}
// 获取关键标签 ID，用于简化表单逻辑
$tag_id_pass_product = find_tag_id_by_code('pass_product', $all_pos_tags);
$tag_id_eligible_bev = find_tag_id_by_code('pass_eligible_beverage', $all_pos_tags);
$tag_id_free_addon = find_tag_id_by_code('free_addon', $all_pos_tags);
$tag_id_paid_addon = find_tag_id_by_code('paid_addon', $all_pos_tags);

?>
<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" id="create-plan-btn" data-bs-toggle="offcanvas" data-bs-target="#plan-drawer">
        <i class="bi bi-plus-circle me-2"></i>创建新次卡方案
    </button>
</div>

<div class="card">
    <div class="card-header">
        次卡方案管理 (P)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>方案名称</th>
                        <th>总次数</th>
                        <th>有效期</th>
                        <th>限制 (单笔/单日)</th>
                        <th>销量</th>
                        <th>状态</th>
                        <th class="text-end">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pass_plans)): ?>
                        <tr><td colspan="7" class="text-center">暂无次卡方案。</td></tr>
                    <?php else: ?>
                        <?php foreach ($pass_plans as $plan): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($plan['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($plan['total_uses']); ?></td>
                                <td><?php echo htmlspecialchars($plan['validity_days']); ?> 天</td>
                                <td><?php echo htmlspecialchars($plan['max_uses_per_order']); ?> / <?php echo htmlspecialchars($plan['max_uses_per_day']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($plan['total_sold_count']); ?>
                                    <?php if ($plan['pending_review_count'] > 0): ?>
                                        <span class="badge text-bg-warning" title="待审核"><?php echo $plan['pending_review_count']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($plan['is_active']): ?>
                                        <span class="badge text-bg-success">可售卖</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">已下架</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary edit-plan-btn" data-id="<?php echo $plan['pass_plan_id']; ?>" data-bs-toggle="offcanvas" data-bs-target="#plan-drawer">
                                        配置
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger delete-plan-btn" data-id="<?php echo $plan['pass_plan_id']; ?>" data-name="<?php echo htmlspecialchars($plan['name']); ?>">
                                        删除
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="plan-drawer" aria-labelledby="drawer-label" style="width: 800px;">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="drawer-label">创建/编辑次卡方案</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <form id="plan-form">
            <input type="hidden" id="pass_plan_id" name="id">
            
            <input type="hidden" id="tag_id_pass_product" value="<?php echo $tag_id_pass_product; ?>">
            <input type="hidden" id="tag_id_eligible_bev" value="<?php echo $tag_id_eligible_bev; ?>">
            <input type="hidden" id="tag_id_free_addon" value="<?php echo $tag_id_free_addon; ?>">
            <input type="hidden" id="tag_id_paid_addon" value="<?php echo $tag_id_paid_addon; ?>">


            <div class="card mb-3">
                <div class="card-header">1. 方案详情 (定义次卡)</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="name_zh" class="form-label">方案名称 (中文) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name_zh" name="name_zh" placeholder="例如: 10次奶茶卡" required>
                    </div>
                    <div class="mb-3">
                        <label for="name_es" class="form-label">方案名称 (西班牙语) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name_es" name="name_es" placeholder="Ej: Tarjeta de 10 bebidas" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="total_uses" class="form-label">总可用次数 <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="total_uses" name="total_uses" value="10" required>
                        </div>
                        <div class="col-md-6">
                            <label for="validity_days" class="form-label">有效期 (天数) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="validity_days" name="validity_days" value="90" required>
                        </div>
                        <div class="col-md-6">
                            <label for="max_uses_per_order" class="form-label">单笔订单核销上限 <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="max_uses_per_order" name="max_uses_per_order" value="1" required>
                        </div>
                        <div class="col-md-6">
                            <label for="max_uses_per_day" class="form-label">单日核销上限 (0=不限) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="max_uses_per_day" name="max_uses_per_day" value="0" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">2. 销售设置 (定义售卖商品)</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="sale_sku" class="form-label">售卖 SKU (P-Code) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="sale_sku" name="sale_sku" placeholder="例如: PASS10" required>
                            <div class="form-text">用于关联POS商品的唯一编码。</div>
                        </div>
                        <div class="col-md-6">
                            <label for="sale_price" class="form-label">售卖价格 (€) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="sale_price" name="sale_price" step="0.01" placeholder="例如: 30.00" required>
                        </div>
                        <div class="col-md-12">
                             <label for="sale_category_id" class="form-label">显示在POS分类 <span class="text-danger">*</span></label>
                            <select class="form-select" id="sale_category_id" name="sale_category_id" required>
                                <option value="" selected disabled>-- 请选择分类 --</option>
                                <?php if (!empty($pos_categories)): ?>
                                    <?php foreach ($pos_categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['name_zh']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                     <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">激活此方案 (允许在POS上售卖)</label>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">3. 核销规则 (定义可兑换内容)</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="eligible_beverages" class="form-label">可核销的饮品 (多选)</label>
                        <select class="form-select" id="eligible_beverages" name="eligible_beverages" multiple size="8">
                            <?php if (!empty($all_menu_items)): ?>
                                <?php foreach ($all_menu_items as $item): ?>
                                    <option value="<?php echo $item['id']; ?>">
                                        [<?php echo htmlspecialchars($item['category_name_zh'] ?? 'N/A'); ?>] <?php echo htmlspecialchars($item['name_zh']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="free_addons" class="form-label">可免费的加料 (多选)</label>
                            <select class="form-select" id="free_addons" name="free_addons" multiple size="6">
                                <?php if (!empty($all_pos_addons)): ?>
                                    <?php foreach ($all_pos_addons as $addon): ?>
                                        <option value="<?php echo $addon['id']; ?>">
                                            <?php echo htmlspecialchars($addon['name_zh']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="paid_addons" class="form-label">需付费的加料 (多选)</label>
                            <select class="form-select" id="paid_addons" name="paid_addons" multiple size="6">
                                 <?php if (!empty($all_pos_addons)): ?>
                                    <?php foreach ($all_pos_addons as $addon): ?>
                                        <option value="<?php echo $addon['id']; ?>">
                                            <?php echo htmlspecialchars($addon['name_zh']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="d-flex justify-content-end mt-4">
                <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="offcanvas">取消</button>
                <button type="submit" class="btn btn-primary">保存方案</button>
            </div>
        </form>
    </div>
</div>