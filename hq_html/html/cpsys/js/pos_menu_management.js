/**
 * TopTea HQ - JavaScript for POS Menu Item Management
 * [R2.3] Added POS Tag whitelist selection
 */
$(document).ready(function() {

    const API_GATEWAY_URL = 'api/cpsys_api_gateway.php';
    const API_RES = 'pos_menu_items';

    const dataDrawer = new bootstrap.Offcanvas(document.getElementById('data-drawer'));
    const form = $('#data-form');
    const drawerLabel = $('#drawer-label');
    const dataIdInput = $('#data-id');
    
    // [R2.3] Tag selection
    const tagSelect = $('#tag_ids');

    function resetForm() {
        drawerLabel.text('创建新商品');
        form[0].reset();
        dataIdInput.val('');
        // [R2.3] Reset tag selection
        tagSelect.val([]);
        $('#sort_order').val(99);
        $('#is_active').prop('checked', true);
    }

    $('#create-btn').on('click', function() {
        resetForm();
    });

    $('.table').on('click', '.edit-btn', function() {
        resetForm();
        drawerLabel.text('编辑商品');
        const dataId = $(this).data('id');
        dataIdInput.val(dataId);

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
                    $('#pos_category_id').val(data.pos_category_id);
                    $('#name_zh').val(data.name_zh);
                    $('#name_es').val(data.name_es);
                    $('#description_zh').val(data.description_zh);
                    $('#description_es').val(data.description_es);
                    $('#sort_order').val(data.sort_order);
                    $('#is_active').prop('checked', data.is_active == 1);
                    
                    // [R2.3] Populate tags
                    tagSelect.val(data.tag_ids || []);
                    
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
            id: dataIdInput.val(),
            pos_category_id: $('#pos_category_id').val(),
            name_zh: $('#name_zh').val(),
            name_es: $('#name_es').val(),
            description_zh: $('#description_zh').val(),
            description_es: $('#description_es').val(),
            sort_order: $('#sort_order').val(),
            is_active: $('#is_active').is(':checked') ? 1 : 0,
            
            // [R2.3] Send tag_ids array
            tag_ids: tagSelect.val()
        };

        $.ajax({
            url: API_GATEWAY_URL,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ data: formData }),
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
                if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                    alert('操作失败: ' + jqXHR.responseJSON.message);
                } else {
                    alert('保存过程中发生网络或服务器错误。');
                }
            }
        });
    });

    $('.table').on('click', '.delete-btn', function() {
        const dataId = $(this).data('id');
        const dataName = $(this).data('name');
        
        if (confirm(`您确定要删除商品 "${dataName}" 吗？\n警告：这将同时删除其关联的所有规格和标签。`)) {
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
                error: function(jqXHR) {
                    if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        alert('操作失败: ' + jqXHR.responseJSON.message);
                    } else {
                        alert('删除过程中发生网络或服务器错误。');
                    }
                }
            });
        }
    });
});