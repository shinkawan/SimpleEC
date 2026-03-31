jQuery(document).ready(function($) {
    var sortList = $('#the-list');
    if (sortList.length > 0 && $('body').hasClass('post-type-photo_product')) {
        console.log('Simple EC Sortable initialized');
        
        sortList.sortable({
            items: 'tr',
            handle: '.photo-drag-handle',
            axis: 'y',
            placeholder: 'ui-sortable-placeholder',
            helper: function(e, tr) {
                var $originals = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function(index) {
                    $(this).width($originals.eq(index).width());
                });
                return $helper;
            },
            start: function(event, ui) {
                ui.placeholder.height(ui.item.height());
            },
            update: function(event, ui) {
                var order = [];
                sortList.find('tr').each(function() {
                    var id = $(this).find('.photo-item-id').val();
                    if (!id) {
                        // Fallback: try post-ID format from row ID attribute
                        var rowId = $(this).attr('id');
                        if (rowId && rowId.indexOf('post-') === 0) {
                            id = rowId.replace('post-', '');
                        }
                    }
                    if (id) order.push(id);
                });

                console.log('New order:', order);

                $('.photo-drag-handle').addClass('dashicons-update spin').removeClass('dashicons-move');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'photo_purchase_update_order',
                        order: order,
                        nonce: photoSortData.nonce
                    },
                    success: function(response) {
                        $('.photo-drag-handle').removeClass('dashicons-update spin').addClass('dashicons-move');
                        if (response.success) {
                            console.log('Order saved successfully');
                        } else {
                            alert('並び替えの保存に失敗しました。');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('.photo-drag-handle').removeClass('dashicons-update spin').addClass('dashicons-move');
                        console.error('AJAX Error:', error);
                        alert('通信エラーが発生しました。');
                    }
                });
            }
        }).disableSelection();
    }
});
