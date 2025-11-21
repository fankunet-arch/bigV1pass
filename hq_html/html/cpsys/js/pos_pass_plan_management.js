/**
 * Toptea HQ - JavaScript for POS Seasons Pass Plan Management
 * Engineer: Gemini | Date: 2025-11-16
 */
$(document).ready(function() {

    const API_GATEWAY_URL = 'api/cpsys_api_gateway.php';
    const API_RES = 'pos_pass_plans';

    const dataDrawer = new bootstrap.Offcanvas(document.getElementById('plan-drawer'));
    const form = $('#plan-form');
    const drawerLabel = $('#drawer-label');
    
    const dataIdInput = $('#pass_plan_id');
    const skuInput = $('#sale_sku');
    
    // 隐藏的标签 ID
    const tagIdPassProduct = $('#tag_id_pass_product').val();
    const tagIdEligibleBev = $('#tag_id_eligible_bev').val();
    const tagIdFreeAddon = $('#tag_id_free_addon').val();
    const tagIdPaidAddon = $('#tag_id_paid_addon').val();

    // 多选框
    const $eligibleBevSelect = $('#eligible_beverages');
    const $freeAddonSelect = $('#free_addons');
    const $paidAddonSelect = $('#paid_addons');


    function resetForm() {
        drawerLabel.text('创建新次卡方案');
        form[0].reset();
        dataIdInput.val('');
        skuInput.prop('readonly', false);
        $('#is_active').prop('checked', true);
        
        $eligibleBevSelect.val([]);
        $freeAddonSelect.val([]);
        $paidAddonSelect.val([]);
    }

    $('#create-plan-btn').on('click', function() {
        resetForm();
    });

    $('.table').on('click', '.edit-plan-btn', function() {
        resetForm();
        const dataId = $(this).data('id');
        drawerLabel.text('编辑次卡方案');
        dataIdInput.val(dataId);
        skuInput.prop('readonly', true); // SKU (P-Code) 不允许编辑

        $.ajax({
            url: API_GATEWAY_URL,
            type: 'GET',
            data: { 
                res: API_RES,
                act: 'get',
                id: dataId 
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    const data = response.data;
                    
                    // 1. 方案详情 (pass_plans)
                    $('#name_zh').val(data.plan.name);
                    $('#total_uses').val(data.plan.total_uses);
                    $('#validity_days').val(data.plan.validity_days);
                    $('#max_uses_per_order').val(data.plan.max_uses_per_order);
                    $('#max_uses_per_day').val(data.plan.max_uses_per_day);
                    $('#is_active').prop('checked', data.plan.is_active == 1);

                    // 2. 销售设置 (pos_menu_items / variants)
                    if (data.pos_item) {
                        $('#name_es').val(data.pos_item.name_es || data.plan.name); // 填充西语
                        skuInput.val(data.pos_item.product_code);
                        $('#sale_category_id').val(data.pos_item.pos_category_id);
                    } else {
                        $('#name_es').val(data.plan.name); // 回退
                    }
                    if (data.pos_variant) {
                        $('#sale_price').val(data.pos_variant.price_eur);
                    }

                    // 3. 核销规则 (tags)
                    $eligibleBevSelect.val(data.rules.eligible_beverage_ids || []);
                    $freeAddonSelect.val(data.rules.free_addon_ids || []);
                    $paidAddonSelect.val(data.rules.paid_addon_ids || []);

                } else {
                    alert('获取数据失败: ' + response.message);
                    dataDrawer.hide();
                }
            },
            error: function() {
                alert('获取数据时发生网络错误。');
                dataDrawer.hide();
            }
        });
    });

    form.on('submit', function(e) {
        e.preventDefault();
        
        const formData = {
            // 1. 方案详情
            plan_details: {
                id: dataIdInput.val(),
                name_zh: $('#name_zh').val(),
                name_es: $('#name_es').val(),
                total_uses: $('#total_uses').val(),
                validity_days: $('#validity_days').val(),
                max_uses_per_order: $('#max_uses_per_order').val(),
                max_uses_per_day: $('#max_uses_per_day').val(),
                is_active: $('#is_active').is(':checked') ? 1 : 0,
            },
            // 2. 销售设置
            sale_settings: {
                sku: skuInput.val(),
                price: $('#sale_price').val(),
                category_id: $('#sale_category_id').val(),
                tag_id_pass_product: tagIdPassProduct
            },
            // 3. 核销规则
            rules: {
                eligible_beverage_ids: $eligibleBevSelect.val() || [],
                tag_id_eligible_bev: tagIdEligibleBev,
                
                free_addon_ids: $freeAddonSelect.val() || [],
                tag_id_free_addon: tagIdFreeAddon,
                
                paid_addon_ids: $paidAddonSelect.val() || [],
                tag_id_paid_addon: tagIdPaidAddon
            }
        };

        $.ajax({
            url: API_GATEWAY_URL,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ data: formData }), // { data: { plan_details: {}, ... } }
            dataType: 'json',
            beforeSend: function (xhr, settings) {
                settings.url += `?res=${API_RES}&act=save`;
            },
            success: function(response) {
                if (response.status === 'success') {
                    alert(response.message);
                    window.location.reload();
                } else {
                    alert('保存失败: ' + (response.message || '未知错误'));
                }
            },
            error: function(jqXHR) {
                const errorMsg = jqXHR.responseJSON ? jqXHR.responseJSON.message : '保存过程中发生网络或服务器错误。';
                alert('操作失败: ' + errorMsg);
            }
        });
    });

    $('.table').on('click', '.delete-plan-btn', function() {
        const dataId = $(this).data('id');
        const dataName = $(this).data('name');
        
        if (confirm(`您确定要删除次卡方案 "${dataName}" 吗？\n警告：此操作将同时删除关联的POS售卖商品，但已售出的次卡不受影响。`)) {
            $.ajax({
                url: API_GATEWAY_URL,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ id: dataId }),
                dataType: 'json',
                beforeSend: function (xhr, settings) {
                    settings.url += `?res=${API_RES}&act=delete`;
                },
                success: function(response) {
                    if (response.status === 'success') {
                        alert(response.message);
                        window.location.reload();
                    } else {
                        alert('删除失败: ' + response.message);
                    }
                },
                error: function() {
                    alert('删除过程中发生网络或服务器错误。');
                }
            });
        }
    });
});