# シンプルECプラグイン 技術仕様書 (v3.8.0)

## 1. データベース定義

### 受注テーブル (`wp_photo_orders`)
| カラム名 | 型 | 説明 |
| :--- | :--- | :--- |
| `id` | mediumint(9) | 主キー |
| `order_token` | varchar(255) | ユニークトークン（照会用） |
| `buyer_name` | varchar(255) | 購入者氏名 |
| `buyer_email` | varchar(255) | 購入者メールアドレス |
| `order_items` | text | 商品JSON（ID, 数量, 税率, 種別等） |
| `shipping_info` | text | 配送先JSON（郵便番号, 住所, 都道府県別送料等） |
| `coupon_info` | text | クーポンJSON（コード, 割引額等） |
| `total_amount` | int(11) | 最終合計金額 |
| `payment_method` | varchar(50)  | card, paypay, bank_transfer, cod |
| `status` | varchar(50)  | pending_payment, processing, completed, cancelled, active |
| `transaction_id` | varchar(255) | 決済ID（返金・追跡用） |
| `stripe_customer_id` | varchar(255) | Stripe顧客ID（マイページ連携用） |
| `stripe_subscription_id` | varchar(255) | StripeサブスクID |
| `tracking_number`| varchar(255) | 配送追跡番号 |
| `created_at` | datetime | 注文日時 |

## 2. 決済・返金フロー

### Stripe / PayPay 連携
- **自動返金**: ステータス「キャンセル」変更時に Stripe API 経由で即時実行。
- **PayPay**: Stripe Payment Methods 経由で統合。
- **サブスクリプション**: カード決済のみ。解約 Webhook 受信時に自動ステータス更新。

### 統合マイページ [v3.8.0]
- `[ec_member_dashboard]` ショートコードで提供。
- 会員（WPログイン）およびゲスト（メール認証）の両方に対応。
- Stripe カスタマーポータルとの動的リンク（マイページへの戻りURL指定）。

## 3. 主要ロジック

### 配送料・税率計算
- **都道府県別送料**: `photo_pp_shipping_prefecture_rates` オプションに保存。一括設定（地方別）に対応。
- **インボイス対応**: `photo_pp_tokusho_registration_number` (T番号) と複数税率（10%, 8%）の内訳計算。
- **COD手数料**: 総額に応じた 3 段階（+最大）のティア計算。

### 在庫・クーポン管理
- **自動在庫操作**: 注文確定時に減算、キャンセル/削除時に自動復元。
- **クーポン**: 初回のみ/永続/回数限定（サブスク用）の設定。

## 4. ショートコード一覧
- `[ec_gallery]`: 商品一覧（検索・ソート付）
- `[ec_checkout]`: カート・チェックアウト
- `[ec_member_dashboard]`: 統合マイページ（履歴・サブスク管理）
- `[ec_order_inquiry]`: ゲスト用単発注文照会
- `[ec_cart_indicator]`: カート点数・フローティング
- `[ec_tokushoho]`: 特定商取引法に基づく表記
- `[ec_shipping_payment]`: 送料・支払い規定

## 5. 主要関数
- `photo_purchase_calculate_cod_fee()`: COD段階計算
- `photo_purchase_save_order()`: 受注データ生成
- `photo_purchase_create_portal_session()`: StripeポータルURL取得
- `photo_purchase_order_print_view()`: 納品書・領収書HTML生成
- `photo_purchase_handle_stripe_webhook()`: Webhook処理 (サブスク解約等)
