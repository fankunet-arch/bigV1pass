# POS 已知问题与技术债清单（TopTea 次卡相关）

说明：
- 本文仅记录 POS 线中的已知问题与技术债，方便后续阶段性集中处理。
- 每条问题包含：编号、类型、模块、描述、影响、临时措施、计划修复阶段与当前状态。
- 编号规则：`ISSUE-POS-<类别>-<序号>`，以及少量架构备注 `ARCH-POS-...`。

---

## 1. 打印相关问题

### ISSUE-POS-PRINT-001：打印模板中商品字段命名不统一

- 类型：一致性问题 / 潜在显示错误
- 模块：POS · 打印（小票 / 杯贴）
- 描述：
  - 杯贴（Cup Sticker）模板中使用占位符 `{item_name}` 表示商品名称；
  - 次卡核销小票（`PASS_REDEMPTION_SLIP`）模板中使用 `{title}` / `{variant_name}` / `{qty}`；
  - 不同模板中对“商品名称”的字段命名不统一，未来新增模板时容易误抄 `{item_name}`，导致渲染结果为空。
- 影响：
  - 当前版本中，`PASS_REDEMPTION_SLIP` 模板已确认使用 `{title}` 等字段，短期内不会影响生产；
  - 但模板设计缺乏统一规范，新模板或后续修改存在踩坑风险。
- 临时措施：
  - 本次次卡核销小票模板中，仅使用 `{title}`, `{variant_name}`, `{qty}` 不使用 `{item_name}`；
  - 在设计打印模板时，优先参考新线模板字段命名，不再沿用旧的 `{item_name}` 写法。
- 计划修复阶段：
  - 建议在后续“打印系统规范化阶段”（例如 P4.x 或专门的 PRINT 规范任务）统一：
    - 定义所有打印任务通用的字段命名规范；
    - 对现有模板字段进行一次体检（必要时重构）。
- 当前状态：记录中（未统一规范，仅局部规避）。

---

## 2. 数据库 / 日结（EOD）相关问题

### ISSUE-POS-DB-001：calculate_eod_totals 引用不存在的 `pos_invoice_payments` 表

- 类型：技术债 / 潜在运行时错误（幽灵表引用）
- 模块：POS · 后端 EOD（日结）辅助函数
- 位置（参考）：`pos_backend/helpers/pos_helper.php` 中的 `calculate_eod_totals` 函数
- 描述：
  - 函数 `calculate_eod_totals` 中原本存在 SQL，引用了表 `pos_invoice_payments`；
  - 在当前 Schema（`db_schema_structure_only.sql`）中，**不存在** `pos_invoice_payments` 这张表；
  - EOD 正式逻辑已经在 `pos_repo.php::getInvoiceSummaryForPeriod()` 中实现，基于 `pos_invoices.payment_summary` 字段；
  - `calculate_eod_totals` 确认属于旧实现残留的“幽灵函数”。

- 影响：
  - 修复前：若该函数被旧接口或未来误调用，会因访问幽灵表导致 SQL 错误（500）；
  - 容易误导后续维护者，以为系统存在 `pos_invoice_payments` 这张表。

- 修复方式（2025-11-21）：
  - 分支：`claude/secure-eod-module-01ACdts5h7P4fHesgdvk757r`
  - Commit：`c454508 - [SECURITY] Deprecate calculate_eod_totals`
  - 具体改动：
    - 保留 `calculate_eod_totals` 函数签名；
    - 在函数开头抛出明确的废弃异常（包含 "DEPRECATED" 关键字，并指向 `pos_repo::getInvoiceSummaryForPeriod()` 作为替代）；
    - 删除所有访问 `pos_invoice_payments` 的 SQL 逻辑；
    - 添加 `@deprecated` / `@throws` PHPDoc 注解和简短说明，方便后续维护者追踪。

- 自测要点：
  - 直接调用 `calculate_eod_totals(...)`：
    - 预期：抛出“DEPRECATED” 异常，而非 SQL 错误；
  - 通过 API 走一遍 EOD 流程：
    - `?res=eod&act=get_preview`、`?res=eod&act=submit_report` 返回 200，统计结果正常；
    - PHP 错误日志中不再出现 `pos_invoice_payments` 相关错误。

- 当前状态：
  - ✅ 已修复（2025-11-21），待门店/测试环境按上述自测方案验证；
  - 后续在全系统幽灵表整理报告中，将本案例列为“已处理示例”。

---

## 3. 功能缺口 / 优惠券相关问题

### ISSUE-POS-FEATURE-001：POS 不支持 `pos_member_issued_coupons`（个人券）核销

- 类型：功能缺口 / 业务能力未覆盖
- 模块：POS · 优惠券系统
- 描述：
  - 数据库 Schema 中存在 `pos_member_issued_coupons` 表，含义为“发放给某个具体会员的优惠券实例”；
  - POS 当前的优惠券处理逻辑，仅基于 `pos_coupons`（通用券/公开券），未实现对 `pos_member_issued_coupons` 的读取与核销；
  - 这意味着：“个人券”（只针对某个会员）在 POS 前端目前不可用。
- 影响：
  - 如业务希望使用“个人券”场景（例如针对部分会员的精准优惠），POS 端暂无法直接支持；
  - 当前次卡项目范围内不涉及个人券，因此不影响本阶段上线，但属于未来可扩展能力。
- 临时措施：
  - 业务侧如暂不规划个人券功能，则可以接受现状；
  - 若后续要启用个人券，需要专门立一个“优惠券系统增强”需求。
- 计划修复阶段：
  - 建议在“优惠券系统增强 / 精准营销”阶段统一规划：
    - POS 加入对 `pos_member_issued_coupons` 的支持；
    - 与 CPSYS/HQ 的发券逻辑打通。
- 当前状态：记录中（功能缺口，当前项目不处理）。

---

## 4. 架构备注：幽灵表与旧表的定义（重要）

### ARCH-POS-GHOST-TABLES-001：幽灵表与旧表的区分与审计策略

- 类型：架构级备注 / 审计方法说明
- 描述：
  - **幽灵表（Ghost Table）**：
    - 指代码中仍然引用，但在当前 Schema 中已经不存在的表；
    - 典型表现：某个函数/模块的 SQL 使用某表名，Schema 里没有该表定义；
    - 这类问题一旦执行，将直接造成运行时错误（500）。
    - 示例：`pos_invoice_payments` 就是当前已确认的幽灵表引用。
  - **旧表（Legacy Table）**：
    - 指数据库中仍存在，但已经不再被现有链路使用的表；
    - 注意：旧表**不一定以 `old_` 前缀命名**，很多旧表是按“新风格命名”保留下来的（例如早期版本的设计残留），只是升级链路时改用了新表而未删除旧表；
    - 旧表在短期内不会直接导致错误，但会让 Schema 与实际业务不一致，增加维护成本。
- 审计策略：
  - 幽灵表：
    - 通过“代码引用 vs 当前 Schema”对比，可以发现代码中使用但 Schema 中不存在的表名；
    - POS 线 L4-2B 已经找到一个典型案例（ISSUE-POS-DB-001），全系统（POS/KDS/CPSYS）完成 L4-2B 后，需要统一汇总所有幽灵表引用并逐一清理。
  - 旧表：
    - 不能仅依赖命名规则（例如 `old_` 前缀），因为历史上旧表也可能已经按新命名风格；
    - 需要结合：
      - Schema 粗筛（L4-2A）；
      - 各线代码引用审计（L4-2B）；
    - 得出“在 Schema 存在但各线代码均不再引用”的表清单，然后以“改名 / 降权 / 观察期 / 最终清理”的节奏处理。
- 当前状态：
  - POS 线 L4-2B：已发现一个幽灵表引用（ISSUE-POS-DB-001）；
  - 全系统幽灵表与旧表的最终清单，待 POS / CPSYS / KDS 各线 L4-2B 完成后统一整理。

## 5. 次卡售价与展示/落库不一致

### ISSUE-POS-PASS-PRICE-001：次卡方案售价 60€，POS 与 VR 订单金额为 0.00€

- 类型：跨系统逻辑不一致 / 严重业务错误  
- 模块：POS · 次卡售卡（VR） / CPSYS · 次卡方案管理  

- 现象（已复盘）：
  - 在 CPSYS 的「次卡方案管理」页面中，“销售价格 (€)” 配置为 60.00（例如「10次奶茶卡」）；  
  - POS 优惠中心中，同一张卡显示为 0.00€；  
  - POS 结账时，应收金额为 0.00€；  
  - CPSYS Topup Orders 页面中，对应 VR 订单 “总金额 (€)” 也为 0.00€；  
  - 说明：售价在总部配置正确，但售卖链路实际使用的是 0。  

- 根因（已由 Gemini + Jules + Claude 三方闭环确认）：  
  1）**CPSYS 写入缺失**  
     - 文件：`/hq_html/html/cpsys/api/registries/cpsys_registry_bms_pass_plan.php`  
     - 函数：`handle_pass_plan_save`  
     - 问题：后端接收到 `sale_settings['price']` 后，只写入影子商品 `pos_item_variants.price_eur`，**没有写入 `pass_plans.sale_price`**，导致该字段长期为 0.00。  

  2）**POS 端“诚实读取”错误字段**  
     - POS 列表接口 `handle_pass_list` 从 `pass_plans.sale_price` 读取售价；  
     - 因为 sale_price = 0.00，前端展示为 0.00；  
     - 购买时，前端将 0.00 放进 `cart_item.price` 传给后端，  
       后端 `create_pass_records()` 用该值写入 `topup_orders.amount_total`，VR 订单金额也变成 0。  

- 采取的修复措施（已落地）：
  1）**CPSYS 写入逻辑修复**（Claude 已完成）  
     - 修改文件：`hq_html/html/cpsys/api/registries/cpsys_registry_bms_pass_plan.php`  
     - 在 `handle_pass_plan_save` 中：  
       - `$plan_params` 新增 `':sale_price' => (float)($sale_settings['price'] ?? 0)`；  
       - `UPDATE pass_plans ...` 增加 `sale_price = :sale_price`；  
       - `INSERT INTO pass_plans (...) VALUES (...)` 补上 `sale_price` 列与占位符。  
     - 分支：`claude/fix-toptea-pass-pricing-01PwzkxTUjWNz6r3PTh2SNtC`  
     - Commit：`e016362`（已推送）。  

  2）**历史数据回填脚本**（Data Patch）  
     - 新增脚本：`hq_html/html/cpsys/tools/fix_pass_plans_sale_price.php`  
     - 逻辑：当 `pp.sale_price = 0.00` 且对应 `variant.price_eur > 0` 时，从 `pos_item_variants.price_eur` 回填到 `pass_plans.sale_price`。  
     - 特性：幂等、事务保护、执行前预览+执行后验证。  

  3）**测试指南**  
     - 新增文档：`hq_html/html/cpsys/tools/TESTING_GUIDE_sale_price_fix.md`  
     - 覆盖场景：  
       - 新建/编辑方案 → sale_price 正确写入；  
       - POS 优惠中心展示价格正确；  
       - POS 售卡 → `topup_orders.amount_total` 与配置价一致；  
       - Data Patch 前后对比与幂等性验证。:contentReference[oaicite:4]{index=4}  

- 当前状态：  
  - ✅ 代码修复：已在 CPSYS 线落地（INSERT + UPDATE 修复）。  
  - ✅ 数据修复：Data Patch 已设计并在测试环境验证通过。  
  - ✅ POS 前端：你已在真实 POS 页面实测，展示价格与 VR 订单金额正确。  
  - ⏳ 生产环境：待按 TESTING_GUIDE 步骤在生产执行脚本 & 最终确认。  

- 后续建议：  
  - 在生产环境执行顺序：  
    1）先部署代码；  
    2）执行 `fix_pass_plans_sale_price.php`（数据回填）；  
    3）按测试指南完成一次“后台改价 → POS 展示 → VR 落库”的完整回归；  
  - 完成后，可将本 Issue 状态更新为「✅ 已在生产验证通过」。  

---
## 6. 多语言 / 文案显示问题

### ISSUE-POS-I18N-001：次卡名称在西班牙语界面下仍显示中文

- 类型：多语言/i18n 体验问题（需进一步确认是数据还是代码问题）
- 模块：POS · 次卡展示（优惠中心） / CPSYS · 次卡方案管理
- 描述：
  - 当前次卡方案仅录入了中文名称；
  - 在 POS 切换为西班牙语界面时，次卡名称仍显示中文；
  - 需确认：
    - CPSYS / DB 中是否存在西班牙语名称字段（如 name_es）但数据为空；
    - 或 POS 前端在多语言环境下始终读取中文字段（硬编码），没有做语言 fallback。

- 影响：
  - 西班牙语模式下，员工看到的仍是中文，不利于日常使用与培训；
  - 后续如果接入多语言小票/前台文案，会持续产生不一致。

- 建议修复阶段：
  - 与次卡售价问题一起，在 `POS-CPSYS-PASS-VR-PRICE-MINI` 或单独的 i18n 整理阶段中处理：
    - 审计 pass_plans / 相关表的多语言字段设计；
    - 明确 POS 在不同语言下的字段选择和回退策略；
    - 制定运营层面的文案录入规范（必须同时填写中/西文）。

- 当前状态：已记录，待确认是“缺数据”还是“代码逻辑问题”。
---
## 7. 审核机制与核销支付渠道限制

### ISSUE-POS-PASS-FLOW-002：次卡售卡缺少“需人工审核”配置，且核销加价支付渠道未受限

- 类型：业务流程缺口 / 风控规则未落地
- 模块：POS · 次卡售卡与核销结账 / CPSYS · 次卡方案管理（B1）
- 描述：
  1）审核机制缺失：
     - CPSYS 的 Topup Orders 页面中，次卡售卡订单状态为 `pending`；
     - 实际上，POS 已经可以立刻核销该次卡，说明当前实现是“自动激活 + 审核仅作展示”；
     - 2.4 文档中的 B1 阶段本来预期存在“售卡 VR 审核”流程（⚠️ 高风险），需要在方案层面
       提供“自动生效 / 需人工审核”的配置，并在核销时真正生效。

  2）核销支付渠道未受限：
     - 当次卡核销存在额外加价（extra_charge_total > 0）时，POS 结账弹窗会展示所有支付方式
       （现金 / 刷卡 / Bizum / 平台码等）；
     - 业务期望：次卡核销产生的加价部分，仅允许使用“现金 / 银行卡”支付，
       其他渠道在该场景下应被隐藏或禁止使用。

- 影响：
  - 审核缺失：
    - 无法对高风险或特殊次卡进行人工把关；
    - 后台看到大量 pending 订单，但对应卡已经在使用，审核记录形同虚设。
  - 支付渠道未限：
    - 财务与风控难以对“次卡相关收入”建立统一口径（例如要求全部走现金/刷卡）；
    - 某些支付方式可能不适合与次卡场景叠加，容易引起对账和合规问题。

- 建议修复阶段：
  - 新增一个阶段或子阶段：`CPSYS-B1-PASS-REVIEW-AND-PAYMENT`：
    - 设计层面：
      - 在 pass_plans 增加“是否需人工审核”配置；
      - 定义审核通过前，member_passes 不可核销的规则（可以是延迟创建 member_passes，或创建但标记为 pending）；
      - 为“次卡核销加价”增加支付渠道白名单配置（首版可写死为现金/银行卡）。
    - 实现层面（Claude 执行）：
      - 修改 POS 售卡与核销逻辑，接入上述配置；
      - 在结账层统一限制核销场景下的支付方式，并在后端做托底校验。

- 当前状态：已记录，等待与 B1 阶段需求统一规划后实施。
