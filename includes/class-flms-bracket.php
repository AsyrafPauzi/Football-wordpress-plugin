<?php
class FLMS_Bracket {
    public function __construct() {
        add_shortcode('flms_bracket', [$this, 'render_bracket']);
    }

    public function render_bracket($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $tid = intval($atts['id']);
        
        // 1. Error Check: No ID
        if( $tid === 0 ) {
            return '<p style="color:red; font-weight:bold;">Error: You must provide a Tournament ID. Example: [flms_bracket id="123"]</p>';
        }

        // 2. Query Matches — only knockout phase for group_knockout tournaments
        $format = get_post_meta( $tid, '_flms_format', true );

        $meta_query = [
            [ 'key' => 'flms_tournament_id', 'value' => $tid ]
        ];

        // For group+knockout format, only show matches tagged as knockout phase
        if ( $format === 'group_knockout' ) {
            $meta_query[] = [ 'key' => 'flms_match_phase', 'value' => 'knockout' ];
        }

        $matches = get_posts([
            'post_type'      => 'flms_match',
            'posts_per_page' => -1,
            'meta_key'       => 'flms_round',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
            'meta_query'     => $meta_query,
        ]);

        // 3. Error Check: No Matches
        if ( empty($matches) ) {
            return '<p>No matches found for Tournament ID ' . $tid . '. Please generate matches first.</p>';
        }

        // 4. Group by Round — but pull the Third-Place Play-off match aside so it can be rendered as its own block.
        $rounds                = [];
        $third_place_matches   = [];
        foreach($matches as $m) {
            if ( get_post_meta( $m->ID, 'flms_is_third_place_match', true ) ) {
                $third_place_matches[] = $m;
                continue;
            }
            $r = get_post_meta($m->ID, 'flms_round', true);
            $rounds[$r][] = $m;
        }

        // Sort rounds to ensure 1, 2, 3 order
        ksort($rounds);

        /**
         * Label knockout rounds by their position from the FINAL backwards.
         * Last round = Final, second-to-last = Semi-Final, etc.
         * This is more reliable than the raw `flms_round` number because in
         * Group Stage + Knockout tournaments, the knockout rounds continue from
         * the group rounds (e.g. groups use rounds 1-5, knockouts are 6-8).
         */
        $round_keys      = array_keys( $rounds );
        $total_ko_rounds = count( $round_keys );
        $ko_label_map    = [
            0 => 'Final',
            1 => 'Semi-Final',
            2 => 'Quarter-Final',
            3 => 'Round of 16',
            4 => 'Round of 32',
            5 => 'Round of 64',
        ];

        ob_start();
        ?>
        <div class="flms-bracket-wrapper">
            <div class="flms-bracket-container">
                <?php foreach($rounds as $r_num => $games):
                    $idx              = array_search( $r_num, $round_keys, true );
                    $rounds_from_end  = $total_ko_rounds - $idx - 1;
                    $round_label      = $ko_label_map[ $rounds_from_end ] ?? ( 'Round ' . $r_num );
                ?>
                    <div class="flms-bracket-round round-<?php echo $r_num; ?>">
                        <h4 class="round-header"><?php echo esc_html( $round_label ); ?></h4>
                        <div class="flms-bracket-matches">
                        <?php foreach($games as $game): 
                            $h_id = get_post_meta($game->ID, 'flms_home_team', true);
                            $a_id = get_post_meta($game->ID, 'flms_away_team', true);
                            
                            // Handle TBD / BYE
                            $h_name = $h_id ? get_the_title($h_id) : 'TBD';
                            $a_name = $a_id ? get_the_title($a_id) : 'TBD';
                            if(!$h_id) $h_name = 'BYE';
                            if(!$a_id) $a_name = 'BYE';

                            $h_score = get_post_meta($game->ID, 'flms_home_score', true);
                            $a_score = get_post_meta($game->ID, 'flms_away_score', true);
                            $status = get_post_meta($game->ID, 'flms_match_status', true);
                            
                            $is_completed = ($status === 'completed');
                        ?>
                            <div class="bracket-match">
                                <!-- Home Team -->
                                <div class="bm-team <?php echo ($is_completed && $h_score > $a_score) ? 'winner' : ''; ?>">
                                    <span class="bm-name"><?php echo esc_html($h_name); ?></span>
                                    <span class="bm-score"><?php echo $is_completed ? $h_score : ''; ?></span>
                                </div>
                                <!-- Away Team -->
                                <div class="bm-team <?php echo ($is_completed && $a_score > $h_score) ? 'winner' : ''; ?>">
                                    <span class="bm-name"><?php echo esc_html($a_name); ?></span>
                                    <span class="bm-score"><?php echo $is_completed ? $a_score : ''; ?></span>
                                </div>
                                <?php if(!$is_completed): ?>
                                    <div class="bm-info">vs</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $third_place_matches ) ) : ?>
                <div class="flms-third-place-section">
                    <h4 class="round-header third-place-header">Third-Place Play-off</h4>
                    <div class="flms-third-place-matches">
                        <?php foreach ( $third_place_matches as $game ) :
                            $h_id    = get_post_meta( $game->ID, 'flms_home_team', true );
                            $a_id    = get_post_meta( $game->ID, 'flms_away_team', true );
                            $h_name  = $h_id ? get_the_title( $h_id ) : 'TBD';
                            $a_name  = $a_id ? get_the_title( $a_id ) : 'TBD';
                            if ( ! $h_id ) $h_name = 'TBD';
                            if ( ! $a_id ) $a_name = 'TBD';

                            $h_score      = get_post_meta( $game->ID, 'flms_home_score', true );
                            $a_score      = get_post_meta( $game->ID, 'flms_away_score', true );
                            $status       = get_post_meta( $game->ID, 'flms_match_status', true );
                            $is_completed = ( $status === 'completed' );
                        ?>
                            <div class="bracket-match third-place-match">
                                <div class="bm-team <?php echo ($is_completed && $h_score > $a_score) ? 'winner' : ''; ?>">
                                    <span class="bm-name"><?php echo esc_html( $h_name ); ?></span>
                                    <span class="bm-score"><?php echo $is_completed ? esc_html( $h_score ) : ''; ?></span>
                                </div>
                                <div class="bm-team <?php echo ($is_completed && $a_score > $h_score) ? 'winner' : ''; ?>">
                                    <span class="bm-name"><?php echo esc_html( $a_name ); ?></span>
                                    <span class="bm-score"><?php echo $is_completed ? esc_html( $a_score ) : ''; ?></span>
                                </div>
                                <?php if ( ! $is_completed ) : ?>
                                    <div class="bm-info">vs</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
            .flms-bracket-wrapper { overflow-x: auto; padding-bottom: 20px; }
            .flms-bracket-container { display: flex; gap: 50px; padding: 20px 10px; min-width: 600px; }
            
            .flms-bracket-round { 
                min-width: 220px; 
                display: flex; 
                flex-direction: column; 
            }
            
            .round-header { 
                text-align: center; 
                background: #37003c; 
                color: #fff; 
                padding: 8px; 
                border-radius: 4px; 
                margin-bottom: 20px;
            }

            .flms-bracket-matches { 
                display: flex; 
                flex-direction: column; 
                justify-content: space-around; 
                flex-grow: 1; 
                gap: 40px; 
            }

            .bracket-match { 
                border: 1px solid #ccc; 
                background: #fff; 
                border-radius: 6px; 
                overflow: hidden; 
                box-shadow: 0 2px 5px rgba(0,0,0,0.1); 
                position: relative;
                font-size: 14px;
            }

            .bm-team { 
                display: flex; 
                justify-content: space-between; 
                padding: 10px 12px; 
                border-bottom: 1px solid #eee; 
            }
            .bm-team:last-child { border-bottom: none; }
            
            .bm-team.winner { 
                background: #d4edda; 
                color: #155724; 
                font-weight: bold; 
            }

            .bm-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; }
            .bm-score { font-weight: bold; background: #eee; padding: 0 6px; border-radius: 4px; min-width: 20px; text-align: center; }
            
            .bm-info { 
                position: absolute; 
                right: 10px; 
                top: 50%; 
                transform: translateY(-50%); 
                font-size: 10px; 
                color: #999; 
                opacity: 0.5;
            }

            .flms-third-place-section {
                margin-top: 30px;
                padding: 20px 10px;
                border-top: 1px dashed #c9b066;
                max-width: 320px;
            }
            .third-place-header {
                background: #b8860b !important;
                color: #fff;
            }
            .flms-third-place-matches .bracket-match.third-place-match {
                border-color: #c9b066;
                box-shadow: 0 2px 6px rgba(184, 134, 11, 0.15);
            }
        </style>
        <?php
        return ob_get_clean();
    }
}