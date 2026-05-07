<?php
class FLMS_Mobile_Menu {

    public function __construct() {
        add_action( 'wp_footer', [ $this, 'render_mobile_menu' ] );
    }

    public function render_mobile_menu() {
        if ( is_admin() ) return;

        $home_url = home_url( '/' );
        $comp_url = home_url( '/tournaments/' ); // Adjust slug if needed
        $play_url = home_url( '/player-directory/' ); // Adjust slug if needed
        $logout_url = wp_logout_url( home_url() );
        
        $is_logged_in = is_user_logged_in();

        // Smart Dashboard Logic
        if ( $is_logged_in ) {
            $user = wp_get_current_user();
            if ( in_array( 'referee_leader', (array) $user->roles ) ) {
                $account_url = home_url( '/referee-leader-dashboard/' );
                $account_text = 'Leader';
            } elseif ( in_array( 'referee', (array) $user->roles ) ) {
                $account_url = home_url( '/referee-dashboard/' );
                $account_text = 'Referee';
            } else {
                $account_url = home_url( '/my-team-dashboard/' );
                $account_text = 'My Team';
            }
            $icon_color = '#D4AF37'; // Gold for active user
        } else {
            $account_url = home_url( '/login/' );
            $account_text = 'Login';
            $icon_color = 'currentColor';
        }

        ?>
        <div class="flms-mobile-nav">
            
            <a href="<?php echo $home_url; ?>" class="mn-item">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                <span>Home</span>
            </a>

            <a href="<?php echo $comp_url; ?>" class="mn-item">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M17 1H7c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-2-2-2zm0 18H7V5h10v14zm-3-8l-2.25-3-2.25 3h4.5z"/></svg>
                <span>Leagues</span>
            </a>

            <a href="<?php echo $play_url; ?>" class="mn-item">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                <span>Players</span>
            </a>

            <a href="<?php echo $account_url; ?>" class="mn-item" style="color: <?php echo $icon_color; ?>;">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>
                <span><?php echo $account_text; ?></span>
            </a>

            <!-- LOGOUT BUTTON (Only if logged in) -->
            <?php if ( $is_logged_in ) : ?>
            <a href="<?php echo $logout_url; ?>" class="mn-item" style="color: #e74c3c;">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                <span>Logout</span>
            </a>
            <?php endif; ?>

        </div>
        <?php
    }
}