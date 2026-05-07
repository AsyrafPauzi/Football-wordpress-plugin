<?php
class FLMS_Activator {
    public static function activate() {
        // 1. Refresh Team Manager (needs flms_friendly_apps caps to submit "Request to Play" and see My Requests)
        remove_role( 'team_manager' );
        add_role( 'team_manager', 'Team Manager', [
            'read' => true,
            'upload_files' => true,
            'edit_flms_friendly_apps' => true,
            'publish_flms_friendly_apps' => true,
            'edit_published_flms_friendly_apps' => true,
        ]);

        // 2. Refresh Referee
        remove_role( 'referee' );
        add_role( 'referee', 'Referee', [
            'read' => true,
        ]);

        // 3. Refresh Scorekeeper
        remove_role( 'scorekeeper' );
        add_role( 'scorekeeper', 'Scorekeeper', [
            'read' => true,
            'upload_files' => true,
        ]);

        // 4. Refresh Referee Leader
        remove_role( 'referee_leader' );
        add_role( 'referee_leader', 'Referee Leader', [
            'read' => true,
        ]);

        // 5. Refresh Finance Officer (CRITICAL FIX)
        remove_role( 'finance_officer' );
        add_role( 'finance_officer', 'Finance Officer', [
            'read' => true,
            'manage_woocommerce'   => true, // View Orders
            'edit_posts'           => true, // Access Edit Screen
            'edit_published_posts' => true, // Edit Live Matches
            'edit_others_posts'    => true, // Edit Admin's Matches
            'upload_files'         => false,
        ]);

        // 6. League Admin
        $admin = get_role('administrator');
        if ( $admin ) {
            remove_role( 'league_admin' );
            add_role( 'league_admin', 'League Admin', $admin->capabilities );
        }

        // 7. Ensure Administrator can manage Friendly Applications (CPT uses custom caps)
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( 'edit_flms_friendly_apps' );
            $admin->add_cap( 'edit_others_flms_friendly_apps' );
            $admin->add_cap( 'publish_flms_friendly_apps' );
            $admin->add_cap( 'read_private_flms_friendly_apps' );
            $admin->add_cap( 'delete_flms_friendly_apps' );
            $admin->add_cap( 'delete_others_flms_friendly_apps' );
            $admin->add_cap( 'edit_published_flms_friendly_apps' );
            $admin->add_cap( 'delete_published_flms_friendly_apps' );
        }

        // 8. Migrate old friendly application post type (was 28 chars, DB limit is 20)
        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->posts} SET post_type = 'flms_friendly_app' WHERE post_type = 'flms_friendly_application'" );

        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }
}