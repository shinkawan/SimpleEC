jQuery(document).ready(function ($) {
    // Ajax Status Update
    $('.photo-update-status-ajax').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var orderId = $btn.data('id');
        var status = $btn.data('status');

        if ($btn.hasClass('working')) return;
        $btn.addClass('working').css('opacity', '0.5');

        $.ajax({
            url: photoPurchaseAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'photo_update_order_status_ajax',
                order_id: orderId,
                status: status,
                nonce: photoPurchaseAdmin.nonce_status
            },
            success: function (response) {
                if (response.success) {
                    location.reload(); // Simplest way to reflect all changes, can be optimized later
                } else {
                    alert('エラーが発生しました: ' + (response.data || 'Unknown error'));
                    $btn.removeClass('working').css('opacity', '1');
                }
            },
            error: function () {
                alert('通信エラーが発生しました。');
                $btn.removeClass('working').css('opacity', '1');
            }
        });
    });

    // Postal Code Auto-fill
    $('input[name="shipping_zip"]').on('blur', function () {
        var zip = $(this).val().replace(/[ー−-]/g, '');
        if (zip.length === 7) {
            var $zip_input = $(this);
            $.ajax({
                url: 'https://zipcloud.ibsnet.co.jp/api/search?zipcode=' + zip,
                dataType: 'jsonp',
                success: function (data) {
                    if (data.results) {
                        var res = data.results[0];
                        var address = res.address2 + res.address3;
                        var $pref = $('select[name="shipping_pref"]');
                        var $addr = $('textarea[name="shipping_address"]');

                        if ($pref.length) $pref.val(res.address1).trigger('change');
                        if ($addr.length) {
                            // If it's the checkout page (cart.js handles this mostly but we can add support here too)
                            $addr.val(address);
                        }
                    }
                }
            });
        }
    });
    // Select All Checkboxes
    $('#cb-select-all-1, #cb-select-all-2').on('change', function () {
        var isChecked = $(this).prop('checked');
        $('input[name="order_ids[]"]').prop('checked', isChecked);
        $('#cb-select-all-1, #cb-select-all-2').prop('checked', isChecked);
    });

    $('input[name="order_ids[]"]').on('change', function () {
        var allChecked = $('input[name="order_ids[]"]:not(:checked)').length === 0;
        $('#cb-select-all-1, #cb-select-all-2').prop('checked', allChecked);
    });
});
