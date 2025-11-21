# CPSYS 已知问题与技术债清单（TopTea 次卡相关）

说明：
- 本文记录总部系统（CPSYS/BMS）中与「次卡/会员资产/营销」相关的已知问题与技术债。
- 每条问题包含：编号、类型、模块、描述、影响、建议修复阶段与当前状态。
- 编号规则：`ISSUE-CPSYS-<类别>-<序号>`，以及少量架构备注 `ARCH-CPSYS-...`。

---

## 1. 优惠券体系相关

### ISSUE-CPSYS-FEATURE-001：优惠券模型（pos_coupons 系列）的未来规划未定

- 类型：功能规划 / 架构决策
- 模块：CPSYS · 营销 / 优惠券系统
- 描述：
  - 数据库中存在完整的优惠券模型：
    - `pos_coupons`：定义优惠券规则；
    - `pos_member_issued_coupons`：发放给具体会员的券实例。
  - 当前 CPSYS/BMS 代码对这两张表的直接 CRUD 支持有限，更多通过 `pos_promotions` 等机制配置活动；
  - 在「次卡」新线落地后，优惠券体系与次卡、积分并行存在，但缺少统一规划文档和清晰边界。

- 影响：
  - 对现有次卡功能无直接影响；
  - 若未来希望加强“个人券 / 精准营销”，需要明确优惠券体系与次卡、积分的职责划分与协同策略。

- 建议修复/规划阶段：
  - 在后续“营销/优惠券系统增强”阶段统一评估：
    - 是否继续投资优惠券能力（通用券 + 个人券）；
    - 是否和次卡、积分整合为统一的会员权益架构。

- 当前状态：记录中（等待业务/产品侧决策）。

---

## 2. EOD / 日结相关

### ISSUE-CPSYS-CODE-001：对 `pos_eod_records` 的过度防御性写法

- 类型：代码风格 / 可维护性
- 模块：CPSYS · 报表 / 日结 EOD 相关
- 描述：
  - 部分代码在更新 `pos_eod_records` 时使用 try-catch 包裹，以防表不存在；
  - 经 Schema 审计确认该表存在且为正式结构；
  - 这样的防御逻辑在当前阶段已经意义不大，反而会掩盖真实错误来源。

- 影响：
  - 不影响当前功能运行，但增加阅读和排错成本；
  - 若 future 出现结构问题，错误可能被吞掉，降低可观测性。

- 建议修复阶段：
  - 在后续统一整理 “EOD / 日结 / 对账” 模块时：
    - 视情况移除多余 try-catch；
    - 统一采用清晰的错误上报与日志策略。

- 当前状态：记录中（低优先级，不阻塞次卡上线）。

---

## 3. 架构备注：旧表与幽灵表的判断基准（CPSYS 视角）

### ARCH-CPSYS-LEGACY-001：旧表不一定有 `old_` 前缀，需结合使用情况与三线审计结果判断

- 描述：
  - 在本项目中，“旧表（Legacy Table）”的判断标准不是表名带不带 `old_`，而是：
    - 表结构存在于 Schema；
    - 在 POS / CPSYS / KDS 三条主线代码中都已不再引用；
    - 或仅存在极少历史残留、且已确认不再走正式链路。
  - 许多旧表在命名上与新风格一致，只是在链路升级后被新表取代但暂未删除。

- 审计策略：
  - 结合：
    - L4-2A（Schema 粗筛）：给出候选表清单；
    - L4-2B-POS / L4-2B-CPSYS / L4-2B-KDS：确认实际引用情况；
  - 得出“Schema 有但三线代码均不再使用”的表清单，再按照“改名 / 降权 / 观察期 / 最终删除”的节奏处理。

- 当前状态：
  - CPSYS 线 L4-2B 已确认：未发现仍在使用的旧线次卡表；
  - 全系统旧表清单将在 KDS 线 L4-2B 完成后统一整理。

## 4. 次卡售价写入问题

### ISSUE-CPSYS-PASS-PRICE-001：次卡方案售价未写入 pass_plans.sale_price（导致 POS 与 VR 金额为 0）

- 类型：跨系统逻辑错误 / 严重业务问题  
- 模块：CPSYS · 次卡方案管理（BMS 后台）  

- 描述：  
  - 修复前：  
    - 「次卡方案管理」里输入的“销售价格 (€)”只写入 `pos_item_variants.price_eur`；  
    - `pass_plans.sale_price` 永远保持 0.00；  
    - POS 读取 `pass_plans.sale_price` → 展示 0.00；  
    - POS 售卡时将 0 写入 `topup_orders.amount_total`。  

- 根因：  
  - `hq_html/html/cpsys/api/registries/cpsys_registry_bms_pass_plan.php` 的 `handle_pass_plan_save` 函数在构建 SQL 时完全漏掉了 `sale_price` 字段。  

- 修复措施：  
  1）写入逻辑修复  
     - 在 `$plan_params` 中新增 `:sale_price`；  
     - 在 `INSERT` / `UPDATE pass_plans` 语句中补充 `sale_price = :sale_price`；  
     - 同时保持 `pos_item_variants.price_eur` 更新，确保两者价格一致。  

  2）历史数据回填（Data Patch）  
     - 新增工具脚本：`hq_html/html/cpsys/tools/fix_pass_plans_sale_price.php`；  
     - 基于 `sale_sku` 关联 `pos_menu_items` + `pos_item_variants`，把历史上 `sale_price=0` 且 `variant.price_eur>0` 的方案回填为正确价格；  
     - 幂等设计，可多次执行；附详细测试与验证步骤。:contentReference[oaicite:7]{index=7}  

  3）测试验证  
     - 测试指导文档：`hq_html/html/cpsys/tools/TESTING_GUIDE_sale_price_fix.md`；  
     - 已在测试环境按指南完成：  
       - 新建/修改方案 → DB sale_price 正确；  
       - POS 优惠中心展示正确价格；  
       - VR 订单金额正确；  
       - Data Patch 正常工作且幂等。  

- 当前状态：  
  - ✅ 代码修复：已合入分支 `claude/fix-toptea-pass-pricing-01PwzkxTUjWNz6r3PTh2SNtC`（Commit: e016362）。  
  - ✅ 测试环境数据已回填并验证通过。  
  - ⏳ 生产环境待执行 Data Patch 与最终验证。  

- 与 POS 线 issue 的关系：  
  - 与 `ISSUE-POS-PASS-PRICE-001` 属于同一问题的总部侧根因；  
  - 修复顺序：先修 CPSYS 写入 + 数据回填，再由 POS 前端重新读取 sale_price。  
