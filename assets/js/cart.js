jQuery(document).ready(function ($) {

    // --- Utility: Ripple Effect ---
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

    // --- Cart Functions ---
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
    }

    function updateCartUI() {
        var cart = getCart();
        var totalCount = cart.reduce((acc, item) => acc + parseInt(item.qty), 0);
        $('.cart-count').text(totalCount);

        $('.photo-item').removeClass('is-in-cart');
        cart.forEach(function (item) {
            $('.photo-item[data-id="' + item.id + '"]').addClass('is-in-cart');
        });

        if ($('#photo-checkout-items').length) {
            renderCheckout();
        }
        
        // Update Drawer
        renderDrawer();
    }

    // --- Drawer Functions ---
    function openDrawer() {
        $('#ec-cart-drawer, #ec-cart-overlay').addClass('is-active');
        $('body').css('overflow', 'hidden');
    }

    function closeDrawer() {
        $('#ec-cart-drawer, #ec-cart-overlay').removeClass('is-active');
        $('body').css('overflow', '');
    }

    $('#ec-drawer-close, #ec-cart-overlay').on('click', closeDrawer);

    function renderDrawer() {
        var cart = getCart();
        var $itemsContainer = $('#ec-drawer-items');
        var $totalAmount = $('#ec-drawer-total-amount');

        if (cart.length === 0) {
            $itemsContainer.html('<p style="text-align:center; color:#999; margin-top:40px;">カートは空です</p>');
            $totalAmount.text('¥0');
            return;
        }

        $.ajax({
            url: photoPurchase.ajax_url,
            type: 'POST',
            data: {
                action: 'photo_get_cart_details',
                cart: cart,
                nonce: photoPurchase.nonce
            },
            success: function (response) {
                if (response.success) {
                    var html = '';
                    var total = 0;
                    response.data.forEach(function (item) {
                        // Match cartItem with gift_wrap consideration
                        var cartItem = cart.find(c => c.id == item.id && c.format == item.format); 
                        // Note: In a robust system, response.data should match cart structure. 
                        // For now we assume one match or iterate cart directly if needed.
                        // Actually, it's better to iterate the CART to ensure all combinations (gift/no-gift) show up.
                    });

                    // REWRITTEN renderDrawer INNER LOGIC
                    var html = '';
                    var total = 0;
                    
                    // We iterate the actual cart to handle multiple combinations of same ID/Format
                    cart.forEach(function(cartItem, index) {
                        var itemDetails = response.data.find(d => d.id == cartItem.id && d.format == cartItem.format);
                        if (!itemDetails) return;

                        var itemPrice = parseInt(itemDetails.price);
                        var extraPrice = 0;
                        var optLabels = '';
                        if (cartItem.options && cartItem.options.length > 0) {
                            cartItem.options.forEach(function(opt) {
                                extraPrice += opt.price;
                                var gLabel = (opt.group && !['項目', 'オプション'].includes(opt.group)) ? opt.group + ': ' : '+ ';
                                optLabels += '<span style="color:var(--pp-accent); font-size:0.75rem; display:block;">' + gLabel + opt.name + '</span>';
                            });
                        }

                        var subtotal = (itemPrice + extraPrice) * cartItem.qty;
                        total += subtotal;

                        var formatLabel = photoPurchase.labels[cartItem.format] || cartItem.format;
                        var itemKey = index; // Use index as key for absolute removal

                        html += '<div class="ec-drawer-item">';
                        html += '<div class="ec-drawer-item-thumb-wrap">' + itemDetails.thumb + '</div>';
                        html += '<div class="ec-drawer-item-info">';
                        html += '<span class="ec-drawer-item-title">' + itemDetails.title + '</span>';
                        html += optLabels;
                        html += '<div class="ec-drawer-item-meta">' + formatLabel + ' x ' + cartItem.qty + '</div>';
                        html += '<div class="ec-drawer-item-price">¥' + subtotal.toLocaleString() + '</div>';
                        html += '</div>';
                        html += '<button class="remove-item" data-index="' + index + '" style="background:none; border:none; color:#ccc; cursor:pointer;">&times;</button>';
                        html += '</div>';
                    });
                    $itemsContainer.html(html);
                    $totalAmount.text('¥' + total.toLocaleString());
                }
            }
        });
    }

    // --- Favorites Functions ---
    function getFavs() {
        var favs = localStorage.getItem('photo_favorites');
        return favs ? JSON.parse(favs) : [];
    }

    function saveFavs(favs) {
        localStorage.setItem('photo_favorites', JSON.stringify(favs));
        updateFavUI();
    }

    function updateFavUI() {
        var favs = getFavs();
        $('.fav-btn').removeClass('is-fav');
        favs.forEach(function (id) {
            $('.fav-btn[data-id="' + id + '"]').addClass('is-fav');
        });
    }

    // --- Event Handlers ---


    // Favorites Toggle
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

    // Favorite Filter
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

    // --- Quickview / Lightbox ---
    $(document).on('click', '.lightbox-trigger', function () {
        var $item = $(this).closest('.photo-item');
        var photoId = $item.data('id');
        var title = $item.find('h3').text();
        var description = $item.data('description');
        var gallery = $item.data('gallery');
        
        $('#lightbox-img').attr('src', gallery[0]);
        $('#ec-quickview-title').text(title);
        $('#ec-quickview-description').html(description);
        $('#ec-quickview-add-to-cart').data('id', photoId);
        $('#ec-quickview-qty').val(1);

        var $modalFormat = $('#ec-quickview-format');
        $modalFormat.empty();
        $item.find('.photo-format option').each(function() {
            $modalFormat.append($(this).clone());
        });

        var $galleryContainer = $('#ec-quickview-gallery');
        $galleryContainer.empty();
        
        // Clone custom options from the list item to the modal
        var $modalOptionsContainer = $('#ec-quickview-options-container');
        $modalOptionsContainer.empty();
        var $originalOpts = $item.find('.custom-options-wrap');
        if ($originalOpts.length) {
            var $clonedOpts = $originalOpts.clone();
            // Reset checkboxes inside the modal
            $clonedOpts.find('.custom-opt-check').prop('checked', false);
            $modalOptionsContainer.append($clonedOpts);
        }

        if (gallery && gallery.length > 1) {
            gallery.forEach(function (url, index) {
                var activeClass = index === 0 ? 'is-active' : '';
                $galleryContainer.append('<img src="' + url + '" class="' + activeClass + '" data-full="' + url + '">');
            });
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


    // Remove from Cart
    $(document).on('click', '.remove-item', function (e) {
        e.preventDefault();
        var index = $(this).data('index');
        var cart = getCart();
        if (index !== undefined) {
            cart.splice(index, 1);
        }
        saveCart(cart);
    });

    // Clear Cart
    $(document).on('click', '.clear-cart-btn', function (e) {
        e.preventDefault();
        if (confirm('カートを空にしてもよろしいですか？')) {
            localStorage.removeItem('photo_cart');
            localStorage.removeItem('photo_applied_coupon');
            updateCartUI();
        }
    });

    // Render Checkout Table
    function renderCheckout() {
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
            return true; // l_size, 2l_size etc.
        });
        var hasDigital = cart.some(item => item.format === 'digital');
        var hasSubscription = cart.some(item => item.format === 'subscription');
        var hasNormalItems = cart.some(item => item.format !== 'subscription');

        var subItems = cart.filter(item => item.format === 'subscription');
        var subProductIds = [...new Set(subItems.map(item => item.id))];

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

        var $codOption = $('input[name="payment_method"][value="cod"]').closest('label');
        var $bankOption = $('input[name="payment_method"][value="bank_transfer"]').closest('label');

        var $paypayOption = $('input[name="payment_method"][value="paypay"]').closest('label');
        if (hasSubscription) {
            $codOption.hide();
            $bankOption.hide();
            $paypayOption.hide();
            var currentMethod = $('input[name="payment_method"]:checked').val();
            if (currentMethod === 'cod' || currentMethod === 'bank_transfer' || currentMethod === 'paypay') {
                $('input[name="payment_method"][value="stripe"]').prop('checked', true);
            }
        } else if (hasDigital) {
            $codOption.hide();
            $bankOption.show();
            if ($('input[name="payment_method"]:checked').val() === 'cod') {
                $('input[name="payment_method"]:visible').first().prop('checked', true);
            }
        } else {
            $codOption.show();
            $bankOption.show();
        }

        $.ajax({
            url: photoPurchase.ajax_url,
            type: 'POST',
            data: {
                action: 'photo_get_cart_details',
                cart: cart,
                nonce: photoPurchase.nonce
            },
            success: function (response) {
                if (response.success) {
                    var html = '<div class="table-responsive"><table style="width:100%; border-collapse:collapse; min-width: 600px;">';
                    html += '<thead style="background:#f8f9fa;"><tr><th style="padding:15px; text-align:left;">写真</th><th style="padding:15px; text-align:left;">商品名 / 形式</th><th style="padding:15px; text-align:right;">単価</th><th style="padding:15px; text-align:center;">数量</th><th style="padding:15px; text-align:right;">小計</th><th style="padding:15px;"></th></tr></thead><tbody>';

                    var itemsTotal = 0;
                    cart.forEach(function (cartItem, index) {
                        var itemDetails = response.data.find(d => d.id == cartItem.id && d.format == cartItem.format);
                        if (!itemDetails) return;

                        var itemPrice = parseInt(itemDetails.price);
                        var extraPrice = 0;
                        var optHtml = '';
                        if (cartItem.options && cartItem.options.length > 0) {
                            cartItem.options.forEach(function(opt) {
                                extraPrice += opt.price;
                                var gLabel = (opt.group && !['項目', 'オプション'].includes(opt.group)) ? opt.group + ': ' : '+ ';
                                optHtml += '<br><small style="color:var(--pp-accent);">' + gLabel + opt.name + ' (+ ' + opt.price.toLocaleString() + '円)</small>';
                            });
                        }

                        var unitTotal = itemPrice + extraPrice;
                        var subtotal = unitTotal * cartItem.qty;
                        itemsTotal += subtotal;

                        var formatLabel = photoPurchase.labels[cartItem.format] || cartItem.format;

                        html += '<tr style="border-bottom:1px solid #eee;">';
                        html += '<td style="padding:15px;">' + itemDetails.thumb + '</td>';
                        html += '<td style="padding:15px;"><strong>' + itemDetails.title + '</strong><br><small style="color:#666;">タイプ: ' + formatLabel + '</small>' + optHtml + '</td>';
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
                            shippingFee = (rules.pref_rates[selectedPref] !== undefined) ? parseInt(rules.pref_rates[selectedPref]) : parseInt(rules.flat_rate);
                        } else {
                            shippingPending = true;
                        }

                        if (selectedMethod === 'cod') {
                            var baseTotal = itemsTotal + shippingFee;
                            var tiers = photoPurchase.shipping.cod_tiers;
                            if (baseTotal < parseInt(tiers.tier1_limit)) {
                                codFee = parseInt(tiers.tier1_fee);
                            } else if (baseTotal < parseInt(tiers.tier2_limit)) {
                                codFee = parseInt(tiers.tier2_fee);
                            } else if (baseTotal < parseInt(tiers.tier3_limit)) {
                                codFee = parseInt(tiers.tier3_fee);
                            } else {
                                codFee = parseInt(tiers.max_fee);
                            }
                        }
                    }

                    var grandTotal = itemsTotal + shippingFee + codFee;

                    // Coupon Logic
                    var appliedCoupon = getAppliedCoupon();
                    var discountAmount = 0;
                    if (appliedCoupon) {
                        if (appliedCoupon.type === 'percent') {
                            discountAmount = Math.floor(itemsTotal * (appliedCoupon.amount / 100));
                        } else {
                            discountAmount = parseInt(appliedCoupon.amount);
                        }
                        grandTotal -= discountAmount;
                    }

                    html += '<tr style="background:#fcfcfc;"><td colspan="4" style="padding:10px 20px; text-align:right;">商品合計</td><td style="padding:10px 20px; text-align:right;">' + itemsTotal.toLocaleString() + ' 円</td><td></td></tr>';

                    if (appliedCoupon) {
                        var durationLabel = '';
                        if (hasSubscription) {
                            if (appliedCoupon.stripe_duration === 'forever') {
                                durationLabel = ' <small style="font-weight:normal;">(継続適用)</small>';
                            } else if (appliedCoupon.stripe_duration === 'repeating') {
                                durationLabel = ' <small style="font-weight:normal;">(' + appliedCoupon.stripe_months + 'ヶ月間)</small>';
                            } else {
                                durationLabel = ' <small style="font-weight:normal;">(初回のみ)</small>';
                            }
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
                    if (hasPhysical && shippingPending) {
                        html += '<br><small style="font-weight:normal; font-size:0.75rem; color:#999;">+ 送料</small>';
                    }
                    html += '</td><td></td></tr>';
                    html += '</tfoot></table></div>';

                    // Add Coupon Input if not applied
                    if (!appliedCoupon) {
                        html += '<div class="coupon-wrap" style="margin-top:20px; padding:15px; background:#f9f9f9; border-radius:8px; display:flex; gap:10px; align-items:center;">';
                        html += '<input type="text" id="coupon-code-input" placeholder="クーポンコードを入力" style="flex:1; padding:8px; border:1px solid #ddd; border-radius:4px;">';
                        html += '<button id="apply-coupon-btn" class="button" style="padding:8px 20px;">適用</button>';
                        html += '</div>';
                        html += '<div id="coupon-message" style="margin-top:5px; font-size:0.85rem;"></div>';
                    }

                    $container.html(html);
                    // Pass to hidden fields
                    $('#cart_json').val(JSON.stringify(cart));
                    $('#coupon_info').val(appliedCoupon ? JSON.stringify(appliedCoupon) : '');
                }
            }
        });
    }

    $(document).on('change', '#shipping_pref, input[name="payment_method"]', function () {
        renderCheckout();

        // Highlight total amount temporarily
        setTimeout(function() {
            var $totalRow = $('#photo-checkout-items tfoot tr:last-child');
            if ($totalRow.length) {
                $totalRow.css('transition', 'background-color 0.5s');
                $totalRow.css('background-color', '#fff9e6');
                setTimeout(function() {
                    $totalRow.css('background-color', '#fcfcfc');
                }, 1000);
            }
        }, 300); // Wait for renderCheckout to complete (ajax)
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
                        var $pref = $('#shipping_pref');
                        var $addr = $('textarea[name="shipping_address"]');

                        if ($pref.length) $pref.val(res.address1).trigger('change');
                        if ($addr.length) $addr.val(address);
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

    // --- Simulator Logic ---

    function updateItemSimulator($item) {
        var $formatSelect = $item.find('.photo-format');
        var $qtyInput = $item.find('.photo-qty');
        var $priceDisplay = $item.find('.photo-price-val');
        var $priceWrap = $item.find('.photo-price-anim-wrap');
        var $optionsWrap = $item.find('.custom-options-wrap');

        var price = parseInt($formatSelect.find('option:selected').data('price')) || 0;
        var format = $formatSelect.val();
        var qty = parseInt($qtyInput.val()) || 1;

        // Show/Hide Options for physical products only
        if (format && format !== 'digital' && format !== 'subscription') {
            $optionsWrap.slideDown(200);
        } else {
            $optionsWrap.slideUp(200);
            $item.find('.custom-opt-check').prop('checked', false);
        }

        var extraPrice = 0;
        $item.find('.custom-opt-check:checked').each(function() {
            extraPrice += parseInt($(this).val()) || 0;
        });

        var total = (price + extraPrice) * qty;
        $priceDisplay.text(total.toLocaleString());
    }

    $(document).on('change input', '.photo-format, .photo-qty, .custom-opt-check', function () {
        var $item = $(this).closest('.photo-item, .ec-quickview-layout');
        updateItemSimulator($item);
    });

    // Initialize simulators
    $('.photo-item').each(function () {
        updateItemSimulator($(this));
    });

    // Update simulation on Quickview load
    $(document).on('click', '.lightbox-trigger', function () {
        setTimeout(function() {
            updateItemSimulator($('.ec-quickview-layout'));
        }, 100);
    });

    // Modify Add to Cart to include gift_wrap
    $('.photo-purchase-gallery').on('click', '.add-to-cart-btn', function (e) {
        e.preventDefault();
        var $item = $(this).closest('.photo-item');
        if ($item.data('sold-out') == '1') {
            alert('この商品は売り切れです。');
            return;
        }
        var photoId = $(this).data('id');
        var $formatSelect = $item.find('.photo-format');
        var format = $formatSelect.val() || 'digital';
        var $selectedOption = $formatSelect.find('option:selected');
        var subReq = $selectedOption.data('sub-requires-shipping');

        if ($formatSelect.length && !$formatSelect.val()) {
            alert('購入形式を選択してください。');
            return;
        }

        // Validation for required attributes
        var missingGroups = [];
        $item.find('.attribute-group-wrap[data-required="1"]').each(function() {
            var groupName = $(this).data('group-name');
            if ($(this).find('.custom-opt-check:checked').length === 0) {
                missingGroups.push(groupName);
            }
        });

        if (missingGroups.length > 0) {
            alert('以下の項目を選択してください：\n・' + missingGroups.join('\n・'));
            return;
        }
        var qty = parseInt($item.find('.photo-qty').val()) || 1;
        
        var manageStock = $item.data('manage-stock') == '1';
        var stockQty = parseInt($item.data('stock-qty')) || 0;

        if (manageStock && qty > stockQty) {
            alert('申し訳ありません。在庫が不足しています（残り' + stockQty + '点）。');
            return;
        }
        var selectedOpts = [];
        $item.find('.custom-opt-check:checked').each(function() {
            selectedOpts.push({
                name: $(this).data('name'),
                group: $(this).data('group'),
                price: parseInt($(this).val())
            });
        });

        var cart = getCart();
        
        // v3.0.0 Mixed Cart Protection
        var hasSub = cart.some(c => c.format === 'subscription');
        if (format === 'subscription' && cart.length > 0 && !hasSub) {
            alert('サブスクリプション商品は通常商品と一緒に購入できません。一度カートを空にしてください。');
            return;
        }
        if (format !== 'subscription' && hasSub) {
            alert('カート内にサブスクリプション商品が含まれています。通常商品を追加するには一度カートを空にしてください。');
            return;
        }
        
        // v3.2.1 Multiple Subscriptions Protection
        if (format === 'subscription' && hasSub) {
            var existingSub = cart.find(c => c.format === 'subscription');
            if (existingSub && existingSub.id != photoId) {
                alert('異なる種類のサブスクリプション商品を同時に購入することはできません（1決済1種類まで）。\n別のサブスクを購入する場合は一度カートを空にしてください。');
                return;
            }
        }

        cart.push({ 
            id: photoId, 
            format: format, 
            qty: qty, 
            options: selectedOpts,
            sub_requires_shipping: subReq == '1'
        });

        saveCart(cart);
        openDrawer();
    });

    // Modified Quickview Add to Cart
    $('#ec-quickview-add-to-cart').on('click', function (e) {
        e.preventDefault();
        var $modal = $(this).closest('.ec-quickview-layout');
        var $itemOrigin = $('.photo-item[data-id="' + $(this).data('id') + '"]');
        if ($itemOrigin.data('sold-out') == '1') {
            alert('この商品は売り切れです。');
            return;
        }
        var photoId = $(this).data('id');
        var $formatSelect = $('#ec-quickview-format');
        var format = $formatSelect.val();
        var $selectedOption = $formatSelect.find('option:selected');
        var subReq = $selectedOption.data('sub-requires-shipping');
        var qty = parseInt($('#ec-quickview-qty').val()) || 1;
        
        var manageStock = $itemOrigin.data('manage-stock') == '1';
        var stockQty = parseInt($itemOrigin.data('stock-qty')) || 0;

        if (manageStock && qty > stockQty) {
            alert('申し訳ありません。在庫が不足しています（残り' + stockQty + '点）。');
            return;
        }
        
        var selectedOpts = [];
        $modal.find('.custom-opt-check:checked').each(function() {
            selectedOpts.push({
                name: $(this).data('name'),
                group: $(this).data('group'),
                price: parseInt($(this).val())
            });
        });

        if (!format) {
            alert('購入形式を選択してください。');
            return;
        }

        // Validation for required attributes
        var missingGroups = [];
        $modal.find('.attribute-group-wrap[data-required="1"]').each(function() {
            var groupName = $(this).data('group-name');
            if ($(this).find('.custom-opt-check:checked').length === 0) {
                missingGroups.push(groupName);
            }
        });

        if (missingGroups.length > 0) {
            alert('以下の項目を選択してください：\n・' + missingGroups.join('\n・'));
            return;
        }

        var cart = getCart();
        
        // v3.0.0 Mixed Cart Protection
        var hasSub = cart.some(c => c.format === 'subscription');
        if (format === 'subscription' && cart.length > 0 && !hasSub) {
            alert('サブスクリプション商品は通常商品と一緒に購入できません。一度カートを空にしてください。');
            return;
        }
        if (format !== 'subscription' && hasSub) {
            alert('カート内にサブスクリプション商品が含まれています。通常商品を追加するには一度カートを空にしてください。');
            return;
        }

        // v3.2.1 Multiple Subscriptions Protection
        if (format === 'subscription' && hasSub) {
            var existingSub = cart.find(c => c.format === 'subscription');
            if (existingSub && existingSub.id != photoId) {
                alert('異なる種類のサブスクリプション商品を同時に購入することはできません（1決済1種類まで）。\n別のサブスクを購入する場合は一度カートを空にしてください。');
                return;
            }
        }

        cart.push({ 
            id: photoId, 
            format: format, 
            qty: qty, 
            options: selectedOpts,
            sub_requires_shipping: subReq == '1'
        });

        saveCart(cart);
        $('#photo-lightbox').removeClass('is-active');
        openDrawer();
    });

    // Coupon Application
    $(document).on('click', '#apply-coupon-btn', function() {
        var code = $('#coupon-code-input').val();
        if (!code) {
            alert('クーポンコードを入力してください。');
            return;
        }

        var cart = getCart();
        if (cart.length === 0) return;

        $('#apply-coupon-btn').prop('disabled', true).text('適用中...');
        $('#coupon-message').text('').css('color', '');

        $.ajax({
            url: photoPurchase.ajax_url,
            type: 'POST',
            data: {
                action: 'photo_purchase_validate_coupon',
                code: code,
                cart: cart, // Pass cart to calculate total on server
                nonce: photoPurchase.nonce
            },
            success: function(response) {
                $('#apply-coupon-btn').prop('disabled', false).text('適用');
                if (response.success) {
                    localStorage.setItem('photo_applied_coupon', JSON.stringify(response.data));
                    renderCheckout();
                } else {
                    $('#coupon-message').text(response.data.message).css('color', 'red');
                }
            },
            error: function() {
                $('#apply-coupon-btn').prop('disabled', false).text('適用');
                $('#coupon-message').text('エラーが発生しました。').css('color', 'red');
            }
        });
    });

    $(document).on('click', '#remove-coupon', function() {
        localStorage.removeItem('photo_applied_coupon');
        renderCheckout();
    });

    updateCartUI();
    updateFavUI();
});
