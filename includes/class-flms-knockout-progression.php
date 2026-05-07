<?php
class FLMS_Knockout_Progression {
    public function __construct() {
        // Priority 100 ensures this runs LAST
        add_action( 'save_post_flms_match', [ $this, 'check_progression' ], 100, 3 );
    }

    public function check_progression( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        
        // 1. Is the current match finished?
        $status = get_post_meta( $post_id, 'flms_match_status', true );
        if ( $status !== 'completed' ) return;

        // 2. Determine Winner
        $home_score = (int) get_post_meta( $post_id, 'flms_home_score', true );
        $away_score = (int) get_post_meta( $post_id, 'flms_away_score', true );
        $home_team  = get_post_meta( $post_id, 'flms_home_team', true );
        $away_team  = get_post_meta( $post_id, 'flms_away_team', true );

        $winner_id = 0;
        if ( $home_score > $away_score ) {
            $winner_id = $home_team;
        } elseif ( $away_score > $home_score ) {
            $winner_id = $away_team;
        } else {
            return; // Draw
        }

        if ( ! $winner_id ) return;

        // 3. Find the NEXT Match
        $next_match = get_posts([
            'post_type'  => 'flms_match',
            'meta_query' => [
                'relation' => 'OR',
                [ 'key' => 'flms_source_match_home', 'value' => $post_id ],
                [ 'key' => 'flms_source_match_away', 'value' => $post_id ]
            ],
            'posts_per_page' => 1,
            'post_status' => 'any'
        ]);

        if ( empty( $next_match ) ) return; 

        $next_mid = $next_match[0]->ID;

        // 4. Update the Next Match Team
        $source_home = get_post_meta( $next_mid, 'flms_source_match_home', true );

        if ( $source_home == $post_id ) {
            update_post_meta( $next_mid, 'flms_home_team', $winner_id );
        } else {
            update_post_meta( $next_mid, 'flms_away_team', $winner_id );
        }

        // 5. NUCLEAR CLEANUP: Delete any ghost data from Next Match
        delete_post_meta( $next_mid, 'flms_home_score' );
        delete_post_meta( $next_mid, 'flms_away_score' );
        delete_post_meta( $next_mid, '_flms_match_events' ); // Remove goals/cards history
        
        // Force status back to pending
        update_post_meta( $next_mid, 'flms_match_status', 'pending' );

        // 6. Update Title
        $new_home = get_post_meta( $next_mid, 'flms_home_team', true );
        $new_away = get_post_meta( $next_mid, 'flms_away_team', true );
        
        $h_name = $new_home ? get_the_title($new_home) : 'TBD';
        $a_name = $new_away ? get_the_title($new_away) : 'TBD';

        wp_update_post([
            'ID' => $next_mid,
            'post_title' => "$h_name vs $a_name"
        ]);
    }
}