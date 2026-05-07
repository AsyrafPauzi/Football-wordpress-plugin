<?php
class FLMS_Finance {

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_finance_metabox' ] );
        add_action( 'save_post_flms_match', [ $this, 'save_finance_data' ] );
    }

    /**
     * Add the Finance Metabox.
     * Blocks admin1@agdsports.com even if they are an administrator.
     */
    public function add_finance_metabox() {
        $user = wp_get_current_user();
        
        // --- HARDCODED BLOCK BY EMAIL ---
        if ( $user->user_email === 'admin1@agdsports.com' ) {
            return; 
        }

        if ( current_user_can('administrator') || current_user_can('finance_officer') ) {
            add_meta_box( 
                'flms_match_finance', 
                '💰 Match Finance & Fees', 
                [ $this, 'render_metabox' ], 
                'flms_match', 
                'side', 
                'high' 
            );
        }
    }

    public function render_metabox( $post ) {
        $fee_amount = get_post_meta( $post->ID, '_flms_match_fee', true ) ?: '100';
        $paid_home = get_post_meta( $post->ID, '_flms_paid_home', true );
        $paid_away = get_post_meta( $post->ID, '_flms_paid_away', true );
        
        $h_id = get_post_meta( $post->ID, 'flms_home_team', true );
        $a_id = get_post_meta( $post->ID, 'flms_away_team', true );
        $h_name = $h_id ? get_the_title($h_id) : 'Home';
        $a_name = $a_id ? get_the_title($a_id) : 'Away';

        wp_nonce_field( 'flms_finance_nonce', 'flms_finance_nonce_field' );
        ?>
        <div class="flms-finance-panel">
            <p>
                <label><strong>Match Fee (RM):</strong></label>
                <input type="number" name="flms_match_fee" value="<?php echo esc_attr($fee_amount); ?>" style="width:100%; font-weight:bold;">
            </p>
            <hr>
            <p><strong>Payment Status (Override):</strong></p>
            <p><label><input type="checkbox" name="flms_paid_home" value="yes" <?php checked($paid_home, 'yes'); ?>> <?php echo esc_html($h_name); ?></label></p>
            <p><label><input type="checkbox" name="flms_paid_away" value="yes" <?php checked($paid_away, 'yes'); ?>> <?php echo esc_html($a_name); ?></label></p>
            <p class="description" style="color:#d63031; font-size:11px;">* Force mark as PAID (Cash).</p>
        </div>
        <?php
    }

    public function save_finance_data( $post_id ) {
        if ( ! isset( $_POST['flms_finance_nonce_field'] ) || ! wp_verify_nonce( $_POST['flms_finance_nonce_field'], 'flms_finance_nonce' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

        // Security check for the hardcoded restricted email
        if ( wp_get_current_user()->user_email === 'admin1@agdsports.com' ) return;

        $old_fee = get_post_meta( $post_id, '_flms_match_fee', true );
        $old_home = get_post_meta( $post_id, '_flms_paid_home', true );
        $old_away = get_post_meta( $post_id, '_flms_paid_away', true );

        $new_fee = isset( $_POST['flms_match_fee'] ) ? sanitize_text_field( $_POST['flms_match_fee'] ) : $old_fee;
        $new_home = isset( $_POST['flms_paid_home'] ) ? 'yes' : 'no';
        $new_away = isset( $_POST['flms_paid_away'] ) ? 'yes' : 'no';

        update_post_meta( $post_id, '_flms_match_fee', $new_fee );
        update_post_meta( $post_id, '_flms_paid_home', $new_home );
        update_post_meta( $post_id, '_flms_paid_away', $new_away );

        if ( class_exists('FLMS_Logger') ) {
            $changes = [];
            if ( $old_fee != $new_fee ) $changes[] = "Fee changed: RM $old_fee -> RM $new_fee";
            if ( $old_home !== $new_home ) $changes[] = "Home Paid: " . strtoupper($new_home);
            if ( $old_away !== $new_away ) $changes[] = "Away Paid: " . strtoupper($new_away);

            if ( ! empty($changes) ) {
                FLMS_Logger::log( get_current_user_id(), 'Finance Update', $post_id, implode("\n", $changes) );
            }
        }
    }
}