<?php
class FLMS_Standings {
    public function __construct() {
        // Run calculation whenever a Match is saved/updated
        add_action( 'save_post_flms_match', [ $this, 'trigger_calculation' ], 20, 3 );
    }

    public function trigger_calculation( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( 'flms_match' !== $post->post_type ) return;
        
        // --- FIX: REMOVED THE "IF COMPLETED" CHECK ---
        // We want to recalculate stats even if a match changes back to 'pending',
        // so that the points are removed from the team's total.

        // Get the Teams & Tournament
        $home_team_id = get_post_meta( $post_id, 'flms_home_team', true );
        $away_team_id = get_post_meta( $post_id, 'flms_away_team', true );
        $tournament_id = get_post_meta( $post_id, 'flms_tournament_id', true );

        // Recalculate Stats for BOTH teams involved
        if ( $home_team_id ) $this->calculate_team_stats( $home_team_id, $tournament_id );
        if ( $away_team_id ) $this->calculate_team_stats( $away_team_id, $tournament_id );
    }

    private function calculate_team_stats( $team_id, $tournament_id ) {
        // Initialize Stats to 0
        $stats = [
            'played' => 0,
            'won'    => 0,
            'drawn'  => 0,
            'lost'   => 0,
            'gf'     => 0, // Goals For
            'ga'     => 0, // Goals Against
            'gd'     => 0, // Goal Difference
            'points' => 0
        ];

        $form_guide = []; // W-D-L History

        // Query ONLY matches that are currently marked 'completed'
        $args = [
            'post_type'  => 'flms_match',
            'posts_per_page' => -1,
            'meta_key'   => 'flms_match_date',
            'orderby'    => 'meta_value',
            'order'      => 'ASC',
            'meta_query' => [
                'relation' => 'AND',
                [ 'key' => 'flms_tournament_id', 'value' => $tournament_id ],
                [ 'key' => 'flms_match_status', 'value' => 'completed' ], // STRICTLY COMPLETED
                [ 
                    'relation' => 'OR',
                    [ 'key' => 'flms_home_team', 'value' => $team_id ],
                    [ 'key' => 'flms_away_team', 'value' => $team_id ]
                ]
            ]
        ];

        $matches = get_posts( $args );

        foreach ( $matches as $match ) {
            $mid = $match->ID;
            $h_id = get_post_meta( $mid, 'flms_home_team', true );
            
            $h_score = (int) get_post_meta( $mid, 'flms_home_score', true );
            $a_score = (int) get_post_meta( $mid, 'flms_away_score', true );

            // Determine if we are Home or Away
            $is_home = ( $h_id == $team_id );

            $my_score = $is_home ? $h_score : $a_score;
            $op_score = $is_home ? $a_score : $h_score;

            // Update Basic Stats
            $stats['played']++;
            $stats['gf'] += $my_score;
            $stats['ga'] += $op_score;

            // Determine W/D/L
            if ( $my_score > $op_score ) {
                $stats['won']++;
                $stats['points'] += 3;
                $form_guide[] = 'W';
            } elseif ( $my_score == $op_score ) {
                $stats['drawn']++;
                $stats['points'] += 1;
                $form_guide[] = 'D';
            } else {
                $stats['lost']++;
                $form_guide[] = 'L';
            }
        }

        // Calculate Goal Difference
        $stats['gd'] = $stats['gf'] - $stats['ga'];

        // Get last 5 form results
        $last_5_form = array_slice( $form_guide, -5 );

        // SAVE to Team Post Meta
        update_post_meta( $team_id, 'flms_stats_played', $stats['played'] );
        update_post_meta( $team_id, 'flms_stats_won', $stats['won'] );
        update_post_meta( $team_id, 'flms_stats_drawn', $stats['drawn'] );
        update_post_meta( $team_id, 'flms_stats_lost', $stats['lost'] );
        update_post_meta( $team_id, 'flms_stats_gf', $stats['gf'] );
        update_post_meta( $team_id, 'flms_stats_ga', $stats['ga'] );
        update_post_meta( $team_id, 'flms_stats_gd', $stats['gd'] );
        update_post_meta( $team_id, 'flms_stats_points', $stats['points'] );
        update_post_meta( $team_id, 'flms_stats_form', $last_5_form );
    }
}