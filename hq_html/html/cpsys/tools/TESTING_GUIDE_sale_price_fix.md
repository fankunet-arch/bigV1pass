# TopTea 次卡售价修复 - 测试验证指南

**修复阶段**: POS-CPSYS-PASS-VR-PRICE-MINI
**修复日期**: 2025-11-21
**修复范围**: CPSYS 次卡方案保存逻辑 + 历史数据回填

---

## 一、修复内容概述

### 问题根因
在修复前，CPSYS 保存次卡方案时：
- ✗ **只写入** `pos_item_variants.price_eur`（影子商品价格）
- ✗ **未写入** `pass_plans.sale_price`（方案售价）
- ✗ 导致 `pass_plans.sale_price` 保持默认值 `0.00`

### 修复内容
1. **代码修复**: 修改 `handle_pass_plan_save()` 函数，确保同时写入两个价格字段
2. **数据修复**: 提供脚本回填历史数据（从 variant 价格回填到 plan 价格）

### 预期效果
修复后，价格链路应该是：
```
CPSYS 后台配置价格 60.00€
    ↓ (保存)
pass_plans.sale_price = 60.00
    ↓ (POS 读取)
POS 优惠中心显示 60.00€
    ↓ (售卡)
topup_orders.amount_total = 60.00
```

---

## 二、测试前准备

### 2.1 环境要求
- 测试环境数据库（避免影响生产）
- 有效的 CPSYS 管理员账号
- 有效的 POS 测试账号
- 数据库访问权限（用于验证数据）

### 2.2 数据库备份
```bash
# 在测试环境执行前，建议备份相关表
mysqldump -u [user] -p [database] pass_plans pos_menu_items pos_item_variants topup_orders > backup_before_fix_$(date +%Y%m%d_%H%M%S).sql
```

---

## 三、完整测试流程

### 测试 A：新建次卡方案（验证代码修复）

#### A1. CPSYS 后台保存价格
1. 登录 CPSYS：`https://hqv3.toptea.es/cpsys/index.php`
2. 导航到：**商品管理 → 次卡方案管理**（或类似菜单）
3. 点击 **"新增次卡方案"**
4. 填写表单：
   ```
   方案名称（中文）: 测试10次卡
   方案名称（西语）: Pase de prueba 10
   售卖 SKU: PASS-TEST-10
   销售价格: 60.00 €
   总次数: 10
   有效期: 180 天
   其他字段: 按需填写
   ```
5. 点击 **"保存"**，确认保存成功

#### A2. 数据库验证
```sql
-- 查询刚创建的方案
SELECT
    pass_plan_id,
    name,
    sale_sku,
    sale_price,  -- 应该是 60.00
    created_at
FROM pass_plans
WHERE sale_sku = 'PASS-TEST-10';

-- 查询关联的商品价格
SELECT
    mi.id,
    mi.product_code,
    mi.name_zh,
    v.price_eur,  -- 应该也是 60.00
    v.variant_name_zh
FROM pos_menu_items mi
JOIN pos_item_variants v ON mi.id = v.menu_item_id
WHERE mi.product_code = 'PASS-TEST-10'
  AND mi.deleted_at IS NULL
  AND v.deleted_at IS NULL;
```

**预期结果**:
- `pass_plans.sale_price` = `60.00`
- `pos_item_variants.price_eur` = `60.00`
- 两者**完全一致**

---

### 测试 B：编辑现有次卡方案（验证代码修复）

#### B1. CPSYS 后台修改价格
1. 在 CPSYS 次卡方案管理中，选择一个现有方案
2. 点击 **"编辑"**
3. 将 **"销售价格"** 改为 `75.00` €
4. 点击 **"保存"**

#### B2. 数据库验证
```sql
-- 查询修改后的方案（替换为实际的 pass_plan_id）
SELECT
    pass_plan_id,
    name,
    sale_price,  -- 应该更新为 75.00
    updated_at
FROM pass_plans
WHERE pass_plan_id = [实际ID];
```

**预期结果**:
- `pass_plans.sale_price` 已更新为 `75.00`

---

### 测试 C：POS 展示价格（验证读取逻辑）

#### C1. POS 优惠中心查看
1. 登录 POS：`https://storev3.toptea.es/pos/index.php`
2. 点击 **"优惠中心"** 或 **"购买次卡"** 按钮
3. 查看次卡列表，找到测试方案 **"测试10次卡"**

**预期结果**:
- 列表显示价格为 **60.00€**（与后台配置一致）
- 如果之前显示 0.00€，现在应该显示正确价格

#### C2. 浏览器开发者工具验证
打开浏览器 DevTools（F12），查看网络请求：
```json
// POS 获取次卡列表的响应示例
{
  "data": [
    {
      "pass_plan_id": 1,
      "name": "测试10次卡",
      "sale_price": 60.00,  // ← 确认此字段为 60.00
      "total_uses": 10,
      ...
    }
  ]
}
```

---

### 测试 D：POS 售卡与 VR 订单（验证完整链路）

#### D1. POS 购买次卡
1. 在 POS 中，点击 **"购买次卡"**
2. 选择 **"测试10次卡"**，数量 `1`
3. 点击 **"添加到购物车"**
4. 查看购物车：
   - 商品名称：测试10次卡
   - 单价：**60.00€**
   - 总计：**60.00€**
5. 选择支付方式（如"现金"），完成支付
6. 记录生成的订单编号（如 `T20251121001`）

#### D2. 数据库验证 - VR 订单金额
```sql
-- 查询刚创建的 VR 订单（根据订单号或时间查询）
SELECT
    order_id,
    member_id,
    pass_plan_id,
    quantity,
    amount_total,  -- 应该是 60.00
    order_status,
    created_at
FROM topup_orders
WHERE order_id = [实际订单ID]
   OR created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE);

-- 查询关联的持卡记录
SELECT
    member_pass_id,
    member_id,
    pass_plan_id,
    purchase_order_id,
    total_uses,
    remaining_uses,
    status
FROM member_passes
WHERE purchase_order_id = [实际订单ID];
```

**预期结果**:
- `topup_orders.amount_total` = `60.00`（与配置价格一致）
- `member_passes` 记录已创建，剩余次数 = 总次数

#### D3. CPSYS 后台验证 - Topup Orders 列表
1. 登录 CPSYS
2. 导航到：**订单管理 → Topup Orders**（或类似菜单）
3. 找到刚才的订单

**预期结果**:
- "总金额 (€)" 列显示为 **60.00**（不再是 0.00）

---

### 测试 E：历史数据修复（验证 Data Patch）

#### E1. 执行数据修复脚本
```bash
# 进入 CPSYS 工具目录
cd /hq_html/html/cpsys/tools/

# 执行修复脚本
php fix_pass_plans_sale_price.php
```

#### E2. 查看脚本输出
脚本会显示：
1. **统计信息**：需要修复的记录数
2. **预览列表**：将要修复的方案详情（Plan ID、名称、当前价格、修复后价格）
3. **执行结果**：受影响的行数
4. **验证结果**：是否还有未修复的记录

**预期输出示例**:
```
=== TopTea Pass Plans Sale Price Fix Tool ===
开始时间: 2025-11-21 10:30:00

[步骤 1] 统计需要修复的记录...
需要修复的记录数: 5

[步骤 2] 预览将要修复的记录:
----------------------------------------------------------------------------------------------------
Plan ID  Plan Name                      Sale SKU        当前价格        修复后价格
----------------------------------------------------------------------------------------------------
1        10次畅饮卡                      PASS-10         0.00            50.00
2        20次尊享卡                      PASS-20         0.00            90.00
...
----------------------------------------------------------------------------------------------------

[步骤 3] 执行数据修复...
✓ 修复完成！受影响的行数: 5

[步骤 4] 验证修复结果...
✓ 所有次卡方案的 sale_price 已正确设置！

结束时间: 2025-11-21 10:30:05
=== 修复完成 ===
```

#### E3. 数据库验证 - 修复后的数据
```sql
-- 查询所有次卡方案的价格
SELECT
    pp.pass_plan_id,
    pp.name,
    pp.sale_sku,
    pp.sale_price AS plan_price,
    v.price_eur AS variant_price,
    (pp.sale_price = v.price_eur) AS prices_match
FROM pass_plans pp
LEFT JOIN pos_menu_items mi ON pp.sale_sku = mi.product_code AND mi.deleted_at IS NULL
LEFT JOIN pos_item_variants v ON mi.id = v.menu_item_id AND v.deleted_at IS NULL
WHERE pp.sale_sku IS NOT NULL
ORDER BY pp.pass_plan_id;
```

**预期结果**:
- 所有记录的 `plan_price` 和 `variant_price` 应该一致
- `prices_match` 列应该全部为 `1`（TRUE）
- 不应该再有 `sale_price = 0.00` 且 `variant_price > 0.00` 的情况

#### E4. 幂等性测试
再次执行修复脚本：
```bash
php fix_pass_plans_sale_price.php
```

**预期结果**:
```
需要修复的记录数: 0
✓ 没有需要修复的记录，所有数据已正确。
```

---

## 四、回归测试（确保没有破坏现有功能）

### 4.1 次卡核销功能
1. 在 POS 中使用刚购买的次卡核销一杯饮品
2. 验证核销成功，剩余次数 -1
3. 查看订单，确认核销记录正确

### 4.2 次卡方案停用/启用
1. 在 CPSYS 中将某个方案设为"停用"
2. 在 POS 中刷新，确认该方案不再显示
3. 重新启用，确认重新显示

### 4.3 次卡标签规则
1. 验证"可核销饮品"标签仍然生效
2. 验证"免费加料"和"付费加料"规则正常

---

## 五、验证清单（Checklist）

打印此清单并逐项验证：

- [ ] **A1**: CPSYS 新建方案，sale_price 正确写入
- [ ] **A2**: 数据库中 pass_plans.sale_price = 配置价格
- [ ] **B1**: CPSYS 编辑方案，sale_price 正确更新
- [ ] **B2**: 数据库中 sale_price 已更新
- [ ] **C1**: POS 优惠中心显示正确价格
- [ ] **C2**: API 响应中 sale_price 字段正确
- [ ] **D1**: POS 购物车显示正确价格
- [ ] **D2**: VR 订单 amount_total = 售价 × 数量
- [ ] **D3**: CPSYS Topup Orders 列表显示正确金额
- [ ] **E1**: 数据修复脚本执行成功
- [ ] **E2**: 脚本输出统计信息合理
- [ ] **E3**: 历史数据 sale_price 已回填
- [ ] **E4**: 脚本幂等性验证通过
- [ ] **回归**: 次卡核销功能正常
- [ ] **回归**: 方案启用/停用功能正常
- [ ] **回归**: 标签规则功能正常

---

## 六、常见问题（FAQ）

### Q1: 修复后，POS 仍然显示 0.00€？
**可能原因**:
1. 浏览器缓存 - 强制刷新（Ctrl+F5）
2. POS 后端缓存 - 清理缓存或重启服务
3. 数据库连接未刷新 - 检查数据库连接池

**排查步骤**:
```sql
-- 直接查询数据库，确认价格已更新
SELECT pass_plan_id, name, sale_price FROM pass_plans WHERE pass_plan_id = [ID];
```

### Q2: 数据修复脚本报错找不到数据库配置？
**解决方案**:
检查脚本中的配置文件路径：
```php
require_once __DIR__ . '/../../config/database.php';
```
根据实际项目结构调整路径。

### Q3: 部分方案修复后仍然是 0.00？
**可能原因**:
1. 对应的 pos_item_variants.price_eur 也是 0.00
2. 商品或规格已被软删除
3. sale_sku 未关联到有效商品

**处理方式**:
脚本会输出这些记录，需要手动检查并决定处理方式。

### Q4: 修复后如何验证生产环境？
**建议流程**:
1. 在测试环境完整验证通过
2. 在生产环境低峰期执行
3. 先执行脚本查看预览（不实际修改）
4. 确认无误后再执行修改
5. 立即执行测试 C 和 D，验证线上功能

---

## 七、回滚方案（如果出现问题）

### 回滚数据
```bash
# 使用备份恢复（替换为实际备份文件名）
mysql -u [user] -p [database] < backup_before_fix_20251121_103000.sql
```

### 回滚代码
```bash
# 切换到修复前的 commit
git checkout [previous_commit_hash]

# 或者撤销修复提交
git revert [fix_commit_hash]
```

---

## 八、联系支持

如果测试过程中遇到问题，请联系：
- **开发团队**: TopTea Backend Team
- **问题追踪**: 标注为 `POS-CPSYS-PASS-VR-PRICE-MINI`

---

**文档版本**: v1.0
**最后更新**: 2025-11-21
