<?php
class FLMS_Player_Stats {

    public function __construct() {
        // Admin & Saving
        add_action( 'add_meta_boxes', [ $this, 'add_stats_metabox' ] );
        add_action( 'save_post_flms_match', [ $this, 'save_match_events' ] );
        
        // Shortcodes
        add_shortcode( 'flms_golden_boot', [ $this, 'shortcode_goals' ] );
        add_shortcode( 'flms_top_assists', [ $this, 'shortcode_assists' ] );
        add_shortcode( 'flms_leaderboard', [ $this, 'render_leaderboard' ] );
    }

    public function add_stats_metabox() {
        add_meta_box( 'flms_match_events_box', 'Match Events & Lineups', [ $this, 'render_events_ui' ], 'flms_match', 'normal', 'high' );
    }

    public function render_events_ui( $post ) {
        // --- SECURITY & SAFETY FLAGS ---
        wp_nonce_field( 'flms_save_events_data', 'flms_events_nonce' );
        
        // Hidden field to prevent data overwrite on Quick Edit
        echo '<input type="hidden" name="flms_events_present" value="1">';

        $home_id = get_post_meta( $post->ID, 'flms_home_team', true );
        $away_id = get_post_meta( $post->ID, 'flms_away_team', true );

        if ( ! $home_id || ! $away_id ) {
            echo '<p style="color:red;">⚠ Please assign Home and Away teams and SAVE the match first.</p>';
            return;
        }

        $home_name = get_the_title($home_id);
        $away_name = get_the_title($away_id);
        
        // Get All Players for Selection (Admin needs to see everyone)
        $home_all_players = get_posts(['post_type'=>'flms_player', 'meta_key'=>'flms_team_id', 'meta_value'=>$home_id, 'posts_per_page'=>-1, 'orderby'=>'title', 'order'=>'ASC']);
        $away_all_players = get_posts(['post_type'=>'flms_player', 'meta_key'=>'flms_team_id', 'meta_value'=>$away_id, 'posts_per_page'=>-1, 'orderby'=>'title', 'order'=>'ASC']);

        // Get Saved Lineups
        $home_lineup = get_post_meta($post->ID, '_flms_lineup_home', true) ?: [];
        $away_lineup = get_post_meta($post->ID, '_flms_lineup_away', true) ?: [];

        $events = get_post_meta( $post->ID, '_flms_match_events', true );
        if ( ! is_array( $events ) ) $events = [];

        ?>
        <div id="flms-lineup-wrapper" style="margin-bottom:30px; background:#f9f9f9; padding:15px; border:1px solid #ddd;">
            <h3 style="margin-top:0;">📋 Confirm Lineups (Check who played)</h3>
            <div style="display:flex; gap:20px;">
                <!-- Home Lineup -->
                <div style="flex:1;">
                    <strong><?php echo esc_html($home_name); ?></strong><br>
                    <div style="max-height:150px; overflow-y:auto; border:1px solid #ccc; padding:5px; background:#fff;">
                        <?php foreach($home_all_players as $p): ?>
                            <label style="display:block;">
                                <input type="checkbox" name="flms_lineup_home[]" value="<?php echo $p->ID; ?>" <?php checked(in_array($p->ID, $home_lineup)); ?>> 
                                <?php echo esc_html($p->post_title); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <!-- Away Lineup -->
                <div style="flex:1;">
                    <strong><?php echo esc_html($away_name); ?></strong><br>
                    <div style="max-height:150px; overflow-y:auto; border:1px solid #ccc; padding:5px; background:#fff;">
                        <?php foreach($away_all_players as $p): ?>
                            <label style="display:block;">
                                <input type="checkbox" name="flms_lineup_away[]" value="<?php echo $p->ID; ?>" <?php checked(in_array($p->ID, $away_lineup)); ?>> 
                                <?php echo esc_html($p->post_title); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <p class="description">Only checked players will get +1 Appearance and potentially Clean Sheet points.</p>
        </div>

        <div id="flms-events-wrapper">
            <h3 style="border-bottom:1px solid #ccc; padding-bottom:5px;">⚽ Match Events</h3>
            <div style="display:flex; font-weight:bold; padding: 5px 0; border-bottom:1px solid #ccc; margin-bottom:10px;">
                <div style="width:15%;">Minute (')</div>
                <div style="width:25%;">Event</div>
                <div style="width:50%;">Player</div>
                <div style="width:10%;"></div>
            </div>

            <div id="flms-events-list">
                <?php 
                if ( ! empty( $events ) ) {
                    foreach ( $events as $index => $event ) {
                        $this->render_event_row( $index, $event, $home_name, $home_all_players, $away_name, $away_all_players );
                    }
                }
                ?>
            </div>
            
            <div style="margin-top: 15px;">
                <button type="button" class="button button-primary" id="flms-add-event">+ Add Event</button>
            </div>

            <div id="flms-row-template" style="display:none;">
                <?php $this->render_event_row( 'INDEX', [], $home_name, $home_all_players, $away_name, $away_all_players ); ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            var list = $('#flms-events-list');
            var template = $('#flms-row-template').html();
            var count = <?php echo count($events); ?>;

            $('#flms-add-event').click(function(){
                var row = template.replace(/INDEX/g, count); 
                list.append(row);
                count++;
            });

            $(document).on('click', '.flms-remove-row', function(){
                $(this).closest('.flms-event-row').remove();
            });
        });
        </script>
        <?php
    }

    private function render_event_row( $i, $data, $h_name, $h_players, $a_name, $a_players ) {
        $min  = isset($data['minute']) ? $data['minute'] : '';
        $type = isset($data['type']) ? $data['type'] : 'goal';
        $pid  = isset($data['player_id']) ? $data['player_id'] : '';
        ?>
        <div class="flms-event-row" style="display:flex; gap:10px; margin-bottom:10px; padding-bottom:10px; border-bottom:1px solid #eee;">
            <div style="width:15%;"><input type="number" name="flms_events[<?php echo $i; ?>][minute]" value="<?php echo esc_attr($min); ?>" placeholder="Min" style="width:100%;"></div>
            <div style="width:25%;">
                <select name="flms_events[<?php echo $i; ?>][type]" style="width:100%;">
                    <option value="goal" <?php selected($type, 'goal'); ?>>Goal</option>
                    <option value="assist" <?php selected($type, 'assist'); ?>>Assist</option>
                    <option value="yellow" <?php selected($type, 'yellow'); ?>>Yellow Card</option>
                    <option value="red" <?php selected($type, 'red'); ?>>Red Card</option>
                </select>
            </div>
            <div style="width:50%;">
                <select name="flms_events[<?php echo $i; ?>][player_id]" style="width:100%;">
                    <option value="">-- Select Player --</option>
                    <optgroup label="<?php echo esc_attr($h_name); ?>">
                        <?php foreach($h_players as $p): ?><option value="<?php echo $p->ID; ?>" <?php selected($pid, $p->ID); ?>><?php echo esc_html($p->post_title); ?></option><?php endforeach; ?>
                    </optgroup>
                    <optgroup label="<?php echo esc_attr($a_name); ?>">
                        <?php foreach($a_players as $p): ?><option value="<?php echo $p->ID; ?>" <?php selected($pid, $p->ID); ?>><?php echo esc_html($p->post_title); ?></option><?php endforeach; ?>
                    </optgroup>
                </select>
            </div>
            <div style="width:10%;"><button type="button" class="button flms-remove-row" style="color: #a00;">&times;</button></div>
        </div>
        <?php
    }

    public function save_match_events( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        
        // --- 1. CRITICAL SAFETY CHECK ---
        if ( ! isset( $_POST['flms_events_present'] ) ) return;

        // Security Nonce
        if ( ! isset( $_POST['flms_events_nonce'] ) || ! wp_verify_nonce( $_POST['flms_events_nonce'], 'flms_save_events_data' ) ) return;

        $home_id = get_post_meta( $post_id, 'flms_home_team', true );
        $away_id = get_post_meta( $post_id, 'flms_away_team', true );
        if ( ! $home_id || ! $away_id ) return;

        // 2. Save Lineups
        $home_lineup = isset($_POST['flms_lineup_home']) ? array_map('intval', $_POST['flms_lineup_home']) : [];
        $away_lineup = isset($_POST['flms_lineup_away']) ? array_map('intval', $_POST['flms_lineup_away']) : [];
        update_post_meta($post_id, '_flms_lineup_home', $home_lineup);
        update_post_meta($post_id, '_flms_lineup_away', $away_lineup);

        // 3. Save Events
        $events = isset($_POST['flms_events']) ? $_POST['flms_events'] : [];
        $clean_events = [];
        $current_reds = [];

        foreach ( $events as $e ) {
            if ( ! empty( $e['player_id'] ) ) {
                $clean_events[] = $e;
                if( $e['type'] === 'red' ) $current_reds[] = $e['player_id'];
            }
        }

        // RED CARD LOGIC
        $old_events = get_post_meta( $post_id, '_flms_match_events', true ) ?: [];
        $old_reds = [];
        foreach($old_events as $oe) { if($oe['type'] === 'red') $old_reds[] = $oe['player_id']; }

        $new_reds = array_diff($current_reds, $old_reds);
        foreach($new_reds as $pid) {
            $this->send_red_card_alert($pid, $post_id);
        }

        // UPDATE DB
        update_post_meta( $post_id, '_flms_match_events', $clean_events );

        // --- AUDIT LOGGING ---
        if ( class_exists('FLMS_Logger') ) {
            $user_id = get_current_user_id();
            $event_count = count($clean_events);
            $lineup_count = count($home_lineup) + count($away_lineup);
            $msg = "Events Updated ($event_count total). Lineup Updated ($lineup_count players).";
            FLMS_Logger::log( $user_id, 'EVENTS_UPDATE', $post_id, $msg );
        }

        // RECALCULATE STATS
        // We calculate for everyone in the lineup and everyone in events to ensure clean sheets/apps are accurate
        $players_to_update = array_unique(
            array_filter(
                array_map(
                    'intval',
                    array_merge(
                        array_column( $old_events, 'player_id' ),
                        array_column( $clean_events, 'player_id' ),
                        $home_lineup,
                        $away_lineup
                    )
                )
            )
        );

        $this->recalculate_players_batch( $players_to_update );
    }

    /**
     * Recalculate many players with one match query per team (not per player).
     *
     * @param int[] $pids
     */
    private function recalculate_players_batch( $pids ) {
        if ( empty( $pids ) ) {
            return;
        }

        $by_team = [];
        foreach ( $pids as $pid ) {
            if ( ! $pid ) {
                continue;
            }
            $team_id = (int) get_post_meta( $pid, 'flms_team_id', true );
            if ( $team_id ) {
                $by_team[ $team_id ][] = $pid;
            } else {
                $this->recalculate_single_player( $pid );
            }
        }

        foreach ( $by_team as $team_id => $team_pids ) {
            $match_ids = get_posts(
                [
                    'post_type'      => 'flms_match',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'post_status'    => 'publish',
                    'meta_query'     => [
                        'relation' => 'OR',
                        [ 'key' => 'flms_home_team', 'value' => $team_id ],
                        [ 'key' => 'flms_away_team', 'value' => $team_id ],
                    ],
                ]
            );
            update_meta_cache( 'post', $match_ids );
            foreach ( array_unique( $team_pids ) as $pid ) {
                $this->recalculate_single_player_for_match_ids( (int) $pid, $match_ids, (int) $team_id );
            }
        }
    }

    private function send_red_card_alert( $player_id, $match_id ) {
        $p_name = get_the_title($player_id);
        $tid = get_post_meta($player_id, 'flms_team_id', true);
        $manager_id = get_post_field('post_author', $tid);
        $manager = get_userdata($manager_id);
        if(!$manager) return;
        $total_reds = (int) get_post_meta($player_id, 'flms_total_red', true) + 1; 
        $subject = "⚠️ Disciplinary Alert: $p_name";
        $message = "Hello {$manager->display_name},\n\nYour player **$p_name** received a RED CARD in Match #$match_id.\n\n";
        if ( $total_reds % 2 == 0 ) {
            $message .= "🔴 IMPORTANT: This is their {$total_reds}th Red Card. You are required to pay a RM50 fine to the organizer immediately.\n";
        }
        wp_mail( $manager->user_email, $subject, $message );
    }

    /**
     * RECALCULATION FUNCTION (FIXED FOR CLEAN SHEETS)
     */
    public function recalculate_single_player( $pid ) {
        $pid     = (int) $pid;
        $team_id = (int) get_post_meta( $pid, 'flms_team_id', true );

        if ( ! $team_id ) {
            $this->persist_player_stats( $pid, [ 'goals' => 0, 'assists' => 0, 'yellow' => 0, 'red' => 0, 'apps' => 0, 'cleans' => 0, 'points' => 0 ] );
            return;
        }

        $match_ids = get_posts(
            [
                'post_type'      => 'flms_match',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'post_status'    => 'publish',
                'meta_query'     => [
                    'relation' => 'OR',
                    [ 'key' => 'flms_home_team', 'value' => $team_id ],
                    [ 'key' => 'flms_away_team', 'value' => $team_id ],
                ],
            ]
        );
        update_meta_cache( 'post', $match_ids );
        $this->recalculate_single_player_for_match_ids( $pid, $match_ids, $team_id );
    }

    /**
     * @param int   $pid
     * @param int[] $match_ids
     * @param int   $team_id
     */
    private function recalculate_single_player_for_match_ids( $pid, $match_ids, $team_id ) {
        $stats      = [ 'goals' => 0, 'assists' => 0, 'yellow' => 0, 'red' => 0, 'apps' => 0, 'cleans' => 0, 'points' => 0 ];
        $raw_pos    = get_post_meta( $pid, 'flms_position', true );
        $player_pos = strtoupper( trim( (string) $raw_pos ) );
        $pid        = (int) $pid;
        $team_id    = (int) $team_id;

        foreach ( $match_ids as $mid ) {
            if ( get_post_meta( $mid, 'flms_match_status', true ) !== 'completed' ) {
                continue;
            }

            $hid     = get_post_meta( $mid, 'flms_home_team', true );
            $h_score = get_post_meta( $mid, 'flms_home_score', true );
            $a_score = get_post_meta( $mid, 'flms_away_score', true );

            $home_lineup = get_post_meta( $mid, '_flms_lineup_home', true ) ?: [];
            $away_lineup = get_post_meta( $mid, '_flms_lineup_away', true ) ?: [];

            $is_home_player = in_array( $pid, array_map( 'intval', (array) $home_lineup ), true );
            $is_away_player = in_array( $pid, array_map( 'intval', (array) $away_lineup ), true );

            if ( $is_home_player || $is_away_player ) {
                $stats['apps']++;

                if ( in_array( $player_pos, [ 'GK', 'DEF' ], true ) ) {
                    if ( $is_home_player && $a_score !== '' && (int) $a_score === 0 ) {
                        $stats['cleans']++;
                    }
                    if ( $is_away_player && $h_score !== '' && (int) $h_score === 0 ) {
                        $stats['cleans']++;
                    }
                }
            }

            $events = get_post_meta( $mid, '_flms_match_events', true ) ?: [];
            foreach ( $events as $e ) {
                if ( isset( $e['player_id'] ) && (int) $e['player_id'] === $pid ) {
                    if ( $e['type'] === 'goal' ) {
                        $stats['goals']++;
                    }
                    if ( $e['type'] === 'assist' ) {
                        $stats['assists']++;
                    }
                    if ( $e['type'] === 'yellow' ) {
                        $stats['yellow']++;
                    }
                    if ( $e['type'] === 'red' ) {
                        $stats['red']++;
                    }
                }
            }
        }

        $stats['points'] = ( $stats['goals'] * 5 ) + ( $stats['assists'] * 3 ) + ( $stats['cleans'] * 3 ) + ( $stats['apps'] * 1 ) - ( $stats['yellow'] * 2 ) - ( $stats['red'] * 5 );
        $this->persist_player_stats( $pid, $stats );
    }

    private function persist_player_stats( $pid, $stats ) {
        foreach ( $stats as $key => $val ) {
            update_post_meta( $pid, 'flms_total_' . $key, $val );
        }
        update_post_meta( $pid, 'flms_ranking_points', $stats['points'] );
    }

    public function shortcode_goals($atts) {
        $atts = shortcode_atts(['id' => 0, 'limit' => 10, 'title' => 'Top Scorers'], $atts);
        return $this->render_leaderboard(['type' => 'goals', 'id' => $atts['id'], 'limit' => $atts['limit'], 'title' => $atts['title']]);
    }
    public function shortcode_assists($atts) {
        $atts = shortcode_atts(['id' => 0, 'limit' => 10, 'title' => 'Top Assists'], $atts);
        return $this->render_leaderboard(['type' => 'assists', 'id' => $atts['id'], 'limit' => $atts['limit'], 'title' => $atts['title']]);
    }
    private function render_leaderboard( $args ) {
        $type  = $args['type'];  $limit = intval($args['limit']); $tid   = intval($args['id']); $title = $args['title'];
        $cache_key = 'flms_lead_' . FLMS_Cache_Bump::version() . '_' . $type . '_' . $tid . '_' . $limit;
        $cached = get_transient($cache_key);
        if ( false !== $cached ) return $cached . '<!-- Cached -->';

        $query_args = [ 'post_type' => 'flms_player', 'posts_per_page' => -1, 'post_status' => 'publish' ];
        if ( $tid > 0 ) {
            global $wpdb;
            $team_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'flms_tournament_id' AND meta_value = %d", $tid ) );
            if ( empty($team_ids) ) return '<div style="padding:20px; text-align:center; color:#999;">No teams found.</div>';
            $query_args['meta_query'] = [ [ 'key' => 'flms_team_id', 'value' => $team_ids, 'compare' => 'IN' ] ];
        }

        $query = new WP_Query($query_args);
        $aggregated = [];

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $pid = get_the_ID();
                $raw_ic = get_post_meta($pid, 'flms_ic', true);
                $clean_ic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $raw_ic));
                
                if ( empty($clean_ic) || $clean_ic === '0' || $clean_ic === '000000000000' ) $key = 'id_' . $pid;
                else $key = $clean_ic;

                $stat_key = 'flms_total_' . $type; 
                $val = (int) get_post_meta($pid, $stat_key, true);

                if ($val > 0) {
                    if ( isset($aggregated[$key]) ) {
                        $aggregated[$key]['val'] += $val;
                        if($tid > 0) { $aggregated[$key]['id'] = $pid; }
                    } else {
                        $team_id = get_post_meta($pid, 'flms_team_id', true);
                        $team_name = $team_id ? get_the_title($team_id) : 'Free Agent';
                        $aggregated[$key] = [ 'id' => $pid, 'name' => get_the_title(), 'team' => $team_name, 'val' => $val ];
                    }
                }
            }
        }
        wp_reset_postdata();

        uasort($aggregated, function($a, $b) { return $b['val'] <=> $a['val']; });
        $top_list = array_slice($aggregated, 0, $limit);

        ob_start();
        ?>
        <div class="flms-leaderboard-box"><h3 class="lb-title"><?php echo esc_html($title); ?></h3><?php if(empty($top_list)): ?><div style="padding:20px; text-align:center; color:#999; font-size:13px;">No stats recorded yet.</div><?php else: ?><table class="flms-lb-table"><thead><tr><th width="10">#</th><th>Player</th><th width="50" style="text-align:center;"><?php echo ($type=='assists')?'AST':'GOL'; ?></th></tr></thead><tbody><?php $rank = 1; foreach($top_list as $p): $photo = class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_player_photo($p['id'], 'thumbnail') : ''; $row_class = ($rank === 1) ? 'lb-first' : ''; $rank_display = $rank; if($rank===1) $rank_display = '🥇'; if($rank===2) $rank_display = '🥈'; if($rank===3) $rank_display = '🥉'; ?><tr class="<?php echo $row_class; ?>"><td class="lb-rank"><?php echo $rank_display; ?></td><td class="lb-player"><a href="<?php echo get_permalink($p['id']); ?>" class="lb-link"><img src="<?php echo esc_url($photo); ?>" class="lb-img"><div class="lb-info"><span class="lb-name"><?php echo esc_html($p['name']); ?></span><span class="lb-team"><?php echo esc_html($p['team']); ?></span></div></a></td><td class="lb-val"><?php echo $p['val']; ?></td></tr><?php $rank++; endforeach; ?></tbody></table><?php endif; ?></div><style>.flms-leaderboard-box { background: #fff; border-radius: 8px; border: 1px solid #eee; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.03); margin-bottom: 30px; } .lb-title { background: #0a0a0a; color: #D4AF37; margin: 0; padding: 12px 15px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid #D4AF37; font-weight:800; } .flms-lb-table { width: 100%; border-collapse: collapse; } .flms-lb-table th { background: #f9f9f9; text-align: left; padding: 8px 10px; font-size: 10px; color: #999; text-transform: uppercase; font-weight: 700; } .flms-lb-table td { padding: 10px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; } .flms-lb-table tr:last-child td { border-bottom: none; } .lb-rank { font-weight: 800; color: #333; font-size: 13px; text-align: center; } .lb-first { background: #fffdf0; } .lb-link { display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; } .lb-img { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 1px solid #eee; } .lb-info { display: flex; flex-direction: column; } .lb-name { font-weight: 700; font-size: 13px; color: #0a0a0a; line-height: 1.2; } .lb-team { font-size: 10px; color: #888; margin-top: 1px; } .lb-val { font-size: 16px; font-weight: 900; color: #0a0a0a; text-align: center; } @media (max-width: 600px) { .lb-name { font-size: 12px; } .lb-img { width: 30px; height: 30px; } .flms-lb-table td, .flms-lb-table th { padding: 8px; } }</style>
        <?php
        $html = ob_get_clean();
        set_transient($cache_key, $html, 12 * HOUR_IN_SECONDS);
        return $html;
    }
}