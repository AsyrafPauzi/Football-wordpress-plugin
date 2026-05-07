<?php
/**
 * Seed Teams for Group Knockout Testing
 * 
 * Triggered by: /wp-admin/?flms_seed_gk_test=23558
 * (or any tournament ID you pass)
 * 
 * Visit: https://staging.agdsports.com/wp-admin/?flms_seed_gk_test=23558
 * as an admin to run this.
 */
class FLMS_GK_Seeder {

    public function __construct() {
        add_action( 'init', [ $this, 'run' ] );
    }

    public function run() {
        if ( ! isset( $_GET['flms_seed_gk_test'] ) ) return;
        if ( ! current_user_can( 'administrator' ) ) wp_die( 'Access Denied.' );

        $tournament_id = intval( $_GET['flms_seed_gk_test'] );
        if ( ! $tournament_id ) wp_die( 'Invalid tournament ID.' );

        set_time_limit( 120 );

        $team_names = [
            'Harimau FC', 'Rajawali United', 'Naga Mas', 'Garuda Muda',
            'Petala Unit', 'Wira Bestari', 'Helang Merah', 'Singa Biru',
            'Tenaga Biru', 'Garuda Prima', 'Rimau Sakti', 'Elang Emas',
            'Srikandi FC', 'Badak Kuat', 'Kijang Laju', 'Panther City',
        ];

        $colors = [
            '#e74c3c', '#3498db', '#2ecc71', '#f39c12',
            '#9b59b6', '#1abc9c', '#e67e22', '#34495e',
            '#e91e63', '#00bcd4', '#ff5722', '#8bc34a',
            '#673ab7', '#ff9800', '#795548', '#607d8b',
        ];

        echo '<div style="background:#fff; color:#000; padding:40px; font-family:monospace; line-height:1.8;">';
        echo '<h1>🌱 FLMS Group Knockout Seeder</h1>';
        echo "<p>Seeding <strong>16 teams</strong> into tournament <strong>#$tournament_id</strong>...</p>";
        echo '<hr>';

        foreach ( $team_names as $i => $name ) {

            // Create a manager user for this team (if not exists)
            $username = 'gk_manager_' . ($i + 1);
            $email    = 'gk_manager' . ($i + 1) . '@agdtest.com';

            $user_id = username_exists( $username );
            if ( ! $user_id ) {
                $user_id = wp_create_user( $username, 'Password123!', $email );
                if ( is_wp_error( $user_id ) ) {
                    echo "<span style='color:red'>❌ Failed to create user for $name: " . $user_id->get_error_message() . "</span><br>";
                    continue;
                }
                $user = new WP_User( $user_id );
                $user->set_role( 'team_manager' );
                update_user_meta( $user_id, 'flms_account_status', 'active' );
            }

            // Check if team already exists for this tournament
            $existing = get_posts([
                'post_type'      => 'flms_team',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'author'         => $user_id,
                'meta_query'     => [
                    [ 'key' => 'flms_tournament_id', 'value' => $tournament_id ],
                ],
            ]);

            if ( ! empty( $existing ) ) {
                echo "<span style='color:#888'>⏭ Skipped (already exists): $name</span><br>";
                continue;
            }

            // Create the team
            $team_id = wp_insert_post([
                'post_type'   => 'flms_team',
                'post_title'  => $name,
                'post_status' => 'publish',
                'post_author' => $user_id,
            ]);

            if ( is_wp_error( $team_id ) || ! $team_id ) {
                echo "<span style='color:red'>❌ Failed to create team: $name</span><br>";
                continue;
            }

            // Link team to tournament
            update_post_meta( $team_id, 'flms_tournament_id', $tournament_id );
            update_post_meta( $team_id, 'flms_home_color', $colors[$i] );
            update_post_meta( $team_id, 'flms_away_color', '#ffffff' );
            update_user_meta( $user_id, 'flms_club_name', $name );

            // Create 18 dummy players
            $positions = ['GK','GK','DEF','DEF','DEF','DEF','DEF','MID','MID','MID','MID','MID','FWD','FWD','FWD','FWD','FWD','FWD'];
            $first_names = ['Ali','Abu','Chong','Muthu','David','Hakim','Faiz','Wei','Siva','Wan','Zul','Ravi','Kevin','Izzat','Irfan','Faris','Luqman','Aiman'];
            $last_names  = ['Ahmad','Wong','Singh','Tan','Lim','Othman','Razak','Lee','Bakar','Yusof','Ismail','Chua','Kumar','Sulaiman','Ramli','Hamid'];

            foreach ( $positions as $pos ) {
                $p_name = $first_names[ array_rand( $first_names ) ] . ' ' . $last_names[ array_rand( $last_names ) ] . ' ' . rand(10,99);
                $pid = wp_insert_post([
                    'post_type'   => 'flms_player',
                    'post_title'  => $p_name,
                    'post_status' => 'publish',
                    'post_author' => $user_id,
                ]);
                update_post_meta( $pid, 'flms_team_id',      $team_id );
                update_post_meta( $pid, 'flms_tournament_id', $tournament_id );
                update_post_meta( $pid, 'flms_position',     $pos );
                update_post_meta( $pid, 'flms_number',       rand(1, 99) );
                update_post_meta( $pid, 'flms_age',          rand(18, 35) );
                update_post_meta( $pid, 'flms_total_goals',  0 );
            }

            echo "<span style='color:green'>✅ Created: <strong>$name</strong> (Team ID: $team_id, Manager: $username / Password123!)</span><br>";
        }

        echo '<hr>';
        echo '<h2 style="color:green">✅ Seeding Complete!</h2>';
        echo '<p><strong>Next Steps:</strong></p>';
        echo '<ol>';
        echo "<li>Go to <a href='/wp-admin/edit.php?post_type=flms_match&page=flms-match-gen' target='_blank'>Generate Matches</a></li>";
        echo "<li>Select tournament <strong>#$tournament_id</strong></li>";
        echo '<li>Choose format: <strong>Group Stage + Knockout</strong></li>';
        echo '<li>Set Number of Groups: <strong>4</strong></li>';
        echo '<li>Click Generate Group Matches</li>';
        echo '</ol>';
        echo '</div>';
        die();
    }
}

new FLMS_GK_Seeder();
