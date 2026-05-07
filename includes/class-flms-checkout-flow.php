<?php
class FLMS_Checkout_Flow {

    public function __construct() {
        // 1. Basic WooCommerce Tweaks
        add_filter( 'woocommerce_is_sold_individually', '__return_true' );
        add_filter( 'woocommerce_product_single_add_to_cart_text', [ $this, 'custom_cart_button_text' ] );
        add_filter( 'woocommerce_add_to_cart_redirect', [ $this, 'skip_cart_redirect' ] );
        add_filter( 'woocommerce_checkout_fields', [ $this, 'simplify_checkout_fields' ] );
        add_filter( 'woocommerce_enable_order_notes_field', '__return_false' );
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'force_single_item_cart' ], 10, 2 );

        // 2. Referral / Reference Field
        add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'render_referral_field' ] );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_referral_field' ], 10, 2 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_referral_admin' ], 10, 1 );

        // 3. BYPASS CODE LOGIC
        add_action( 'woocommerce_review_order_before_submit', [ $this, 'render_bypass_field' ], 8 );
        add_action( 'woocommerce_review_order_before_submit', [ $this, 'render_checkout_terms_consent' ], 12 );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_bypass_discount' ], 20 );
        add_filter( 'woocommerce_cart_needs_payment', [ $this, 'override_payment_requirement' ], 20, 2 );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_bypass_code' ] );

        // Tournament registration: terms checkbox + modal (checkout)
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_checkout_terms_consent' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_checkout_terms_consent' ], 15, 2 );
        add_action( 'wp_footer', [ $this, 'maybe_print_checkout_terms_modal' ], 99 );
    }

    /** Cart contains a tournament product (not friendly fee only). */
    private function cart_has_tournament_product() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
            return false;
        }
        $friendly_product_id = defined( 'FLMS_FRIENDLY_FEE_PRODUCT_ID' ) ? (int) FLMS_FRIENDLY_FEE_PRODUCT_ID : 0;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product_id = (int) $cart_item['product_id'];
            if ( $friendly_product_id && $product_id === $friendly_product_id ) {
                continue;
            }
            if ( get_post_meta( $product_id, '_flms_start_date', true ) ) {
                return true;
            }
        }
        return false;
    }

    public function render_checkout_terms_consent() {
        if ( ! $this->cart_has_tournament_product() ) {
            return;
        }
        ?>
        <div class="flms-checkout-terms-consent">
            <p class="flms-checkout-terms-line">
                <label class="flms-checkout-terms-label" for="flms_checkout_terms_agree">
                    <input type="checkbox" name="flms_checkout_terms_agree" id="flms_checkout_terms_agree" value="1" />
                    <span class="flms-checkout-terms-text"><?php esc_html_e( 'I have read and agree to the', 'flms' ); ?></span>
                </label>
                <button type="button" class="flms-checkout-open-terms"><?php esc_html_e( 'Participation Consent & Terms and Conditions', 'flms' ); ?></button>
            </p>
        </div>
        <?php
    }

    public function validate_checkout_terms_consent() {
        if ( ! $this->cart_has_tournament_product() ) {
            return;
        }
        if ( empty( $_POST['flms_checkout_terms_agree'] ) ) {
            wc_add_notice( __( 'Please agree to the Participation Consent & Terms and Conditions to complete registration.', 'flms' ), 'error' );
        }
    }

    public function save_checkout_terms_consent( $order_id ) {
        if ( empty( $_POST['flms_checkout_terms_agree'] ) ) {
            return;
        }
        update_post_meta( $order_id, '_flms_checkout_terms_agreed', 'yes' );
        update_post_meta( $order_id, '_flms_checkout_terms_agreed_at', current_time( 'mysql' ) );
    }

    public function maybe_print_checkout_terms_modal() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
            return;
        }
        if ( ! $this->cart_has_tournament_product() ) {
            return;
        }
        if ( ! class_exists( 'FLMS_Friendly' ) ) {
            return;
        }
        ?>
        <div id="flms-checkout-terms-modal" class="flms-friendly-modal flms-checkout-terms-modal-wrap" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="flms-checkout-terms-title">
            <div class="flms-friendly-modal-overlay" tabindex="-1"></div>
            <div class="flms-friendly-modal-box flms-friendly-modal-wide">
                <button type="button" class="flms-checkout-terms-modal-close" aria-label="<?php esc_attr_e( 'Close', 'flms' ); ?>">&times;</button>
                <h2 id="flms-checkout-terms-title"><?php esc_html_e( 'PARTICIPATION CONSENT & TERMS AND CONDITIONS', 'flms' ); ?></h2>
                <div class="flms-friendly-terms-scroll flms-friendly-terms-scroll-visible">
                    <?php echo FLMS_Friendly::get_participation_consent_terms_html(); ?>
                </div>
                <p class="flms-checkout-terms-modal-actions"><button type="button" class="button flms-checkout-terms-close-btn"><?php esc_html_e( 'Close', 'flms' ); ?></button></p>
            </div>
        </div>
        <?php
    }

    public function force_single_item_cart( $passed, $product_id ) {
        if ( ! WC()->cart->is_empty() ) WC()->cart->empty_cart();
        return $passed;
    }
    public function custom_cart_button_text() { return 'Register Tournament'; }
    public function skip_cart_redirect() { return wc_get_checkout_url(); }

    public function simplify_checkout_fields( $fields ) {
        unset( $fields['billing']['billing_company'], $fields['billing']['billing_address_2'], $fields['billing']['billing_postcode'], $fields['billing']['billing_city'], $fields['billing']['billing_state'], $fields['billing']['billing_country'] ); 
        return $fields;
    }

    public function render_referral_field( $checkout ) {
        echo '<div class="flms-checkout-referral">';
        woocommerce_form_field( 'flms_referral_name', [ 'type' => 'text', 'class' => [ 'form-row-wide' ], 'label' => 'Referral / Reference (Optional)', 'placeholder' => 'Enter agent name', 'required' => false ], $checkout->get_value( 'flms_referral_name' ) );
        echo '</div>';
    }
    public function save_referral_field( $order, $data ) { if ( ! empty( $_POST['flms_referral_name'] ) ) $order->update_meta_data( 'Referral Reference', sanitize_text_field( $_POST['flms_referral_name'] ) ); }
    public function display_referral_admin( $order ) { $ref = $order->get_meta( 'Referral Reference' ); if ( $ref ) echo '<p><strong>Referral / Agent:</strong> <br>' . esc_html( $ref ) . '</p>'; }

    // --- BYPASS CODE UI ---
    public function render_bypass_field() {
        // Try to get code from session
        $session_code = WC()->session->get('flms_active_bypass');
        ?>
        <div id="flms-bypass-area" class="flms-checkout-bypass">
            <p class="flms-checkout-bypass-title"><?php esc_html_e( 'Have a Registration Code? (Paid Manual)', 'flms' ); ?></p>
            <div class="flms-checkout-bypass-row">
                <input type="text" id="flms_code_input" value="<?php echo esc_attr($session_code); ?>" placeholder="<?php esc_attr_e( 'Enter Code', 'flms' ); ?>" class="flms-bypass-input">
                <button type="button" class="button" id="flms_apply_code_btn"><?php esc_html_e( 'Apply', 'flms' ); ?></button>
            </div>
            <div id="flms_status_msg" style="font-size:12px; margin-top:5px; display:none;"></div>
            <input type="hidden" name="flms_bypass_code_hidden" id="flms_bypass_code_hidden" value="<?php echo esc_attr($session_code); ?>">
        </div>
        
        <script>
        jQuery(document).ready(function($){
            $(document.body).on('click', '#flms_apply_code_btn', function(e){
                e.preventDefault();
                var code = $('#flms_code_input').val();
                $('#flms_bypass_code_hidden').val(code); 
                $(document.body).trigger('update_checkout'); 
            });

            $(document.body).on('updated_checkout', function(){
                var code = $('#flms_code_input').val();
                if(code) {
                    // If the negative fee row exists in the table
                    if( $('tr.fee').length > 0 ) {
                        $('#flms_status_msg').text('✅ Code Applied Successfully!').css('color', 'green').show();
                    } else {
                        $('#flms_status_msg').text('❌ Invalid or Expired Code.').css('color', 'red').show();
                    }
                }
            });
        });
        </script>
        <?php
    }

    // --- APPLY DISCOUNT LOGIC (HIGH PERFORMANCE) ---
    public function apply_bypass_discount() {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        // Find the code from POST data
        $code = '';
        if ( isset($_POST['flms_bypass_code_hidden']) ) {
            $code = $_POST['flms_bypass_code_hidden'];
        } elseif ( isset($_POST['post_data']) ) {
            parse_str($_POST['post_data'], $post_data);
            $code = isset($post_data['flms_bypass_code_hidden']) ? $post_data['flms_bypass_code_hidden'] : '';
        }

        // Fallback to session
        if ( empty($code) ) $code = WC()->session->get('flms_active_bypass');
        if ( empty($code) ) return;

        $code = strtolower(trim($code));

        // 1. FIND COUPON MANUALLY (Ignores Private/Public filters)
        global $wpdb;
        $coupon_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_title = %s AND post_type = 'shop_coupon' LIMIT 1", 
            $code
        ));

        if ( ! $coupon_id ) {
            WC()->session->__unset('flms_active_bypass');
            return;
        }

        // 2. VALIDATE LOGIC
        $coupon = new WC_Coupon( $coupon_id );
        $now = current_time('timestamp');
        
        // Expiry Check (With 24-hour grace period for timezone safety)
        if ( $coupon->get_date_expires() ) {
            $expiry_time = $coupon->get_date_expires()->getTimestamp() + (24 * 60 * 60); // Add 1 day buffer
            if ( $now > $expiry_time ) {
                WC()->session->__unset('flms_active_bypass');
                return;
            }
        }

        // Usage Limit Check
        if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
            WC()->session->__unset('flms_active_bypass');
            return;
        }

        // 3. SUCCESS: CALCULATE TOTAL AND APPLY NEGATIVE FEE
        // Use subtotal + tax to reach exactly 0
        $total_to_discount = (float) WC()->cart->get_subtotal() + (float) WC()->cart->get_subtotal_tax();
        
        if ( $total_to_discount > 0 ) {
            WC()->cart->add_fee( '✅ Paid Manually (Code Used)', -$total_to_discount );
            WC()->session->set('flms_active_bypass', $code);
        }
    }

    public function override_payment_requirement( $needs_payment, $cart ) {
        if ( WC()->session->get('flms_active_bypass') ) return false;
        return $needs_payment;
    }

    public function save_bypass_code( $order_id ) {
        $code = WC()->session->get('flms_active_bypass');
        if ( $code ) {
            $order = wc_get_order( $order_id );
            $order->set_payment_method('manual_bypass');
            $order->set_payment_method_title('Manual Code: ' . strtoupper($code));
            
            // Mark as completed immediately to trigger team creation logic
            $order->update_status( 'completed', 'Bypass code used: ' . $code );
            $order->save();

            // Increase usage count manually
            $coupon = new WC_Coupon($code);
            if ($coupon->get_id()) {
                $coupon->set_usage_count($coupon->get_usage_count() + 1);
                $coupon->save();
            }

            WC()->session->__unset('flms_active_bypass');
        }
    }
}