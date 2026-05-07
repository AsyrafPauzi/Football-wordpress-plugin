<?php
class FLMS_Image_Helper {

    // 1. Get Team Logo (or Generate Initials)
    public static function get_team_logo( $team_id, $size = 'medium' ) {
        // Try to get uploaded image
        $img = get_the_post_thumbnail_url( $team_id, $size );
        
        if ( ! $img ) {
            // Get Team Name
            $name = get_the_title( $team_id );
            // Generate URL for Initials Image (e.g. "Red Giants" -> "RG")
            // background=random makes it colorful
            return 'https://ui-avatars.com/api/?name=' . urlencode( $name ) . '&background=random&color=fff&size=128&font-size=0.5';
        }
        return $img;
    }

    // 2. Get Player Photo (or Generate Initials)
    public static function get_player_photo( $player_id, $size = 'medium' ) {
        // Try to get uploaded image
        $img = get_the_post_thumbnail_url( $player_id, $size );
        
        if ( ! $img ) {
            // Get Player Name
            $name = get_the_title( $player_id );
            // Grey background for players
            return 'https://ui-avatars.com/api/?name=' . urlencode( $name ) . '&background=ccc&color=fff&size=128';
        }
        return $img;
    }
}