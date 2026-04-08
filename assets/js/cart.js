jQuery(document).ready(function ($) {

    // --- エスケープ用ユーティリティ関数（XSS対策） ---
    function escapeHtml(text) {
        if (text === undefined || text === null) return '';
        return text.toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // --- ユーティリティ: リップルエフェクト ---
    $(document).on('click', '.button, .add-to-cart-btn, .fav-btn', function (e) {
        var $el = $(this);
        var $ripple = $('<span class="pp-ripple"></span>');
        var rect = $el[0].getBoundingClientRect();
        var size = Math.max(rect.width, rect.height);
        var x = e.pageX - rect.left - window.scrollX - size / 2;
        var y = e.pageY - rect.top - window.scrollY - size / 2;

        $ripple.css({
            width: size + 'px',
            height: size + 'px',
            top: y + 'px',
            left: x + 'px'
        });

        $el.append($ripple);
        setTimeout(function() { $ripple.remove(); }, 600);
    });

    // --- カート基本機能 ---
    function getCart() {
        var cartStr = localStorage.getItem('photo_cart');
        if (!cartStr) return [];
        try {
            var cart = JSON.parse(cartStr);
            if (cart.length > 0 && (!cart[0].id || !cart[0].format)) {
                localStorage.removeItem('photo_cart');
                return [];
            }
            return cart;
        } catch (e) {
            localStorage.removeItem('photo_cart');
            return [];
        }
    }

    function getAppliedCoupon() {
        var couponStr = localStorage.getItem('photo_applied_coupon');
        return couponStr ? JSON.parse(couponStr) : null;
    }

    function saveCart(cart) {
        localStorage.setItem('photo_cart', JSON.stringify(cart));
        updateCartUI();
        $(document).trigger('cart_updated');
    }

    function updateCartCount(count) {
        $('.cart-count').text(count);
    }

    /**
     * カート詳細の取得と不整合データの自動削除
     */
    function updateCartUI() {
        var cart = getCart();
        
        // カートバッジ更新
        var totalCount = cart.reduce((acc, item) => acc + (parseInt(item.qty, 10) || 0), 0);
        updateCartCount(totalCount);

        // 商品一覧の「カート投入済み」クラス更新
        $('.photo-item').removeClass('is-in-cart');
        cart.forEach(function (item) {
            $('.photo-item[data-id="' + item.id + '"]').addClass('is-in-cart');
        });

        // お気に入りダッシュボードは常に実行（カートが空でも表示が必要）
        if (typeof renderFavDashboard === 'function') renderFavDashboard();

        // カートが空の場合は早期リターン
        if (cart.length === 0) {
            renderDrawer([]);
            if ($('#photo-checkout-items').length) renderCheckout([]);
            return;
        }

        // サーバーから最新の詳細情報を取得
        $.ajax({
            url: photoPurchase.ajax_url,
            type: 'POST',
            data: {
                action: 'photo_get_cart_details',
                nonce: photoPurchase.nonce,
                cart: cart
            },
            success: function(response) {
                if (response.success) {
                    var serverItems = response.data;
                    
                    // --- 追加: 自動クリーンアップロジック ---
                    var validKeys = serverItems.map(function(item) { 
                        return item.id + '-' + item.format + '-' + (item.variation_id || ''); 
                    });
                    
                    var cleanedCart = cart.filter(function(localItem) {
                        var localKey = localItem.id + '-' + localItem.format + '-' + (localItem.variation_id || '');
                        return validKeys.indexOf(localKey) !== -1;
                    });

                    if (cleanedCart.length !== cart.length) {
                        console.warn('Simple EC: 削除された商品をカートから自動除去しました。');
                        saveCart(cleanedCart);
                        return;
                    }
                    // --------------------------------------

                    renderDrawer(serverItems);
                    if ($('#photo-checkout-items').length) {
                        renderCheckout(serverItems);
                    }
                    
                    updateCartCount(cleanedCart.reduce((acc, item) => acc + (parseInt(item.qty, 10) || 0), 0));
                }
            }
        });


    }

    // --- お気に入り表示の同期 ---
    function updateFavUI() {
        var favs = getFavs();
        $('.fav-btn').each(function() {
            var id = $(this).data('id');
            // includes の代わりに indexOf を使用（互換性）
            if (favs.indexOf(id) !== -1) {
                $(this).addClass('is-favorited').addClass('is-fav');
            } else {
                $(this).removeClass('is-favorited').removeClass('is-fav');
            }
        });
    }

    function renderFavDashboard() {
        // 優先順位をつけてコンテナを特定
        var $container = $('#ec-member-fav-list').length ? $('#ec-member-fav-list') : $('#ec-favorites-dashboard-list');
        if (!$container.length) $container = $('#ec-favorites-dashboard-wrapper #ec-favorites-dashboard-list');
        
        if (!$container.length) return;
        $container.show();
        $('#ec-favorites-dashboard-wrapper').show();
        
        var favs = getFavs();
        if (favs.length === 0) {
            $container.html('<p style="text-align:center; padding:40px; color:#94a3b8;">お気に入りに登録されている商品はありません。</p>');
            $('#ec-favorites-dashboard-wrapper').show();
            return;
        }

        // 読み込み中表示
        if ($container.is(':empty')) {
            $container.html('<div style="text-align:center; padding:40px; color:#94a3b8;">読み込み中...</div>');
        }

        $.ajax({
            url: photoPurchase.ajax_url,
            type: 'POST',
            data: {
                action: 'photo_get_fav_details',
                favs: favs,
                nonce: photoPurchase.nonce
            },
            success: function(response) {
                if (response.success) {
                    var html = '';
                    response.data.forEach(function(item) {
                        html += `
                            <div class="ec-fav-item fav-product-card" style="background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); border:1px solid #e2e8f0; position:relative; transition:all 0.2s;">
                                <div class="ec-fav-thumb" style="aspect-ratio:1/1; overflow:hidden;">
                                    <a href="${escapeHtml(item.url)}">${item.thumb}</a>
                                </div>
                                <div style="padding:15px;">
                                    <h4 style="margin:0 0 6px; font-size:0.9rem; font-weight:bold; color:#1e293b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${escapeHtml(item.title)}</h4>
                                    <div style="font-size:14px; font-weight:800; color:#4f46e5; margin-bottom:10px;">${escapeHtml(item.price_display)}</div>
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <a href="${escapeHtml(item.url)}" class="button" style="padding:6px 12px; font-size:0.8rem;">詳細を見る</a>
                                        <button class="fav-btn is-favorited is-fav" data-id="${item.id}" style="background:rgba(255,255,255,0.9); border:none; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 2px 4px rgba(0,0,0,0.1); z-index:10;">
                                            <svg width="20" height="20" fill="#f43f5e" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>`;
                    });
                    $container.html(html);
                }
            }
        });
    }


    // --- ドロワー（サイドバー）機能 ---
    function openDrawer() {
        $('#ec-cart-drawer, #ec-cart-overlay').addClass('is-active');
        $('body').css('overflow', 'hidden');
    }

    function closeDrawer() {
        $('#ec-cart-drawer, #ec-cart-overlay').removeClass('is-active');
        $('body').css('overflow', '');
    }

    $('#ec-drawer-close, #ec-cart-overlay').on('click', closeDrawer);

    function renderDrawer(serverItems) {
        var cart = getCart();
        var $itemsContainer = $('#ec-drawer-items');
        var $totalAmount = $('#ec-drawer-total-amount');

        if (cart.length === 0) {
            $itemsContainer.html('<p style="text-align:center; color:#999; margin-top:40px;">カートは空です</p>');
            $totalAmount.text('¥0');
            return;
        }

        // 引数がない場合は updateCartUI 経由での再実行を待つ
        if (!serverItems) {
            updateCartUI();
            return;
        }

        var html = '';
        var total = 0;

        cart.forEach(function(cartItem, index) {
            var itemDetails = serverItems.find(d => d.id == cartItem.id && d.format == cartItem.format && (d.variation_id || '') == (cartItem.variation_id || ''));
            if (!itemDetails) return;

            var itemPrice = parseInt(itemDetails.price, 10) || 0;
            var extraPrice = 0;
            var optLabels = '';
            if (cartItem.options && cartItem.options.length > 0) {
                cartItem.options.forEach(function(opt) {
                    extraPrice += (parseInt(opt.price, 10) || 0);
                    var gLabel = (opt.group && !['項目', 'オプション'].includes(opt.group)) ? escapeHtml(opt.group) + ': ' : '+ ';
                    optLabels += '<span style="color:var(--pp-accent); font-size:0.75rem; display:block;">' + gLabel + escapeHtml(opt.name) + '</span>';
                });
            }

            var subtotal = (itemPrice + extraPrice) * (parseInt(cartItem.qty, 10) || 1);
            total += subtotal;

            var formatLabel = photoPurchase.labels[cartItem.format] || cartItem.format;

            html += '<div class="ec-drawer-item">';
            html += '<div class="ec-drawer-item-thumb-wrap">' + itemDetails.thumb + '</div>';
            html += '<div class="ec-drawer-item-info">';
            html += '<span class="ec-drawer-item-title">' + escapeHtml(itemDetails.title) + '</span>';
            html += optLabels;
            var varLabel = cartItem.variation_name ? '<span style="color:var(--pp-accent); font-size:0.75rem; display:block;">' + escapeHtml(cartItem.variation_name) + '</span>' : '';
            html += '<div class="ec-drawer-item-meta">' + varLabel + escapeHtml(formatLabel) + ' x ' + cartItem.qty + '</div>';
            var priceHtml = '¥' + subtotal.toLocaleString();
            if (itemPrice < itemDetails.original_price) {
                priceHtml += ' <span class="ec-member-discount-badge" style="font-size:10px; background:#ff4d4d; color:#fff; padding:2px 4px; border-radius:3px; margin-left:5px; vertical-align:middle;">会員割引</span>';
            }

            html += '<div class="ec-drawer-item-price">' + priceHtml + '</div>';
            html += '</div>';
            html += '<button class="remove-item" data-index="' + index + '" style="background:none; border:none; color:#ccc; cursor:pointer;">&times;</button>';
            html += '</div>';
        });
        $itemsContainer.html(html);
        $totalAmount.text('¥' + total.toLocaleString());
    }

    // --- お気に入り機能 ---
    function getFavs() {
        var favs = localStorage.getItem('photo_favorites');
        return favs ? JSON.parse(favs) : [];
    }

    function saveFavs(favs) {
        localStorage.setItem('photo_favorites', JSON.stringify(favs));
        updateFavUI();
        renderFavDashboard();
    }


    $(document).on('click', '.fav-btn', function (e) {
        e.preventDefault();
        var id = $(this).data('id');
        var favs = getFavs();
        if (favs.includes(id)) {
            favs = favs.filter(f => f != id);
        } else {
            favs.push(id);
        }
        saveFavs(favs);
    });

    // --- 再入荷通知機能 ---
    $(document).on('click', '.restock-notify-open-btn', function(e) {
        e.preventDefault();
        $(this).next('.restock-notify-form').slideToggle(200);
    });

    $(document).on('click', '.restock-submit-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $form = $btn.closest('.restock-notify-form');
        var $input = $form.find('.restock-email-input');
        var $msg = $form.find('.restock-msg');
        var productId = $btn.data('id');
        var email = $input.val();

        if (!email || !email.includes('@')) {
            $msg.text('有効なメールアドレスを入力してください。').css('color', '#e11d48').fadeIn();
            return;
        }

        $btn.prop('disabled', true).text('登録中...');

        $.ajax({
            url: photoPurchase.ajax_url,
            type: 'POST',
            data: {
                action: 'photo_register_restock',
                product_id: productId,
                email: email,
                page_url: window.location.href,
                nonce: photoPurchase.nonce
            },
            success: function(response) {
                if (response.success) {
                    $form.html('<div style="text-align:center; padding:10px 0;"><span style="font-size:24px;">✅</span><p style="margin:5px 0 0; font-size:11px; color:#059669;">' + response.data.message + '</p></div>');
                    setTimeout(function() {
                        $form.closest('.restock-notify-wrap').fadeOut();
                    }, 3000);
                } else {
                    $msg.text(response.data.message).css('color', '#e11d48').fadeIn();
                    $btn.prop('disabled', false).text('通知を受け取る');
                }
            },
            error: function() {
                $msg.text('通信エラーが発生しました。').css('color', '#e11d48').fadeIn();
                $btn.prop('disabled', false).text('通知を受け取る');
            }
        });
    });

    // --- お気に入りフィルタ ---
    $('#toggle-fav-filter').on('click', function () {
        $(this).toggleClass('active');
        var isFilterActive = $(this).hasClass('active');
        var favs = getFavs();

        if (isFilterActive) {
            $('.photo-item').each(function () {
                var id = $(this).data('id');
                if (!favs.includes(id)) {
                    $(this).hide();
                }
            });
        } else {
            $('.photo-item').show();
        }
    });

    // --- クイックビュー / ライトボックス ---
    $(document).on('click', '.lightbox-trigger', function () {
        var $item = $(this).closest('.photo-item');
        var photoId = $item.data('id');
        var isSoldOut = $item.data('sold-out') == '1';
        var title = $item.find('h3').text();
        var description = $item.data('description');
        var gallery = $item.data('gallery');
        
        $('#lightbox-img').attr('src', gallery[0]);
        $('#ec-quickview-title').text(title);
        $('#ec-quickview-description').html(description);
        
        var $qvBtn = $('#ec-quickview-add-to-cart');
        $qvBtn.data('id', photoId);
        if (isSoldOut) {
            $qvBtn.text('売り切れ').prop('disabled', true).css('opacity', '0.6');
        } else {
            $qvBtn.text('カートに入れる').prop('disabled', false).css('opacity', '1');
        }
        $('#ec-quickview-qty').val(1);

        var $modalFormat = $('#ec-quickview-format');
        $modalFormat.empty();
        var $originalFormat = $item.find('.photo-format');
        if ($originalFormat.is('select')) {
            $modalFormat.replaceWith('<select id="ec-quickview-format" class="photo-format"></select>');
            $modalFormat = $('#ec-quickview-format'); // refresh reference
            $originalFormat.find('option').each(function() {
                $modalFormat.append($(this).clone());
            });
            $modalFormat.val($originalFormat.val());
        } else {
            // Hidden input for SKU products
            $modalFormat.replaceWith('<input type="hidden" id="ec-quickview-format" class="photo-format" value="' + $originalFormat.val() + '" data-price="' + ($originalFormat.data('price') || 0) + '">');
        }

        var $galleryContainer = $('#ec-quickview-gallery');
        $galleryContainer.empty();
        
        var $modalOptionsContainer = $('#ec-quickview-options-container');
        $modalOptionsContainer.empty();
        var $originalOpts = $item.find('.custom-options-wrap');
        if ($originalOpts.length) {
            var $clonedOpts = $originalOpts.clone();
            $clonedOpts.find('.custom-opt-check').prop('checked', false);
            $modalOptionsContainer.append($clonedOpts);
        }

        if (gallery && gallery.length > 1) {
            gallery.forEach(function (url, index) {
                var activeClass = index === 0 ? 'is-active' : '';
                $galleryContainer.append('<img src="' + url + '" class="' + activeClass + '" data-full="' + url + '">');
            });
        }

        // --- Variation Quickview Support ---
        var $qvVarWrap = $('#ec-quickview-variation-wrap');
        var $originalVarWrap = $item.find('.ec-variation-selection-wrap, .ec-variation-selection-multi-wrap');
        if ($originalVarWrap.length) {
            $qvVarWrap.html($originalVarWrap.clone()).show();
            // Reset selection in modal
            $qvVarWrap.find('.ec-attr-select, .ec-variation-select').val('');
        } else {
            $qvVarWrap.hide().empty();
        }

        updateItemSimulator($('.ec-quickview-layout'));
        $('#photo-lightbox').addClass('is-active');
    });

    $(document).on('click', '.ec-quickview-gallery img', function () {
        var fullUrl = $(this).data('full');
        $('#lightbox-img').attr('src', fullUrl);
        $('.ec-quickview-gallery img').removeClass('is-active');
        $(this).addClass('is-active');
    });

    $('.photo-lightbox-close, #photo-lightbox').on('click', function (e) {
        if (e.target !== this && !$(e.target).hasClass('photo-lightbox-close')) return;
        $('#photo-lightbox').removeClass('is-active');
    });

    // --- カート内操作 ---
    $(document).on('click', '.remove-item', function (e) {
        e.preventDefault();
        var index = $(this).data('index');
        var cart = getCart();
        if (index !== undefined) {
            cart.splice(index, 1);
        }
        saveCart(cart);
    });

    $(document).on('click', '.clear-cart-btn', function (e) {
        e.preventDefault();
        if (confirm('カートを空にしてもよろしいですか？')) {
            localStorage.removeItem('photo_cart');
            localStorage.removeItem('photo_applied_coupon');
            updateCartUI();
        }
    });

    // --- チェックアウト画面の描画 ---
    function renderCheckout(serverItems) {
        var $container = $('#photo-checkout-items');
        var cart = getCart();

        if (cart.length === 0) {
            $container.html('<div style="padding:40px; text-align:center; background:#f9f9f9; border-radius:10px;"><p>カートは空です。</p></div>');
            $('#checkout-footer').hide();
            return;
        }

        $('#checkout-footer').show();
        $('#cart_json').val(JSON.stringify(cart));

        var hasPhysical = cart.some(item => {
            if (item.format === 'digital') return false;
            if (item.format === 'subscription') return item.sub_requires_shipping === true || item.sub_requires_shipping === '1';
            return true;
        });
        var hasDigital = cart.some(item => item.format === 'digital');
        var hasSubscription = cart.some(item => item.format === 'subscription');
        var hasNormalItems = cart.some(item => item.format !== 'subscription');

        var subItems = cart.filter(item => item.format === 'subscription');
        var subProductIds = [...new Set(subItems.map(item => item.id))];

        // サブスクリプション制限の警告
        if (subProductIds.length > 1) {
            $container.prepend('<div style="padding:15px; background:#fff4f4; color:#c62828; border-radius:8px; margin-bottom:20px; font-size:0.9rem;"><strong>ご注意:</strong> 異なる種類のサブスクリプション商品を同時に購入することはできません。1回のご注文につき1種類のみにしてください。</div>');
            $('.button-primary').prop('disabled', true).css('opacity', '0.5');
        } else if (hasSubscription && hasNormalItems) {
            $container.prepend('<div style="padding:15px; background:#fff4f4; color:#c62828; border-radius:8px; margin-bottom:20px; font-size:0.9rem;"><strong>ご注意:</strong> サブスクリプション商品は他の通常商品と同時に購入することができません。通常商品は一旦削除してください。</div>');
            $('.button-primary').prop('disabled', true).css('opacity', '0.5');
        } else {
            $('.button-primary').prop('disabled', false).css('opacity', '1');
        }

        if (hasPhysical) {
            $('#shipping-info').show().find('input, textarea, select').prop('required', true);
        } else {
            $('#shipping-info').hide().find('input, textarea, select').prop('required', false).val('');
        }

        // 決済方法の表示制御
        var $codOption = $('input[name="payment_method"][value="cod"]').closest('label');
        var $bankOption = $('input[name="payment_method"][value="bank_transfer"]').closest('label');
        var $paypayOption = $('input[name="payment_method"][value="paypay"]').closest('label');

        if (hasSubscription) {
            $codOption.hide(); $bankOption.hide(); $paypayOption.hide();
            if (['cod', 'bank_transfer', 'paypay'].includes($('input[name="payment_method"]:checked').val())) {
                $('input[name="payment_method"][value="stripe"]').prop('checked', true);
            }
        } else if (hasDigital) {
            $codOption.hide(); $bankOption.show();
            if ($('input[name="payment_method"]:checked').val() === 'cod') {
                $('input[name="payment_method"]:visible').first().prop('checked', true);
            }
        } else {
            $codOption.show(); $bankOption.show();
        }

        // 引数がない場合は updateCartUI 経由での再実行を待つ
        if (!serverItems) {
            updateCartUI();
            return;
        }

        var html = '<div class="table-responsive"><table style="width:100%; border-collapse:collapse; min-width: 600px;">';
        html += '<thead style="background:#f8f9fa;"><tr><th style="padding:15px; text-align:left;">写真</th><th style="padding:15px; text-align:left;">商品名 / 形式</th><th style="padding:15px; text-align:right;">単価</th><th style="padding:15px; text-align:center;">数量</th><th style="padding:15px; text-align:right;">小計</th><th style="padding:15px;"></th></tr></thead><tbody>';

        var itemsTotal = 0;
        cart.forEach(function (cartItem, index) {
            var itemDetails = serverItems.find(d => d.id == cartItem.id && d.format == cartItem.format && (d.variation_id || '') == (cartItem.variation_id || ''));
            if (!itemDetails) return;

            var itemPrice = parseInt(itemDetails.price, 10) || 0;
            var extraPrice = 0;
            var optHtml = '';
            if (cartItem.options && cartItem.options.length > 0) {
                cartItem.options.forEach(function(opt) {
                    var p = parseInt(opt.price, 10) || 0;
                    extraPrice += p;
                    var gLabel = (opt.group && !['項目', 'オプション'].includes(opt.group)) ? escapeHtml(opt.group) + ': ' : '+ ';
                    optHtml += '<br><small style="color:var(--pp-accent);">' + gLabel + escapeHtml(opt.name) + ' (+ ' + p.toLocaleString() + '円)</small>';
                });
            }

            var unitTotal = itemPrice + extraPrice;
            var subtotal = unitTotal * (parseInt(cartItem.qty, 10) || 1);
            itemsTotal += subtotal;

            var formatLabel = photoPurchase.labels[cartItem.format] || cartItem.format;

            html += '<tr style="border-bottom:1px solid #eee;">';
            html += '<td style="padding:15px;">' + itemDetails.thumb + '</td>';
            
            var nameLine = '<div style="font-weight:bold; margin-bottom:4px;">' + escapeHtml(itemDetails.title) + '</div>';
            var displayVarName = cartItem.variation_name || itemDetails.variation_name || '';
            if (displayVarName) {
                nameLine += '<div style="font-size:12px; color:var(--pp-accent); margin-bottom:4px;">' + escapeHtml(displayVarName) + '</div>';
            }
            if (unitTotal < (parseInt(itemDetails.original_price, 10) || 0)) {
                nameLine += '<div style="margin-bottom:4px;"><span class="ec-member-discount-badge" style="font-size:10px; background:#ff4d4d; color:#fff; padding:2px 6px; border-radius:3px; font-weight:normal;">会員割引適用中</span></div>';
            }
            
            html += '<td style="padding:15px;">' + nameLine + '<small style="color:#666;">タイプ: ' + escapeHtml(formatLabel) + '</small>' + optHtml + '</td>';
            html += '<td style="padding:15px; text-align:right;">' + unitTotal.toLocaleString() + ' 円</td>';
            html += '<td style="padding:15px; text-align:center;">' + cartItem.qty + '</td>';
            html += '<td style="padding:15px; text-align:right;">' + subtotal.toLocaleString() + ' 円</td>';
            html += '<td style="padding:15px; text-align:right;"><button class="remove-item danger-btn" data-index="' + index + '">&times;</button></td>';
            html += '</tr>';
        });
        html += '</tbody><tfoot>';

        var shippingFee = 0;
        var codFee = 0;
        var selectedPref = $('#shipping_pref').val();
        var selectedMethod = $('input[name="payment_method"]:checked').val();
        var shippingPending = false;

        if (hasPhysical) {
            var rules = photoPurchase.shipping;
            if (rules.free_threshold > 0 && itemsTotal >= rules.free_threshold) {
                shippingFee = 0;
            } else if (selectedPref) {
                shippingFee = (rules.pref_rates[selectedPref] !== undefined) ? parseInt(rules.pref_rates[selectedPref], 10) : parseInt(rules.flat_rate, 10);
            } else {
                shippingPending = true;
            }

            if (selectedMethod === 'cod') {
                var baseTotal = itemsTotal + shippingFee;
                var tiers = photoPurchase.shipping.cod_tiers;
                if (baseTotal < parseInt(tiers.tier1_limit, 10)) {
                    codFee = parseInt(tiers.tier1_fee, 10);
                } else if (baseTotal < parseInt(tiers.tier2_limit, 10)) {
                    codFee = parseInt(tiers.tier2_fee, 10);
                } else if (baseTotal < parseInt(tiers.tier3_limit, 10)) {
                    codFee = parseInt(tiers.tier3_fee, 10);
                } else {
                    codFee = parseInt(tiers.max_fee, 10);
                }
            }
        }

        var grandTotal = itemsTotal + shippingFee + codFee;
        var appliedCoupon = getAppliedCoupon();
        var discountAmount = 0;
        if (appliedCoupon) {
            if (appliedCoupon.type === 'percent') {
                discountAmount = Math.floor(itemsTotal * (parseInt(appliedCoupon.amount, 10) / 100));
            } else {
                discountAmount = parseInt(appliedCoupon.amount, 10);
            }
            grandTotal -= discountAmount;
        }

        html += '<tr style="background:#fcfcfc;"><td colspan="4" style="padding:10px 20px; text-align:right;">商品合計</td><td style="padding:10px 20px; text-align:right;">' + itemsTotal.toLocaleString() + ' 円</td><td></td></tr>';

        if (appliedCoupon) {
            var durationLabel = '';
            if (hasSubscription) {
                if (appliedCoupon.stripe_duration === 'forever') { durationLabel = ' <small style="font-weight:normal;">(継続適用)</small>'; }
                else if (appliedCoupon.stripe_duration === 'repeating') { durationLabel = ' <small style="font-weight:normal;">(' + appliedCoupon.stripe_months + 'ヶ月間)</small>'; }
                else { durationLabel = ' <small style="font-weight:normal;">(初回のみ)</small>'; }
            }
            html += '<tr style="background:#f0fff0;"><td colspan="4" style="padding:10px 20px; text-align:right; color:#2e7d32;">クーポン割引 (' + appliedCoupon.code + ')' + durationLabel + '</td>';
            html += '<td style="padding:10px 20px; text-align:right; color:#2e7d32;">- ' + discountAmount.toLocaleString() + ' 円</td>';
            html += '<td style="padding:10px 20px;"><button id="remove-coupon" class="danger-btn" style="padding:2px 8px; font-size:0.8rem;">解除</button></td></tr>';
        }

        if (hasPhysical) {
            if (shippingPending) {
                html += '<tr style="background:#fcfcfc;"><td colspan="4" style="padding:10px 20px; text-align:right;">送料</td><td style="padding:10px 20px; text-align:right; color:#999; font-style:italic;">都道府県選択後に表示</td><td></td></tr>';
            } else {
                var shippingLabel = (shippingFee === 0 && itemsTotal > 0) ? '送料無料' : shippingFee.toLocaleString() + ' 円';
                html += '<tr style="background:#fcfcfc;"><td colspan="4" style="padding:10px 20px; text-align:right;">送料 (' + selectedPref + ')</td><td style="padding:10px 20px; text-align:right;">' + shippingLabel + '</td><td></td></tr>';
            }
            if (codFee > 0) {
                html += '<tr style="background:#fff9e6;"><td colspan="4" style="padding:10px 20px; text-align:right; font-size:0.9rem;">代引き手数料</td><td style="padding:10px 20px; text-align:right; font-size:0.9rem;">' + codFee.toLocaleString() + ' 円</td><td></td></tr>';
            }
        }

        html += '<tr style="background:#fcfcfc;"><td colspan="4" style="padding:20px; font-weight:bold; text-align:right; font-size:1.1rem;">合計</td>';
        html += '<td style="padding:20px; font-weight:bold; text-align:right; font-size:1.1rem; color:#0073aa;">' + Math.max(0, grandTotal).toLocaleString() + ' 円';
        if (hasPhysical && shippingPending) { html += '<br><small style="font-weight:normal; font-size:0.75rem; color:#999;">+ 送料</small>'; }
        html += '</td><td></td></tr>';
        html += '</tfoot></table></div>';

        if (!appliedCoupon) {
            html += '<div class="coupon-wrap" style="margin-top:20px; padding:15px; background:#f9f9f9; border-radius:8px; display:flex; gap:10px; align-items:center;">';
            html += '<input type="text" id="coupon-code-input" placeholder="クーポンコードを入力" style="flex:1; padding:8px; border:1px solid #ddd; border-radius:4px;">';
            html += '<button id="apply-coupon-btn" class="button" style="padding:8px 20px;">適用</button>';
            html += '</div><div id="coupon-message" style="margin-top:5px; font-size:0.85rem;"></div>';
        }

        $container.html(html);
        $('#cart_json').val(JSON.stringify(cart));
        $('#coupon_info').val(appliedCoupon ? JSON.stringify(appliedCoupon) : '');
    }

    $(document).on('change', '#shipping_pref, input[name="payment_method"]', function () {
        renderCheckout();
        setTimeout(function() {
            var $totalRow = $('#photo-checkout-items tfoot tr:last-child');
            if ($totalRow.length) {
                $totalRow.css('transition', 'background-color 0.5s');
                $totalRow.css('background-color', '#fff9e6');
                setTimeout(function() { $totalRow.css('background-color', '#fcfcfc'); }, 1000);
            }
        }, 300);
    });

    $(document).on('blur', 'input[name="shipping_zip"]', function () {
        var zip = $(this).val().replace(/[ー−-]/g, '');
        if (zip.length === 7) {
            $.ajax({
                url: 'https://zipcloud.ibsnet.co.jp/api/search?zipcode=' + zip,
                dataType: 'jsonp',
                success: function (data) {
                    if (data.results) {
                        var res = data.results[0];
                        var address = res.address2 + res.address3;
                        if ($('#shipping_pref').length) $('#shipping_pref').val(res.address1).trigger('change');
                        if ($('textarea[name="shipping_address"]').length) $('textarea[name="shipping_address"]').val(address);
                    }
                }
            });
        }
    });

    $('#photo-purchase-form').on('submit', function (e) {
        var cart = getCart();
        if (cart.length === 0) {
            alert('カートが空です。');
            return false;
        }
        $('#cart_json').val(JSON.stringify(cart));
    });

    // --- かご落ち同期機能 ---
    function syncAbandonedCart() {
        var email = $('input[name="buyer_email"]').val();
        var cart = getCart();
        
        if (!email || cart.length === 0) return;
        if (!email.match(/.+@.+\..+/)) return;

        $.ajax({
            url: photoPurchase.ajax_url,
            type: 'POST',
            data: {
                action: 'photo_purchase_sync_abandoned_cart',
                email: email,
                cart_json: JSON.stringify(cart),
                nonce: photoPurchase.nonce
            }
        });
    }

    $(document).on('blur', 'input[name="buyer_email"]', syncAbandonedCart);
    $(document).on('cart_updated', function() {
        if ($('input[name="buyer_email"]').val()) {
            syncAbandonedCart();
        }
    });

    // --- シミュレーター機能 ---
    function updateItemSimulator($item) {
        var $formatSelect = $item.find('.photo-format');
        var $qtyInput = $item.find('.photo-qty');
        var $priceDisplay = $item.find('.photo-price-val');
        
        // Base price from format (select or hidden input)
        var basePrice = 0;
        if ($formatSelect.is('select')) {
            basePrice = parseInt($formatSelect.find('option:selected').data('price'), 10) || 0;
        } else {
            basePrice = parseInt($formatSelect.data('price'), 10) || 0;
        }
        
        // Variation Price Add-on
        var varPrice = 0;
        var $varMultiInput = $item.find('.ec-variation-id-input');
        var $varSelect = $item.find('.ec-variation-select');
        
        if ($varMultiInput.length && $varMultiInput.data('current-v')) {
            varPrice = parseInt($varMultiInput.data('current-v').price, 10) || 0;
        } else if ($varSelect.length) {
            varPrice = parseInt($varSelect.find('option:selected').data('price'), 10) || 0;
        }

        var extra = 0;
        $item.find('.custom-opt-check:checked').each(function() {
            extra += (parseInt($(this).val(), 10) || 0);
        });

        var qty = parseInt($qtyInput.val(), 10) || 1;
        var total = (basePrice + varPrice + extra) * qty;
        
        if ($priceDisplay.length) {
            $priceDisplay.text(total.toLocaleString());
        }
    }

    $(document).on('change', '.photo-format, .photo-qty, .custom-opt-check, .ec-variation-select, .ec-variation-id-input', function() {
        var $item = $(this).closest('.photo-item, .ec-quickview-layout');
        updateItemSimulator($item);
    });

    // --- Multi-Attribute Variation Search ---
    $(document).on('change', '.ec-attr-select', function() {
        var $wrapper = $(this).closest('.ec-variation-selection-multi-wrap');
        var variations = $wrapper.data('variations') || {};
        var $statusMsg = $wrapper.find('.ec-variation-status-msg');
        var $idInput = $wrapper.find('.ec-variation-id-input');
        var $addBtn = $(this).closest('.photo-item, .ec-quickview-layout').find('.photo-purchase-add-to-cart-btn, .add-to-cart-btn, #ec-quickview-add-to-cart');
        
        // Collect current selections
        var currentSelections = {};
        var allSelected = true;
        $wrapper.find('.ec-attr-select').each(function() {
            var val = $(this).val();
            var name = $(this).data('attr-name');
            if (!val) {
                allSelected = false;
            } else {
                currentSelections[name] = val;
            }
        });

        if (!allSelected) {
            $idInput.val('').data('current-v', null);
            $statusMsg.text('オプションを選択してください').css('color', '#666');
            $addBtn.prop('disabled', false).css('opacity', '1').text($addBtn.is('#ec-quickview-add-to-cart') ? 'カートに追加' : ($addBtn.hasClass('add-to-cart-btn') ? 'カートに追加' : 'カートに追加'));
            return;
        }

        // Find match
        var match = null;
        for (var v_id in variations) {
            var v = variations[v_id];
            var v_matches = true;
            // v.attrs example: [{name: "Color", value: "Red"}, {name: "Size", value: "S"}]
            if (v.attrs && v.attrs.length > 0) {
                v.attrs.forEach(function(a) {
                    if (currentSelections[a.name] !== a.value) v_matches = false;
                });
            } else {
                v_matches = false;
            }
            if (v_matches) {
                match = v;
                match.id = v_id;
                break;
            }
        }

        if (match) {
            $idInput.val(match.id).data('current-v', match).data('current-v-name', match.name).trigger('change');
            var stock = parseInt(match.stock || 0);
            if (stock <= 0) {
                $statusMsg.text('申し訳ございません。この組み合わせは売り切れです。').css('color', '#dc2626');
                $addBtn.prop('disabled', true).css('opacity', '0.6').text('売り切れ (Sold Out)');
            } else {
                $statusMsg.text('在庫あり (残り ' + stock + '点)').css('color', '#16a34a');
                $addBtn.prop('disabled', false).css('opacity', '1').text('カートに追加');
            }
        } else {
            $idInput.val('').data('current-v', null).data('current-v-name', '').trigger('change');
            $statusMsg.text('申し訳ございませんが、選択した組み合わせは存在しません。').css('color', '#dc2626');
            $addBtn.prop('disabled', true).css('opacity', '0.6').text('選択不可');
        }
    });

    // --- カート追加（通常） ---
    $('.photo-purchase-gallery').on('click', '.add-to-cart-btn', function (e) {
        e.preventDefault();
        var $item = $(this).closest('.photo-item');
        if ($item.data('sold-out') == '1') { alert('この商品は売り切れです。'); return; }
        
        var photoId = $(this).data('id');
        var $formatSelect = $item.find('.photo-format');
        var format = $formatSelect.val() || 'digital';
        var subReq = $formatSelect.is('select') ? $formatSelect.find('option:selected').data('sub-requires-shipping') : $formatSelect.data('sub-requires-shipping');

        if ($formatSelect.length && !$formatSelect.val()) { alert('購入形式を選択してください。'); return; }

        var missingGroups = [];
        $item.find('.attribute-group-wrap[data-required="1"]').each(function() {
            if ($(this).find('.custom-opt-check:checked').length === 0) missingGroups.push($(this).data('group-name'));
        });
        if (missingGroups.length > 0) { alert('以下の項目を選択してください：\n・' + missingGroups.join('\n・')); return; }

        var qty = parseInt($item.find('.photo-qty').val(), 10) || 1;
        if ($item.data('manage-stock') == '1' && qty > parseInt($item.data('stock-qty'), 10)) {
            alert('在庫が不足しています（残り' + $item.data('stock-qty') + '点）。'); return;
        }

        var selectedOpts = [];
        $item.find('.custom-opt-check:checked').each(function() {
            selectedOpts.push({ name: $(this).data('name'), group: $(this).data('group'), price: parseInt($(this).val(), 10) });
        });

        // Variation Logic
        var variationId = '';
        var variationName = '';
        var $varSelect = $item.find('.ec-variation-select');
        var $varMultiInput = $item.find('.ec-variation-id-input');

        if ($item.data('use-variations') == '1') {
            if ($varMultiInput.length) {
                variationId = $varMultiInput.val();
                if (!variationId) { alert('オプション（カラー・サイズ等）をすべて選択してください。'); return; }
                var vData = $varMultiInput.data('current-v');
                variationName = vData ? vData.name : '';
                var varStock = parseInt(vData ? vData.stock : 0, 10);
            } else if ($varSelect.length) {
                variationId = $varSelect.val();
                if (!variationId) { alert('バリエーションを選択してください。'); return; }
                variationName = $varSelect.find('option:selected').text().split('(')[0].trim();
                var varStock = parseInt($varSelect.find('option:selected').data('stock'), 10);
            }

            if (variationId && qty > varStock) {
                alert('選択したバリエーションの在庫が不足しています（残り' + varStock + '点）。'); return;
            }
        }

        var cart = getCart();
        var hasSub = cart.some(c => c.format === 'subscription');
        if (format === 'subscription' && cart.length > 0 && !hasSub) { alert('サブスクリプション商品は通常商品と一緒に購入できません。'); return; }
        if (format !== 'subscription' && hasSub) { alert('カート内にサブスクリプション商品が含まれています。'); return; }

        cart.push({ 
            id: photoId, 
            format: format, 
            qty: qty, 
            options: selectedOpts, 
            variation_id: variationId,
            variation_name: variationName,
            sub_requires_shipping: subReq == '1' 
        });
        saveCart(cart);
        openDrawer();
    });

    // --- カート追加（クイックビュー） ---
    $('#ec-quickview-add-to-cart').on('click', function (e) {
        e.preventDefault();
        var photoId = $(this).data('id');
        var $modal = $(this).closest('.ec-quickview-layout');
        var $formatSelect = $modal.find('.photo-format');
        var format = $formatSelect.val();
        var subReq = $formatSelect.is('select') ? $formatSelect.find('option:selected').data('sub-requires-shipping') : $formatSelect.data('sub-requires-shipping');
        var qty = parseInt($('#ec-quickview-qty').val(), 10) || 1;

        if (!format) { alert('購入形式を選択してください。'); return; }

        var selectedOpts = [];
        $modal.find('.custom-opt-check:checked').each(function() {
            selectedOpts.push({ name: $(this).data('name'), group: $(this).data('group'), price: parseInt($(this).val(), 10) });
        });

        // Variation Logic (Quickview)
        var variationId = '';
        var variationName = '';
        var $varSelect = $modal.find('.ec-variation-select');
        var $varMultiInput = $modal.find('.ec-variation-id-input');
        var $itemRef = $('.photo-item[data-id="' + photoId + '"]');
        
        if ($itemRef.data('use-variations') == '1') {
            if ($varMultiInput.length) {
                variationId = $varMultiInput.val();
                if (!variationId) { alert('オプションをすべて選択してください。'); return; }
                
                variationName = $varMultiInput.data('current-v-name');
                var vData = $varMultiInput.data('current-v');
                if (!variationName && vData) {
                    variationName = vData.name;
                }
                var varStock = parseInt(vData ? vData.stock : 0, 10);
            } else if ($varSelect.length) {
                variationId = $varSelect.val();
                if (!variationId) { alert('バリエーションを選択してください。'); return; }
                variationName = $varSelect.find('option:selected').text().split('(')[0].trim();
                var varStock = parseInt($varSelect.find('option:selected').data('stock'), 10);
            }

            if (variationId && qty > varStock) {
                alert('在庫が不足しています（残り' + varStock + '点）。'); return;
            }
        }

        var cart = getCart();
        var hasSub = cart.some(c => c.format === 'subscription');
        if (format === 'subscription' && cart.length > 0 && !hasSub) { alert('サブスクリプション商品は通常商品と一緒に購入できません。'); return; }
        if (format !== 'subscription' && hasSub) { alert('カート内にサブスクリプション商品が含まれています。'); return; }

        var $btn = $(this);
        var oldHtml = $btn.html();
        $btn.prop('disabled', true).html('追加中...');

        setTimeout(function() {
            cart.push({ 
                id: photoId, 
                format: format, 
                qty: qty, 
                options: selectedOpts, 
                variation_id: variationId,
                variation_name: variationName,
                sub_requires_shipping: subReq == '1' 
            });
            saveCart(cart);
            $('#photo-lightbox').removeClass('is-active');
            openDrawer();
            
            // ボタンを戻す（モーダルが閉じるので実質見えないが、再開時のために）
            $btn.prop('disabled', false).html(oldHtml);
        }, 300);
    });

    // --- クーポン適用 ---
    $(document).on('click', '#apply-coupon-btn', function() {
        var code = $('#coupon-code-input').val();
        if (!code) { alert('クーポンコードを入力してください。'); return; }
        var cart = getCart();
        if (cart.length === 0) return;

        $('#apply-coupon-btn').prop('disabled', true).text('適用中...');
        $.ajax({
            url: photoPurchase.ajax_url,
            type: 'POST',
            data: { action: 'photo_purchase_validate_coupon', code: code, cart: cart, nonce: photoPurchase.nonce },
            success: function(response) {
                $('#apply-coupon-btn').prop('disabled', false).text('適用');
                if (response.success) {
                    localStorage.setItem('photo_applied_coupon', JSON.stringify(response.data));
                    renderCheckout();
                } else {
                    $('#coupon-message').text(response.data.message).css('color', 'red');
                }
            }
        });
    });

    $(document).on('click', '#remove-coupon', function() {
        localStorage.removeItem('photo_applied_coupon');
        renderCheckout();
    });

    // --- もう一度注文（リピート注文） ---
    $(document).on('click', '.buy-again-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var items = $btn.data('items');
        if (!items || !Array.isArray(items)) return;

        var oldHtml = $btn.html();
        $btn.html('確認中...').prop('disabled', true);

        $.ajax({
            url: photoPurchase.ajax_url,
            type: 'POST',
            data: { 
                action: 'photo_purchase_validate_reorder', 
                items: items, 
                current_cart: getCart(),
                nonce: photoPurchase.nonce 
            },
            success: function(response) {
                if (response.success) {
                    var cart = getCart();
                    var hasSubInCart = cart.some(c => c.format === 'subscription');
                    var hasNormalInCart = cart.some(c => c.format !== 'subscription');
                    
                    var addedCount = 0;
                    var mixedError = false;

                    response.data.available_items.forEach(function(item) {
                        if (mixedError) return;

                        // 混在チェック
                        var isAddingSub = item.format === 'subscription';
                        if ((hasSubInCart && !isAddingSub) || (hasNormalInCart && isAddingSub)) {
                            mixedError = true;
                            return;
                        }

                        var existingIdx = cart.findIndex(c => c.id == item.id && c.format == item.format && (c.variation_id || '') == (item.variation_id || '') && JSON.stringify(c.options || []) === JSON.stringify(item.options || []));
                        if (existingIdx > -1) {
                            cart[existingIdx].qty = (parseInt(cart[existingIdx].qty, 10) || 0) + (parseInt(item.qty, 10) || 1);
                        } else {
                            cart.push({
                                id: item.id,
                                format: item.format,
                                qty: parseInt(item.qty, 10) || 1,
                                options: item.options || [],
                                variation_id: item.variation_id || '',
                                variation_name: item.variation_name || '',
                                sub_requires_shipping: item.sub_requires_shipping === true || item.sub_requires_shipping === '1'
                            });
                        }
                        
                        // 1つでも追加されたら、その後の追加のために cart の状態を更新して認識させる
                        if (isAddingSub) hasSubInCart = true;
                        else hasNormalInCart = true;
                        
                        addedCount++;
                    });

                    if (mixedError) {
                        alert('【購入制限エラー】\nサブスクリプション商品と通常商品を同時にカートに入れることはできません。現在のカートを空にするか、同一タイプの商品のみを選択してください。');
                    }

                    // PHP側からの混在エラーメッセージ（sold_out_titlesとは別枠）
                    if (response.data.error_messages && response.data.error_messages.length > 0) {
                        alert('【購入制限エラー】\n\n' + response.data.error_messages.join('\n'));
                    }

                    if (addedCount > 0) {
                        saveCart(cart);
                        $btn.html('✅ ' + addedCount + ' 個商品を追加').css('background', '#dcfce7');
                        openDrawer();
                    }

                    if (response.data.sold_out_titles && response.data.sold_out_titles.length > 0) {
                        alert('【売り切れのお知らせ】\n\n以下の商品は在庫がないため追加できませんでした：\n' + response.data.sold_out_titles.join('\n'));
                    }
                } else {
                    alert(response.data.message || '商品の検証中にエラーが発生しました。');
                }
            },
            error: function() {
                alert('通信エラーが発生しました。時間を置いて再度お試しください。');
            },
            complete: function() {
                setTimeout(function() { 
                    $btn.html(oldHtml).css('background', '').prop('disabled', false); 
                }, 3000);
            }
        });
    });

    // 初期化
    $('.photo-item').each(function() {
        updateItemSimulator($(this));
    });
    updateCartUI();
    updateFavUI();
    // マイページ用：カートと独立してお気に入りダッシュボードを初期化
    if ($('#ec-favorites-dashboard-wrapper').length || $('#ec-member-fav-list').length) {
        renderFavDashboard();
    }

    // URLパラメータによるクイックビュー起動
    const urlParams = new URLSearchParams(window.location.search);
    const photoIdParam = urlParams.get('photo_id');
    if (photoIdParam) {
        setTimeout(function() {
            var $target = $('.photo-item[data-id="' + photoIdParam + '"] .lightbox-trigger');
            if ($target.length) {
                $target.trigger('click');
                $('html, body').animate({ scrollTop: $target.offset().top - 100 }, 500);
            }
        }, 500);
    }
});
