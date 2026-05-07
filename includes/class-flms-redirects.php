<?php
class FLMS_Redirects {

    public function __construct() {
        // Run before the page loads
        add_action( 'template_redirect', [ $this, 'handle_redirects' ] );
    }

    public function handle_redirects() {
        // 1. Target the Cart Page
        if ( is_cart() ) {
            
            // If the cart is empty, send them to find a tournament
            if ( WC()->cart->is_empty() ) {
                wp_safe_redirect( home_url( '/tournaments/' ) ); // Change this slug if your page is different
                exit;
            } 
            
            // If the cart has items, go STRAIGHT to Checkout
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        // 2. Optional: Redirect Shop/Categories to Competitions List
        if ( is_shop() || is_product_category() || is_product_tag() ) {
            wp_safe_redirect( home_url( '/tournaments/' ) );
            exit;
        }
    }
}