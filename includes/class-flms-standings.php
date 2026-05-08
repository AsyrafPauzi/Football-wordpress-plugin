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

        $team_ids = array_unique( array_filter( array_map( 'intval', [ $home_team_id, $away_team_id ] ) ) );
        if ( empty( $team_ids ) || ! $tournament_id ) {
            return;
        }

        $match_ids = get_posts( [
            'post_type'      => 'flms_match',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => 'flms_match_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => 'flms_tournament_id', 'value' => $tournament_id ],
                [ 'key' => 'flms_match_status', 'value' => 'completed' ],
                [
                    'relation' => 'OR',
                    [ 'key' => 'flms_home_team', 'value' => $team_ids, 'compare' => 'IN' ],
                    [ 'key' => 'flms_away_team', 'value' => $team_ids, 'compare' => 'IN' ],
                ],
            ],
        ] );

        update_meta_cache( 'post', $match_ids );

        foreach ( $team_ids as $tid ) {
            $this->calculate_team_stats_from_matches( $tid, $match_ids );
        }
    }

    /**
     * @param int   $team_id
     * @param int[] $match_ids Completed tournament matches (may include other teams).
     */
    private function calculate_team_stats_from_matches( $team_id, $match_ids ) {
        $stats = [
            'played' => 0,
            'won'    => 0,
            'drawn'  => 0,
            'lost'   => 0,
            'gf'     => 0,
            'ga'     => 0,
            'gd'     => 0,
            'points' => 0,
        ];

        $form_guide = [];
        $team_id    = (int) $team_id;

        foreach ( $match_ids as $mid ) {
            $h_id = (int) get_post_meta( $mid, 'flms_home_team', true );
            $a_id = (int) get_post_meta( $mid, 'flms_away_team', true );
            if ( $h_id !== $team_id && $a_id !== $team_id ) {
                continue;
            }

            $h_score = (int) get_post_meta( $mid, 'flms_home_score', true );
            $a_score = (int) get_post_meta( $mid, 'flms_away_score', true );

            $is_home   = ( $h_id === $team_id );
            $my_score  = $is_home ? $h_score : $a_score;
            $op_score  = $is_home ? $a_score : $h_score;

            $stats['played']++;
            $stats['gf'] += $my_score;
            $stats['ga'] += $op_score;

            if ( $my_score > $op_score ) {
                $stats['won']++;
                $stats['points'] += 3;
                $form_guide[] = 'W';
            } elseif ( $my_score === $op_score ) {
                $stats['drawn']++;
                $stats['points'] += 1;
                $form_guide[] = 'D';
            } else {
                $stats['lost']++;
                $form_guide[] = 'L';
            }
        }

        $stats['gd']       = $stats['gf'] - $stats['ga'];
        $last_5_form       = array_slice( $form_guide, -5 );

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