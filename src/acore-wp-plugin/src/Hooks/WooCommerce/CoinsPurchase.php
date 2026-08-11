<?php

namespace ACore\Hooks\WooCommerce;

use ACore\Manager\ACoreServices;

class CoinsPurchase extends \ACore\Lib\WpClass {

    private static function isCoinsSku($sku) {
        return is_string($sku) && preg_match('/^coins_\d+$/', $sku) === 1;
    }

    private static function getAmount($sku) {
        return (int) explode("_", $sku)[1];
    }

    public static function init() {
        add_filter('woocommerce_add_cart_item_data', self::sprefix() . 'add_cart_item_data', 20, 3);
        add_action('woocommerce_add_order_item_meta', self::sprefix() . 'add_order_item_meta', 1, 3);
        add_action('woocommerce_checkout_order_processed', self::sprefix() . 'checkout_order_processed', 20, 2);
        add_action('woocommerce_payment_complete', self::sprefix() . 'payment_complete');
        add_action('woocommerce_add_to_cart_validation', [__CLASS__, 'add_to_cart_validation'], 10, 5);
        add_filter('woocommerce_quantity_input_args', [__CLASS__, 'quantity_input_args'], 10, 2);
        add_filter('woocommerce_cart_item_quantity', [__CLASS__, 'cart_item_quantity'], 10, 3);
        add_action('woocommerce_cart_totals_after_order_total', [__CLASS__, 'show_cart_coins_total']);
        add_action('woocommerce_review_order_after_order_total', [__CLASS__, 'show_cart_coins_total']);
    }

    // SAVE INTO ITEM DATA
    // Stash the SKU so add_order_item_meta and payment_complete can recognise coins lines.
    public static function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        $product = $variation_id ? \wc_get_product($variation_id) : \wc_get_product($product_id);
        $sku = $product->get_sku();
        if (!self::isCoinsSku($sku)) {
            return $cart_item_data;
        }

        $cart_item_data['acore_item_sku'] = $sku;
        /* below statement make sure every add to cart action as unique line item */
        $cart_item_data['unique_key'] = md5(microtime() . rand());

        return $cart_item_data;
    }

    // ADD DATA TO FINAL ORDER META
    public static function add_order_item_meta($item_id, $values, $cart_item_key) {
        if (isset($values['acore_item_sku']) && self::isCoinsSku($values['acore_item_sku'])) {
            \wc_add_order_item_meta($item_id, "acore_item_sku", $values['acore_item_sku']);
        }
    }

    // CHECK BEFORE PAYMENT
    public static function checkout_order_processed($order_id, $posted_data) {
        $order = new \WC_Order($order_id);
        $items = $order->get_items();

        foreach ($items as $item) {
            if (isset($item['acore_item_sku']) && self::isCoinsSku($item['acore_item_sku'])) {
                // The coin tables are created by the in-game shop module's db-auth
                // SQL (mod-shop), not by this plugin. Refuse the checkout
                // if they (or the auth DB) are unavailable so a payment cannot
                // complete against a dead ledger.
                try {
                    if (!self::coinsTablesExist()) {
                        throw new \Exception('account_coins tables missing');
                    }
                } catch (\Throwable $e) {
                    (new \WC_Logger())->add("acore_log", "Coins checkout $order_id: " . $e->getMessage());
                    throw new \Exception(__('The donation service is temporarily unavailable, try again later!', 'acore-wp-plugin'));
                }
                return;
            }
        }
    }

    // Validator for add to cart from external sources: coins require a logged-in buyer.
    public static function add_to_cart_validation($passed, $product_id, $quantity, $variation_id = null, $variations = null) {
        $product = $variation_id ? \wc_get_product($variation_id) : \wc_get_product($product_id);
        $sku = $product->get_sku();
        if (!self::isCoinsSku($sku)) {
            return $passed;
        }

        if (!is_user_logged_in()) {
            \wc_add_notice(__('You must be logged in to buy it!', 'acore-wp-plugin'), 'error');
            return false;
        }

        $account = ACoreServices::I()->getAccountRepo()->findOneByUsername(wp_get_current_user()->user_login);
        if (!$account) {
            \wc_add_notice(__('Account not found. Please reconnect and try again.', 'woocommerce'), 'error');
            return false;
        }

        return $passed;
    }

    /**
     * Lock the product-page quantity input at 1 for coins SKUs.
     */
    public static function quantity_input_args($args, $product) {
        if ($product && self::isCoinsSku((string) $product->get_sku())) {
            $args['min_value']   = 1;
            $args['max_value']   = 1;
            $args['input_value'] = 1;
        }
        return $args;
    }

    /**
     * Lock the cart-row quantity at 1 for coins line items.
     */
    public static function cart_item_quantity($product_quantity, $cart_item_key, $cart_item) {
        if (!empty($cart_item['acore_item_sku'])
            && self::isCoinsSku((string) $cart_item['acore_item_sku'])) {
            return '1';
        }
        return $product_quantity;
    }

    /**
     * Total coins across every cart line, so the checkout can show the buyer
     * the full amount a payment will credit.
     */
    private static function cartCoinsTotal() {
        $total = 0;
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item['acore_item_sku']) && self::isCoinsSku((string) $item['acore_item_sku'])) {
                $total += self::getAmount($item['acore_item_sku']) * (int) $item['quantity'];
            }
        }
        return $total;
    }

    /**
     * Cart and checkout totals row showing the coins the order will credit.
     */
    public static function show_cart_coins_total() {
        $total = self::cartCoinsTotal();
        if ($total <= 0) {
            return;
        }
        ?>
        <tr class="order-total">
            <th><?php esc_html_e('Coins to receive', 'acore-wp-plugin'); ?></th>
            <td><strong><?php echo esc_html(number_format($total)); ?></strong></td>
        </tr>
        <?php
    }

    /**
     * Read-only check that the shared coin tables exist in the auth DB. They are
     * created by the in-game shop module's db-auth SQL (mod-redscale-shop), not by
     * this plugin; used to gate checkout and to report status on the settings page.
     */
    public static function coinsTablesExist() {
        $conn = ACoreServices::I()->getAccountEm()->getConnection();
        $count = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM `information_schema`.`TABLES` "
            . "WHERE LOWER(`TABLE_SCHEMA`) = LOWER(DATABASE()) "
            . "AND LOWER(`TABLE_NAME`) IN ('account_coins','account_coins_log')"
        );
        return $count === 2;
    }

    // DO THE FINAL ACTION — credit account_coins on payment complete
    public static function payment_complete($order_id) {
        $logs = new \WC_Logger();
        try {
            $order = new \WC_Order($order_id);
            $items = $order->get_items();

            // Resolve the buyer's auth account once. WP username == account.username.
            $accountId = null;
            $customerId = $order->get_customer_id();
            if ($customerId) {
                $buyer = \get_user_by('id', $customerId);
                if ($buyer) {
                    $account = ACoreServices::I()->getAccountRepo()->findOneByUsername($buyer->user_login);
                    if ($account) {
                        $accountId = $account->getId();
                    }
                }
            }
            if ($accountId === null) {
                throw new \Exception("Coins order $order_id has no buyer linked to an AC account; nothing credited.");
            }

            $conn = ACoreServices::I()->getAccountEm()->getConnection();

            // Sum every coins line: several denominations, or the same SKU bought
            // twice, credit once so the unique order_id log row still blocks
            // retry double-credit without dropping legitimate lines.
            $total = 0;
            foreach ($items as $item) {
                if (!isset($item['acore_item_sku']) || !self::isCoinsSku($item['acore_item_sku'])) {
                    continue;
                }
                $total += self::getAmount($item['acore_item_sku']) * (int) $item->get_quantity();
            }
            if ($total <= 0) {
                return;
            }

            $reason = "WooCommerce order #{$order_id}";
            $conn->beginTransaction();
            try {
                $conn->executeStatement(
                    "INSERT INTO account_coins (account_id, balance) VALUES (:id, :amount)
                     ON DUPLICATE KEY UPDATE balance = balance + :amount",
                    array('id' => $accountId, 'amount' => $total)
                );
                $conn->executeStatement(
                    "INSERT INTO account_coins_log (account_id, amount, reason, order_id)
                     VALUES (:id, :amount, :reason, :orderId)",
                    array('id' => $accountId, 'amount' => $total, 'reason' => $reason, 'orderId' => (string) $order_id)
                );
                $conn->commit();
            } catch (\Exception $e) {
                $conn->rollBack();
                // The unique order_id index blocks double-credit when WooCommerce
                // retries a completed payment; log and continue without failing.
                $logs->add("acore_log", "Coins order $order_id: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            $logs->add("acore_log", $e->getMessage());
        }
    }
}

CoinsPurchase::init();
