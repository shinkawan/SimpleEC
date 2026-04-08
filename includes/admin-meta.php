<?php
/**
 * Admin Meta and Bulk Tools for Simple EC (Japanese)
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Add Meta Box: Photo Details
 */
function photo_purchase_add_meta_boxes()
{
	add_meta_box(
		'photo_purchase_details',
		__('商品の詳細設定', 'photo-purchase'),
		'photo_purchase_meta_box_callback',
		'photo_product',
		'normal',
		'high'
	);
}
add_action('add_meta_boxes', 'photo_purchase_add_meta_boxes');

/**
 * Meta Box Callback
 */
function photo_purchase_meta_box_callback($post)
{
	wp_nonce_field('photo_purchase_save_meta', 'photo_purchase_nonce');

	// Prices
	$price_digital = get_post_meta($post->ID, '_photo_price_digital', true);
	$price_l = get_post_meta($post->ID, '_photo_price_l', true);
	$price_2l = get_post_meta($post->ID, '_photo_price_2l', true);

	$enable_digital = get_option('photo_pp_enable_digital_sales', '1');

	// Legacy support - only if explicitly enabled
	if (!$price_digital && $enable_digital === '1') {
		$price_digital = get_post_meta($post->ID, '_photo_price', true);
		if (!$price_digital)
			$price_digital = 0;
	}
	if (!$price_l)
		$price_l = 0;
	if (!$price_2l)
		$price_2l = 0;

	$high_res_file = get_post_meta($post->ID, '_photo_high_res_file', true);
	$high_res_id = get_post_meta($post->ID, '_photo_high_res_id', true);
	$gallery_ids = get_post_meta($post->ID, '_ec_gallery_ids', true);

	echo '<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">';

	if ($enable_digital === '1') {
		echo '<div>';
		echo '<label for="photo_price_digital"><strong>' . __('ダウンロード版 (円)', 'photo-purchase') . '</strong></label><br>';
		echo '<input type="number" id="photo_price_digital" name="photo_price_digital" value="' . esc_attr($price_digital) . '" step="1" style="width:100%;">';
		echo '<p class="description">0円にすると販売されません</p>';
		echo '</div>';
	}

	echo '<div>';
	echo '<label for="photo_price_l"><strong>' . __('価格 (配送品) (円)', 'photo-purchase') . '</strong></label><br>';
	echo '<input type="number" id="photo_price_l" name="photo_price_l" value="' . esc_attr($price_l) . '" step="1" style="width:100%;">';
	echo '<p class="description">0円にすると配送販売されません</p>';
	echo '</div>';

	echo '<div>';
	$tax_type = get_post_meta($post->ID, '_photo_tax_type', true) ?: 'standard';
	echo '<label for="photo_tax_type"><strong>' . __('適用税率', 'photo-purchase') . '</strong></label><br>';
	echo '<select id="photo_tax_type" name="photo_tax_type" style="width:100%;">';
	echo '<option value="standard" ' . selected($tax_type, 'standard', false) . '>標準税率 (10%)</option>';
	echo '<option value="reduced" ' . selected($tax_type, 'reduced', false) . '>軽減税率 (8%)</option>';
	echo '</select>';
	echo '<p class="description">食品などは軽減税率を適用してください</p>';
	echo '</div>';

	// Sold Out Setting
	$is_sold_out = get_post_meta($post->ID, '_photo_is_sold_out', true);
	$manage_stock = get_post_meta($post->ID, '_photo_manage_stock', true);
	$stock_qty = get_post_meta($post->ID, '_photo_stock_qty', true);

	echo '<div>';
	echo '<label for="photo_is_sold_out"><strong>' . __('売り切れ設定', 'photo-purchase') . '</strong></label><br>';
	echo '<label><input type="checkbox" id="photo_is_sold_out" name="photo_is_sold_out" value="1" ' . checked($is_sold_out, '1', false) . '> ' . __('売り切れにする', 'photo-purchase') . '</label>';

	// Show restock notification count
	$restock_emails = get_post_meta($post->ID, '_photo_restock_emails', true) ?: array();
	if (!empty($restock_emails) && is_array($restock_emails)) {
		echo '<div style="background: #fff8e1; border-left: 4px solid #ffc107; padding: 12px; margin-top: 15px; border-radius: 4px;">';
		echo '<span style="font-size: 1.2rem; margin-right: 8px;">📧</span>';
		echo '<strong>' . sprintf(__('%d 名が入荷通知を待っています', 'photo-purchase'), count($restock_emails)) . '</strong>';
		echo '<p style="font-size: 11px; color: #666; margin: 8px 0 0;">在庫を増やすか、売り切れチェックを外して保存すると自動的に通知が送信されます。</p>';
		echo '</div>';
	}
	echo '<p class="description">チェックを入れるとフロントエンドで「売り切れ」と表示され、購入できなくなります。</p>';

	// --- Inventory Management Settings (v3.12.3 Restore) ---
	echo '<div style="margin-top:15px; padding-top:10px; border-top:1px dashed #ddd;">';
	echo '<label><input type="checkbox" id="photo_manage_stock" name="photo_manage_stock" value="1" ' . checked($manage_stock, '1', false) . '> <strong>' . __('在庫管理を行う', 'photo-purchase') . '</strong></label>';
	echo '<div id="stock_qty_wrap" style="margin-top:10px; padding:10px; background:#fff; border:1px solid #ddd; border-radius:4px; max-width:200px; ' . ($manage_stock === '1' ? '' : 'display:none;') . '">';
	echo '<label for="photo_stock_qty">' . __('現在の在庫数:', 'photo-purchase') . '</label><br>';
	echo '<input type="number" id="photo_stock_qty" name="photo_stock_qty" value="' . esc_attr($stock_qty) . '" min="0" step="1" style="width:100px;">';
	echo '</div>';
    
	echo '<script>
	jQuery(document).ready(function($) {
		$("#photo_manage_stock").change(function() {
			if ($(this).is(":checked")) {
				$("#stock_qty_wrap").slideDown(200);
			} else {
				$("#stock_qty_wrap").slideUp(200);
			}
		});

		$("#photo_use_variations").change(function() {
			if ($(this).is(":checked")) {
				$("#variation_manager_wrap").slideDown(200);
				$("#stock_qty_wrap_main").slideUp(200);
			} else {
				$("#variation_manager_wrap").slideUp(200);
				$("#stock_qty_wrap_main").slideDown(200);
			}
		});
	});
	</script>';
	echo '</div>'; // End stock_qty_wrap_main

	// --- SKU / Variation Management (v4.1.0) ---
	$use_variations = get_post_meta($post->ID, '_photo_use_variations', true);
	$variations = get_post_meta($post->ID, '_photo_variation_skus', true) ?: array();

	echo '<div style="margin-top:10px; padding-top:10px; border-top:1px dashed #ddd;">';
	echo '<label><input type="checkbox" id="photo_use_variations" name="photo_use_variations" value="1" ' . checked($use_variations, '1', false) . '> <strong>' . __('バリエーション別の在庫管理を行う (SKU)', 'photo-purchase') . '</strong></label>';
	echo '<div id="variation_manager_wrap" style="margin-top:15px; padding:15px; background:#f9fbff; border:1px solid #c2e0ff; border-radius:8px; ' . ($use_variations === '1' ? '' : 'display:none;') . '">';
	
	echo '<div style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">';
	echo '<strong>1. 属性の定義 (例: サイズ, カラー)</strong><br>';
	echo '<p class="description">カンマ区切りで値を入力してください（例: S, M, L）</p>';
	echo '<div id="ec_attribute_rows">';
	echo '<div class="attr-row" style="display:flex; gap:10px; margin-bottom:5px;">';
	echo '<input type="text" class="attr-name" placeholder="属性名 (例: サイズ)" style="width:120px;">';
	echo '<input type="text" class="attr-values" placeholder="値 (例: S, M, L)" style="flex:1;">';
	echo '</div>';
	echo '<div class="attr-row" style="display:flex; gap:10px; margin-bottom:5px;">';
	echo '<input type="text" class="attr-name" placeholder="属性名 (例: カラー)" style="width:120px;">';
	echo '<input type="text" class="attr-values" placeholder="値 (例: 赤, 青)" style="flex:1;">';
	echo '</div>';
	echo '</div>';
	?>
	<div style="margin-top:15px; background:#f9f9f9; padding:10px; border:1px solid #ddd; border-radius:4px;">
		<h4 style="margin-top:0;">一括設定 (Bulk Actions)</h4>
		<div style="display:flex; gap:15px; align-items:flex-end; flex-wrap:wrap;">
			<div>
				<label style="display:block; font-size:12px;">一括価格設定 (+加算額)</label>
				<input type="number" id="bulk_v_price" value="0" style="width:100px;">
				<button type="button" id="apply_bulk_price" class="button">価格を一括適用</button>
			</div>
			<div>
				<label style="display:block; font-size:12px;">SKUプレフィックス</label>
				<input type="text" id="bulk_v_sku_prefix" placeholder="ITEM-RED-" style="width:120px;">
				<button type="button" id="apply_bulk_sku" class="button">SKUを一括生成</button>
			</div>
			<div style="flex-grow:1; text-align:right;">
				<button type="button" id="ec_generate_variations" class="button button-primary">組み合わせを自動生成</button>
			</div>
		</div>
	</div>

	<div style="display:flex; justify-content:space-between; align-items:center;">
		<strong>2. バリエーション一覧</strong>
		<label style="font-size:12px; cursor:pointer;"><input type="checkbox" id="ec_variation_check_all"> 全て選択</label>
	</div>
	<div id="ec_variation_list" style="margin-top:10px; display:grid; gap:10px;">
		<?php
		foreach ($variations as $v_id => $v) {
			echo photo_purchase_render_variation_row($v_id, $v);
		}
		?>
	</div>
	<p class="description" style="margin-top:10px;">※生成ボタンを押すと現在のリストが上書きされます。個別に価格や在庫を調整してください。</p>
	</div>

	<script>
	jQuery(document).ready(function($) {
		$("#ec_generate_variations").click(function() {
			var is_append = false;
			var existing_count = $(".variation-row").length;
			
			if (existing_count > 0) {
				if (confirm("既存のリストを残したまま新しい組み合わせを追加しますか？\n(「キャンセル」で既存をリセットして生成します)")) {
					is_append = true;
				} else {
					if (!confirm("既存のリストが完全にリセットされます。よろしいですか？")) return;
				}
			}
			
			var attributes = [];
			$(".attr-row").each(function() {
				var name = $(this).find(".attr-name").val().trim();
				var values = $(this).find(".attr-values").val().split(",").map(s => s.trim()).filter(s => s !== "");
				if (name && values.length > 0) {
					attributes.push({ name: name, values: values });
				}
			});

			if (attributes.length === 0) {
				alert("属性名と値を入力してください。");
				return;
			}

			function cartesian(args) {
				var r = [], max = args.length-1;
				function helper(arr, i) {
					for (var j=0, l=args[i].values.length; j<l; j++) {
						var a = arr.slice();
						a.push({ name: args[i].name, value: args[i].values[j] });
						if (i==max) r.push(a);
						else helper(a, i+1);
					}
				}
				helper([], 0);
				return r;
			}

			var combinations = cartesian(attributes);
			var $list = $("#ec_variation_list");
			
			if (!is_append) {
				$list.empty();
			}

			// Get current existing attrs to skip duplicates
			var current_attrs = [];
			$(".variation-row").each(function() {
				try {
					var raw = $(this).find("input[name='v_attrs[]']").val();
					current_attrs.push(JSON.stringify(JSON.parse(raw)));
				} catch (e) {}
			});

			var added_count = 0;
			combinations.forEach(function(combo, idx) {
				var combo_json = JSON.stringify(combo);
				if (current_attrs.indexOf(combo_json) !== -1) return; // Skip duplicate

				var v_id = "v_" + Date.now() + "_" + idx;
				var name = combo.map(c => c.value).join(" / ");
				var attr_str = combo_json.replace(/'/g, "&#39;");
				
				var row = `<div class="variation-row" style="display:grid; grid-template-columns: 30px 2fr 1fr 1fr 40px; gap:10px; align-items:center; background:#fff; padding:10px; border:1px solid #ddd; border-radius:4px;">
					<div><input type="checkbox" class="variation-select-checkbox"></div>
					<div>
                        <strong>${name}</strong>
                        <input type="hidden" name="v_name[]" value="${name}">
                        <input type="hidden" name="v_id[]" value="${v_id}">
                        <input type="hidden" name="v_attrs[]" value='${attr_str}'>
                        <div style="font-size:10px; color:#666; margin-top:4px;">SKU: <input type="text" name="v_sku[]" value="" placeholder="自動付与可" style="width:100px; font-size:10px; padding:2px;"></div>
                    </div>
					<div>在庫: <input type="number" name="v_stock[]" value="10" style="width:60px;"></div>
					<div>価格: <input type="number" name="v_price[]" value="0" style="width:80px;"> <span style="font-size:10px; color:#666;">(+加算)</span></div>
					<button type="button" class="remove-variation button" style="color:red; border:none; background:none; font-size:18px; cursor:pointer;">&times;</button>
				</div>`;
				$list.append(row);
				added_count++;
			});

			if (is_append && added_count === 0) {
				alert("新しい組み合わせは見つかりませんでした。すべて追加済みです。");
			}
		});

		// Checkbox handle all selection
		$("#ec_variation_check_all").on("change", function() {
			$(".variation-select-checkbox").prop("checked", $(this).prop("checked"));
		});

		$(document).on("click", ".remove-variation", function() {
			if(confirm("このバリエーションを削除しますか？")) {
				$(this).closest(".variation-row").remove();
			}
		});

		$("#apply_bulk_price").on("click", function() {
			const price = $("#bulk_v_price").val();
			var $checked = $(".variation-select-checkbox:checked");
			
			if ($checked.length === 0) {
				alert("対象となるバリエーションにチェックを入れてください。");
				return;
			}
			
			if(confirm($checked.length + " 件の選択したバリエーションに価格 " + price + " 円を適用しますか？")) {
				$checked.closest(".variation-row").find("input[name='v_price[]']").val(price);
			}
		});

		$("#apply_bulk_sku").on("click", function() {
			const prefix = $("#bulk_v_sku_prefix").val();
			if(!prefix) return alert("プレフィックスを入力してください");
			
			var $checked = $(".variation-select-checkbox:checked");
			if ($checked.length === 0) {
				alert("対象となるバリエーションにチェックを入れてください。");
				return;
			}
			
			if(confirm($checked.length + " 件の選択したバリエーションにSKUを一括付与しますか？")) {
				$checked.each(function(index) {
					const $input = $(this).closest(".variation-row").find("input[name='v_sku[]']");
					$input.val(prefix + (index + 1).toString().padStart(3, '0'));
				});
			}
		});
	});
	</script>
	<?php
	echo '</div>'; // End variation_manager_wrap section
	echo '</div>'; // End parent wrapper for stock

	echo '<div style="margin-top:15px; border-top:1px solid #eee; padding-top:15px; grid-column: span 3;">';
	$product_label = get_post_meta($post->ID, '_photo_product_label', true);
	$product_label_color = get_post_meta($post->ID, '_photo_product_label_color', true) ?: '#4f46e5';
	echo '<label for="photo_product_label"><strong>' . __('商品ラベル (例: NEW, おすすめ)', 'photo-purchase') . '</strong></label><br>';
	echo '<div style="display:flex; gap:10px; align-items:center; margin-top:5px; flex-wrap:wrap;">';
	echo '<input type="text" id="photo_product_label" name="photo_product_label" value="' . esc_attr($product_label) . '" style="width:100%; max-width:250px; padding:8px; border-radius:5px; border:1px solid #ccc;" placeholder="空欄にすると表示されません">';
	echo '<input type="color" id="photo_product_label_color" name="photo_product_label_color" value="' . esc_attr($product_label_color) . '" style="width:40px; height:38px; padding:2px; border:1px solid #ccc; border-radius:4px; cursor:pointer;" title="ラベルの色を選択">';
	
	// Preset colors
	$presets = [
		'#4f46e5' => 'Indigo',
		'#dc2626' => 'Crimson',
		'#059669' => 'Emerald',
		'#d97706' => 'Gold',
		'#7c3aed' => 'Purple',
		'#1e293b' => 'Slate'
	];
	echo '<div style="display:flex; gap:5px;">';
	foreach ($presets as $code => $name) {
		echo '<button type="button" class="label-color-preset" data-color="' . $code . '" style="width:24px; height:24px; border-radius:50%; border:2px solid transparent; background:' . $code . '; cursor:pointer; box-shadow:0 0 0 1px #ddd;" title="' . $name . '"></button>';
	}
	echo '</div>';
	echo '</div>';

	echo '<script>
	document.querySelectorAll(".label-color-preset").forEach(btn => {
		btn.addEventListener("click", function() {
			document.getElementById("photo_product_label_color").value = this.dataset.color;
		});
	});
	</script>';

	echo '<p class="description">商品画像の左上に表示されるカスタムラベルの文言と色（背景）を変更できます。</p>';
	echo '</div>';

	echo '</div>'; // End grid

	$is_subscription = get_post_meta($post->ID, '_photo_is_subscription', true);
	$sub_requires_shipping = get_post_meta($post->ID, '_photo_sub_requires_shipping', true);
	$sub_price = get_post_meta($post->ID, '_photo_price_subscription', true);
	$sub_interval = get_post_meta($post->ID, '_photo_sub_interval', true) ?: 'month';
	$sub_interval_count = get_post_meta($post->ID, '_photo_sub_interval_count', true) ?: '1';

	echo '<hr style="margin: 20px 0;">';
	echo '<div style="background: #f0f7ff; padding: 15px; border-radius: 8px; border: 1px solid #c2e0ff;">';
	echo '<h4 style="margin-top:0;"><span class="dashicons dashicons-update" style="vertical-align: middle;"></span> ' . __('サブスクリプション（継続課金）設定', 'photo-purchase') . '</h4>';
	echo '<div style="display: flex; gap: 20px; align-items: flex-start; flex-direction: column;">';
	
	echo '<div>';
	echo '<label><input type="checkbox" id="photo_is_subscription" name="photo_is_subscription" value="1" ' . checked($is_subscription, '1', false) . '> <strong>' . __('この商品をサブスクリプションにする', 'photo-purchase') . '</strong></label>';
	echo '</div>';

	echo '<div class="sub-fields" style="' . ($is_subscription === '1' ? '' : 'display:none;') . '">';
	echo '<div style="margin-bottom:10px;">';
	echo '<label><input type="checkbox" name="photo_sub_requires_shipping" value="1" ' . checked($sub_requires_shipping, '1', false) . '> <strong>' . __('配送が必要な商品 (定期購入)', 'photo-purchase') . '</strong></label>';
	echo '<p class="description" style="margin-left:25px;">チェックを入れると、チェックアウト時に住所登録が必須になります。</p>';
	echo '</div>';
	echo '<div>';
	echo '<span>価格: <input type="number" name="photo_price_subscription" value="' . esc_attr($sub_price) . '" style="width:100px;"> 円 / </span>';
	echo ' <span>サイクル: <input type="number" name="photo_sub_interval_count" value="' . esc_attr($sub_interval_count) . '" style="width:50px;" min="1"> ';
	echo '<select name="photo_sub_interval">';
	echo '<option value="day" ' . selected($sub_interval, 'day', false) . '>日</option>';
	echo '<option value="week" ' . selected($sub_interval, 'week', false) . '>週</option>';
	echo '<option value="month" ' . selected($sub_interval, 'month', false) . '>月</option>';
	echo '<option value="year" ' . selected($sub_interval, 'year', false) . '>年</option>';
	echo '</select> ごとに課金</span>';
	echo '</div>';

	echo '</div>';
	echo '<p class="description">サブスクリプションを有効にすると、Stripe Checkout画面で継続課金として処理されます。</p>';
	echo '</div>';

	if ($enable_digital === '1') {
		echo '<hr style="margin: 20px 0;">';

		echo '<p>';
		echo '<label for="photo_high_res_file"><strong>' . __('ダウンロード用ファイル', 'photo-purchase') . '</strong></label><br>';
		echo '<input type="text" id="photo_high_res_file" name="photo_high_res_file" value="' . esc_attr($high_res_file) . '" style="width:60%;" readonly>';
		echo '<input type="hidden" id="photo_high_res_id" name="photo_high_res_id" value="' . esc_attr($high_res_id) . '">';
		echo ' <button type="button" class="button photo_purchase_upload_button">' . __('ファイルを選択', 'photo-purchase') . '</button>';
		echo ' <button type="button" class="button photo_purchase_clear_button" style="color:#b32d2e;" ' . (empty($high_res_file) ? 'style="display:none;"' : '') . '>' . __('クリア', 'photo-purchase') . '</button>';
		echo '</p>';
	}

	echo '<hr style="margin: 20px 0;">';
	echo '<p><strong>' . __('商品ギャラリー (サブ画像)', 'photo-purchase') . '</strong></p>';
	echo '<div id="ec_gallery_container" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px; border: 1px dashed #ccc; padding: 10px; min-height: 100px; background: #f9f9f9;">';
	if ($gallery_ids) {
		$ids = explode(',', $gallery_ids);
		foreach ($ids as $id) {
			$url = wp_get_attachment_thumb_url($id);
			if ($url) {
				echo '<div class="gallery-item" data-id="' . $id . '" style="position:relative; width:80px; height:80px; border:1px solid #ddd; background:#fff; padding:2px;">';
				echo '<img src="' . $url . '" style="width:100%; height:100%; object-fit:cover; cursor:move;">';
				echo '<button type="button" class="remove-gallery-item" style="position:absolute; top:-5px; right:-5px; background:#f00; color:#fff; border:none; border-radius:50%; width:18px; height:18px; font-size:12px; cursor:pointer; display:flex; align-items:center; justify-content:center;">&times;</button>';
				echo '</div>';
			}
		}
	}
	echo '</div>';
	echo '<input type="hidden" name="ec_gallery_ids" id="ec_gallery_ids" value="' . esc_attr($gallery_ids) . '">';
	echo '<button type="button" class="button" id="ec_select_gallery_button">' . __('ギャラリー画像を追加', 'photo-purchase') . '</button>';
	echo '<p class="description">画像をクリックしてドラッグすると並び替えができます。</p>';

	// --- Custom Options Consolidated (v6) ---
	$custom_options = get_post_meta($post->ID, '_photo_custom_options', true);
	if (!is_array($custom_options)) $custom_options = array();
	
	echo '<hr style="margin: 30px 0 15px;">';
	
	echo '<fieldset style="border: 1px solid #ccc; padding: 15px; border-radius: 5px; margin-bottom: 20px; background: #fdfdfd;">';
	echo '<legend style="padding: 0 10px; font-weight: bold; font-size: 14px; color: #333;">' . __('商品オプション設定', 'photo-purchase') . '</legend>';
	echo '<p class="description" style="margin-top:0;">' . __('商品の選択項目（サイズ、カラー、ギフトラッピング等）を設定します。<br>ラジオボタンにする場合は同じグループ名（例：サイズ）を設定し、個別に選択させる（複数可）場合はグループ名を空にするか個別に設定してください。', 'photo-purchase') . '</p>';
	
	echo '<div id="ec_options_container" style="margin-bottom: 10px;">';
	foreach ($custom_options as $index => $opt) {
		$type = $opt['type'] ?? 'radio';
		$group = $opt['group'] ?? '';
		$required = $opt['required'] ?? '0';
		$category = $opt['category'] ?? 'attribute';
		echo photo_purchase_render_option_row_v5($opt['name'], $opt['price'], $type, $group, $category, $required);
	}
	echo '</div>';
	echo '<button type="button" class="button ec-add-opt-v5">' . __('オプション項目を追加', 'photo-purchase') . '</button>';
	echo '</fieldset>';
	
	?>
	<script>
		jQuery(document).ready(function ($) {
			$('.ec-add-opt-v5').click(function() {
				var container = $('#ec_options_container');
				var cat = 'attribute'; 
				var defType = 'radio';
				var isRequired = false;
				
				var row = '<div class="custom-option-row" style="display: flex; gap: 10px; margin-bottom: 5px; align-items: center; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">' +
					'<input type="hidden" name="ec_opt_category[]" value="' + cat + '">' +
					'<div style="flex:2;">' +
					'<label style="font-size:11px; color:#666;">グループ名 (例: サイズ)</label><br>' +
					'<input type="text" name="ec_opt_group[]" value="" placeholder="グループ名" style="width:100%;">' +
					'</div>' +
					'<div style="flex:3;">' +
					'<label style="font-size:11px; color:#666;">オプション名 (例: Lサイズ)</label><br>' +
					'<input type="text" name="ec_opt_name[]" value="" placeholder="名称" style="width:100%;">' +
					'</div>' +
					'<div style="width:100px;">' +
					'<label style="font-size:11px; color:#666;">追加価格</label><br>' +
					'<input type="number" name="ec_opt_price[]" value="0" placeholder="価格" style="width:70px;"> 円' +
					'</div>' +
					'<div style="width:120px;">' +
					'<label style="font-size:11px; color:#666;">形式</label><br>' +
					'<select name="ec_opt_type[]" style="width:100%;">' +
					'<option value="radio" ' + (defType === 'radio' ? 'selected' : '') + '>単一 (Radio)</option>' +
					'<option value="checkbox" ' + (defType === 'checkbox' ? 'selected' : '') + '>複数 (Check)</option>' +
					'</select>' +
					'</div>' +
					'<div style="width:60px;">' +
					'<label style="font-size:11px; color:#666;">必須</label><br>' +
					'<input type="checkbox" name="ec_opt_required_check[]" value="1" ' + (isRequired ? 'checked' : '') + ' class="ec-opt-req-proxy">' +
					'<input type="hidden" name="ec_opt_required[]" value="' + (isRequired ? '1' : '0') + '" class="ec-opt-req-hidden">' +
					'</div>' +
					'<button type="button" class="remove-opt button" style="color:red; align-self:flex-end;">&times;</button>' +
					'</div>';
				container.append(row);
			});
			$(document).on('change', '.ec-opt-req-proxy', function() {
				$(this).siblings('.ec-opt-req-hidden').val($(this).is(':checked') ? '1' : '0');
			});
			$(document).on('click', '.remove-opt', function() {
				$(this).closest('.custom-option-row').remove();
			});
			$('.photo_purchase_upload_button').click(function (e) {
				e.preventDefault();
				var frame = wp.media({
					title: '<?php _e("ダウンロード用ファイルを選択", "photo-purchase"); ?>',
					button: { text: '<?php _e("このファイルを使用する", "photo-purchase"); ?>' },
					multiple: false
				});
				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					$('#photo_high_res_file').val(attachment.url);
					$('#photo_high_res_id').val(attachment.id);
				});
				frame.open();
			});

			$('.photo_purchase_clear_button').click(function(e) {
				e.preventDefault();
				if (confirm('ダウンロード用ファイルの設定をクリアしますか？')) {
					$('#photo_high_res_file').val('');
					$('#photo_high_res_id').val('');
				}
			});

			// Gallery Selection
			var galleryFrame;
			$('#ec_select_gallery_button').click(function (e) {
				e.preventDefault();
				if (galleryFrame) { galleryFrame.open(); return; }
				galleryFrame = wp.media({
					title: '<?php _e("ギャラリー画像を選択", "photo-purchase"); ?>',
					button: { text: '<?php _e("ギャラリーに追加", "photo-purchase"); ?>' },
					multiple: true
				});
				galleryFrame.on('select', function () {
					var selection = galleryFrame.state().get('selection');
					var currentIds = $('#ec_gallery_ids').val() ? $('#ec_gallery_ids').val().split(',') : [];
					selection.map(function (attachment) {
						attachment = attachment.toJSON();
						if (currentIds.indexOf(attachment.id.toString()) === -1) {
							currentIds.push(attachment.id);
							$('#ec_gallery_container').append(
								'<div class="gallery-item" data-id="' + attachment.id + '" style="position:relative; width:80px; height:80px; border:1px solid #ddd; background:#fff; padding:2px; cursor:move;">' +
								'<img src="' + (attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url) + '" style="width:100%; height:100%; object-fit:cover;">' +
								'<button type="button" class="remove-gallery-item" style="position:absolute; top:-5px; right:-5px; background:#f00; color:#fff; border:none; border-radius:50%; width:18px; height:18px; font-size:12px; cursor:pointer; display:flex; align-items:center; justify-content:center;">&times;</button>' +
								'</div>'
							);
						}
					});
					$('#ec_gallery_ids').val(currentIds.join(','));
				});
				galleryFrame.open();
			});

			$(document).on('click', '.remove-gallery-item', function () {
				var item = $(this).closest('.gallery-item');
				var id = item.data('id').toString();
				var currentIds = $('#ec_gallery_ids').val().split(',');
				currentIds = currentIds.filter(function (cid) { return cid !== id; });
				$('#ec_gallery_ids').val(currentIds.join(','));
				item.remove();
			});

			$('#photo_is_subscription').change(function() {
				if ($(this).is(':checked')) {
					$('.sub-fields').fadeIn();
				} else {
					$('.sub-fields').fadeOut();
				}
			});

			if ($.fn.sortable) {
				$('#ec_gallery_container').sortable({
					update: function () {
						var ids = [];
						$('#ec_gallery_container .gallery-item').each(function () {
							ids.push($(this).data('id'));
						});
						$('#ec_gallery_ids').val(ids.join(','));
					}
				});
			}
		});
	</script>
	<?php
}

/**
 * Save Meta Box Data
 */
function photo_purchase_save_meta($post_id)
{
	if (!isset($_POST['photo_purchase_nonce']) || !wp_verify_nonce($_POST['photo_purchase_nonce'], 'photo_purchase_save_meta')) {
		return;
	}
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
		return;

	// Capture old status for restock notification
	$old_is_sold_out = get_post_meta($post_id, '_photo_is_sold_out', true) === '1';
	$old_manage_stock = get_post_meta($post_id, '_photo_manage_stock', true) === '1';
	$old_stock_qty = intval(get_post_meta($post_id, '_photo_stock_qty', true));
	$was_unavailable = $old_is_sold_out || ($old_manage_stock && $old_stock_qty <= 0);
	if (!current_user_can('edit_post', $post_id))
		return;

	if (isset($_POST['photo_price_digital'])) {
		update_post_meta($post_id, '_photo_price_digital', sanitize_text_field($_POST['photo_price_digital']));
	}
	if (isset($_POST['photo_price_l'])) {
		update_post_meta($post_id, '_photo_price_l', sanitize_text_field($_POST['photo_price_l']));
	}
	if (isset($_POST['photo_price_2l'])) {
		update_post_meta($post_id, '_photo_price_2l', sanitize_text_field($_POST['photo_price_2l']));
	}
	if (isset($_POST['photo_tax_type'])) {
		update_post_meta($post_id, '_photo_tax_type', sanitize_text_field($_POST['photo_tax_type']));
	}
	if (isset($_POST['photo_high_res_file'])) {
		update_post_meta($post_id, '_photo_high_res_file', esc_url_raw($_POST['photo_high_res_file']));
	}
	if (isset($_POST['photo_high_res_id'])) {
		update_post_meta($post_id, '_photo_high_res_id', intval($_POST['photo_high_res_id']));
	}
	if (isset($_POST['ec_gallery_ids'])) {
		update_post_meta($post_id, '_ec_gallery_ids', sanitize_text_field($_POST['ec_gallery_ids']));
	}

	// Save Subscription info
	$is_sub = isset($_POST['photo_is_subscription']) ? '1' : '0';
	update_post_meta($post_id, '_photo_is_subscription', $is_sub);
	
	$sub_req_shipping = isset($_POST['photo_sub_requires_shipping']) ? '1' : '0';
	update_post_meta($post_id, '_photo_sub_requires_shipping', $sub_req_shipping);
	if (isset($_POST['photo_price_subscription'])) {
		update_post_meta($post_id, '_photo_price_subscription', intval($_POST['photo_price_subscription']));
	}
	if (isset($_POST['photo_sub_interval'])) {
		update_post_meta($post_id, '_photo_sub_interval', sanitize_text_field($_POST['photo_sub_interval']));
	}
	if (isset($_POST['photo_sub_interval_count'])) {
		update_post_meta($post_id, '_photo_sub_interval_count', intval($_POST['photo_sub_interval_count']));
	}

	// Save Sold Out status
	$is_sold_out = isset($_POST['photo_is_sold_out']) ? '1' : '0';
	update_post_meta($post_id, '_photo_is_sold_out', $is_sold_out);

	if (isset($_POST['photo_manage_stock'])) {
		update_post_meta($post_id, '_photo_manage_stock', '1');
		update_post_meta($post_id, '_photo_stock_qty', intval($_POST['photo_stock_qty']));
	} else {
		update_post_meta($post_id, '_photo_manage_stock', '0');
	}

	// Trigger restock notification if becomes available
	$new_sold_out_flag = isset($_POST['photo_is_sold_out']) ? '1' : '0';
	$new_manage_stock_flag = isset($_POST['photo_manage_stock']) ? '1' : '0';
	$new_stock_qty_val = intval($_POST['photo_stock_qty'] ?? 0);
	
	$is_now_available = ($new_sold_out_flag === '0') && ($new_manage_stock_flag === '0' || $new_stock_qty_val > 0);

	if ($was_unavailable && $is_now_available) {
		if (function_exists('photo_purchase_send_restock_notifications')) {
			photo_purchase_send_restock_notifications($post_id);
		}
	}

	if (isset($_POST['photo_product_label'])) {
		update_post_meta($post_id, '_photo_product_label', sanitize_text_field($_POST['photo_product_label']));
	}
	if (isset($_POST['photo_product_label_color'])) {
		update_post_meta($post_id, '_photo_product_label_color', sanitize_hex_color($_POST['photo_product_label_color']));
	}

	// Save Custom Options
	if (isset($_POST['ec_opt_name']) && is_array($_POST['ec_opt_name'])) {
		$options = array();
		foreach ($_POST['ec_opt_name'] as $i => $name) {
			$name = sanitize_text_field($name);
			$price = intval($_POST['ec_opt_price'][$i]);
			$type = sanitize_text_field($_POST['ec_opt_type'][$i] ?? 'checkbox');
			$group = sanitize_text_field($_POST['ec_opt_group'][$i] ?? '');
			$category = sanitize_text_field($_POST['ec_opt_category'][$i] ?? 'attribute');
			$required = sanitize_text_field($_POST['ec_opt_required'][$i] ?? '0');
			if (!empty($name)) {
				$options[] = array(
					'name' => $name,
					'price' => $price,
					'type' => $type,
					'group' => $group,
					'category' => $category,
					'required' => $required
				);
			}
		}
		update_post_meta($post_id, '_photo_custom_options', $options);
	} else {
		delete_post_meta($post_id, '_photo_custom_options');
	}

	// Save Variation info (v4.1.0)
	$use_vars = isset($_POST['photo_use_variations']) ? '1' : '0';
	update_post_meta($post_id, '_photo_use_variations', $use_vars);

	if (isset($_POST['v_id']) && is_array($_POST['v_id'])) {
		$variations = array();
		foreach ($_POST['v_id'] as $i => $v_id) {
			$v_id = sanitize_text_field($v_id);
			$name = sanitize_text_field($_POST['v_name'][$i]);
			$stock = intval($_POST['v_stock'][$i]);
			$price = intval($_POST['v_price'][$i]);
			$sku = sanitize_text_field($_POST['v_sku'][$i] ?? '');
			$attrs = json_decode(stripslashes($_POST['v_attrs'][$i]), true);

			$variations[$v_id] = array(
				'variation_id' => $v_id,
				'name' => $name,
				'stock' => $stock,
				'price' => $price,
				'sku' => $sku,
				'attrs' => $attrs
			);
		}
		update_post_meta($post_id, '_photo_variation_skus', $variations);
	} else {
		delete_post_meta($post_id, '_photo_variation_skus');
	}

	// Check for stock alert
	if (function_exists('photo_purchase_check_stock_alert')) {
		photo_purchase_check_stock_alert($post_id);
	}
}
add_action('save_post', 'photo_purchase_save_meta');

/**
 * Add Bulk Tool Menu
 */
function photo_purchase_bulk_menu()
{
	add_submenu_page(
		'edit.php?post_type=photo_product',
		__('一括登録', 'photo-purchase'),
		__('一括登録', 'photo-purchase'),
		'manage_options',
		'photo-bulk-upload',
		'photo_purchase_bulk_page'
	);
}
add_action('admin_menu', 'photo_purchase_bulk_menu');

/**
 * Bulk Upload Page Callback
 */
function photo_purchase_bulk_page()
{
	if (isset($_POST['photo_bulk_submit']) && check_admin_referer('photo_bulk_action', 'photo_bulk_nonce')) {
		$image_ids = explode(',', $_POST['bulk_image_ids']);
		$event_id = intval($_POST['photo_event_id']);
		$price_digital = intval($_POST['default_price_digital']);
		$price_l = intval($_POST['default_price_l']);
		$price_2l = intval($_POST['default_price_2l']);
		$tax_type = sanitize_text_field($_POST['default_tax_type'] ?? 'standard');
		$count = 0;

		$enable_digital_bulk = isset($_POST['enable_digital_bulk']);
		$enable_physical_bulk = isset($_POST['enable_physical_bulk']);

		foreach ($image_ids as $attachment_id) {
			$attachment_id = intval(trim($attachment_id));
			if (!$attachment_id)
				continue;

			$post_id = wp_insert_post(array(
				'post_title' => get_the_title($attachment_id),
				'post_type' => 'photo_product',
				'post_status' => 'publish',
			));

			if ($post_id) {
				update_post_meta($post_id, '_thumbnail_id', $attachment_id);
				
				$final_price_digital = $enable_digital_bulk ? $price_digital : 0;
				$final_price_l = $enable_physical_bulk ? $price_l : 0;
				
				update_post_meta($post_id, '_photo_price_digital', $final_price_digital);
				update_post_meta($post_id, '_photo_price', $final_price_digital); // Compatibility
				update_post_meta($post_id, '_photo_price_l', $final_price_l);
				update_post_meta($post_id, '_photo_price_2l', $price_2l);
				update_post_meta($post_id, '_photo_tax_type', $tax_type);
				update_post_meta($post_id, '_photo_high_res_file', wp_get_attachment_url($attachment_id));
				update_post_meta($post_id, '_photo_high_res_id', $attachment_id);
				if ($event_id) {
					wp_set_post_terms($post_id, array($event_id), 'photo_event');
				}
				$count++;
			}
		}
		echo '<div class="updated"><p>' . sprintf(__('%d 件の商品を登録しました。', 'photo-purchase'), $count) . '</p></div>';
	}

	$events = get_terms(array('taxonomy' => 'photo_event', 'hide_empty' => false));
	?>
	<div class="wrap">
		<h1><?php _e('メディアライブラリから販売商品を一括登録', 'photo-purchase'); ?></h1>
		<form method="post">
			<?php wp_nonce_field('photo_bulk_action', 'photo_bulk_nonce'); ?>
			<table class="form-table">
				<tr>
					<th><?php _e('アイテムを選択', 'photo-purchase'); ?></th>
					<td>
						<input type="hidden" name="bulk_image_ids" id="bulk_image_ids">
						<div id="bulk_image_preview" style="display:flex; flex-wrap:wrap; gap:5px; margin-bottom:10px;">
						</div>
						<button type="button" class="button"
							id="select_bulk_images"><?php _e('メディアライブラリから選択', 'photo-purchase'); ?></button>
					</td>
				</tr>
				<tr>
					<th><?php _e('関連付けるカテゴリー', 'photo-purchase'); ?></th>
					<td>
						<select name="photo_event_id">
							<option value="0"><?php _e('なし', 'photo-purchase'); ?></option>
							<?php foreach ($events as $event): ?>
								<option value="<?php echo $event->term_id; ?>"><?php echo esc_html($event->name); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php _e('デフォルト価格設定 (円)', 'photo-purchase'); ?></th>
					<td>
						<div style="display: flex; flex-direction: column; gap: 15px;">
							<?php if (get_option('photo_pp_enable_digital_sales', '1') === '1'): ?>
								<div style="display: flex; align-items: center; gap: 10px;">
									<input type="checkbox" name="enable_digital_bulk" id="enable_digital_bulk" checked>
									<label for="enable_digital_bulk"><strong>ダウンロード版を販売する</strong></label>
									<span>価格: <input type="number" name="default_price_digital" value="300" style="width:100px;"> 円</span>
								</div>
							<?php endif; ?>
							
							<div style="display: flex; align-items: center; gap: 10px;">
								<input type="checkbox" name="enable_physical_bulk" id="enable_physical_bulk" checked>
								<label for="enable_physical_bulk"><strong>配送品（現物）として販売する</strong></label>
								<span>価格: <input type="number" name="default_price_l" value="500" style="width:100px;"> 円</span>
							</div>

							<div style="margin-top:5px; border-top:1px solid #eee; padding-top:10px;">
								<label>デフォルト税率: 
									<select name="default_tax_type">
										<option value="standard">標準税率 (10%)</option>
										<option value="reduced">軽減税率 (8%)</option>
									</select>
								</label>
								<input type="hidden" name="default_price_2l" value="0">
							</div>
						</div>
						<p class="description">チェックを入れた形式のみ商品が生成されます。食品などの場合は「配送品」のみにチェックを入れてください。</p>
					</td>
				</tr>
			</table>
			<p>
				<input type="submit" name="photo_bulk_submit" class="button button-primary"
					value="<?php _e('商品を一括生成する', 'photo-purchase'); ?>">
			</p>
		</form>
	</div>
	<script>
		jQuery(document).ready(function ($) {
			var frame;
			$('#select_bulk_images').click(function (e) {
				e.preventDefault();
				if (frame) { frame.open(); return; }
				frame = wp.media({
					title: '<?php _e("登録するアイテムを選択してください", "photo-purchase"); ?>',
					button: { text: '<?php _e("これらのアイテムを処理する", "photo-purchase"); ?>' },
					multiple: true
				});
				frame.on('select', function () {
					var selection = frame.state().get('selection');
					var ids = [];
					$('#bulk_image_preview').empty();
					selection.map(function (attachment) {
						attachment = attachment.toJSON();
						ids.push(attachment.id);
						$('#bulk_image_preview').append('<img src="' + attachment.url + '" style="width:50px;height:50px;object-fit:cover;">');
					});
					$('#bulk_image_ids').val(ids.join(','));
				});
				frame.open();
			});
		});
	</script>
	<?php
}

function photo_purchase_admin_scripts($hook)
{
	wp_enqueue_media();
	wp_enqueue_script('jquery-ui-sortable');
	wp_enqueue_script('photo-purchase-admin', PHOTO_PURCHASE_URL . 'assets/js/admin.js', array('jquery'), PHOTO_PURCHASE_VERSION, true);
	wp_localize_script('photo-purchase-admin', 'photoPurchaseAdmin', array(
		'ajax_url' => admin_url('admin-ajax.php'),
		'nonce_status' => wp_create_nonce('photo_update_order_status_ajax'),
	));
}
add_action('admin_enqueue_scripts', 'photo_purchase_admin_scripts');

/**
 * Render Option Row v5 (Helper)
 */
function photo_purchase_render_option_row_v5($name, $price, $type, $group, $category = 'attribute', $required = '0') {
	$html = '<div class="custom-option-row" style="display: flex; gap: 10px; margin-bottom: 5px; align-items: center; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">';
	$html .= '<input type="hidden" name="ec_opt_category[]" value="' . esc_attr($category) . '">';
	
	// Group Name
	$html .= '<div style="flex:2;">';
	$html .= '<label style="font-size:11px; color:#666;">グループ名 (例: サイズ)</label><br>';
	$html .= '<input type="text" name="ec_opt_group[]" value="' . esc_attr($group) . '" placeholder="グループ名" style="width:100%;">';
	$html .= '</div>';
	
	$html .= '<div style="flex:3;">';
	$html .= '<label style="font-size:11px; color:#666;">オプション名 (例: Lサイズ)</label><br>';
	$html .= '<input type="text" name="ec_opt_name[]" value="' . esc_attr($name) . '" placeholder="名称" style="width:100%;">';
	$html .= '</div>';
	
	$html .= '<div style="width:100px;">';
	$html .= '<label style="font-size:11px; color:#666;">追加価格</label><br>';
	$html .= '<input type="number" name="ec_opt_price[]" value="' . esc_attr($price) . '" placeholder="価格" style="width:70px;"> 円';
	$html .= '</div>';
	
	$html .= '<div style="width:120px;">';
	$html .= '<label style="font-size:11px; color:#666;">形式</label><br>';
	$html .= '<select name="ec_opt_type[]" style="width:100%;">';
	$html .= '<option value="radio" ' . selected($type, 'radio', false) . '>単一 (Radio)</option>';
	$html .= '<option value="checkbox" ' . selected($type, 'checkbox', false) . '>複数 (Check)</option>';
	$html .= '</select>';
	$html .= '</div>';

	// Required checkbox
	$html .= '<div style="width:60px;">';
	$html .= '<label style="font-size:11px; color:#666;">必須</label><br>';
	$html .= '<input type="checkbox" name="ec_opt_required_check[]" value="1" ' . checked($required, '1', false) . ' class="ec-opt-req-proxy">';
	$html .= '<input type="hidden" name="ec_opt_required[]" value="' . esc_attr($required) . '" class="ec-opt-req-hidden">';
	$html .= '</div>';
	
	$html .= '<button type="button" class="remove-opt button" style="color:red; align-self:flex-end;">&times;</button>';
	$html .= '</div>';
	
	return $html;
}

/**
 * Render Variation Row (Helper v4.1.0)
 */
function photo_purchase_render_variation_row($v_id, $v) {
	$name = esc_attr($v['name']);
	$stock = intval($v['stock'] ?? 0);
	$price = intval($v['price'] ?? 0);
	$sku = esc_attr($v['sku'] ?? '');
	$attrs_json = esc_attr(json_encode($v['attrs'] ?? []));

	$html = '<div class="variation-row" style="display:grid; grid-template-columns: 30px 2fr 1fr 1fr 40px; gap:10px; align-items:center; background:#fff; padding:10px; border:1px solid #ddd; border-radius:4px;">';
	$html .= '<div><input type="checkbox" class="variation-select-checkbox"></div>';
	$html .= '<div>';
	$html .= '<strong>' . $name . '</strong>';
	$html .= '<input type="hidden" name="v_name[]" value="' . $name . '">';
	$html .= '<input type="hidden" name="v_id[]" value="' . esc_attr($v_id) . '">';
	$html .= '<input type="hidden" name="v_attrs[]" value=\'' . $attrs_json . '\'>';
	$html .= '<div style="font-size:10px; color:#666; margin-top:4px;">SKU: <input type="text" name="v_sku[]" value="' . $sku . '" placeholder="自動付与可" style="width:100px; font-size:10px; padding:2px;"></div>';
	$html .= '</div>';
	$html .= '<div>在庫: <input type="number" name="v_stock[]" value="' . $stock . '" style="width:60px;"></div>';
	$html .= '<div>価格: <input type="number" name="v_price[]" value="' . $price . '" style="width:80px;"> <span style="font-size:10px; color:#666;">(+加算)</span></div>';
	$html .= '<button type="button" class="remove-variation button" style="color:red; border:none; background:none; font-size:18px; cursor:pointer;">&times;</button>';
	$html .= '</div>';
	
	return $html;
}
