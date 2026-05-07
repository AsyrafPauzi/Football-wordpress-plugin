<?php
class FLMS_Referee {
    public function __construct() {
        // Frontend Dashboard
        add_shortcode( 'flms_referee_dashboard', [ $this, 'dashboard_shortcode' ] );
        add_action( 'wp_ajax_flms_leave_match', [ $this, 'ajax_leave_match' ] );

        // Admin Interface (The Missing Part)
        add_action( 'add_meta_boxes', [ $this, 'add_admin_metabox' ] );
        add_action( 'save_post_flms_match', [ $this, 'save_admin_assignment' ] );
    }

    // --- 1. ADMIN METABOX (Assign Referee) ---
    public function add_admin_metabox() {
        add_meta_box(
            'flms_referee_assignment',
            'Match Official (Referee)',
            [ $this, 'render_admin_metabox' ],
            'flms_match',
            'side', // Shows on the right side
            'default'
        );
    }

    public function render_admin_metabox( $post ) {
        // Get current assigned ID
        $current_ref = get_post_meta( $post->ID, 'flms_referee_id', true );
        
        // Get all users who are Referees
        $referees = get_users([ 'role' => 'referee', 'orderby' => 'display_name' ]);

        wp_nonce_field( 'flms_ref_assign_nonce', 'flms_ref_assign_nonce_field' );
        ?>
        <p><strong>Assign a Referee:</strong></p>
        <select name="flms_referee_id" style="width:100%;">
            <option value="">-- No Referee Assigned --</option>
            <?php foreach ( $referees as $ref ) : ?>
                <option value="<?php echo $ref->ID; ?>" <?php selected( $current_ref, $ref->ID ); ?>>
                    <?php echo esc_html( $ref->display_name ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description" style="margin-top:5px; font-size:12px;">
            The selected referee will see this match in their dashboard.
        </p>
        <?php
    }

    public function save_admin_assignment( $post_id ) {
        // Security checks
        if ( ! isset( $_POST['flms_ref_assign_nonce_field'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['flms_ref_assign_nonce_field'], 'flms_ref_assign_nonce' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        
        // Save logic
        if ( isset( $_POST['flms_referee_id'] ) ) {
            $ref_id = sanitize_text_field( $_POST['flms_referee_id'] );
            
            if ( ! empty( $ref_id ) ) {
                update_post_meta( $post_id, 'flms_referee_id', $ref_id );
            } else {
                delete_post_meta( $post_id, 'flms_referee_id' ); // Unassign if empty
            }
        }
    }

    // --- 2. FRONTEND DASHBOARD (For Referees) ---
    public function dashboard_shortcode() {
        if ( ! is_user_logged_in() ) return '<p>Please login to view your assignments.</p>';
        
        $user = wp_get_current_user();
        if ( ! in_array( 'referee', (array) $user->roles ) && ! current_user_can('administrator') ) {
            return '<p class="error">Access restricted. Referee area only.</p>';
        }

        $user_id = get_current_user_id();

        // Query Matches
        $upcoming = get_posts([
            'post_type' => 'flms_match', 'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'AND',
                [ 'key' => 'flms_referee_id', 'value' => $user_id ],
                [ 'key' => 'flms_match_status', 'value' => 'pending' ]
            ],
            'orderby' => 'meta_value', 'meta_key' => 'flms_match_date', 'order' => 'ASC'
        ]);

        $history = get_posts([
            'post_type' => 'flms_match', 'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'AND',
                [ 'key' => 'flms_referee_id', 'value' => $user_id ],
                [ 'key' => 'flms_match_status', 'value' => 'completed' ]
            ],
            'orderby' => 'date', 'order' => 'DESC'
        ]);

        ob_start();
        ?>
        <div class="flms-referee-dash">
            <div class="flms-dash-header">
                <h2>My Officiating Schedule</h2>
                <div class="flms-kit-display"><span class="kit-badge" style="background:#0a0a0a; color:#D4AF37; width:auto; padding:8px 15px; border-radius:4px; border:1px solid #D4AF37;">Official</span></div>
            </div>

            <div class="flms-tabs">
                <button class="tab-btn active" onclick="flmsOpenTab(event, 'ref-upc')">Upcoming</button>
                <button class="tab-btn" onclick="flmsOpenTab(event, 'ref-hist')">History</button>
            </div>

            <div id="ref-upc" class="flms-tab-content" style="display:block;">
                <?php if(empty($upcoming)): ?>
                    <div class="flms-empty-state"><p style="font-size:18px;">✅ No upcoming matches.</p></div>
                <?php else: ?>
                    <div class="flms-ref-grid"><?php foreach($upcoming as $m) $this->render_match_card($m, 'upcoming'); ?></div>
                <?php endif; ?>
            </div>

            <div id="ref-hist" class="flms-tab-content" style="display:none;">
                <?php if(empty($history)): ?><p style="text-align:center; padding:20px; color:#999;">No match history.</p><?php else: ?>
                    <div class="flms-ref-grid"><?php foreach($history as $m) $this->render_match_card($m, 'history'); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        function flmsOpenTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("flms-tab-content");
            for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; }
            tablinks = document.getElementsByClassName("tab-btn");
            for (i = 0; i < tablinks.length; i++) { tablinks[i].className = tablinks[i].className.replace(" active", ""); }
            document.getElementById(tabName).style.display = "block";
            evt.currentTarget.className += " active";
        }
        jQuery(document).ready(function($){
            var ajaxurl = '<?php echo admin_url( "admin-ajax.php" ); ?>';
            $('.btn-leave').click(function(e){
                e.preventDefault();
                if(!confirm('Request a substitute/withdraw?')) return;
                var btn = $(this); btn.text('Processing...').prop('disabled', true);
                $.post(ajaxurl, { action: 'flms_leave_match', match_id: btn.data('id') }, function(res) {
                    if(res.success) { location.reload(); } else { alert('Error.'); btn.text('Withdraw').prop('disabled', false); }
                });
            });
        });
        </script>
        <style>
            .flms-referee-dash { max-width: 1000px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
            .flms-dash-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px; margin-bottom: 20px; }
            .flms-ref-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
            .flms-empty-state { text-align: center; padding: 50px; background: #f9f9f9; border-radius: 8px; border: 1px dashed #ccc; }
            .ref-card { background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
            .ref-card h4 { margin: 0 0 10px 0; color: #37003c; font-size:16px; line-height:1.4; border-bottom:1px solid #eee; padding-bottom:10px; }
            .ref-meta { font-size: 13px; color: #666; margin-bottom: 15px; line-height: 1.6; }
            .btn-leave { background: #e74c3c; color: #fff; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; width:100%; font-size: 11px; }
            .btn-view { background: #f0f0f0; color: #333; text-decoration:none; display:block; text-align:center; padding: 10px 15px; border-radius: 4px; font-weight: bold; font-size: 11px; }
            @media (max-width: 600px) { .flms-tabs { display: flex; gap: 5px; } .tab-btn { flex: 1; padding: 10px; font-size: 13px; text-align:center; } }
        </style>
        <?php
        return ob_get_clean();
    }

    private function render_match_card($post, $type) {
        $h_id = get_post_meta($post->ID, 'flms_home_team', true); $a_id = get_post_meta($post->ID, 'flms_away_team', true);
        $h_name = $h_id ? get_the_title($h_id) : 'TBD'; $a_name = $a_id ? get_the_title($a_id) : 'TBD';
        $date = get_post_meta($post->ID, 'flms_match_date', true); $time = get_post_meta($post->ID, 'flms_match_time', true);
        $venue_terms = get_the_terms($post->ID, 'flms_venue'); $venue_name = $venue_terms ? $venue_terms[0]->name : 'Venue TBD';
        $display_date = $date ? date('d M Y', strtotime($date)) : '-'; $display_time = $time ? date('h:i A', strtotime($time)) : '-';
        $score_display = '';
        if ($type === 'history') {
            $hs = get_post_meta($post->ID, 'flms_home_score', true); $as = get_post_meta($post->ID, 'flms_away_score', true);
            $score_display = "<div style='text-align:center; font-weight:900; margin-bottom:10px; font-size:24px; color:#37003c;'>$hs - $as</div>";
        }
        echo '<div class="ref-card">';
        echo "<h4>$h_name <span style='font-weight:400; color:#999; font-size:12px;'>VS</span><br>$a_name</h4>";
        echo $score_display;
        echo "<div class='ref-meta'>📅 $display_date | ⏰ $display_time<br>📍 $venue_name</div>";
        if($type === 'upcoming') { echo "<button class='btn-leave' data-id='{$post->ID}'>Request Sub / Withdraw</button>"; } 
        else { echo "<a href='".get_permalink($post->ID)."' class='btn-view'>View Match Report</a>"; }
        echo '</div>';
    }

    public function ajax_leave_match() {
        $match_id = intval( $_POST['match_id'] );
        if(get_post_meta($match_id, 'flms_referee_id', true) == get_current_user_id()) {
            delete_post_meta( $match_id, 'flms_referee_id' );
            wp_send_json_success();
        }
        wp_send_json_error();
    }
}