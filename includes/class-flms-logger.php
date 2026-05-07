<?php
class FLMS_Logger {

    public function __construct() {
        add_action( 'init', [ $this, 'register_log_cpt' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_match_log_metabox' ] );
    }

    // 1. Create CPT
    public function register_log_cpt() {
        register_post_type( 'flms_log', [
            'label' => 'System Logs',
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=flms_match',
            'supports' => ['title'],
            'capabilities' => [ 'create_posts' => 'do_not_allow' ],
            'map_meta_cap' => true,
        ]);
    }

    // 2. Add Log Entry
    public static function log( $user_id, $action, $match_id, $details = '' ) {
        $user = get_userdata( $user_id );
        $user_name = $user ? $user->display_name . ' (' . implode(', ', $user->roles) . ')' : 'System/Unknown';
        
        $time = current_time('mysql');
        $title = sprintf( '%s | %s | Match #%d', $action, $time, $match_id );
        
        $post_id = wp_insert_post([
            'post_type'   => 'flms_log',
            'post_title'  => $title,
            'post_status' => 'private', // Saved as Private
        ]);

        if ( $post_id ) {
            update_post_meta( $post_id, '_log_user', $user_name );
            update_post_meta( $post_id, '_log_match_id', $match_id );
            update_post_meta( $post_id, '_log_action', $action );
            update_post_meta( $post_id, '_log_details', $details );
            update_post_meta( $post_id, '_log_date', $time );
        }

        self::cleanup_logs();
    }

    // 3. Auto Cleanup (>200 logs)
    public static function cleanup_logs() {
        $all_logs = get_posts([
            'post_type' => 'flms_log',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'any', // Check all statuses
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        if ( count($all_logs) > 200 ) {
            $to_delete = array_slice( $all_logs, 200 );
            foreach ( $to_delete as $id ) wp_delete_post( $id, true );
        }
    }

    // 4. Metabox on Match Page
    public function add_match_log_metabox() {
        add_meta_box( 'flms_match_history', '📜 Audit Log / History', [ $this, 'render_match_logs' ], 'flms_match', 'normal', 'low' );
    }

    public function render_match_logs( $post ) {
        // FIX: Added 'post_status' => 'any' to ensure private logs show up
        $logs = get_posts([
            'post_type' => 'flms_log',
            'posts_per_page' => 20,
            'meta_key' => '_log_match_id',
            'meta_value' => $post->ID,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => 'any' 
        ]);

        if ( empty($logs) ) {
            echo '<p style="color:#999; font-style:italic; padding:10px;">No activity recorded for this match yet.</p>';
            return;
        }

        echo '<div style="max-height:300px; overflow-y:auto;">';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Date</th><th>User</th><th>Action</th><th>Details</th></tr></thead><tbody>';
        
        foreach( $logs as $log ) {
            $user = get_post_meta( $log->ID, '_log_user', true );
            $action = get_post_meta( $log->ID, '_log_action', true );
            $details = get_post_meta( $log->ID, '_log_details', true );
            $date = get_post_meta( $log->ID, '_log_date', true );
            
            echo "<tr>
                <td style='white-space:nowrap; font-size:11px;'>$date</td>
                <td><strong>".esc_html($user)."</strong></td>
                <td><span style='background:#eee; padding:2px 6px; border-radius:4px; font-size:10px; text-transform:uppercase;'>".esc_html($action)."</span></td>
                <td style='font-family:monospace; font-size:11px; color:#555;'>".nl2br(esc_html($details))."</td>
            </tr>";
        }
        echo '</tbody></table></div>';
    }
}