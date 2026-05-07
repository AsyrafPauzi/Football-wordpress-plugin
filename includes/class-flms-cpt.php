<?php
class FLMS_CPT {
    public function __construct() {
        add_action( 'init', [ $this, 'register_cpts' ] );
        add_action( 'init', [ $this, 'register_taxonomies' ] );
    }

    public function register_cpts() {
        // Team
        register_post_type( 'flms_team', [
            'labels' => [ 'name' => 'Teams', 'singular_name' => 'Team' ],
            'public' => true,
            'menu_icon' => 'dashicons-groups',
            'supports' => [ 'title', 'thumbnail', 'editor', 'custom-fields', 'author' ],
            'rewrite' => [ 'slug' => 'team' ],
        ]);

        // Player
        register_post_type( 'flms_player', [
            'labels' => [ 'name' => 'Players', 'singular_name' => 'Player' ],
            'public' => true,
            'menu_icon' => 'dashicons-id-alt',
            'supports' => [ 'title', 'thumbnail', 'custom-fields' ], 
            'rewrite' => [ 'slug' => 'player' ],
        ]);

        // Match
        register_post_type( 'flms_match', [
            'labels' => [ 'name' => 'Matches', 'singular_name' => 'Match' ],
            'public' => true,
            'menu_icon' => 'dashicons-tickets-alt',
            'supports' => [ 'title', 'custom-fields' ],
            'rewrite' => [ 'slug' => 'match' ],
        ]);

        // Friendly Match (separate from league)
        register_post_type( 'flms_friendly', [
            'labels' => [ 'name' => 'Friendly Matches', 'singular_name' => 'Friendly Match' ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-smiley',
            'supports' => [ 'title', 'custom-fields', 'author', 'comments' ],
            'capability_type' => 'post',
        ]);

        // Friendly Application (request to play a friendly slot) — post_type must be ≤20 chars (WordPress DB limit)
        register_post_type( 'flms_friendly_app', [
            'labels' => [ 'name' => 'Friendly Requests', 'singular_name' => 'Friendly Request' ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-email-alt',
            'supports' => [ 'custom-fields', 'author' ],
            'capability_type' => [ 'flms_friendly_app', 'flms_friendly_apps' ],
            'map_meta_cap' => true,
        ]);

        // Inbox announcement (shown in Friendly Inbox -> Notifications tab)
        // WordPress post types must be <= 20 chars.
        register_post_type( 'flms_inbox_notice', [
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
        ]);
    }

    // NEW: Register Venue Taxonomy
    public function register_taxonomies() {
        register_taxonomy( 'flms_venue', 'flms_match', [
            'labels' => [
                'name' => 'Venues',
                'singular_name' => 'Venue',
                'add_new_item' => 'Add New Venue',
                'new_item_name' => 'New Venue Name'
            ],
            'hierarchical' => true, // Like Categories (checkboxes)
            'public' => true,
            'show_admin_column' => true, // Show in Admin List
            'rewrite' => [ 'slug' => 'venue' ],
        ]);
    }
}