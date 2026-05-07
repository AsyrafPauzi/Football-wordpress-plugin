<?php
class FLMS_Data_Integrity {

    public function __construct() {
        // 1. Intercept Trash Action
        add_filter( 'pre_trash_post', [ $this, 'prevent_deletion_if_linked' ], 10, 2 );
        
        // 2. Display Error Messages
        add_action( 'admin_notices', [ $this, 'show_blocking_notice' ] );
    }

    /**
     * Stop deletion if child data exists.
     */
    public function prevent_deletion_if_linked( $trash, $post ) {
        
        // --- CASE 1: TOURNAMENT (Product) ---
        if ( $post->post_type === 'product' ) {
            // Check if any TEAM is linked to this Tournament
            $linked_teams = get_posts([
                'post_type'   => 'flms_team',
                'meta_key'    => 'flms_tournament_id',
                'meta_value'  => $post->ID,
                'post_status' => 'any',
                'numberposts' => 1, // We only need to know if ONE exists
                'fields'      => 'ids'
            ]);

            if ( ! empty( $linked_teams ) ) {
                $this->redirect_with_error( 'error_tournament_has_teams' );
                return false; // BLOCK DELETION
            }
        }

        // --- CASE 2: TEAM ---
        if ( $post->post_type === 'flms_team' ) {
            // Check if any PLAYER is linked to this Team
            $linked_players = get_posts([
                'post_type'   => 'flms_player',
                'meta_key'    => 'flms_team_id',
                'meta_value'  => $post->ID,
                'post_status' => 'any',
                'numberposts' => 1,
                'fields'      => 'ids'
            ]);

            if ( ! empty( $linked_players ) ) {
                $this->redirect_with_error( 'error_team_has_players' );
                return false; // BLOCK DELETION
            }
        }

        return $trash; // Allow deletion if no checks failed
    }

    /**
     * Helper: Redirect back to list with error code
     */
    private function redirect_with_error( $code ) {
        $redirect_url = add_query_arg( 'flms_error', $code, wp_get_referer() );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Show Admin Notice based on error code
     */
    public function show_blocking_notice() {
        if ( ! isset( $_GET['flms_error'] ) ) return;

        $code = sanitize_text_field( $_GET['flms_error'] );
        $message = '';

        if ( $code === 'error_tournament_has_teams' ) {
            $message = '⛔ <strong>CRITICAL DENIED:</strong> You cannot delete this Tournament because active Teams are linked to it. <br>Please delete or re-assign the Teams first.';
        }
        
        if ( $code === 'error_team_has_players' ) {
            $message = '⛔ <strong>CRITICAL DENIED:</strong> You cannot delete this Team because Players are registered to it. <br>Please release or transfer the Players first.';
        }

        if ( $message ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . $message . '</p></div>';
        }
    }
}