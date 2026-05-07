<?php
class FLMS_Scorekeeper {

    public function __construct() {
        add_shortcode( 'flms_scorekeeper_form', [ $this, 'render_form' ] );
        add_filter( 'manage_flms_match_posts_columns', [ $this, 'add_qr_column' ] );
        add_action( 'manage_flms_match_posts_custom_column', [ $this, 'render_qr_column' ], 10, 2 );
        add_action( 'init', [ $this, 'process_score_update' ] );
    }

    public function render_form() {
        if ( ! is_user_logged_in() ) return $this->login_prompt();
        $user = wp_get_current_user();
        if ( ! in_array( 'scorekeeper', (array) $user->roles ) && ! current_user_can('administrator') ) {
            return '<div class="flms-error">Access Denied. Not a Scorekeeper.</div>';
        }

        $match_id = isset( $_GET['mid'] ) ? intval( $_GET['mid'] ) : 0;
        if ( ! $match_id ) return '<div class="flms-error">No match selected.</div>';

        $home_id = get_post_meta( $match_id, 'flms_home_team', true );
        $away_id = get_post_meta( $match_id, 'flms_away_team', true );
        $home_name = get_the_title($home_id);
        $away_name = get_the_title($away_id);
        
        $h_score = get_post_meta( $match_id, 'flms_home_score', true );
        $a_score = get_post_meta( $match_id, 'flms_away_score', true );
        
        // Get Stats
        $h_corn = get_post_meta( $match_id, 'flms_home_corners', true );
        $a_corn = get_post_meta( $match_id, 'flms_away_corners', true );
        $h_foul = get_post_meta( $match_id, 'flms_home_fouls', true );
        $a_foul = get_post_meta( $match_id, 'flms_away_fouls', true );

        $events = get_post_meta( $match_id, '_flms_match_events', true ) ?: [];
        $home_players = $this->get_team_players($home_id, $match_id, 'home');
        $away_players = $this->get_team_players($away_id, $match_id, 'away');

        ob_start();
        ?>
        <div class="flms-sk-wrapper">
            <div class="flms-sk-header">
                <div class="sk-match-id">Match #<?php echo $match_id; ?></div>
                <div class="sk-teams"><div class="sk-team-name"><?php echo $home_name; ?></div><div class="sk-vs">VS</div><div class="sk-team-name"><?php echo $away_name; ?></div></div>
            </div>

            <form method="post" class="flms-sk-form" enctype="multipart/form-data">
                
                <!-- SCORE -->
                <div class="sk-section">
                    <h3>Current Score</h3>
                    <div class="sk-score-inputs">
                        <div class="sk-input-group"><label>Home</label><input type="number" name="h_score" value="<?php echo esc_attr($h_score); ?>" placeholder="0"></div>
                        <div class="sk-input-group"><label>Away</label><input type="number" name="a_score" value="<?php echo esc_attr($a_score); ?>" placeholder="0"></div>
                    </div>
                </div>

                <!-- TEAM STATS -->
                <div class="sk-section">
                    <h3>Team Stats</h3>
                    <div style="margin-bottom:15px;">
                        <label style="font-size:12px; font-weight:bold;">POSSESSION (Home %)</label>
                        <input type="number" name="h_poss" value="<?php echo get_post_meta($match_id, 'flms_home_possession', true); ?>" placeholder="50" style="width:100%; text-align:center; padding:10px; border:1px solid #ccc; border-radius:4px;">
                    </div>
                    
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <?php 
                        $stats = [ ['Shots', 'shots'], ['Corners', 'corners'], ['Offsides', 'offside'], ['Fouls', 'fouls'], ['Saves', 'saves'] ];
                        foreach($stats as $s): 
                            $h_val = get_post_meta($match_id, 'flms_home_'.$s[1], true);
                            $a_val = get_post_meta($match_id, 'flms_away_'.$s[1], true);
                        ?>
                        <div>
                            <label style="font-size:10px; font-weight:bold; display:block; text-align:center; text-transform:uppercase;"><?php echo $s[0]; ?></label>
                            <div style="display:flex; gap:5px;">
                                <input type="number" name="h_<?php echo $s[1]; ?>" value="<?php echo $h_val; ?>" placeholder="H" class="sk-stat-in">
                                <input type="number" name="a_<?php echo $s[1]; ?>" value="<?php echo $a_val; ?>" placeholder="A" class="sk-stat-in">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- EVENTS -->
                <div class="sk-section">
                    <h3>Match Events</h3>
                    <div style="display:flex; gap:5px; margin-bottom:5px; font-size:11px; color:#888; font-weight:bold;">
                        <span style="width:70px; text-align:center;">Min</span>
                        <span style="width:90px;">Event</span>
                        <span style="flex:1;">Player</span>
                        <span style="width:30px;"></span>
                    </div>
                    <div id="sk-events-list">
                        <?php foreach($events as $i => $ev) $this->render_event_row($i, $ev, $home_name, $home_players, $away_name, $away_players); ?>
                    </div>
                    <button type="button" id="sk-add-btn" class="sk-add-btn">+ Add Event</button>
                </div>

                <!-- PHOTOS -->
                <div class="sk-section">
                    <h3>Upload Photos</h3>
                    <input type="file" name="sk_gallery[]" multiple accept="image/*" class="sk-file-input">
                    <p style="font-size:11px; color:#999; margin-top:5px;">Select multiple files to add to gallery.</p>
                </div>

                <input type="hidden" name="flms_action" value="update_score">
                <input type="hidden" name="match_id" value="<?php echo $match_id; ?>">
                <?php wp_nonce_field('flms_sk_nonce'); ?>

                <button type="submit" class="sk-save-btn">Update Live Score & Photos</button>
            </form>

            <div id="sk-row-template" style="display:none;"><?php $this->render_event_row('INDEX', [], $home_name, $home_players, $away_name, $away_players); ?></div>
        </div>

        <script>
        jQuery(document).ready(function($){
            var list = $('#sk-events-list'), tmpl = $('#sk-row-template').html(), count = <?php echo count($events); ?>;
            $('#sk-add-btn').click(function(){ list.append(tmpl.replace(/INDEX/g, count++)); });
            $(document).on('click', '.sk-remove-row', function(){ $(this).closest('.sk-event-row').remove(); });
        });
        </script>

        <style>
            .flms-sk-wrapper { max-width: 600px; margin: 20px auto; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); font-family: sans-serif; }
            .flms-sk-header { text-align: center; border-bottom: 1px solid #eee; padding-bottom: 20px; margin-bottom: 20px; }
            .sk-match-id { font-size: 12px; color: #999; margin-bottom: 5px; }
            .sk-teams { display: flex; justify-content: space-between; align-items: center; font-size: 16px; font-weight: 700; color: #333; }
            .sk-vs { color: #ccc; font-size: 12px; margin: 0 10px; }
            .sk-section { margin-bottom: 30px; }
            .sk-section h3 { font-size: 15px; border-bottom: 2px solid #37003c; display: inline-block; padding-bottom: 3px; margin-bottom: 12px; color: #37003c; }
            .sk-score-inputs { display: flex; gap: 20px; }
            .sk-input-group { flex: 1; text-align: center; }
            .sk-input-group label { display: block; margin-bottom: 5px; font-size: 12px; font-weight:bold; color:#555; }
            .sk-input-group input { width: 100%; font-size: 32px; text-align: center; padding: 10px; border: 2px solid #eee; border-radius: 8px; font-weight: bold; }
            .sk-stat-in { width: 100%; text-align: center; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
            
            .sk-event-row { display: flex; gap: 5px; margin-bottom: 8px; background: #f9f9f9; padding: 8px; border-radius: 6px; align-items: center; }
            .sk-input-min { width: 70px !important; text-align: center; padding: 10px 5px !important; border: 1px solid #ddd; border-radius: 4px; font-size:16px; }
            .sk-input-type { width: 90px !important; padding: 10px 5px !important; border: 1px solid #ddd; border-radius: 4px; font-size:14px; }
            .sk-input-player { flex: 1; min-width: 0; padding: 10px 5px !important; border: 1px solid #ddd; border-radius: 4px; font-size:14px; }
            .sk-remove-row { background: #ff4d4d; color: white; border: none; width: 30px; height: 30px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }
            .sk-add-btn { width: 100%; padding: 10px; background: #eee; border: 1px dashed #ccc; color: #555; border-radius: 6px; cursor: pointer; font-weight:600; }
            .sk-save-btn { width: 100%; background: #2ecc71; color: white; border: none; padding: 15px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; margin-top: 10px; }
            .sk-file-input { width: 100%; padding: 10px; background: #f9f9f9; border: 1px dashed #ccc; }
            .flms-error { padding: 20px; text-align: center; background: #fee; color: #c00; border-radius: 8px; }
        </style>
        <?php
        return ob_get_clean();
    }

    private function render_event_row($i, $data, $h_name, $h_players, $a_name, $a_players) {
        $min  = isset($data['minute']) ? $data['minute'] : '';
        $type = isset($data['type']) ? $data['type'] : 'goal';
        $pid  = isset($data['player_id']) ? $data['player_id'] : '';
        ?>
        <div class="sk-event-row">
            <input type="number" name="flms_events[<?php echo $i; ?>][minute]" value="<?php echo esc_attr($min); ?>" placeholder="Min" class="sk-input-min">
            <select name="flms_events[<?php echo $i; ?>][type]" class="sk-input-type"><option value="goal" <?php selected($type, 'goal'); ?>>⚽ Goal</option><option value="assist" <?php selected($type, 'assist'); ?>>👟 Assist</option><option value="yellow" <?php selected($type, 'yellow'); ?>>🟨 Yel</option><option value="red" <?php selected($type, 'red'); ?>>🟥 Red</option></select>
            <select name="flms_events[<?php echo $i; ?>][player_id]" class="sk-input-player"><option value="">Player...</option><optgroup label="<?php echo esc_attr($h_name); ?>"><?php foreach($h_players as $p): ?><option value="<?php echo $p->ID; ?>" <?php selected($pid, $p->ID); ?>><?php echo esc_html($p->post_title); ?></option><?php endforeach; ?></optgroup><optgroup label="<?php echo esc_attr($a_name); ?>"><?php foreach($a_players as $p): ?><option value="<?php echo $p->ID; ?>" <?php selected($pid, $p->ID); ?>><?php echo esc_html($p->post_title); ?></option><?php endforeach; ?></optgroup></select>
            <button type="button" class="sk-remove-row">&times;</button>
        </div>
        <?php
    }

    private function get_team_players( $team_id, $match_id = 0, $side = '' ) {
        $lineup = [];
        if ( $match_id && $side ) {
            $key = ($side === 'home') ? '_flms_lineup_home' : '_flms_lineup_away';
            $lineup = get_post_meta( $match_id, $key, true );
        }
        $args = [ 'post_type' => 'flms_player', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ];
        if ( ! empty($lineup) && is_array($lineup) ) { $args['post__in'] = $lineup; } else { $args['meta_key'] = 'flms_team_id'; $args['meta_value'] = $team_id; }
        return get_posts( $args );
    }

    private function login_prompt() { return '<div style="text-align:center; padding:40px;"><h3>Login Required</h3><p>Please login.</p><a href="/manager-login/" class="button">Login</a></div>'; }
    public function add_qr_column( $columns ) { $columns['flms_qr'] = 'Scorekeeper QR'; return $columns; }
    public function render_qr_column( $column, $post_id ) { if ( $column === 'flms_qr' ) { $url = home_url( '/scorekeeper/?mid=' . $post_id ); $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode( $url ); echo "<img src='$qr' style='width:60px;'><br><a href='$url' target='_blank' style='font-size:10px;'>Link</a>"; } }

    public function process_score_update() {
        if ( isset( $_POST['flms_action'] ) && $_POST['flms_action'] === 'update_score' ) {
            check_admin_referer('flms_sk_nonce');
            $mid = intval( $_POST['match_id'] );
            
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );

            // 1. Save Scores & Stats
            update_post_meta( $mid, 'flms_home_score', intval($_POST['h_score']) );
            update_post_meta( $mid, 'flms_away_score', intval($_POST['a_score']) );
            $stats = ['corners', 'fouls', 'shots', 'offside', 'saves'];
            foreach($stats as $s) {
                update_post_meta( $mid, "flms_home_$s", intval($_POST["h_$s"]) );
                update_post_meta( $mid, "flms_away_$s", intval($_POST["a_$s"]) );
            }
            update_post_meta( $mid, 'flms_home_possession', intval($_POST['h_poss']) );
            update_post_meta( $mid, 'flms_match_status', 'completed' );

            // 2. Save Events
            $events = isset($_POST['flms_events']) ? $_POST['flms_events'] : [];
            $clean_events = [];
            foreach ( $events as $e ) { if ( ! empty( $e['player_id'] ) ) $clean_events[] = $e; }
            update_post_meta( $mid, '_flms_match_events', $clean_events );

            // 3. Handle Gallery Uploads (Fixed)
            $existing_gallery = get_post_meta($mid, 'flms_match_gallery', true);
            $gallery_ids = $existing_gallery ? explode(',', $existing_gallery) : [];

            if ( ! empty( $_FILES['sk_gallery']['name'][0] ) ) {
                $files = $_FILES['sk_gallery'];
                foreach ( $files['name'] as $key => $value ) {
                    if ( $files['name'][$key] ) {
                        $file = [ 'name' => $files['name'][$key], 'type' => $files['type'][$key], 'tmp_name' => $files['tmp_name'][$key], 'error' => $files['error'][$key], 'size' => $files['size'][$key] ];
                        // Fix for frontend upload (media_handle_sideload requires specific structure)
                        $_FILES['upload_file'] = $file;
                        $aid = media_handle_upload( 'upload_file', $mid );
                        if ( ! is_wp_error( $aid ) ) $gallery_ids[] = $aid;
                    }
                }
                update_post_meta( $mid, 'flms_match_gallery', implode(',', array_unique($gallery_ids)) );
            }

            // 4. LOGGING
            if ( class_exists('FLMS_Logger') ) {
                $details = "Score Update: {$_POST['h_score']} - {$_POST['a_score']}";
                FLMS_Logger::log( get_current_user_id(), 'Scorekeeper Update', $mid, $details );
            }

            // 5. Triggers
            if(class_exists('FLMS_Player_Stats')) {
                $all_pids = [];
                foreach($clean_events as $ne) if(isset($ne['player_id'])) $all_pids[] = $ne['player_id'];
                foreach(array_unique($all_pids) as $pid) (new FLMS_Player_Stats())->recalculate_single_player($pid);
            }
            if(class_exists('FLMS_Standings')) { (new FLMS_Standings())->trigger_calculation($mid, get_post($mid), true); }
            if(class_exists('FLMS_Knockout_Progression')) { (new FLMS_Knockout_Progression())->check_progression($mid, get_post($mid), true); }

            wp_redirect( add_query_arg( 'updated', 'true', $_SERVER['REQUEST_URI'] ) );
            exit;
        }
    }
}