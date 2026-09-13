<?php

namespace ACore\Hooks\WooCommerce;

/**
 * Hides the store entry points in the header from guests: the shop link in the
 * navigation and the cart button. StoreLogin sends guests off every store page
 * already, so advertising them in the header only costs a pointless trip
 * through the login screen. Logged-in users get the full header.
 */
class StoreHeader {

    public static function init() {
        add_filter('render_block', self::class . '::hide_store_blocks_for_guests', 10, 2);
    }

    public static function hide_store_blocks_for_guests($block_content, $block) {
        if (is_user_logged_in()) {
            return $block_content;
        }

        if ($block['blockName'] === 'woocommerce/mini-cart') {
            return '';
        }

        if ($block['blockName'] === 'core/navigation-link' && self::points_at_shop($block)) {
            return '';
        }

        return $block_content;
    }

    private static function points_at_shop($block) {
        $url = isset($block['attrs']['url']) ? $block['attrs']['url'] : '';
        $shop = wc_get_page_permalink('shop');

        if ($url === '' || !$shop) {
            return false;
        }

        // Navigation links may store a relative path, so compare paths only.
        return untrailingslashit(wp_parse_url($url, PHP_URL_PATH) ?: '')
            === untrailingslashit(wp_parse_url($shop, PHP_URL_PATH) ?: '');
    }
}

StoreHeader::init();
