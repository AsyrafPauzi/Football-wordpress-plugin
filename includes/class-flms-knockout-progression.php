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

        // 2. Determine Winner AND Loser
        $home_score = (int) get_post_meta( $post_id, 'flms_home_score', true );
        $away_score = (int) get_post_meta( $post_id, 'flms_away_score', true );
        $home_team  = get_post_meta( $post_id, 'flms_home_team', true );
        $away_team  = get_post_meta( $post_id, 'flms_away_team', true );

        $winner_id = 0;
        $loser_id  = 0;
        if ( $home_score > $away_score ) {
            $winner_id = $home_team;
            $loser_id  = $away_team;
        } elseif ( $away_score > $home_score ) {
            $winner_id = $away_team;
            $loser_id  = $home_team;
        } else {
            return; // Draw — nothing to progress
        }

        if ( ! $winner_id ) return;

        // 3. Progress the WINNER into the next round's match (e.g. SF -> Final).
        $this->progress_team_into_match( $post_id, $winner_id, 'flms_source_match_home', 'flms_source_match_away' );

        // 4. Progress the LOSER into a third-place play-off, if one references this match.
        if ( $loser_id ) {
            $this->progress_team_into_match( $post_id, $loser_id, 'flms_source_match_home_loser', 'flms_source_match_away_loser' );
        }
    }

    /**
     * Generic helper: find a match referencing $source_match_id via the given meta keys
     * and place the supplied $team_id into its home or away slot.
     */
    private function progress_team_into_match( $source_match_id, $team_id, $home_source_key, $away_source_key ) {
        $next_match = get_posts([
            'post_type'      => 'flms_match',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'meta_query'     => [
                'relation' => 'OR',
                [ 'key' => $home_source_key, 'value' => $source_match_id ],
                [ 'key' => $away_source_key, 'value' => $source_match_id ],
            ],
        ]);

        if ( empty( $next_match ) ) return;

        $next_mid    = $next_match[0]->ID;
        $source_home = get_post_meta( $next_mid, $home_source_key, true );

        if ( (int) $source_home === (int) $source_match_id ) {
            update_post_meta( $next_mid, 'flms_home_team', $team_id );
        } else {
            update_post_meta( $next_mid, 'flms_away_team', $team_id );
        }

        // Nuclear cleanup: clear any stale scoring data so the next match restarts clean.
        delete_post_meta( $next_mid, 'flms_home_score' );
        delete_post_meta( $next_mid, 'flms_away_score' );
        delete_post_meta( $next_mid, '_flms_match_events' );
        update_post_meta( $next_mid, 'flms_match_status', 'pending' );

        // Update title to reflect the new teams.
        $new_home = get_post_meta( $next_mid, 'flms_home_team', true );
        $new_away = get_post_meta( $next_mid, 'flms_away_team', true );
        $h_name   = $new_home ? get_the_title( $new_home ) : 'TBD';
        $a_name   = $new_away ? get_the_title( $new_away ) : 'TBD';

        // Preserve any "Third-Place Play-off:" / "Round X:" prefix in the existing title.
        $existing_title = get_the_title( $next_mid );
        $prefix         = '';
        if ( preg_match( '/^(.*?:\s)/', $existing_title, $m ) ) {
            $prefix = $m[1];
        }

        wp_update_post([
            'ID'         => $next_mid,
            'post_title' => $prefix . "$h_name vs $a_name",
        ]);
    }
}