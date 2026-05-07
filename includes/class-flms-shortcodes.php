<?php
class FLMS_Shortcodes {
    public function __construct() {
        add_shortcode( 'flms_player_directory', [ $this, 'directory' ] );
    }

    public function directory() {
        // Basic list - Enhancers use JetEngine Listing Grid instead
        $args = [ 'post_type' => 'flms_player', 'posts_per_page' => 20 ];
        $players = get_posts( $args );
        
        $out = '<div class="flms-directory"><ul>';
        foreach ( $players as $p ) {
            $team = get_post_meta( $p->ID, 'flms_team_id', true );
            $team_name = $team ? get_the_title( $team ) : 'Free Agent';
            $out .= '<li>' . $p->post_title . ' (' . $team_name . ')</li>';
        }
        $out .= '</ul></div>';
        return $out;
    }
}