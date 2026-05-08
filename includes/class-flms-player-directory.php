<?php
if ( ! class_exists( 'FLMS_Player_Directory' ) ) {

class FLMS_Player_Directory {

    public function __construct() {
        add_shortcode( 'flms_player_directory', [ $this, 'render_directory' ] );
    }

    public function render_directory( $atts ) {
        // Default to 20 players per page
        $atts = shortcode_atts( [ 'per_page' => 20 ], $atts );
        
        $search_query = isset( $_GET['player_search'] ) ? sanitize_text_field( $_GET['player_search'] ) : '';
        $tour_filter  = isset( $_GET['tour_filter'] ) ? intval( $_GET['tour_filter'] ) : '';
        
        $paged = ( get_query_var( 'paged' ) ) ? absint( get_query_var( 'paged' ) ) : 1;
        if ( $paged < 1 ) { $paged = 1; }

        $list_cache_key = 'flms_dir_' . FLMS_Cache_Bump::version() . '_' . md5( $search_query . '|' . $tour_filter );
        $unique_players = get_transient( $list_cache_key );

        if ( false === $unique_players || ! is_array( $unique_players ) ) {
            $args = [
                'post_type'      => 'flms_player',
                'posts_per_page' => -1, 
                'post_status'    => 'publish',
                'orderby'        => 'title',
                'order'          => 'ASC'
            ];

            if ( ! empty( $search_query ) ) { $args['s'] = $search_query; }

            if ( ! empty( $tour_filter ) ) {
                $teams_in_tour = get_posts([
                    'post_type' => 'flms_team', 'posts_per_page' => -1, 'fields' => 'ids',
                    'meta_key' => 'flms_tournament_id', 'meta_value' => $tour_filter
                ]);

                if(empty($teams_in_tour)) {
                    $unique_players = []; 
                } else {
                    $args['meta_query'] = [ [ 'key' => 'flms_team_id', 'value' => $teams_in_tour, 'compare' => 'IN' ] ];
                }
            }

            if ( $unique_players === false ) {
                $query = new WP_Query( $args );
                $unique_players = [];

                if ( $query->have_posts() ) {
                    while ( $query->have_posts() ) {
                        $query->the_post();
                        $pid = get_the_ID();
                        
                        $all_meta = get_post_meta($pid);
                        $val = function($k) use ($all_meta) { return isset($all_meta[$k][0]) ? (int)$all_meta[$k][0] : 0; };
                        $str = function($k) use ($all_meta) { return isset($all_meta[$k][0]) ? $all_meta[$k][0] : ''; };

                        // DATA
                        $raw_ic = $str('flms_ic');
                        
                        // Clean for Grouping Key (Backend Only)
                        $clean_ic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $raw_ic)); 
                        
                        // If 0000 or empty, DO NOT MERGE. Give unique key.
                        if ( empty($clean_ic) || $clean_ic === '000000000000' ) {
                            $key = 'no_ic_'.$pid; 
                        } else {
                            $key = $clean_ic;
                        }

                        $stats = [
                            'apps' => $val('flms_total_apps'), 
                            'goals' => $val('flms_total_goals'),
                            'assists' => $val('flms_total_assists'), 
                            'cleans' => $val('flms_total_cleans'),
                            'yellow' => $val('flms_total_yellow'), 
                            'red' => $val('flms_total_red'),
                            'points' => $val('flms_ranking_points')
                        ];

                        $tid = $val('flms_team_id');
                        $team_name_display = 'Free Agent';
                        $pos = $str('flms_position') ?: '-';

                        if ( $tid ) {
                            $tour_id = get_post_meta($tid, 'flms_tournament_id', true);
                            if ( ! empty($tour_id) ) {
                                $team_name_display = get_the_title($tid);
                            }
                        }

                        $modified_ts = strtotime( (string) get_post_field( 'post_modified_gmt', $pid ) );
                        if ( ! $modified_ts ) {
                            $modified_ts = strtotime( (string) get_post_field( 'post_modified', $pid ) );
                        }
                        if ( ! $modified_ts ) {
                            $modified_ts = (int) $pid;
                        }

                        if ( isset( $unique_players[$key] ) ) {
                            // Merge Stats
                            foreach($stats as $k => $v) $unique_players[$key][$k] += $v;
                            
                            // Merge Teams
                            if ( $team_name_display !== 'Free Agent' ) {
                                $current_team_str = $unique_players[$key]['team'];
                                if ( $current_team_str === 'Free Agent' || $current_team_str === '' ) {
                                    $unique_players[$key]['team'] = $team_name_display;
                                } 
                                elseif ( strpos($current_team_str, $team_name_display) === false ) {
                                    $unique_players[$key]['team'] .= ' / ' . $team_name_display;
                                }
                            }

                            // Merge Positions
                            if ( $pos !== '-' && strpos($unique_players[$key]['pos'], $pos) === false ) {
                                if ( $unique_players[$key]['pos'] === '-' ) $unique_players[$key]['pos'] = $pos;
                                else $unique_players[$key]['pos'] .= ' / ' . $pos;
                            }
                            
                            // Keep identity fields from the most recently updated record.
                            if ( $modified_ts > (int) $unique_players[$key]['modified_ts'] ) {
                                $unique_players[$key]['name'] = get_the_title();
                                $unique_players[$key]['id'] = $pid;
                                $unique_players[$key]['num'] = $str('flms_number') ?: '-';
                                $unique_players[$key]['modified_ts'] = $modified_ts;
                            }
                        } else {
                            $unique_players[$key] = array_merge($stats, [
                                'id'   => $pid, 
                                'name' => get_the_title(),
                                'team' => $team_name_display,
                                'num'  => $str('flms_number') ?: '-',
                                'pos'  => $pos,
                                'modified_ts' => $modified_ts
                            ]);
                        }
                    }
                }
                wp_reset_postdata();

                uasort($unique_players, function($a, $b) { return $b['points'] <=> $a['points']; });
            }
            set_transient( $list_cache_key, $unique_players, 6 * HOUR_IN_SECONDS );
        }

        // Pagination
        $total_items  = is_array($unique_players) ? count($unique_players) : 0;
        $per_page     = max(1, intval($atts['per_page']));
        $total_pages  = $per_page > 0 ? (int) ceil($total_items / $per_page) : 0;
        $offset = ($paged - 1) * $per_page;
        $paged_players = array_slice($unique_players, $offset, $per_page);

        ob_start();
        ?>
        <div class="flms-dashboard-wrapper flms-player-directory">
            <h2 class="flms-section-title">Player Directory</h2>
            <?php echo $this->render_search_form($search_query, $tour_filter); ?>

        <?php if ( empty($paged_players) ) : ?>
            <p class="no-results">No players found.</p>
        <?php else : ?>
            <div class="flms-table-responsive">
                <table class="flms-league-table flms-player-table">
                    <thead>
                        <tr>
                            <th width="50"></th>
                            <th>Name</th>
                            <!-- IC Hidden for PDPA -->
                            <th>Teams</th>
                            <th>Pos</th>
                            <th title="Appearances">Apps</th>
                            <th title="Goals">Goals</th>
                            <th title="Assists">Ast</th>
                            <th title="Clean Sheets">CS</th>
                            <th title="Yellow Cards">YC</th>
                            <th title="Red Cards">RC</th>
                            <th title="Total Ranking Points">PTS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $paged_players as $p ) : 
                            $photo = class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_player_photo($p['id'], 'thumbnail') : '';
                            $link  = get_permalink( $p['id'] );
                            
                            // --- NEW MEDAL LOGIC (UPDATED) ---
                            $pts = intval($p['points']);
                            $medal = '';
                            
                            if ( $pts >= 12000 ) {
                                $medal = '🏅'; // 12k+
                            } elseif ( $pts >= 6000 ) {
                                $medal = '🥇'; // 6k+
                            } elseif ( $pts >= 3000 ) {
                                $medal = '🥈'; // 3k+
                            } elseif ( $pts >= 888 ) {
                                $medal = '🥉'; // 888+
                            } elseif ( $pts >= 150 ) {
                                $medal = '🏵️'; // 150+
                            }
                            // ---------------------------------
                        ?>
                        <tr>
                            <td><a href="<?php echo $link; ?>"><img src="<?php echo esc_url($photo); ?>" class="dir-avatar"></a></td>
                            <td class="player-name"><a href="<?php echo $link; ?>"><?php echo esc_html($p['name']); ?></a></td>
                            
                            <td class="player-team" style="font-size:11px; white-space:normal; max-width:150px; line-height:1.2;"><?php echo esc_html($p['team']); ?></td>
                            <td><span class="pos-badge"><?php echo esc_html( $p['pos'] ); ?></span></td>
                            
                            <td style="font-weight:bold; color:#555;"><?php echo $p['apps']; ?></td>
                            <td class="stat-goal"><?php echo $p['goals']; ?></td>
                            <td style="font-weight:bold; color:#0d47a1;"><?php echo $p['assists']; ?></td>
                            <td style="font-weight:bold; color:#673ab7;"><?php echo $p['cleans']; ?></td>
                            <td class="stat-yellow"><?php echo $p['yellow']; ?></td>
                            <td class="stat-red"><?php echo $p['red']; ?></td>
                            
                            <td class="stat-points" style="font-weight:900; color:#37003c; background:#f9f9f9; white-space: nowrap;">
                                <?php echo $pts; ?> 
                                <?php if($medal): ?><span style="font-size:14px; margin-left:2px;" title="Achievement Unlocked"><?php echo $medal; ?></span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="flms-pagination">
                <?php 
                echo paginate_links([
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $paged,
                    'total'   => $total_pages,
                    'prev_text' => '&laquo; Prev',
                    'next_text' => 'Next &raquo;',
                    'mid_size'  => 2,
                    'type'      => 'list'
                ]);
                ?>
            </div>
            <?php endif; ?>

        <?php endif; ?>
        </div><?php
        return ob_get_clean();
    }

    private function render_search_form($search, $tour_id) {
        $excluded_ids = [ 16022, 19182 ]; 
        $tournaments = get_posts([ 'post_type' => 'product', 'posts_per_page' => -1, 'meta_key' => '_flms_start_date', 'post__not_in' => $excluded_ids, 'orderby' => 'title', 'order' => 'ASC' ]);
        ob_start();
        ?>
        <div class="flms-directory-wrapper">
            <form method="get" action="<?php echo get_permalink(); ?>" class="flms-dir-search-form">
                <input type="text" name="player_search" value="<?php echo esc_attr($search); ?>" placeholder="Search player name..." class="search-input">
                <select name="tour_filter" class="search-select"><option value="">All Tournaments (Career Stats)</option><?php foreach($tournaments as $t): ?><option value="<?php echo $t->ID; ?>" <?php selected($tour_id, $t->ID); ?>><?php echo esc_html($t->post_title); ?></option><?php endforeach; ?></select>
                <button type="submit" class="search-btn">Filter</button>
                <?php if($search || $tour_id): ?><a href="<?php echo get_permalink(); ?>" class="reset-link">Reset</a><?php endif; ?>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}

}