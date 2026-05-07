<?php
class FLMS_Match_List {

    public function __construct() {
        add_shortcode( 'flms_matches', [ $this, 'render_matches' ] );
        add_shortcode( 'flms_homepage_widget', [ $this, 'render_homepage_widget' ] );
        
        // AJAX Handler for Round Navigation
        add_action( 'wp_ajax_flms_get_matchweek', [ $this, 'ajax_get_matchweek' ] );
        add_action( 'wp_ajax_nopriv_flms_get_matchweek', [ $this, 'ajax_get_matchweek' ] );
    }

    // --- HOMEPAGE WIDGET ---
    public function render_homepage_widget( $atts ) {
        $atts = shortcode_atts( [ 'id' => 0, 'limit' => 4 ], $atts );
        $tid = intval( $atts['id'] );
        if ( $tid === 0 ) return '';

        // Force homepage sorting
        return $this->render_matches( [ 'id' => $tid, 'show_round' => 'false', 'limit' => $atts['limit'], 'sort_homepage' => 'true' ] ); 
    }

    // --- MAIN RENDER FUNCTION ---
    public function render_matches( $atts ) {
        $atts = shortcode_atts( [ 'id' => 0, 'limit' => -1, 'sort_homepage' => 'false', 'show_round' => 'true' ], $atts );
        $tid = intval( $atts['id'] );
        
        if ( $tid === 0 ) return '';

        // Base Query Args
        $args = [
            'post_type'      => 'flms_match',
            'posts_per_page' => intval($atts['limit']),
            'post_status'    => 'publish',
            'meta_key'       => 'flms_match_date', 
            'orderby'        => 'meta_value',
            'meta_query'     => [ [ 'key' => 'flms_tournament_id', 'value' => $tid ] ]
        ];

        // --- HOMEPAGE SMART SORTING ---
        if ( $atts['sort_homepage'] === 'true' ) {
            $today = current_time('Y-m-d');
            
            // 1. Try to get UPCOMING matches (Nearest Date First -> ASC)
            $args_upcoming = $args;
            $args_upcoming['order'] = 'ASC'; // Closest date at top
            $args_upcoming['meta_query'][] = [
                'key'     => 'flms_match_date',
                'value'   => $today,
                'compare' => '>=', // Future or Today
                'type'    => 'DATE'
            ];

            $matches = get_posts( $args_upcoming );

            // 2. If no upcoming matches, show RECENT RESULTS (Latest Date First -> DESC)
            if ( empty( $matches ) ) {
                $args_past = $args;
                $args_past['order'] = 'DESC'; // Newest result at top
                $args_past['meta_query'][] = [
                    'key'     => 'flms_match_date',
                    'value'   => $today,
                    'compare' => '<', // Past
                    'type'    => 'DATE'
                ];
                $matches = get_posts( $args_past );
            }

            return $this->render_match_loop( $matches, false ); 
        }

        // --- TOURNAMENT HUB (AJAX Container) ---
        wp_enqueue_script('jquery');
        ob_start();
        ?>
        <div class="flms-ajax-match-wrapper" id="flms-matches-<?php echo $tid; ?>" data-tid="<?php echo $tid; ?>">
            <div class="flms-match-content" style="min-height:200px; position:relative;">
                <div class="flms-loader" style="text-align:center; padding:40px; color:#999;">Loading Fixtures...</div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            var wrapper = $('#flms-matches-<?php echo $tid; ?>');
            var content = wrapper.find('.flms-match-content');
            var ajaxUrl = '<?php echo admin_url( "admin-ajax.php" ); ?>';

            // Initial Load
            loadRound(0);

            // Navigation Click
            wrapper.on('click', '.nav-arrow', function(e){
                e.preventDefault();
                if($(this).hasClass('disabled')) return;
                var week = $(this).data('week');
                loadRound(week);
            });

            function loadRound(week) {
                content.css('opacity', '0.5');
                $.post(ajaxUrl, {
                    action: 'flms_get_matchweek',
                    tournament_id: <?php echo $tid; ?>,
                    round: week
                }, function(response) {
                    content.html(response).css('opacity', '1');
                });
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }

    // --- AJAX HANDLER ---
    public function ajax_get_matchweek() {
        $tid = intval($_POST['tournament_id']);
        $round_req = intval($_POST['round']);
        echo $this->get_match_list_html_ajax($tid, $round_req);
        wp_die();
    }

    // --- HELPER: RENDER LIST HTML ---
    private function render_match_loop( $matches, $show_round_title = false ) {
        if ( empty( $matches ) ) return '<div style="padding:20px; text-align:center; color:#999;">No matches found.</div>';
        
        ob_start();
        echo '<ul class="flms-fixtures-list">';
        
        $visible_count = 0;

        foreach ( $matches as $game ) {
            $date_raw = get_post_meta( $game->ID, 'flms_match_date', true );

            // --- FIX: HIDE IF NO DATE SET ---
            if ( empty($date_raw) ) continue; 

            $visible_count++;

            $home_id = get_post_meta( $game->ID, 'flms_home_team', true );
            $away_id = get_post_meta( $game->ID, 'flms_away_team', true );
            if ( ! $home_id || ! $away_id ) continue;

            $home_name = get_the_title( $home_id );
            $away_name = get_the_title( $away_id );
            $h_logo = class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_team_logo($home_id) : '';
            $a_logo = class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_team_logo($away_id) : '';
            $h_score = get_post_meta( $game->ID, 'flms_home_score', true );
            $a_score = get_post_meta( $game->ID, 'flms_away_score', true );
            $status = get_post_meta( $game->ID, 'flms_match_status', true );
            
            $time_raw = get_post_meta( $game->ID, 'flms_match_time', true );
            $date_display = $date_raw ? date( 'd M', strtotime($date_raw) ) : '-';
            $time_display = $time_raw ? date( 'H:i', strtotime($time_raw) ) : ''; 
            $venue_display = '';
            $venue_terms = get_the_terms( $game->ID, 'flms_venue' );
            if ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) {
                $venue_display = $venue_terms[0]->name;
            }
            $link = get_permalink( $game->ID );

            if ( $status === 'completed' ) {
                $center = "<span class='score'>$h_score - $a_score</span>";
                $row_class = 'completed';
            } else {
                $center = "<span class='vs-badge'>VS</span>";
                $row_class = 'pending';
            }
            ?>
            <li class="flms-fixture-row <?php echo $row_class; ?>">
                <a href="<?php echo esc_url($link); ?>" class="flms-match-link">
                    <div class="match-meta">
                        <span class="m-date"><?php echo $date_display; ?></span>
                        <span class="m-time"><?php echo $time_display; ?></span>
                        <?php if ( $venue_display ) : ?>
                            <span class="m-venue"><?php echo esc_html( $venue_display ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="match-teams">
                        <div class="team home">
                            <span class="t-name"><?php echo esc_html($home_name); ?></span>
                            <img src="<?php echo esc_url($h_logo); ?>" class="t-logo">
                        </div>
                        <?php echo $center; ?>
                        <div class="team away">
                            <img src="<?php echo esc_url($a_logo); ?>" class="t-logo">
                            <span class="t-name"><?php echo esc_html($away_name); ?></span>
                        </div>
                    </div>
                </a>
            </li>
            <?php
        }
        echo '</ul>';
        
        if ( $visible_count === 0 ) {
             echo '<div style="padding:20px; text-align:center; color:#999;">Matches are scheduled but dates are not announced yet.</div>';
        }

        return ob_get_clean();
    }

    // --- HELPER: AJAX ROUND LOGIC ---
    private function get_match_list_html_ajax( $tid, $round_req = 0 ) {
        // Get all matches for this tournament
        $matches = get_posts([
            'post_type' => 'flms_match', 'posts_per_page' => -1, 'post_status' => 'publish',
            'meta_key' => 'flms_match_date', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [ [ 'key' => 'flms_tournament_id', 'value' => $tid ] ]
        ]);

        if ( empty( $matches ) ) return '<div style="padding:30px; text-align:center; color:#999;">No matches found.</div>';

        // Group by Round
        $rounds = [];
        foreach ( $matches as $m ) {
            $r = (int) get_post_meta( $m->ID, 'flms_round', true );
            $rounds[$r][] = $m;
        }
        ksort($rounds);
        $round_keys = array_keys($rounds);

        // Determine Active Round
        $active_round = $round_req;
        if ( $active_round === 0 ) {
            $active_round = end($round_keys); // Default last
            foreach ($rounds as $r_num => $games) {
                foreach($games as $g) {
                    if ( get_post_meta($g->ID, 'flms_match_status', true) !== 'completed' ) {
                        $active_round = $r_num;
                        break 2;
                    }
                }
            }
        }

        // Navigation Links
        $current_index = array_search($active_round, $round_keys);
        $prev_round = ($current_index > 0) ? $round_keys[$current_index - 1] : 0;
        $next_round = ($current_index < count($round_keys) - 1) ? $round_keys[$current_index + 1] : 0;

        ob_start();
        ?>
        <div class="flms-match-list">
            <div class="flms-round-nav">
                <div class="nav-item left">
                    <?php if($prev_round): ?><a href="#" class="nav-arrow" data-week="<?php echo $prev_round; ?>">&#10094;</a><?php else: ?><span class="nav-arrow disabled">&#10094;</span><?php endif; ?>
                </div>
                <div class="nav-item center">
                    <span class="round-label">Matchweek <?php echo $active_round; ?></span>
                </div>
                <div class="nav-item right">
                    <?php if($next_round): ?><a href="#" class="nav-arrow" data-week="<?php echo $next_round; ?>">&#10095;</a><?php else: ?><span class="nav-arrow disabled">&#10095;</span><?php endif; ?>
                </div>
            </div>
            <div class="flms-fixtures">
                <?php echo $this->render_match_loop($rounds[$active_round]); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}