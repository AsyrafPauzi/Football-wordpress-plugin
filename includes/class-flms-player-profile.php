<?php
class FLMS_Player_Profile {

    public function __construct() {
        add_filter( 'the_content', [ $this, 'render_player_profile' ] );
        
        // Admin: Add Remarks Metabox
        add_action( 'add_meta_boxes', [ $this, 'add_remarks_metabox' ] );
        add_action( 'save_post_flms_player', [ $this, 'save_remarks_meta' ] );

        // PERFORMANCE: Clear cache when data changes
        add_action( 'save_post_flms_match', [ $this, 'clear_profile_cache' ] );
        add_action( 'save_post_flms_player', [ $this, 'clear_profile_cache' ] );
    }

    // --- 1. ADMIN METABOX ---
    public function add_remarks_metabox() {
        if ( current_user_can( 'manage_options' ) ) {
            add_meta_box( 'flms_player_remarks_box', '🏆 Player Awards / Admin Remarks', [ $this, 'render_remarks_ui' ], 'flms_player', 'normal', 'high' );
        }
    }

    public function render_remarks_ui( $post ) {
        $value = get_post_meta( $post->ID, 'flms_player_remarks', true );
        echo '<p><strong>Add awards or notes (Visible on Frontend).</strong></p><textarea name="flms_player_remarks" style="width:100%; height:100px;">'.esc_textarea($value).'</textarea>';
    }

    public function save_remarks_meta( $post_id ) {
        if ( isset( $_POST['flms_player_remarks'] ) && current_user_can('manage_options') ) {
            update_post_meta( $post_id, 'flms_player_remarks', sanitize_textarea_field( $_POST['flms_player_remarks'] ) );
        }
    }

    // --- 2. CACHE ---
    public function clear_profile_cache() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_flms_profile_%'" );
    }

    // --- 3. RENDER PROFILE ---
    public function render_player_profile( $content ) {
        if ( ! is_singular( 'flms_player' ) ) return $content;

        $current_pid = get_the_ID();
        $mh_page = isset( $_GET['mhp'] ) ? max( 1, (int) $_GET['mhp'] ) : 1;

        // CHECK CACHE (include match history page so pagination is correct)
        $cache_key = 'flms_profile_' . $current_pid . '_mhp' . $mh_page;
        $cached_html = get_transient( $cache_key );
        if ( false !== $cached_html ) { return $cached_html . '<!-- Cached Profile -->'; }

        // GET PROFILES BY IC
        $ic = get_post_meta( $current_pid, 'flms_ic', true );
        $all_pids = [$current_pid]; 

        if ( ! empty($ic) ) {
            $related_profiles = get_posts([
                'post_type' => 'flms_player',
                'meta_key' => 'flms_ic',
                'meta_value' => $ic,
                'posts_per_page' => -1,
                'fields' => 'ids'
            ]);
            if(!empty($related_profiles)) $all_pids = $related_profiles;
        }

        // AGGREGATE DATA
        $grand_total = [ 'goals'=>0, 'assists'=>0, 'apps'=>0, 'cleans'=>0, 'yellow'=>0, 'red'=>0, 'points'=>0 ];
        $breakdown_rows = [];
        $teams_list = [];
        $positions_list = []; // NEW: Array to hold positions
        $all_remarks = []; 

        foreach ( $all_pids as $pid ) {
            // OPTIMIZATION: Get ALL meta
            $meta = get_post_meta($pid);
            $val = function($k) use ($meta) { return isset($meta[$k][0]) ? (int)$meta[$k][0] : 0; };

            $tid = isset($meta['flms_team_id'][0]) ? $meta['flms_team_id'][0] : 0;
            $tour_id = $tid ? get_post_meta($tid, 'flms_tournament_id', true) : 0;

            // Collect Remarks
            if ( isset($meta['flms_player_remarks'][0]) && !empty($meta['flms_player_remarks'][0]) ) {
                $all_remarks[] = $meta['flms_player_remarks'][0];
            }

            // Collect Positions (NEW LOGIC)
            if ( isset($meta['flms_position'][0]) && !empty($meta['flms_position'][0]) ) {
                $positions_list[] = $meta['flms_position'][0];
            }

            // Stats
            $g = $val('flms_total_goals');
            $a = $val('flms_total_assists');
            $ap = $val('flms_total_apps');
            $cs = $val('flms_total_cleans');
            $y = $val('flms_total_yellow');
            $r = $val('flms_total_red');
            $pts = $val('flms_ranking_points');

            $grand_total['goals'] += $g; $grand_total['assists'] += $a; $grand_total['apps'] += $ap;
            $grand_total['cleans'] += $cs; $grand_total['yellow'] += $y; $grand_total['red'] += $r;
            $grand_total['points'] += $pts;

            $team_name = get_the_title( $tid );
            $tour_name = $tour_id ? get_the_title($tour_id) : 'Unassigned';

            // Only add to Teams List if in Tournament
            if ( ! empty($tour_id) ) {
                $teams_list[] = $team_name;
            }

            // Breakdown
            if ( ! empty($tour_id) || $ap > 0 ) {
                $breakdown_rows[] = [ 'tournament' => $tour_name, 'team' => $team_name, 'apps' => $ap, 'goals' => $g, 'assists' => $a, 'yellow' => $y, 'red' => $r ];
            }
        }
        
        $teams_display = !empty($teams_list) ? implode(' / ', array_unique($teams_list)) : 'Free Agent';
        
        // MERGE POSITIONS: e.g., "GK / FWD"
        $positions_display = !empty($positions_list) ? implode(' / ', array_unique($positions_list)) : 'Player';

        // Basic Info
        $age = get_post_meta( $current_pid, 'flms_age', true ) ?: '-';
        $num = get_post_meta( $current_pid, 'flms_number', true ) ?: '-'; 
        $photo = class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_player_photo( $current_pid, 'medium' ) : '';
        $display_remarks = array_unique($all_remarks);

        ob_start();
        ?>
        <div class="flms-player-card dark-mode">
            
            <!-- HEADER -->
            <div class="flms-player-header">
                <div class="flms-player-photo"><img src="<?php echo esc_url($photo); ?>"></div>
                <div class="flms-player-info">
                    <h1 class="flms-p-name"><?php the_title(); ?></h1>
                    <div class="flms-p-team"><span><?php echo esc_html($teams_display); ?></span></div>
                    
                    <div class="flms-p-meta">
                        <span class="meta-tag">No: <?php echo esc_html($num); ?></span>
                        
                        <!-- DISPLAY COMBINED POSITIONS HERE -->
                        <span class="meta-tag">Pos: <?php echo esc_html($positions_display); ?></span>
                        
                        <span class="meta-tag">Age: <?php echo esc_html($age); ?></span>
                    </div>

                    <!-- AWARDS -->
                    <?php if ( ! empty($display_remarks) ) : ?>
                    <div class="flms-p-awards">
                        <?php foreach($display_remarks as $remark): ?>
                            <div class="award-item"><span class="award-icon">🏆</span> <?php echo nl2br(esc_html($remark)); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CAREER TOTALS -->
            <div class="flms-stats-grid primary">
                <div class="stat-box points"><span class="count"><?php echo $grand_total['points']; ?></span><span class="label">Total Pts</span></div>
                <div class="stat-box"><span class="count"><?php echo $grand_total['apps']; ?></span><span class="label">Matches</span></div>
                <div class="stat-box goal"><span class="count"><?php echo $grand_total['goals']; ?></span><span class="label">Goals</span></div>
                <div class="stat-box assist"><span class="count"><?php echo $grand_total['assists']; ?></span><span class="label">Assists</span></div>
            </div>
             <div class="flms-stats-grid secondary">
                <div class="stat-box clean"><span class="count"><?php echo $grand_total['cleans']; ?></span><span class="label">Clean Sheets</span></div>
                <div class="stat-box yellow"><span class="count"><?php echo $grand_total['yellow']; ?></span><span class="label">Yellow</span></div>
                <div class="stat-box red"><span class="count"><?php echo $grand_total['red']; ?></span><span class="label">Red</span></div>
            </div>

            <!-- BREAKDOWN TABLE -->
            <?php if(!empty($breakdown_rows)): ?>
            <div class="flms-section-box">
                <h3>Stats by Tournament</h3>
                <div class="flms-table-responsive dark-table-wrap">
                    <table class="flms-league-table dark-table">
                        <thead><tr><th style="text-align:left;">Competition</th><th style="text-align:left;">Team</th><th>Apps</th><th>Goals</th><th>Ast</th><th>Yel</th><th>Red</th></tr></thead>
                        <tbody>
                            <?php foreach($breakdown_rows as $row): ?>
                            <tr><td class="t-col"><?php echo esc_html($row['tournament']); ?></td><td class="team-col"><?php echo esc_html($row['team']); ?></td><td><?php echo $row['apps']; ?></td><td class="val-goal"><?php echo $row['goals']; ?></td><td class="val-ast"><?php echo $row['assists']; ?></td><td class="val-yel"><?php echo $row['yellow']; ?></td><td class="val-red"><?php echo $row['red']; ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- MATCH HISTORY -->
            <div class="flms-match-history">
                <h3>Recent Matches</h3>
                <?php echo $this->get_combined_history( $all_pids, $mh_page, get_permalink() ); ?>
            </div>
        </div>

        <style>
            /* CSS remains same as previous steps, ensured Dark Mode and Mobile grid */
            /* (Inline styles removed to rely on assets/css/flms-style.css as recommended) */
        </style>
        <?php
        
        $html = ob_get_clean();
        set_transient( $cache_key, $html, 12 * HOUR_IN_SECONDS );
        return $html;
    }

    private function get_combined_history( $all_pids, $page = 1, $base_url = '' ) {
        $per_page = 10;
        $page = max( 1, (int) $page );
        $team_ids = [];
        foreach($all_pids as $pid) {
            $tid = get_post_meta($pid, 'flms_team_id', true);
            if($tid) $team_ids[] = $tid;
        }
        $team_ids = array_unique($team_ids);
        if ( empty($team_ids) ) return '<p class="flms-mh-empty">No team assigned.</p>';

        $matches = get_posts([
            'post_type'      => 'flms_match',
            'posts_per_page' => 100,
            'meta_query'     => [
                'relation' => 'AND',
                'status'   => [ 'key' => 'flms_match_status', 'value' => 'completed' ],
                'teams'    => [
                    'relation' => 'OR',
                    [ 'key' => 'flms_home_team', 'value' => $team_ids, 'compare' => 'IN' ],
                    [ 'key' => 'flms_away_team', 'value' => $team_ids, 'compare' => 'IN' ]
                ]
            ],
            'meta_key'       => 'flms_match_date',
            'orderby'        => 'meta_value',
            'meta_type'      => 'DATE',
            'order'          => 'DESC'
        ]);

        if ( empty($matches) ) return '<p class="flms-mh-empty">No matches played.</p>';

        $all_pids_int = array_map( 'intval', $all_pids );
        $rows = [];
        $rows_no_lineup = [];
        foreach ( $matches as $m ) {
            $mid = $m->ID;
            $h_line = get_post_meta( $mid, '_flms_lineup_home', true );
            $a_line = get_post_meta( $mid, '_flms_lineup_away', true );
            $h_line = is_array( $h_line ) ? array_map( 'intval', $h_line ) : [];
            $a_line = is_array( $a_line ) ? array_map( 'intval', $a_line ) : [];
            $in_lineup = false;
            foreach ( $all_pids_int as $pid ) {
                if ( in_array( $pid, $h_line, true ) || in_array( $pid, $a_line, true ) ) {
                    $in_lineup = true;
                    break;
                }
            }
            $no_lineup = empty( $h_line ) && empty( $a_line );
            if ( ! $in_lineup && ! $no_lineup ) continue;

            $h_id = get_post_meta($mid, 'flms_home_team', true);
            $a_id = get_post_meta($mid, 'flms_away_team', true);
            $h_sc = get_post_meta($mid, 'flms_home_score', true);
            $a_sc = get_post_meta($mid, 'flms_away_score', true);
            $date = get_post_meta($mid, 'flms_match_date', true);
            $h_name = get_the_title($h_id);
            $a_name = get_the_title($a_id);
            $events = get_post_meta($mid, '_flms_match_events', true) ?: [];
            $p_goals = 0;
            foreach ( $events as $e ) {
                if ( isset( $e['player_id'] ) && in_array( (int) $e['player_id'], $all_pids_int, true ) && isset( $e['type'] ) && $e['type'] === 'goal' ) {
                    $p_goals++;
                }
            }
            $badge = $p_goals > 0 ? "<span class='p-goal-badge'>$p_goals ⚽</span>" : '';
            $row = "<li><div class=\"flms-mh-row\"><span class=\"flms-mh-date\">$date</span><span>$h_name <strong>$h_sc - $a_sc</strong> $a_name</span></div>$badge</li>";
            if ( $in_lineup ) {
                $rows[] = $row;
            } elseif ( $no_lineup ) {
                $rows_no_lineup[] = $row;
            }
        }
        $combined = array_merge( $rows, $rows_no_lineup );
        $total = count( $combined );
        $total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;
        $offset = ( $page - 1 ) * $per_page;
        $page_rows = array_slice( $combined, $offset, $per_page );

        $html = '<ul class="flms-mini-history">' . implode( '', $page_rows ) . '</ul>';

        if ( $total_pages > 1 && ! empty( $base_url ) ) {
            $sep = strpos( $base_url, '?' ) !== false ? '&' : '?';
            $html .= '<nav class="flms-mh-pagination" aria-label="Match history pagination">';
            $html .= '<span class="flms-mh-pagination-info">' . sprintf( __( 'Showing %1$d–%2$d of %3$d', 'flms' ), $offset + 1, min( $offset + $per_page, $total ), $total ) . '</span>';
            $html .= '<span class="flms-mh-pagination-links">';
            if ( $page > 1 ) {
                $prev_url = $page === 2 ? $base_url : $base_url . $sep . 'mhp=' . ( $page - 1 );
                $html .= '<a class="flms-mh-pag-prev" href="' . esc_url( $prev_url ) . '">' . esc_html__( 'Previous', 'flms' ) . '</a> ';
            }
            for ( $i = 1; $i <= $total_pages; $i++ ) {
                if ( $i === $page ) {
                    $html .= '<span class="flms-mh-pag-current">' . $i . '</span>';
                } else {
                    $link = $i === 1 ? $base_url : $base_url . $sep . 'mhp=' . $i;
                    $html .= ' <a class="flms-mh-pag-num" href="' . esc_url( $link ) . '">' . $i . '</a>';
                }
            }
            if ( $page < $total_pages ) {
                $next_url = $base_url . $sep . 'mhp=' . ( $page + 1 );
                $html .= ' <a class="flms-mh-pag-next" href="' . esc_url( $next_url ) . '">' . esc_html__( 'Next', 'flms' ) . '</a>';
            }
            $html .= '</span></nav>';
        }

        return $html;
    }
}