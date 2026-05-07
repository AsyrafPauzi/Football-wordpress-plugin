<?php
class FLMS_Referee_Leader {

    public function __construct() {
        add_shortcode( 'flms_referee_assigner', [ $this, 'render_dashboard' ] );
        add_action( 'wp_ajax_flms_admin_assign_ref', [ $this, 'ajax_assign_referee' ] );
    }

    public function render_dashboard() {
        if ( ! is_user_logged_in() ) return '<p>Please login.</p>';

        $user = wp_get_current_user();
        // Allow Admin and Referee Leader
        if ( ! in_array( 'referee_leader', (array) $user->roles ) && ! current_user_can('administrator') ) {
            return '<div style="background:#f8d7da; color:#721c24; padding:15px; border-radius:5px;">⛔ Access Denied. Referee Leaders Only.</div>';
        }

        // 1. Get All Referees
        $referees = get_users([ 'role__in' => ['referee'], 'orderby' => 'display_name' ]);

        // 2. Get Pending/Scheduled Matches
        $matches = get_posts([
            'post_type'      => 'flms_match',
            'posts_per_page' => -1,
            'meta_key'       => 'flms_match_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                [ 'key' => 'flms_match_status', 'value' => 'completed', 'compare' => '!=' ] // Only Active Matches
            ]
        ]);

        ob_start();
        ?>
        <div class="flms-dashboard-wrapper">
            <div class="flms-dash-header">
                <h2>Referee Assignment Panel</h2>
              

            <div class="flms-table-responsive">
                <table class="flms-league-table">
                    <thead>
                        <tr>
                            <th>Match Details</th>
                            <th>Current Status</th>
                            <th>Assign Referee</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty($matches) ) : ?>
                            <tr><td colspan="4" style="text-align:center;">No upcoming matches found.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $matches as $m ) : 
                                $mid = $m->ID;
                                $h_id = get_post_meta($mid, 'flms_home_team', true);
                                $a_id = get_post_meta($mid, 'flms_away_team', true);
                                $h_name = get_the_title($h_id);
                                $a_name = get_the_title($a_id);
                                $date = get_post_meta($mid, 'flms_match_date', true);
                                $time = get_post_meta($mid, 'flms_match_time', true);
                                $display_date = $date ? date('d M', strtotime($date)) : '-';
                                $display_time = $time ? date('h:i A', strtotime($time)) : '-';
                                
                                $current_ref_id = get_post_meta($mid, 'flms_referee_id', true);
                            ?>
                            <tr>
                                <td style="text-align:left;">
                                    <strong><?php echo esc_html("$h_name vs $a_name"); ?></strong><br>
                                    <span style="font-size:12px; color:#999;">📅 <?php echo "$display_date | ⏰ $display_time"; ?></span>
                                </td>
                                <td>
                                    <?php if($current_ref_id): ?>
                                        <span style="color:green; font-weight:bold;">✅ Assigned</span>
                                    <?php else: ?>
                                        <span style="color:#e74c3c; font-weight:bold;">⚠️ Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <select id="ref_select_<?php echo $mid; ?>" style="padding:8px; border-radius:4px; border:1px solid #ddd; width:100%;">
                                        <option value="">-- Select Referee --</option>
                                        <?php foreach($referees as $ref): ?>
                                            <option value="<?php echo $ref->ID; ?>" <?php selected($current_ref_id, $ref->ID); ?>>
                                                <?php echo esc_html($ref->display_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <button type="button" class="button assign-btn" data-mid="<?php echo $mid; ?>" style="font-size:12px; padding:6px 12px;">Save</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            $('.assign-btn').click(function(e){
                e.preventDefault();
                var btn = $(this);
                var mid = btn.data('mid');
                var ref_id = $('#ref_select_' + mid).val();
                
                btn.text('Saving...').prop('disabled', true);

                $.post('<?php echo admin_url("admin-ajax.php"); ?>', {
                    action: 'flms_admin_assign_ref',
                    match_id: mid,
                    referee_id: ref_id
                }, function(res) {
                    if(res.success) {
                        btn.text('Saved!').css('background', '#2ecc71').css('color', '#fff');
                        setTimeout(function(){ btn.text('Save').css('background', '').css('color', '').prop('disabled', false); }, 2000);
                    } else {
                        alert('Error saving assignment.');
                        btn.text('Retry').prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public function ajax_assign_referee() {
        $match_id = intval($_POST['match_id']);
        $ref_id = intval($_POST['referee_id']);

        // Check Logic
        if ( ! $match_id ) wp_send_json_error();

        if ( $ref_id > 0 ) {
            // Assign
            update_post_meta( $match_id, 'flms_referee_id', $ref_id );

            // Email Notification
            $ref_user = get_userdata($ref_id);
            $h_id = get_post_meta($match_id, 'flms_home_team', true);
            $a_id = get_post_meta($match_id, 'flms_away_team', true);
            $h_name = get_the_title($h_id); 
            $a_name = get_the_title($a_id);
            $date = get_post_meta($match_id, 'flms_match_date', true);
            
            $subject = "You have been assigned to a Match";
            $message = "Hello {$ref_user->display_name},\n\nYou have been assigned by the Referee Leader to officiate:\n\nMatch: $h_name vs $a_name\nDate: $date\n\nPlease login to your dashboard to view details.";
            
            wp_mail( $ref_user->user_email, $subject, $message );

        } else {
            // Unassign (if they selected empty option)
            delete_post_meta( $match_id, 'flms_referee_id' );
        }

        wp_send_json_success();
    }
}