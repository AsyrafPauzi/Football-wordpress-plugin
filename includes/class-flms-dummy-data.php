<?php
class FLMS_Dummy_Data {

    public function __construct() {
        add_action( 'init', [ $this, 'generate_data' ] );
    }

    public function generate_data() {
        if ( ! isset( $_GET['flms_generate_test_data'] ) ) return;
        if ( ! current_user_can( 'administrator' ) ) wp_die('Error: Access Denied.');

        set_time_limit(600); 

        echo '<div style="background:#fff; color:#000; padding:40px; font-family:monospace; line-height:1.6;">';
        echo '<h1>🚀 Starting FINAL Generator...</h1>';

        // --- STEP 1: CREATE TOURNAMENTS (One set of dates is in the past, one in the future) ---
        $rand = rand(100, 999);
        $t1_name = "Super League 2025 #$rand (PAST)";
        $t2_name = "Champions Cup 2026 #$rand (FUTURE)";
        
        // PAST: Transfers will be PAID (2024-01-01 is past today)
        $t1_id = $this->force_create_product( $t1_name, 'round_robin', '2024-01-01', '2024-06-01' );
        
        // FUTURE: Transfers will be FREE (2026-01-01 is future)
        $t2_id = $this->force_create_product( $t2_name, 'knockout', '2026-01-01', '2026-03-01' );

        if( ! $t1_id || ! $t2_id ) {
            wp_die("<h2 style='color:red'>FATAL ERROR: Could not create products.</h2>");
        }

        // --- STEP 2: CREATE TEAMS & PLAYERS ---
        echo "<hr><h3>Creating 16 Teams & Linking...</h3>";
        
        $locations = ['Cyberjaya', 'Subang', 'Damansara', 'Cheras', 'Ampang', 'Bangsar', 'Puchong', 'Klang', 'Shah Alam', 'Petaling', 'Gombak', 'Sepang', 'Kajang', 'Rawang', 'Sentul', 'Bangi'];
        $mascots   = ['United', 'Warriors', 'Tigers', 'Lions', 'Eagles', 'Falcons', 'Wolves', 'Sharks', 'Dragons', 'Knights', 'Royals', 'Titans', 'Rangers', 'Rovers', 'Athletic', 'City'];
        $colors    = ['#FF0000', '#0000FF', '#00FF00', '#FFFF00', '#800080', '#FFA500', '#000000', '#FFFFFF', '#A52A2A', '#808080', '#00FFFF', '#FFC0CB', '#008080', '#4B0082', '#FA8072', '#DC143C'];

        $positions_template = array_merge(
            ['GK', 'GK'], ['DEF', 'DEF', 'DEF', 'DEF', 'DEF', 'DEF'], ['MID', 'MID', 'MID', 'MID', 'MID', 'MID'], ['FWD', 'FWD', 'FWD', 'FWD']
        );

        for ( $i = 0; $i < 16; $i++ ) {
            
            // Link Logic
            $current_tid = ($i < 8) ? $t1_id : $t2_id;
            $t_label = ($i < 8) ? "Super League" : "Champions Cup";

            // Create Manager (Same user can manage multiple teams, but their club name is unique)
            $username = 'manager_' . ($i + 1);
            $email    = "manager" . ($i + 1) . "@test.com";
            $user_id  = username_exists( $username );
            
            if ( ! $user_id ) {
                $user_id = wp_create_user( $username, 'password', $email );
                $user = new WP_User( $user_id );
                $user->set_role( 'team_manager' );
                update_user_meta( $user_id, 'flms_account_status', 'active' );
            }

            // Create Team
            $base_name = $locations[$i] . ' ' . $mascots[$i];
            $full_name = "$base_name ($t_label #$rand)";
            
            update_user_meta( $user_id, 'flms_club_name', $base_name );

            $team_id = wp_insert_post([
                'post_type'   => 'flms_team',
                'post_title'  => $full_name,
                'post_status' => 'publish',
                'post_author' => $user_id
            ]);

            // CRITICAL: Link Team to Tournament
            update_post_meta( $team_id, 'flms_tournament_id', $current_tid );

            update_post_meta( $team_id, 'flms_home_color', $colors[$i] );
            update_post_meta( $team_id, 'flms_away_color', '#ffffff' );

            echo "Created Team: $full_name (Tourney ID: $current_tid)<br>";

            // Create 18 Players
            foreach ( $positions_template as $pos ) {
                $p_name = $this->get_unique_name();
                $ic = rand(90,99) . rand(1000,9999) . '-' . rand(10,99) . '-' . rand(1000,9999);
                
                $pid = wp_insert_post([
                    'post_type'   => 'flms_player',
                    'post_title'  => $p_name,
                    'post_status' => 'publish',
                    'post_author' => $user_id
                ]);

                update_post_meta( $pid, 'flms_team_id', $team_id );
                update_post_meta( $pid, 'flms_tournament_id', $current_tid );
                update_post_meta( $pid, 'flms_position', $pos );
                update_post_meta( $pid, 'flms_number', rand(1, 99) );
                update_post_meta( $pid, 'flms_age', rand(18, 35) );
                update_post_meta( $pid, 'flms_ic', $ic );
                update_post_meta( $pid, 'flms_total_goals', 0 );
            }
        }

        // --- STEP 3: CREATE STAFF ---
        echo '<hr><h3>Creating Staff...</h3>';
        $this->create_staff('referee', 4);
        $this->create_staff('scorekeeper', 4);

        echo '<hr><h1 style="color:green;">SUCCESS: Generation Complete!</h1>';
        echo "<p>Test the Transfer Window:</p>";
        echo "<ol>";
        echo "<li>Find a team linked to 'Super League 2025 (PAST)' and log in as its manager (The box should be <strong>RED/PAID</strong>).</li>";
        echo "<li>Find a team linked to 'Champions Cup 2026 (FUTURE)' and log in as its manager (The box should be <strong>GREEN/FREE</strong>).</li>";
        echo "</ol>";
        echo '</div>';
        die();
    }

    // --- FORCE CREATE PRODUCT (WC/WP Post) ---
    private function force_create_product( $name, $format, $start, $end ) {
        // Find existing to avoid duplicates
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_title = %s AND post_type = 'product'", $name ) );
        if ( $id ) return $id;

        // Create Post
        $product_id = wp_insert_post([
            'post_title' => $name,
            'post_content' => 'Official Tournament',
            'post_status' => 'publish',
            'post_type' => 'product',
        ]);

        if ( is_wp_error( $product_id ) || !$product_id ) {
            echo "<span style='color:red'>Failed to create $name.</span><br>";
            return false;
        }

        // Set Product Meta
        update_post_meta( $product_id, '_price', '500' );
        update_post_meta( $product_id, '_regular_price', '500' );
        update_post_meta( $product_id, '_sold_individually', 'yes' );
        wp_set_object_terms( $product_id, 'simple', 'product_type' );

        // Set FLMS Meta
        update_post_meta( $product_id, '_flms_start_date', $start );
        update_post_meta( $product_id, '_flms_end_date', $end );
        update_post_meta( $product_id, '_flms_format', $format );

        echo "<span style='color:green'>Created Product: $name (ID: $product_id)</span><br>";
        return $product_id;
    }

    private function create_staff( $role, $count ) {
        for ( $i = 1; $i <= $count; $i++ ) {
            $u = $role . '_' . $i;
            if ( ! username_exists($u) ) {
                $uid = wp_create_user( $u, 'password', "$u@test.com" );
                $user = new WP_User( $uid );
                $user->set_role( $role );
                update_user_meta( $uid, 'flms_account_status', 'active' );
            }
        }
    }

    private function get_unique_name() {
        $first = ['Ali', 'Abu', 'Chong', 'Muthu', 'David', 'Sam', 'Lee', 'Tan', 'Kumar', 'Raj', 'Siti', 'Omar', 'Kevin', 'Dan', 'Mike', 'Steve', 'Alex', 'Ryan', 'Ken', 'Ben', 'Hakim', 'Faiz', 'Wei', 'Jie', 'Siva', 'Ravi', 'Wan', 'Zul'];
        $last  = ['Ahmed', 'Wong', 'Singh', 'Smith', 'Tan', 'Lim', 'Goh', 'Baba', 'Sulaiman', 'Fernandez', 'Yap', 'Liew', 'Ramasamy', 'Krishnan', 'Othman', 'Razak', 'Lee', 'Chua', 'Teoh', 'Ng', 'Bakar', 'Yusof', 'Ismail'];
        return $first[array_rand($first)] . ' ' . $last[array_rand($last)] . ' ' . rand(10,99);
    }
}