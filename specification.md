# シンプルECプラグイン 技術仕様書 (v3.8.0)

本ドキュメントは、シンプルECプラグインの内部構造および技術仕様をまとめたものです。

## 1. データベース定義

### 受注テーブル (`wp_photo_orders`)
| カラム名 | 型 | 説明 |
| :--- | :--- | :--- |
| `id` | mediumint(9) | 主キー（自動採番） |
| `order_token` | varchar(255) | 注文を識別するユニークトークン（注文照会用） |
| `buyer_name` | varchar(255) | 購入者氏名 |
| `buyer_email` | varchar(255) | 購入者メールアドレス |
| `order_items` | text | 注文商品のJSON（ID, 形式, 数量, 単価, 適用税率, 税率タイプ等） |
| `shipping_info` | text | 配送先情報のJSON（郵便番号, 住所等） |
| `coupon_info` | text | 適用されたクーポンのJSON（コード, 種別, 額, 割引額） |
| `total_amount` | int(11) | 合計金額（クーポン適用後） |
| `payment_method` | varchar(50)  | 決済方法 (`card`, `paypay`, `bank_transfer`, `cod`) |
| `status` | varchar(50)  | 注文状態 (`pending_payment`, `processing`, `completed`, `cancelled`, `active`) |
| `transaction_id` | varchar(255) | Stripe等の外部決済トランザクションID（返金用） |
| `stripe_customer_id` | varchar(255) | StripeカスタマーID（カスタマーポータル用） [v3.0.0] |
| `stripe_subscription_id` | varchar(255) | StripeサブスクリプションID [v3.0.0] |
| `tracking_number`| varchar(255) | 配送追跡番号 |
| `created_at` | datetime | 注文日時 |

### クーポンテーブル (`wp_photo_coupons`)
| カラム名 | 型 | 説明 |
| :--- | :--- | :--- |
| `id` | mediumint(9) | 主キー |
| `code` | varchar(50) | クーポンコード（ユニーク） |
| `type` | varchar(20) | 割引種別 (`percent`, `fixed`) |
| `amount` | decimal(10,2) | 割引額（または率） |
| `expiry_date` | date | 有効期限 |
| `usage_limit` | int(11) | 利用回数上限 |
| `usage_count` | int(11) | 現在の利用回数 |
| `active` | tinyint(1) | 有効フラグ |
| `created_at` | datetime | 作成日時 |
| `updated_at` | datetime | 更新日時 |

## 2. 決済・返金フロー

### Stripe / PayPay 連携
- **決済時**: セッション完了時に `transaction_id` を保存。
- **自動返金**: 注文ステータスを「キャンセル」に変更した際、`transaction_id` が存在すれば Stripe API を通じて自動的に全額返金リクエストを送信。
- **サブスクリプション**: 銀行振込・代金引換を制限。ただし「配送が必要な商品 (定期購入)」フラグがオンの場合は住所入力を必須化。オフの場合は入力をスキップ。
- **PayPay**: StripeのPayment Methodとして統合。
- **サブスクリプション [v3.0.0]**: 
    - 商品メタ `_photo_is_subscription` が `1` の場合、Stripe Checkout を `subscription` モードで実行。
    - **支払い制限**: `bank_transfer`、`cod`、`paypay` は選択不可となります（クレジットカード決済のみ対応）。
    - 継続課金が解約された場合、Webhook (`customer.subscription.deleted`) を通じて注文ステータスを自動的に「キャンセル」に変更。

### Stripe カスタマーポータル [v3.0.0]
- 購入者が自身のサブスクリプションを管理（支払い方法変更、解約等）できるポータルへのリンクを提供。
- `stripe_customer_id` を使用してポータルセッションを生成。

## 3. 主要ロジック

### アイコン・UIの実装
- **SVG埋め込み**: Dashicons フォントへの依存を排除し、フロントエンドの全アイコンを SVG 形式で直接埋め込み。
- **チェックアウトレイアウト**: 支払い方法選択の直下に注文内容と合計金額を表示。JSでの金額変更時にハイライト表示を適用。

### 配送料・税率・手数料計算
- `photo_pp_tax_rate_standard`: 標準税率（初期値 10%）
- `photo_pp_tax_rate_reduced`: 軽減税率（初期値 8%）
- `photo_pp_shipping_flat_rate`: 全国一律送料
- `photo_pp_shipping_free_threshold`: 送料無料閾値
- **手数料 (COD)**: 配送総額に応じた段階料金。以下のオプション値で管理：
  - `photo_pp_cod_tier1_limit` / `fee`
  - `photo_pp_cod_tier2_limit` / `fee`
  - `photo_pp_cod_tier3_limit` / `fee`
  - `photo_pp_cod_max_fee`
- **税率ロジック**: 注文確定時に各商品の税率を `order_items` に保存。
- **データ保持**: 管理画面での更新（伝票番号入力等）時に、`shipping_info` 内の `fee` (送料) および `cod_fee` (代引き手数料) をマージして保存。

### 受注管理の強化 [v3.0.0]
- **フィルタリング条件**: 
  - `subscription`: サブスクリプション注文のみを表示。
  - `active`: Stripe/PayPayの `pending_payment` を除外。
- **選択削除**: 選択された `order_ids` に対して一括削除を実行。
- **ステータス表示**: サブスクリプション注文が有効な場合は「有効 (サブスク)」と表示。

### 在庫管理ロジック
- **メタ情報**: `_photo_manage_stock` (1: 有効, 0: 無効), `_photo_stock_qty` (在庫数)
- **自動在庫減算**: 新規注文確定時（`photo_purchase_save_order` 内）に、在庫管理が有効な商品の在庫を数量分減算。
- **自動在庫復元**: 注文ステータスを「キャンセル」に変更した際、または注文を削除した際に在庫を復元（`photo_purchase_update_stock_for_order` を使用）。
- **売り切れ判定**: 手動の `_photo_is_sold_out` フラグに加え、在庫管理が有効かつ在庫数が0の場合も自動的に「売り切れ」として扱う。

### デジタル販売の制限
- **有効期限**: 注文から `photo_pp_download_expiry` 日間のみ有効。
- **上限回数**: `photo_pp_download_limit` 回までダウンロード可能。

### CSVエクスポート
- **項目**: 注文ID, 注文番号, 購入者名, メールアドレス, 購入商品, 商品小計, 送料, 代引き手数料, クーポンコード, クーポン割引額, 合計金額, 支払い方法, ステータス, 注文日時, 配送先情報

## 4. ショートコード
- `[ec_gallery]`: 商品一覧
- `[ec_checkout]`: ショッピングカート・決済画面
- `[ec_order_inquiry]`: 購入者向け注文照会
- `[ec_customer_portal]`: サブスクリプション管理ポータル [v3.0.0]
- `[ec_tokushoho]`: 特定商取引法に基づく表記
- `[ec_shipping_payment]`: 送料・支払い規定

## 5. 主要関数
- `photo_purchase_calculate_cod_fee()`: COD手数料の段階計算
- `photo_purchase_save_order()`: 受注データの生成・保存（送料・手数料計算含む）
- `photo_purchase_export_orders_csv()`: CSV生成（送料・手数料列を含む）
- `photo_purchase_create_portal_session($customer_id)`: StripeカスタマーポータルURLの生成 [v3.0.0]
- `photo_purchase_order_print_view($order_id, $type)`: 帳票（請求書・領収書）のHTML出力 [v3.8.0]
- `photo_purchase_handle_stripe_webhook()`: Stripe Webhookの処理（サブスク解約対応追加 [v3.0.0]）
