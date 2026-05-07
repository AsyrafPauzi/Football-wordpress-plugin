<?php
class FLMS_Scoring {
    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
        // Priority 5 ensures this runs before standings calculation
        add_action( 'save_post_flms_match', [ $this, 'save_score' ], 5 );
        add_shortcode( 'flms_match_qr', [ $this, 'qr_shortcode' ] );
    }

    public function add_metabox() {
        add_meta_box( 'flms_scoring', 'Match Result & Full Stats', [ $this, 'render_metabox' ], 'flms_match', 'normal', 'high' );
    }

    public function render_metabox( $post ) {
        $home = get_post_meta( $post->ID, 'flms_home_score', true );
        $away = get_post_meta( $post->ID, 'flms_away_score', true );
        $home_id = get_post_meta( $post->ID, 'flms_home_team', true );
        $away_id = get_post_meta( $post->ID, 'flms_away_team', true );
        $home_name = $home_id ? get_the_title($home_id) : 'Home';
        $away_name = $away_id ? get_the_title($away_id) : 'Away';
        $status = get_post_meta( $post->ID, 'flms_match_status', true );

        $h_poss = get_post_meta( $post->ID, 'flms_home_possession', true );
        $h_shot = get_post_meta( $post->ID, 'flms_home_shots', true ); $a_shot = get_post_meta( $post->ID, 'flms_away_shots', true );
        $h_corn = get_post_meta( $post->ID, 'flms_home_corners', true ); $a_corn = get_post_meta( $post->ID, 'flms_away_corners', true );
        $h_off  = get_post_meta( $post->ID, 'flms_home_offside', true ); $a_off  = get_post_meta( $post->ID, 'flms_away_offside', true );
        $h_foul = get_post_meta( $post->ID, 'flms_home_fouls', true ); $a_foul = get_post_meta( $post->ID, 'flms_away_fouls', true );
        $h_save = get_post_meta( $post->ID, 'flms_home_saves', true ); $a_save = get_post_meta( $post->ID, 'flms_away_saves', true );

        ?>
        <div class="flms-score-input" style="background: #fcfcfc; padding: 20px; border:1px solid #eee; text-align: center;">
            <div style="display: flex; justify-content: space-around; align-items: flex-end; margin-bottom:20px;">
                <div style="flex: 1;">
                    <h3 style="margin:0 0 5px; color:#37003c;"><?php echo esc_html($home_name); ?></h3>
                    <input type="number" name="flms_home_score" value="<?php echo esc_attr($home); ?>" style="font-size:30px; width:70px; text-align:center; border:2px solid #333;">
                </div>
                <div style="font-size: 30px; color: #ccc; font-weight:300;">VS</div>
                <div style="flex: 1;">
                    <h3 style="margin:0 0 5px; color:#37003c;"><?php echo esc_html($away_name); ?></h3>
                    <input type="number" name="flms_away_score" value="<?php echo esc_attr($away); ?>" style="font-size:30px; width:70px; text-align:center; border:2px solid #333;">
                </div>
            </div>
            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
            <div style="text-align:left; max-width:600px; margin:0 auto;">
                <div style="margin-bottom:15px; background:#f9f9f9; padding:10px; border-radius:4px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">📊 Possession (Home %)</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="number" name="flms_home_possession" value="<?php echo esc_attr($h_poss); ?>" placeholder="50" style="width:70px;"> %
                        <span style="font-size:11px; color:#888;">(Away is auto-calculated)</span>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <?php 
                    $stat_fields = [
                        ['label'=>'Shots on Target', 'key'=>'shots', 'h'=>$h_shot, 'a'=>$a_shot],
                        ['label'=>'Corners', 'key'=>'corners', 'h'=>$h_corn, 'a'=>$a_corn],
                        ['label'=>'Offsides', 'key'=>'offside', 'h'=>$h_off, 'a'=>$a_off],
                        ['label'=>'Fouls', 'key'=>'fouls', 'h'=>$h_foul, 'a'=>$a_foul],
                        ['label'=>'Saves', 'key'=>'saves', 'h'=>$h_save, 'a'=>$a_save],
                    ];
                    foreach($stat_fields as $f) {
                        echo "<div><label style='font-size:12px; font-weight:bold; display:block;'>{$f['label']}</label><div style='display:flex; gap:5px; margin-top:3px;'><input type='number' name='flms_home_{$f['key']}' value='{$f['h']}' placeholder='H' style='width:50%;'><input type='number' name='flms_away_{$f['key']}' value='{$f['a']}' placeholder='A' style='width:50%;'></div></div>";
                    }
                    ?>
                </div>
            </div>
            <div style="margin-top:30px; border-top:1px solid #eee; padding-top:20px;">
                <p style="color:#888; font-style:italic; font-size:11px; margin-bottom:10px;">Current Status: <strong><?php echo strtoupper($status); ?></strong></p>
                <button type="submit" name="flms_reset_match" value="yes" class="button" style="background:#c0392b; color:#fff; border-color:#c0392b;" onclick="return confirm('⚠️ Are you sure? This will delete the scores and events for this match.');">Reset Match Data (Clear Scores)</button>
            </div>
        </div>
        <?php
    }

    public function save_score( $post_id ) {
        if ( get_post_type($post_id) !== 'flms_match' ) return;
        if ( isset($_POST['post_ID']) && intval($_POST['post_ID']) !== $post_id ) return;

        // Get Current User for Logging
        $current_user_id = get_current_user_id();

        // --- RESET LOGIC ---
        if ( isset($_POST['flms_reset_match']) && $_POST['flms_reset_match'] === 'yes' ) {
            update_post_meta( $post_id, 'flms_home_score', 0 );
            update_post_meta( $post_id, 'flms_away_score', 0 );
            update_post_meta( $post_id, 'flms_match_status', 'pending' );
            $stats = ['corners', 'fouls', 'shots', 'offside', 'saves'];
            foreach($stats as $s) { update_post_meta( $post_id, "flms_home_$s", 0 ); update_post_meta( $post_id, "flms_away_$s", 0 ); }
            update_post_meta( $post_id, 'flms_home_possession', 0 );
            delete_post_meta( $post_id, '_flms_match_events' );

            // LOGGING
            if ( class_exists('FLMS_Logger') ) {
                FLMS_Logger::log( $current_user_id, 'MATCH RESET', $post_id, 'Match data completely reset to 0.' );
            }
            return;
        }

        // --- SAVE LOGIC ---
        if ( isset( $_POST['flms_home_score'] ) && $_POST['flms_home_score'] !== '' ) {
            $old_h = get_post_meta( $post_id, 'flms_home_score', true );
            $old_a = get_post_meta( $post_id, 'flms_away_score', true );
            
            $new_h = intval( $_POST['flms_home_score'] );
            $new_a = intval( $_POST['flms_away_score'] );

            update_post_meta( $post_id, 'flms_home_score', $new_h );
            update_post_meta( $post_id, 'flms_away_score', $new_a );
            
            $stats = ['corners', 'fouls', 'shots', 'offside', 'saves'];
            foreach($stats as $s) {
                $h_val = isset($_POST["flms_home_$s"]) ? $_POST["flms_home_$s"] : (isset($_POST["h_$s"]) ? $_POST["h_$s"] : 0);
                $a_val = isset($_POST["flms_away_$s"]) ? $_POST["flms_away_$s"] : (isset($_POST["a_$s"]) ? $_POST["a_$s"] : 0);
                update_post_meta( $post_id, "flms_home_$s", intval($h_val) );
                update_post_meta( $post_id, "flms_away_$s", intval($a_val) );
            }
            update_post_meta( $post_id, 'flms_home_possession', intval($_POST['flms_home_possession']) );
            update_post_meta( $post_id, 'flms_match_status', 'completed' );

            // LOGGING: Only if score changed
            if ( class_exists('FLMS_Logger') && ( $old_h != $new_h || $old_a != $new_a ) ) {
                $details = "Score Changed: ($old_h - $old_a) -> ($new_h - $new_a).";
                FLMS_Logger::log( $current_user_id, 'SCORE UPDATE', $post_id, $details );
            }
        }
    }

    public function qr_shortcode( $atts ) {
        $url = get_permalink(); 
        return '<img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode( $url ) . '" alt="Match QR"/>';
    }
}