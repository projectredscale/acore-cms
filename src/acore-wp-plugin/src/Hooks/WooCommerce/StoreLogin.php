<?php

namespace ACore\Hooks\WooCommerce;

/**
 * Forces login for all WooCommerce store pages: shop, product pages,
 * cart, checkout, order tracking, and any other frontend store endpoints.
 */
class StoreLogin {

    public static function init() {
        add_action('template_redirect', self::class . '::redirect_guest_store_pages');
    }

    public static function redirect_guest_store_pages() {
        if (is_user_logged_in()) {
            return;
        }

        $store_pages = array(
            wc_get_page_id('shop'),
            wc_get_page_id('cart'),
            wc_get_page_id('checkout'),
            wc_get_page_id('myaccount'),
        );

        $queried_id = get_queried_object_id();

        // Redirect on known store pages
        if (in_array($queried_id, $store_pages)) {
            self::do_redirect();
        }

        // Redirect on any product page (single product, product archive, product taxonomy)
        if (is_product() || is_product_category() || is_product_tag() || is_product_taxonomy()) {
            self::do_redirect();
        }

        // Redirect on order-pay, order-received, view-order endpoints
        if (self::is_store_endpoint()) {
            self::do_redirect();
        }
    }

    /**
     * Check if the current URL matches a WooCommerce frontend endpoint
     * that should be login-protected.
     */
    private static function is_store_endpoint() {
        global $wp_query;

        $endpoints = array(
            'order-pay',
            'order-received',
            'view-order',
            'edit-account',
            'edit-address',
            'lost-password',
            'customer-logout',
            'add-payment-method',
            'delete-payment-method',
            'set-default-payment-method',
        );

        foreach ($endpoints as $ep) {
            if (isset($wp_query->query_vars[$ep])) {
                return true;
            }
        }

        return false;
    }

    private static function do_redirect() {
        wc_add_notice(__('You must be logged in to access the store.', 'acore-wp-plugin'), 'notice');
        wp_redirect(wp_login_url(wc_get_page_permalink('shop')));
        exit;
    }
}

StoreLogin::init();
