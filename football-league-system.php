<?php
/**
 * Plugin Name: Football League Management System
 * Description: Enterprise league management with WooCommerce & JetEngine integration.
 * Version: 5.5.9
 * Author: Asyraf Pauzi
 * Text Domain: flms
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define Constants
define( 'FLMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'FLMS_URL', plugin_dir_url( __FILE__ ) );
define( 'FLMS_VERSION', '5.5.9' );
define( 'FLMS_FRIENDLY_FEE_PRODUCT_ID', 23058 );

// Include Classes
require_once FLMS_PATH . 'includes/class-flms-cache-bump.php';
require_once FLMS_PATH . 'includes/class-flms-activator.php';
require_once FLMS_PATH . 'includes/class-flms-cpt.php';
require_once FLMS_PATH . 'includes/class-flms-woo.php';
require_once FLMS_PATH . 'includes/class-flms-match-engine.php';
require_once FLMS_PATH . 'includes/class-flms-referee.php';
require_once FLMS_PATH . 'includes/class-flms-scoring.php';
require_once FLMS_PATH . 'includes/class-flms-shortcodes.php';
require_once FLMS_PATH . 'includes/class-flms-standings.php'; 
require_once FLMS_PATH . 'includes/class-flms-table-shortcode.php';
require_once FLMS_PATH . 'includes/class-flms-match-list.php';
require_once FLMS_PATH . 'includes/class-flms-knockout-progression.php';
require_once FLMS_PATH . 'includes/class-flms-player-stats.php';
require_once FLMS_PATH . 'includes/class-flms-schedule.php';
require_once FLMS_PATH . 'includes/class-flms-team-settings.php';
require_once FLMS_PATH . 'includes/class-flms-player-profile.php';
require_once FLMS_PATH . 'includes/class-flms-bracket.php';
require_once FLMS_PATH . 'includes/class-flms-auth.php';
require_once FLMS_PATH . 'includes/class-flms-manager-dashboard.php';
require_once FLMS_PATH . 'includes/class-flms-match-frontend.php';
require_once FLMS_PATH . 'includes/class-flms-team-frontend.php';
require_once FLMS_PATH . 'includes/class-flms-image-helper.php';
require_once FLMS_PATH . 'includes/class-flms-competitions.php';
require_once FLMS_PATH . 'includes/class-flms-tournament-display.php';
require_once FLMS_PATH . 'includes/class-flms-checkout-flow.php';
require_once FLMS_PATH . 'includes/class-flms-redirects.php';
require_once FLMS_PATH . 'includes/class-flms-player-directory.php';
require_once FLMS_PATH . 'includes/class-flms-scorekeeper.php';

require_once FLMS_PATH . 'includes/class-flms-dummy-data.php';
require_once FLMS_PATH . 'includes/class-flms-homepage-addons.php';
require_once FLMS_PATH . 'includes/class-flms-transfer-system.php';
require_once FLMS_PATH . 'includes/class-flms-notifications.php';
require_once FLMS_PATH . 'includes/class-flms-mobile-menu.php';
require_once FLMS_PATH . 'includes/class-flms-finance.php';
require_once FLMS_PATH . 'includes/class-flms-referee-feedback.php';
require_once FLMS_PATH . 'includes/class-flms-referee-leader.php';
require_once FLMS_PATH . 'includes/class-flms-logger.php';
require_once FLMS_PATH . 'includes/class-flms-multi-view.php';
require_once FLMS_PATH . 'includes/class-flms-friendly.php';
require_once FLMS_PATH . 'includes/class-flms-gk-seeder.php';

require_once FLMS_PATH . 'includes/admin/class-flms-fixture-editor.php';


   


    

    







// Activation Hook
register_activation_hook( __FILE__, [ 'FLMS_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'FLMS_Activator', 'deactivate' ] );

// Initialize System
function flms_init_system() {
    FLMS_Cache_Bump::init();
    new FLMS_CPT();
    new FLMS_Woo();
    new FLMS_Match_Engine();
    new FLMS_Referee();
    new FLMS_Scoring();
    new FLMS_Transfer_System();
    new FLMS_Shortcodes();
    new FLMS_Standings();
    new FLMS_Table_Shortcode();
    new FLMS_Match_List();
    new FLMS_Knockout_Progression();
    new FLMS_Player_Stats();
    new FLMS_Schedule();
    new FLMS_Team_Settings();
    new FLMS_Player_Profile();
    new FLMS_Manager_Dashboard();
    new FLMS_Match_Frontend();
    new FLMS_Team_Frontend();
    new FLMS_Bracket();
    new FLMS_Auth();
    new FLMS_Competitions();
    new FLMS_Tournament_Display();
    new FLMS_Checkout_Flow();
     new FLMS_Redirects();
     new FLMS_Player_Directory();
     new FLMS_Scorekeeper();
     new FLMS_Homepage_Addons();
     new FLMS_Notifications();
     new FLMS_Mobile_Menu();
     new FLMS_Finance();
     new FLMS_Referee_Feedback();
     new FLMS_Referee_Leader();
      new FLMS_Logger();
      new FLMS_Multi_View();
     new FLMS_Friendly();
     new FLMS_Fixture_Editor();
     
     new FLMS_Dummy_Data();

    // Ensure inbox announcement CPT exists.
    // Some deployments may not include the updated CPT class file, so register here as a fallback.
    add_action( 'init', function () {
        $post_type = 'flms_inbox_notice';
        if ( post_type_exists( $post_type ) ) {
            return;
        }
        register_post_type( $post_type, [
            'labels' => [
                'name' => 'Inbox Announcements',
                'singular_name' => 'Inbox Announcement',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-megaphone',
            'supports' => [ 'title', 'editor', 'author' ],
            'capability_type' => 'post',
        ] );
    }, 0 );

    // Enqueue Assets
    add_action( 'wp_enqueue_scripts', 'flms_enqueue_assets' );
    add_action( 'admin_enqueue_scripts', 'flms_enqueue_assets' );
}
add_action( 'plugins_loaded', 'flms_init_system' );

function flms_enqueue_assets() {
    wp_enqueue_style( 'flms-style', FLMS_URL . 'assets/css/flms-style.css', [], FLMS_VERSION );
    
    if ( is_checkout() ) {
        wp_enqueue_script( 'flms-checkout', FLMS_URL . 'assets/js/flms-checkout.js', ['jquery'], FLMS_VERSION, true );
    }
}