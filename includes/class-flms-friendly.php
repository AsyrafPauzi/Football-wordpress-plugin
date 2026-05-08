<?php
/**
 * FLMS Friendly Match System (separate from league).
 * - Team manager creates friendly slot (date, time, place).
 * - Other managers see open requests in inbox and can request to play.
 * - Creator accepts one team → others rejected → pending admin approval.
 * - Admin approves → email to both teams; result can be recorded → friendly points (3/1/-1).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FLMS_Friendly {

    /** @var bool Set when pay modal / friendly UI needs scripts + footer markup */
    private static $friendly_ui_needed = false;

    public function __construct() {
        // Shortcodes
        add_shortcode( 'flms_friendly_create', [ $this, 'shortcode_create' ] );
        add_shortcode( 'flms_friendly_inbox', [ $this, 'shortcode_inbox' ] );
        add_shortcode( 'flms_friendly_match', [ $this, 'shortcode_match_details' ] );
        add_shortcode( 'flms_friendly_schedule', [ $this, 'shortcode_schedule' ] );
        add_shortcode( 'flms_team_directory', [ $this, 'shortcode_team_directory' ] );

        // Form / action handling
        add_action( 'init', [ $this, 'process_actions' ] );
        add_action( 'init', [ $this, 'handle_friendly_pay_link' ], 5 );
        add_action( 'template_redirect', [ $this, 'inbox_no_cache' ], 5 );
        add_action( 'wp_footer', [ $this, 'maybe_print_friendly_consent_modal' ], 99 );

        // Admin: approve + result metabox
        add_action( 'add_meta_boxes', [ $this, 'add_admin_metaboxes' ] );
        add_action( 'save_post_flms_friendly', [ $this, 'save_admin_metaboxes' ], 10, 2 );

        // Force an admin menu entry for Inbox Announcements.
        // This avoids relying solely on the CPT's show_in_menu setting.
        add_action( 'admin_menu', [ $this, 'add_inbox_announcement_admin_menu' ] );

        // Redundant CPT registration:
        // Some deployments may miss the updated class-flms-cpt.php file.
        // Register here too so the post type always exists.
        add_action( 'init', [ $this, 'maybe_register_inbox_announcement_post_type' ], 1 );

        // Admin list table: show approval status in columns
        add_filter( 'manage_flms_friendly_posts_columns', [ $this, 'add_friendly_admin_columns' ] );
        add_action( 'manage_flms_friendly_posts_custom_column', [ $this, 'render_friendly_admin_custom_column' ], 10, 2 );
        add_action( 'admin_init', [ $this, 'handle_friendly_admin_list_actions' ] );
        add_action( 'admin_notices', [ $this, 'render_friendly_admin_list_notice' ] );

        // Admin: inbox announcements (for the Friendly Inbox "Notifications" tab)
        add_action( 'add_meta_boxes', [ $this, 'add_inbox_announcement_metabox' ] );
        add_action( 'save_post_flms_inbox_notice', [ $this, 'save_inbox_announcement_metaboxes' ], 10, 2 );

        // Let logged-in users create friendly applications (Request to Play) even if role lacks the CPT cap
        add_filter( 'map_meta_cap', [ $this, 'map_friendly_application_caps' ], 10, 4 );
        add_filter( 'user_has_cap', [ $this, 'grant_friendly_application_caps' ], 10, 4 );

        add_action( 'comment_post', [ $this, 'save_rating_on_comment' ], 10, 3 );
        add_filter( 'comment_post_redirect', [ $this, 'comment_redirect_to_friendly' ], 10, 2 );
        add_filter( 'comments_open', [ $this, 'open_comments_for_friendly' ], 10, 2 );
    }

    public function maybe_register_inbox_announcement_post_type() {
        // WordPress post types must be <= 20 chars.
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
    }

    public function add_inbox_announcement_admin_menu() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        add_menu_page(
            'Inbox Announcements',
            'Inbox Announcements',
            'manage_options',
            'flms-inbox-announcements',
            [ $this, 'redirect_to_inbox_announcements_list' ],
            'dashicons-megaphone',
            26
        );
    }

    public function redirect_to_inbox_announcements_list() {
        // Redirect to the CPT list table page.
        wp_safe_redirect( admin_url( 'edit.php?post_type=flms_inbox_notice' ) );
        exit;
    }

    public function add_friendly_admin_columns( $columns ) {
        // Keep WP defaults; append our column near the status area.
        $new_columns = [];
        foreach ( $columns as $key => $label ) {
            if ( $key === 'date' ) {
                $new_columns['flms_friendly_venue'] = 'Venue';
            }
            $new_columns[ $key ] = $label;
            if ( $key === 'date' ) {
                $new_columns['flms_approval_status'] = 'Admin Approval';
            }
        }
        if ( ! isset( $new_columns['flms_friendly_venue'] ) ) {
            $new_columns['flms_friendly_venue'] = 'Venue';
        }
        if ( ! isset( $new_columns['flms_approval_status'] ) ) {
            $new_columns['flms_approval_status'] = 'Admin Approval';
        }
        return $new_columns;
    }

    public function render_friendly_admin_custom_column( $column, $post_id ) {
        if ( $column === 'flms_friendly_venue' ) {
            $venue = get_post_meta( $post_id, 'flms_friendly_place', true );
            echo esc_html( $venue ?: '-' );
            return;
        }
        if ( $column !== 'flms_approval_status' ) return;

        $status = get_post_meta( $post_id, 'flms_friendly_status', true );
        if ( empty( $status ) ) $status = 'open';

        $label = ucfirst( str_replace( '_', ' ', $status ) );
        // Map to existing badge variants used in frontend CSS.
        $badge_class = 'status-' . $status;
        echo '<span class="flms-status-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $label ) . '</span>';

        if ( $status === 'pending_admin' && current_user_can( 'edit_post', $post_id ) ) {
            $approve_url = wp_nonce_url(
                add_query_arg(
                    [
                        'post_type'      => 'flms_friendly',
                        'flms_friendly_action' => 'approve',
                        'friendly_id'    => $post_id,
                    ],
                    admin_url( 'edit.php' )
                ),
                'flms_friendly_list_action_' . $post_id
            );
            $reject_url = wp_nonce_url(
                add_query_arg(
                    [
                        'post_type'      => 'flms_friendly',
                        'flms_friendly_action' => 'reject',
                        'friendly_id'    => $post_id,
                    ],
                    admin_url( 'edit.php' )
                ),
                'flms_friendly_list_action_' . $post_id
            );
            echo '<div style="margin-top:6px;display:flex;gap:10px;flex-wrap:wrap;">';
            echo '<a class="button button-small button-primary" href="' . esc_url( $approve_url ) . '">Approve</a>';
            echo '<a class="button button-small" href="' . esc_url( $reject_url ) . '">Reject</a>';
            echo '</div>';
        }
    }

    public function handle_friendly_admin_list_actions() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_GET['post_type'] ) || $_GET['post_type'] !== 'flms_friendly' ) return;
        if ( empty( $_GET['flms_friendly_action'] ) || empty( $_GET['friendly_id'] ) ) return;

        $action = sanitize_key( wp_unslash( $_GET['flms_friendly_action'] ) );
        $friendly_id = (int) $_GET['friendly_id'];
        if ( $friendly_id <= 0 ) return;
        if ( ! current_user_can( 'edit_post', $friendly_id ) ) return;
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'flms_friendly_list_action_' . $friendly_id ) ) {
            return;
        }

        $status = get_post_meta( $friendly_id, 'flms_friendly_status', true ) ?: 'open';
        $msg = '';
        if ( $action === 'approve' ) {
            if ( $status === 'pending_admin' ) {
                $this->approve_friendly_match( $friendly_id );
                $msg = 'approved';
            } else {
                $msg = 'invalid_status';
            }
        } elseif ( $action === 'reject' ) {
            if ( $status === 'pending_admin' ) {
                // Keep it pending for follow-up discussion (venue conflict, team updates, etc.).
                update_post_meta( $friendly_id, 'flms_friendly_status', 'pending_admin' );
                $msg = 'rejected_to_pending';
            } else {
                $msg = 'invalid_status';
            }
        }

        if ( $msg ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'post_type' => 'flms_friendly',
                        'flms_friendly_admin_msg' => $msg,
                    ],
                    admin_url( 'edit.php' )
                )
            );
            exit;
        }
    }

    public function render_friendly_admin_list_notice() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_GET['post_type'] ) || $_GET['post_type'] !== 'flms_friendly' ) return;
        if ( empty( $_GET['flms_friendly_admin_msg'] ) ) return;

        $msg = sanitize_key( wp_unslash( $_GET['flms_friendly_admin_msg'] ) );
        if ( $msg === 'approved' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Friendly match approved successfully.</p></div>';
            return;
        }
        if ( $msg === 'rejected_to_pending' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>Friendly match was sent back to pending for further discussion.</p></div>';
            return;
        }
        if ( $msg === 'invalid_status' ) {
            echo '<div class="notice notice-error is-dismissible"><p>Action could not be completed because the current status is no longer pending admin.</p></div>';
        }
    }

    private function approve_friendly_match( $post_id ) {
        update_post_meta( $post_id, 'flms_friendly_status', 'approved' );
        update_post_meta( $post_id, '_flms_friendly_payment_status', 'unpaid' );
        delete_post_meta( $post_id, '_flms_friendly_paid_host' );
        delete_post_meta( $post_id, '_flms_friendly_paid_away' );
        delete_post_meta( $post_id, '_flms_friendly_receipt_host' );
        delete_post_meta( $post_id, '_flms_friendly_receipt_away' );

        $host_id = (int) get_post_meta( $post_id, 'flms_host_team_id', true );
        $chosen_id = (int) get_post_meta( $post_id, 'flms_chosen_team_id', true );
        $date = get_post_meta( $post_id, 'flms_friendly_date', true );
        $time = get_post_meta( $post_id, 'flms_friendly_time', true );
        $place = get_post_meta( $post_id, 'flms_friendly_place', true );
        $host_name = get_the_title( $host_id );
        $away_name = get_the_title( $chosen_id );
        $subject = 'Friendly match confirmed: ' . $host_name . ' vs ' . $away_name;
        $pay_host_url = self::get_friendly_pay_url( $post_id, $host_id );
        $pay_away_url = self::get_friendly_pay_url( $post_id, $chosen_id );
        $body = "Your friendly match has been approved.\n\n";
        $body .= "Match: {$host_name} vs {$away_name}\n";
        $body .= "Date: {$date} at {$time}\n";
        $body .= "Venue: {$place}\n\n";
        $body .= "Each team must pay RM500 to confirm. Please use the link below for your team.\n\n";
        $body .= "Host team ({$host_name}) - Pay RM500: {$pay_host_url}\n\n";
        $body .= "Away team ({$away_name}) - Pay RM500: {$pay_away_url}\n\n";
        $body .= "Good luck!";
        $email_a = self::get_team_manager_email( $host_id );
        $email_b = self::get_team_manager_email( $chosen_id );
        if ( $email_a ) wp_mail( $email_a, $subject, $body );
        if ( $email_b && $email_b !== $email_a ) wp_mail( $email_b, $subject, $body );
    }

    /** Always allow comments on flms_friendly posts for Rate & Comment. */
    public function open_comments_for_friendly( $open, $post_id ) {
        $post = get_post( $post_id );
        if ( $post && $post->post_type === 'flms_friendly' ) {
            return true;
        }
        return $open;
    }

    /** Redirect back to friendly match page with success message after commenting. */
    public function comment_redirect_to_friendly( $redirect, $comment ) {
        $post = get_post( $comment->comment_post_ID );
        if ( ! $post || $post->post_type !== 'flms_friendly' ) return $redirect;
        $ref = wp_get_referer();
        if ( $ref ) {
            $redirect = add_query_arg( [ 'friendly_id' => $comment->comment_post_ID, 'flms_friendly_msg' => 'rating_saved' ], $ref );
        }
        return $redirect;
    }

    /** Store friendly_id when rendering friendly comment form so we can inject the rating field. */
    private static $comment_form_friendly_id = 0;

    /** Add rating field to comment form for friendly matches (directly above the Comment textarea). */
    public function add_rating_to_comment_form( $content ) {
        if ( self::$comment_form_friendly_id <= 0 ) return $content;
        $ratings = get_post_meta( self::$comment_form_friendly_id, '_flms_friendly_ratings', true );
        if ( ! is_array( $ratings ) ) $ratings = [];
        $current = isset( $ratings[ (string) get_current_user_id() ] ) ? (int) $ratings[ (string) get_current_user_id() ] : 0;
        ob_start();
        ?><p class="flms-comment-rating-field" style="margin:0 0 1em 0; padding:0;">
            <label for="flms-friendly-rating-select"><strong><?php esc_html_e( 'Your rating (1–5):', 'flms' ); ?></strong></label>
            <select name="flms_friendly_rating" id="flms-friendly-rating-select" style="margin-left:8px; padding:6px 10px; min-width:60px;">
                <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                    <option value="<?php echo (int) $i; ?>" <?php selected( $current, $i ); ?>><?php echo (int) $i; ?></option>
                <?php endfor; ?>
            </select>
        </p><?php
        return ob_get_clean() . $content;
    }

    /** Save rating when a comment is posted on a friendly match. */
    public function save_rating_on_comment( $comment_id, $approved, $commentdata ) {
        $post_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'flms_friendly' ) return;
        $rating = isset( $_POST['flms_friendly_rating'] ) ? (int) $_POST['flms_friendly_rating'] : 0;
        if ( $rating < 1 || $rating > 5 ) return;
        $user_id = (int) ( $commentdata['user_id'] ?? get_current_user_id() );
        if ( $user_id <= 0 ) return;
        $ratings = get_post_meta( $post_id, '_flms_friendly_ratings', true );
        if ( ! is_array( $ratings ) ) $ratings = [];
        $ratings[ (string) $user_id ] = $rating;
        update_post_meta( $post_id, '_flms_friendly_ratings', $ratings );
    }

    /** Allow any logged-in user to create/edit their own flms_friendly_app so "Request to Play" and My Requests work. */
    public function map_friendly_application_caps( $caps, $cap, $user_id, $args ) {
        $user = get_userdata( $user_id );
        if ( ! $user || ! $user->exists() ) return $caps;
        if ( strpos( $cap, 'flms_friendly_app' ) === false ) return $caps;
        $post = isset( $args[0] ) ? get_post( $args[0] ) : null;
        if ( $post && $post->post_type === 'flms_friendly_app' && (int) $post->post_author !== (int) $user_id ) return $caps;
        return [ 'read' ];
    }

    /** Prevent caching of inbox so My Requests / My Slots always show fresh data after Request to Play. */
    public function inbox_no_cache() {
        if ( is_user_logged_in() && isset( $_GET['inbox_tab'] ) ) {
            nocache_headers();
        }
    }

    /** Grant flms_friendly_app caps to any logged-in user so Request to Play works and My Requests / My Slots can list them. */
    public function grant_friendly_application_caps( $allcaps, $caps, $args, $user ) {
        if ( ! $user || ! isset( $user->ID ) || ! is_user_logged_in() || (int) $user->ID !== get_current_user_id() ) return $allcaps;
        $app_caps = [
            'edit_flms_friendly_apps', 'publish_flms_friendly_apps', 'edit_flms_friendly_app', 'edit_published_flms_friendly_apps',
            'read_flms_friendly_apps', 'read_private_flms_friendly_apps',
        ];
        foreach ( $app_caps as $cap ) {
            $allcaps[ $cap ] = true;
        }
        return $allcaps;
    }

    // ---------- HELPERS ----------

    /** @return int[] Team IDs where current user is author (manager) */
    public static function get_current_manager_team_ids() {
        if ( ! is_user_logged_in() ) return [];
        $user_id = get_current_user_id();
        $posts = get_posts([
            'post_type'      => 'flms_team',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'any',
        ]);
        return $posts;
    }

    /** @return int[] Single-element array with the first (oldest) team the current manager created, or empty */
    public static function get_first_manager_team_id() {
        if ( ! is_user_logged_in() ) return [];
        $user_id = get_current_user_id();
        $posts = get_posts([
            'post_type'      => 'flms_team',
            'author'         => $user_id,
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'post_status'    => 'any',
        ]);
        return $posts;
    }

    /** @return string Manager email for a team (post_author) */
    public static function get_team_manager_email( $team_id ) {
        $team = get_post( $team_id );
        if ( ! $team || $team->post_type !== 'flms_team' ) return '';
        $user = get_userdata( $team->post_author );
        return $user ? $user->user_email : '';
    }

    /** @return int Player count for team */
    public static function get_team_player_count( $team_id ) {
        $players = get_posts([
            'post_type'      => 'flms_player',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => 'flms_team_id',
            'meta_value'     => $team_id,
        ]);
        return count( $players );
    }

    /** @return int Friendly points for team (meta flms_friendly_points) */
    public static function get_team_friendly_points( $team_id ) {
        return (int) get_post_meta( $team_id, 'flms_friendly_points', true );
    }

    /** WooCommerce product ID used for friendly match fee (add-to-cart). */
    public static function get_friendly_fee_product_id() {
        return defined( 'FLMS_FRIENDLY_FEE_PRODUCT_ID' ) ? (int) FLMS_FRIENDLY_FEE_PRODUCT_ID : 23058;
    }

    /** Product IDs that skip the participation consent modal before payment. */
    public static function get_consent_exempt_product_ids() {
        return apply_filters( 'flms_friendly_consent_exempt_product_ids', [ 23058, 19182, 16022 ] );
    }

    /** Whether the current fee product requires ticking consent before checkout. */
    public static function friendly_fee_consent_required() {
        $pid = self::get_friendly_fee_product_id();
        return ! in_array( $pid, self::get_consent_exempt_product_ids(), true );
    }

    /** @return bool */
    public static function friendly_match_has_any_payment( $friendly_id ) {
        $paid_host = get_post_meta( $friendly_id, '_flms_friendly_paid_host', true ) === 'yes';
        $paid_away = get_post_meta( $friendly_id, '_flms_friendly_paid_away', true ) === 'yes';
        return $paid_host || $paid_away;
    }

    public static function get_friendly_pay_consent_transient_key( $friendly_id, $team_id ) {
        $uid = get_current_user_id();
        return 'flms_fpay_ok_' . md5( (string) $uid . '|' . (int) $friendly_id . '|' . (int) $team_id );
    }

    public static function set_friendly_pay_consent_ok( $friendly_id, $team_id ) {
        set_transient( self::get_friendly_pay_consent_transient_key( $friendly_id, $team_id ), 1, 15 * MINUTE_IN_SECONDS );
    }

    public static function verify_friendly_pay_consent_ok( $friendly_id, $team_id ) {
        return (bool) get_transient( self::get_friendly_pay_consent_transient_key( $friendly_id, $team_id ) );
    }

    public static function clear_friendly_pay_consent_ok( $friendly_id, $team_id ) {
        delete_transient( self::get_friendly_pay_consent_transient_key( $friendly_id, $team_id ) );
    }

    /** Meta keys: host sets at create; both sides can update on match page. Stored as #rrggbb; default white. */
    public static function sanitize_jersey_color( $raw ) {
        $s = is_string( $raw ) ? trim( $raw ) : '';
        if ( preg_match( '/^#([0-9A-Fa-f]{6})$/', $s ) ) {
            return '#' . strtolower( substr( $s, 1 ) );
        }
        if ( preg_match( '/^#([0-9A-Fa-f]{3})$/', $s ) ) {
            $x = substr( $s, 1 );
            return '#' . strtolower( $x[0] . $x[0] . $x[1] . $x[1] . $x[2] . $x[2] );
        }
        return '#ffffff';
    }

    /** Normalized hex for display and inputs; missing/invalid meta → white. */
    public static function get_effective_jersey_color( $meta_value ) {
        return self::sanitize_jersey_color( is_string( $meta_value ) ? $meta_value : '' );
    }

    /** Friendly fee allowed for these statuses (after host accepts opponent, admin may still be pending). */
    public static function friendly_status_allows_fee_payment( $status ) {
        return in_array( $status, [ 'pending_admin', 'approved', 'completed' ], true );
    }

    /** HTML: swatch + label (White or #hex). */
    public static function format_jersey_color_display( $color ) {
        $hex = self::get_effective_jersey_color( $color );
        $label = ( strtolower( $hex ) === '#ffffff' ) ? __( 'White', 'flms' ) : strtoupper( $hex );
        return '<span class="flms-jersey-color"><span class="flms-jersey-swatch" style="background-color:' . esc_attr( $hex ) . ';" title="' . esc_attr( $hex ) . '"></span> <span class="flms-jersey-label">' . esc_html( $label ) . '</span></span>';
    }

    /**
     * Pay control: direct link if consent exempt, else button opening modal.
     *
     * @param string $label Button/link text.
     * @param string $extra_class Extra CSS classes for the control.
     */
    public static function render_friendly_pay_button( $friendly_id, $team_id, $label = 'Pay RM500', $extra_class = '' ) {
        self::enqueue_friendly_ui_assets();
        if ( ! self::friendly_fee_consent_required() ) {
            return '<a href="' . esc_url( self::get_friendly_pay_url( $friendly_id, $team_id ) ) . '" class="button btn-gold ' . esc_attr( $extra_class ) . '">' . esc_html( $label ) . '</a>';
        }
        return '<button type="button" class="button btn-gold flms-friendly-pay-trigger ' . esc_attr( $extra_class ) . '" data-friendly-id="' . (int) $friendly_id . '" data-team-id="' . (int) $team_id . '">' . esc_html( $label ) . '</button>';
    }

    /** Enqueue friendly UI script when shortcodes or pay buttons run (late enqueue is OK before wp_footer). */
    public static function enqueue_friendly_ui_assets() {
        self::$friendly_ui_needed = true;
        if ( ! wp_script_is( 'flms-friendly-ui', 'enqueued' ) ) {
            wp_enqueue_script(
                'flms-friendly-ui',
                FLMS_URL . 'assets/js/flms-friendly.js',
                [],
                defined( 'FLMS_VERSION' ) ? FLMS_VERSION : '1.0',
                true
            );
        }
    }

    /** Pay link for friendly fee (RM500). Product ID from constant. */
    public static function get_friendly_pay_url( $friendly_id, $team_id ) {
        return add_query_arg( [
            'flms_friendly_pay' => 1,
            'friendly_id'       => (int) $friendly_id,
            'team_id'           => (int) $team_id,
        ], home_url( '/' ) );
    }

    /** URL for the friendly match details page (shortcode [flms_friendly_match]). */
    public static function get_friendly_match_details_url( $friendly_id ) {
        $base = apply_filters( 'flms_friendly_match_details_base_url', home_url( '/friendly-match/' ) );
        return add_query_arg( 'friendly_id', (int) $friendly_id, $base );
    }

    /** Inbox page URL (friendly shortcodes / redirects). Filter: flms_friendly_inbox_url */
    public static function get_friendly_inbox_url() {
        return apply_filters( 'flms_friendly_inbox_url', home_url( '/inbox/' ) );
    }

    /** Whether current user can view this friendly match (host/opponent manager, admin, or referee). */
    public function user_can_view_friendly_match( $friendly_id ) {
        if ( ! is_user_logged_in() ) return false;
        if ( current_user_can( 'edit_post', $friendly_id ) ) return true;
        $user = wp_get_current_user();
        if ( in_array( 'referee', (array) $user->roles, true ) ) return true;
        $host_id = (int) get_post_meta( $friendly_id, 'flms_host_team_id', true );
        $chosen_id = (int) get_post_meta( $friendly_id, 'flms_chosen_team_id', true );
        $uid = get_current_user_id();
        $friendly_post = get_post( $friendly_id );
        if ( $friendly_post && (int) $friendly_post->post_author === $uid ) {
            return true;
        }
        $host_post = $host_id ? get_post( $host_id ) : null;
        $chosen_post = $chosen_id ? get_post( $chosen_id ) : null;
        if ( $host_post && $host_post->post_type === 'flms_team' && (int) $host_post->post_author === $uid ) return true;
        if ( $chosen_post && $chosen_post->post_type === 'flms_team' && (int) $chosen_post->post_author === $uid ) return true;
        global $wpdb;
        $app_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_author = %d LIMIT 1",
            'flms_friendly_app',
            $friendly_id,
            $uid
        ) );
        if ( $app_id ) {
            $st = get_post_meta( $app_id, 'flms_app_status', true ) ?: 'pending';
            if ( in_array( $st, [ 'pending', 'accepted' ], true ) ) {
                return true;
            }
        }
        return false;
    }

    /** Apply +3 win, +1 draw, -1 loss to both teams. */
    public static function apply_friendly_result( $host_team_id, $away_team_id, $host_score, $away_score ) {
        $host_pts = self::get_team_friendly_points( $host_team_id );
        $away_pts = self::get_team_friendly_points( $away_team_id );
        if ( $host_score > $away_score ) {
            $host_pts += 3;
            $away_pts -= 1;
        } elseif ( $away_score > $host_score ) {
            $away_pts += 3;
            $host_pts -= 1;
        } else {
            $host_pts += 1;
            $away_pts += 1;
        }
        update_post_meta( $host_team_id, 'flms_friendly_points', $host_pts );
        update_post_meta( $away_team_id, 'flms_friendly_points', $away_pts );
    }

    // ---------- SHORTCODE: CREATE FRIENDLY ----------

    public function shortcode_create() {
        if ( ! is_user_logged_in() ) {
            return '<div class="flms-dashboard-wrapper flms-friendly-create dark-mode"><p class="flms-error">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" style="color:var(--flms-gold);">login</a> to create a friendly match request.</p></div>';
        }
        $team_ids = self::get_current_manager_team_ids();
        if ( empty( $team_ids ) ) {
            return '<div class="flms-dashboard-wrapper flms-friendly-create dark-mode"><p class="flms-error">You need to have a team to create a friendly match. Create or register a team first.</p></div>';
        }

        $msg = isset( $_GET['flms_friendly_msg'] ) ? sanitize_text_field( $_GET['flms_friendly_msg'] ) : '';
        $success = ( $msg === 'created' );
        $manager_url       = apply_filters( 'flms_manager_dashboard_url', home_url( '/my-team-dashboard/' ) );
        $inbox_url         = self::get_friendly_inbox_url();
        $create_url        = get_permalink();
        $dashboard_url     = esc_url( add_query_arg( 'mgr_view', 'dashboard', $manager_url ) );
        $players_url       = esc_url( add_query_arg( 'mgr_view', 'players', $manager_url ) );
        $settings_url      = esc_url( add_query_arg( 'mgr_view', 'settings', $manager_url ) );
        $match_fees_url    = esc_url( add_query_arg( 'mgr_view', 'dashboard', $manager_url ) . '#flms-mgr-fees' );
        $friendly_view_url = esc_url( add_query_arg( 'mgr_view', 'friendly', $manager_url ) );
        $league_view_url   = esc_url( add_query_arg( 'mgr_view', 'league', $manager_url ) );

        ob_start();
        ?>
        <div class="flms-dashboard-wrapper flms-mgr-dashboard flms-friendly-create dark-mode">
            <div class="flms-mgr-shell">
                <aside class="flms-mgr-shell__sidebar">
                    <h2 class="flms-mgr-sidebar__title"><?php esc_html_e( 'Manager Menu', 'flms' ); ?></h2>
                    <nav class="flms-mgr-sidebar__nav" aria-label="<?php esc_attr_e( 'Manager navigation', 'flms' ); ?>">
                        <a class="flms-mgr-sidebar__link" href="<?php echo $dashboard_url; ?>"><?php esc_html_e( 'Dashboard', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link" href="<?php echo $players_url; ?>"><?php esc_html_e( 'Players', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link is-active" href="<?php echo esc_url( $create_url ); ?>"><?php esc_html_e( 'Create Friendly Match', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link" href="<?php echo esc_url( $inbox_url ); ?>"><?php esc_html_e( 'Inbox', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link" href="<?php echo $settings_url; ?>"><?php esc_html_e( 'Team Settings', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link" href="<?php echo $match_fees_url; ?>"><?php esc_html_e( 'Match Fees', 'flms' ); ?></a>
                        <div class="flms-mgr-sidebar__group">
                            <span class="flms-mgr-sidebar__group-label"><?php esc_html_e( 'Matches', 'flms' ); ?></span>
                            <a class="flms-mgr-sidebar__sublink" href="<?php echo $friendly_view_url; ?>"><?php esc_html_e( 'Friendly', 'flms' ); ?></a>
                            <a class="flms-mgr-sidebar__sublink" href="<?php echo $league_view_url; ?>"><?php esc_html_e( 'League', 'flms' ); ?></a>
                        </div>
                    </nav>
                </aside>
                <div class="flms-mgr-shell__main">
                    <section class="flms-mgr-card">
                        <h2 class="flms-mgr-card__title"><?php esc_html_e( 'Create Friendly Match Request', 'flms' ); ?></h2>
                        <p class="flms-mgr-card__hint"><?php esc_html_e( 'Enter the date, time and place. Other team managers will see this in inbox and can request to play against you.', 'flms' ); ?></p>
                        <?php if ( $success ) : ?>
                            <div class="flms-notice flms-notice-success">Your friendly match request has been submitted. Other managers can now request to play.</div>
                        <?php endif; ?>
                        <form method="post" class="flms-friendly-form">
                            <input type="hidden" name="flms_action" value="friendly_create">
                            <?php wp_nonce_field( 'flms_friendly_create' ); ?>
                            <div class="flms-form-grid">
                                <div class="form-group">
                                    <label>Your Team</label>
                                    <select name="host_team_id" required>
                                        <?php foreach ( $team_ids as $tid ) : $t = get_post( $tid ); if ( ! $t ) continue; ?>
                                            <option value="<?php echo (int) $tid; ?>"><?php echo esc_html( $t->post_title ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Date</label>
                                    <input type="date" name="friendly_date" required>
                                </div>
                                <div class="form-group">
                                    <label>Time</label>
                                    <input type="time" name="friendly_time" required>
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label>Place / Venue</label>
                                    <?php
                                    $venue_terms = get_terms( [ 'taxonomy' => 'flms_venue', 'hide_empty' => false ] );
                                    if ( ! empty( $venue_terms ) && ! is_wp_error( $venue_terms ) ) : ?>
                                        <select name="friendly_place" required>
                                            <option value="">Select venue</option>
                                            <?php foreach ( $venue_terms as $v ) : ?>
                                                <option value="<?php echo esc_attr( $v->name ); ?>"><?php echo esc_html( $v->name ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else : ?>
                                        <input type="text" name="friendly_place" required placeholder="e.g. Main Stadium, City">
                                    <?php endif; ?>
                                </div>
                                <div class="form-group flms-kit-color-field" style="grid-column: 1 / -1;">
                                    <label for="flms_host_jersey_color"><?php esc_html_e( 'Your team jersey colour', 'flms' ); ?></label>
                                    <input type="color" name="host_jersey_color" id="flms_host_jersey_color" value="#ffffff" class="flms-kit-color-input" aria-label="<?php esc_attr_e( 'Your team jersey colour', 'flms' ); ?>">
                                    <span class="flms-field-hint"><?php esc_html_e( 'Pick a colour (default is white). Shown to opponents in open requests.', 'flms' ); ?></span>
                                </div>
                            </div>
                            <p style="margin-top:15px;">
                                <button type="submit" class="button button-primary btn-gold">Submit Request</button>
                            </p>
                        </form>
                    </section>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ---------- SHORTCODE: INBOX ----------

    public function shortcode_inbox() {
        if ( ! is_user_logged_in() ) {
            return '<div class="flms-dashboard-wrapper flms-friendly-inbox dark-mode"><p class="flms-error">Please login to view your inbox.</p></div>';
        }
        self::enqueue_friendly_ui_assets();
        $user_id = get_current_user_id();
        $team_ids = self::get_current_manager_team_ids();
        if ( empty( $team_ids ) ) {
            return '<div class="flms-dashboard-wrapper flms-friendly-inbox dark-mode"><p class="flms-error">You need a team to use the friendly match inbox.</p></div>';
        }

        $tab = isset( $_GET['inbox_tab'] ) ? sanitize_text_field( $_GET['inbox_tab'] ) : 'open';
        $allowed = [ 'open', 'my_requests', 'my_slots', 'notifications' ];
        if ( ! in_array( $tab, $allowed, true ) ) $tab = 'open';

        // Counts for tab badges
        $open_requests_count = $this->get_open_requests_count( $user_id, $team_ids );
        $my_requests_count = $this->get_my_requests_count( $user_id );
        $my_slots_count = $this->get_my_slots_count( $user_id, $team_ids );
        $friendly_notifications_count = $this->get_friendly_notifications_count( $user_id );
        // Badge should include active inbox announcements too.
        $now_ts = current_time( 'timestamp' );
        $announcement_count = count( $this->get_active_inbox_announcements( $now_ts ) );
        $friendly_notifications_count += (int) $announcement_count;
        $manager_url       = apply_filters( 'flms_manager_dashboard_url', home_url( '/my-team-dashboard/' ) );
        $inbox_url         = self::get_friendly_inbox_url();
        $create_url        = apply_filters( 'flms_friendly_create_url', home_url( '/create-friendly/' ) );
        $dashboard_url     = esc_url( add_query_arg( 'mgr_view', 'dashboard', $manager_url ) );
        $players_url       = esc_url( add_query_arg( 'mgr_view', 'players', $manager_url ) );
        $settings_url      = esc_url( add_query_arg( 'mgr_view', 'settings', $manager_url ) );
        $match_fees_url    = esc_url( add_query_arg( 'mgr_view', 'dashboard', $manager_url ) . '#flms-mgr-fees' );
        $friendly_view_url = esc_url( add_query_arg( 'mgr_view', 'friendly', $manager_url ) );
        $league_view_url   = esc_url( add_query_arg( 'mgr_view', 'league', $manager_url ) );

        ob_start();
        ?>
        <div class="flms-dashboard-wrapper flms-mgr-dashboard flms-friendly-inbox dark-mode">
            <div class="flms-mgr-shell">
                <aside class="flms-mgr-shell__sidebar">
                    <h2 class="flms-mgr-sidebar__title"><?php esc_html_e( 'Manager Menu', 'flms' ); ?></h2>
                    <nav class="flms-mgr-sidebar__nav" aria-label="<?php esc_attr_e( 'Manager navigation', 'flms' ); ?>">
                        <a class="flms-mgr-sidebar__link" href="<?php echo $dashboard_url; ?>"><?php esc_html_e( 'Dashboard', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link" href="<?php echo $players_url; ?>"><?php esc_html_e( 'Players', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link" href="<?php echo esc_url( $create_url ); ?>"><?php esc_html_e( 'Create Friendly Match', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link is-active" href="<?php echo esc_url( $inbox_url ); ?>"><?php esc_html_e( 'Inbox', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link" href="<?php echo $settings_url; ?>"><?php esc_html_e( 'Team Settings', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link" href="<?php echo $match_fees_url; ?>"><?php esc_html_e( 'Match Fees', 'flms' ); ?></a>
                        <div class="flms-mgr-sidebar__group">
                            <span class="flms-mgr-sidebar__group-label"><?php esc_html_e( 'Matches', 'flms' ); ?></span>
                            <a class="flms-mgr-sidebar__sublink" href="<?php echo $friendly_view_url; ?>"><?php esc_html_e( 'Friendly', 'flms' ); ?></a>
                            <a class="flms-mgr-sidebar__sublink" href="<?php echo $league_view_url; ?>"><?php esc_html_e( 'League', 'flms' ); ?></a>
                        </div>
                    </nav>
                </aside>
                <div class="flms-mgr-shell__main">
                    <section class="flms-mgr-card">
            <h2 class="flms-mgr-card__title">Friendly Match Inbox</h2>
            <div class="flms-tabs-filter-bar">
                <a href="<?php echo esc_url( add_query_arg( 'inbox_tab', 'open' ) ); ?>" class="tab-filter-btn <?php echo $tab === 'open' ? 'active' : ''; ?>">
                    Open Requests
                    <span class="flms-tab-badge"><?php echo (int) $open_requests_count; ?></span>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'inbox_tab', 'my_requests' ) ); ?>" class="tab-filter-btn <?php echo $tab === 'my_requests' ? 'active' : ''; ?>">
                    My Requests
                    <span class="flms-tab-badge"><?php echo (int) $my_requests_count; ?></span>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'inbox_tab', 'my_slots' ) ); ?>" class="tab-filter-btn <?php echo $tab === 'my_slots' ? 'active' : ''; ?>">
                    My Slots
                    <span class="flms-tab-badge"><?php echo (int) $my_slots_count; ?></span>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'inbox_tab', 'notifications' ) ); ?>" class="tab-filter-btn <?php echo $tab === 'notifications' ? 'active' : ''; ?>" title="Includes accepted/rejected + admin confirmations and active announcements">
                    Notifications
                    <span class="flms-tab-badge"><?php echo (int) $friendly_notifications_count; ?></span>
                </a>
            </div>

            <?php
            $inbox_msg = isset( $_GET['flms_friendly_msg'] ) ? sanitize_text_field( $_GET['flms_friendly_msg'] ) : '';
            if ( $inbox_msg === 'friendly_request_sent' ) : ?>
                <div class="flms-notice flms-notice-success">Your request to play has been sent.</div>
            <?php elseif ( $inbox_msg === 'friendly_accepted' ) : ?>
                <div class="flms-notice flms-notice-success">You have accepted the request. Waiting for admin approval.</div>
            <?php elseif ( $inbox_msg === 'request_withdrawn' ) : ?>
                <div class="flms-notice flms-notice-success">Your request has been withdrawn.</div>
            <?php elseif ( $inbox_msg === 'match_cancelled' ) : ?>
                <div class="flms-notice flms-notice-success">The match has been cancelled.</div>
            <?php elseif ( $inbox_msg === 'opponent_withdraw_slot_reopened' ) : ?>
                <div class="flms-notice flms-notice-success">You have withdrawn. The host&rsquo;s slot is <strong>open</strong> again — other managers can request to play.</div>
            <?php endif; ?>
            <?php
            $inbox_err = isset( $_GET['flms_friendly_error'] ) ? sanitize_text_field( wp_unslash( $_GET['flms_friendly_error'] ) ) : '';
            if ( $inbox_err === 'cancel_payment' ) :
                ?>
                <div class="flms-notice flms-notice-error">This match cannot be cancelled because a payment has been recorded.</div>
            <?php elseif ( $inbox_err === 'cannot_cancel_completed' ) : ?>
                <div class="flms-notice flms-notice-error">Completed matches cannot be cancelled.</div>
            <?php elseif ( $inbox_err === 'withdraw_requires_no_payment' ) : ?>
                <div class="flms-notice flms-notice-error">You cannot withdraw while a payment has been recorded for this match.</div>
            <?php endif; ?>

            <?php if ( $tab === 'open' ) : ?>
                <?php echo $this->render_open_requests( $team_ids ); ?>
            <?php elseif ( $tab === 'my_requests' ) : ?>
                <?php echo $this->render_my_requests( $user_id ); ?>
            <?php elseif ( $tab === 'my_slots' ) : ?>
                <?php echo $this->render_my_slots( $user_id ); ?>
            <?php else : ?>
                <?php echo $this->render_notifications( $user_id, $team_ids ); ?>
            <?php endif; ?>
                    </section>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_open_requests_count( $user_id, $my_team_ids ) {
        $today = current_time( 'Y-m-d' );
        $count = 0;

        $slot_ids = get_posts([
            'post_type'      => 'flms_friendly',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => 'flms_friendly_status',
                    'value'   => 'open',
                    'compare' => '=',
                ],
            ],
        ]);

        foreach ( (array) $slot_ids as $sid ) {
            $host_team_id = (int) get_post_meta( $sid, 'flms_host_team_id', true );
            if ( (int) get_post_field( 'post_author', $sid ) === (int) $user_id ) continue; // skip slots I created
            if ( $host_team_id && in_array( $host_team_id, $my_team_ids, true ) ) continue; // skip if my team is host

            $date = get_post_meta( $sid, 'flms_friendly_date', true );
            if ( $date && $date < $today ) continue;

            $count++;
        }

        return (int) $count;
    }

    private function get_my_requests_count( $user_id ) {
        global $wpdb;
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d",
            'flms_friendly_app',
            (int) $user_id
        ) );
        return (int) $count;
    }

    private function get_my_slots_count( $user_id, $my_team_ids ) {
        $slot_ids = get_posts([
            'post_type'      => 'flms_friendly',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);

        if ( ! empty( $my_team_ids ) ) {
            $slots_where_im_host = get_posts([
                'post_type'      => 'flms_friendly',
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'meta_query'     => [
                    [ 'key' => 'flms_host_team_id', 'value' => $my_team_ids, 'compare' => 'IN' ],
                ],
            ]);
            $slot_ids = array_unique( array_merge( (array) $slot_ids, (array) $slots_where_im_host ) );
        }

        return (int) count( (array) $slot_ids );
    }

    private function get_friendly_notifications_count( $user_id ) {
        $count = 0;
        global $wpdb;

        // Accepted / rejected requests (from flms_friendly_app)
        $app_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d ORDER BY post_date DESC LIMIT 50",
            'flms_friendly_app',
            (int) $user_id
        ) );

        foreach ( (array) $app_ids as $aid ) {
            $aid = (int) $aid;
            if ( ! $aid ) continue;
            $status = get_post_meta( $aid, 'flms_app_status', true ) ?: 'pending';
            if ( $status === 'pending' ) continue;
            $count++;
        }

        // Admin confirmations for slots created by this user
        $slots = get_posts([
            'post_type'      => 'flms_friendly',
            'author'         => $user_id,
            'posts_per_page' => 50,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);
        foreach ( (array) $slots as $sid ) {
            $sid = (int) $sid;
            if ( ! $sid ) continue;
            $status = get_post_meta( $sid, 'flms_friendly_status', true ) ?: 'open';
            if ( $status === 'approved' ) $count++;
        }

        // Admin confirmations for accepted apps where the friendly is approved
        foreach ( (array) $app_ids as $aid ) {
            $aid = (int) $aid;
            if ( ! $aid ) continue;
            $status = get_post_meta( $aid, 'flms_app_status', true ) ?: 'pending';
            if ( $status !== 'accepted' ) continue;

            $fid = (int) get_post_field( 'post_parent', $aid );
            if ( ! $fid ) continue;

            $friendly_status = get_post_meta( $fid, 'flms_friendly_status', true ) ?: 'open';
            if ( $friendly_status === 'approved' ) $count++;
        }

        return (int) $count;
    }

    private function render_open_requests( $my_team_ids ) {
        $current_user_id = get_current_user_id();
        $slots = get_posts([
            'post_type'      => 'flms_friendly',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => 'flms_friendly_status',
                    'value'   => 'open',
                    'compare' => '=',
                ],
            ],
        ]);
        $today = current_time( 'Y-m-d' );
        $out = [];
        foreach ( $slots as $s ) {
            $host_team_id = (int) get_post_meta( $s->ID, 'flms_host_team_id', true );
            if ( (int) $s->post_author === $current_user_id ) continue; // skip slots I created
            if ( $host_team_id && in_array( $host_team_id, $my_team_ids, true ) ) continue; // skip if my team is host
            $date = get_post_meta( $s->ID, 'flms_friendly_date', true );
            if ( $date && $date < $today ) continue;
            $out[] = $s;
        }
        if ( empty( $out ) ) {
            return '<div class="flms-empty-box">No open friendly match requests from other managers at the moment.</div>';
        }
        $applicant_team_ids = self::get_first_manager_team_id();
        ob_start();
        ?>
        <div class="flms-table-responsive">
            <table class="flms-league-table">
                <thead>
                    <tr>
                        <th>Host Team</th>
                        <th><?php esc_html_e( 'Host kit', 'flms' ); ?></th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Place</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $out as $s ) :
                        $host_id = (int) get_post_meta( $s->ID, 'flms_host_team_id', true );
                        $host_name = $host_id ? get_the_title( $host_id ) : '—';
                        $host_link = $host_id ? get_permalink( $host_id ) : '#';
                        $date = get_post_meta( $s->ID, 'flms_friendly_date', true );
                        $time = get_post_meta( $s->ID, 'flms_friendly_time', true );
                        $place = get_post_meta( $s->ID, 'flms_friendly_place', true );
                        $host_kit = get_post_meta( $s->ID, 'flms_host_jersey_color', true );
                    ?>
                    <tr>
                        <td><?php echo $host_id ? '<a href="' . esc_url( $host_link ) . '">' . esc_html( $host_name ) . '</a>' : '<span title="Admin: set Host Team in Friendly post">' . esc_html( $host_name ) . '</span>'; ?></td>
                        <td><?php echo self::format_jersey_color_display( is_string( $host_kit ) ? $host_kit : '' ); ?></td>
                        <td><?php echo esc_html( $date ? date( 'd M Y', strtotime( $date ) ) : '-' ); ?></td>
                        <td><?php echo esc_html( $time ?: '-' ); ?></td>
                        <td><?php echo esc_html( $place ?: '-' ); ?></td>
                        <td>
                            <?php if ( ! empty( $applicant_team_ids ) ) :
                                $first_tid = $applicant_team_ids[0];
                                $first_team = get_post( $first_tid );
                                if ( $first_team ) : ?>
                            <form method="post" action="" class="flms-open-request-action" style="display:inline-flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                <input type="hidden" name="flms_action" value="friendly_request_play">
                                <input type="hidden" name="friendly_id" value="<?php echo (int) $s->ID; ?>">
                                <input type="hidden" name="applicant_team_id" value="<?php echo (int) $first_tid; ?>">
                                <?php wp_nonce_field( 'flms_friendly_request' ); ?>
                                <span class="flms-your-team-label">Your team: <strong><?php echo esc_html( $first_team->post_title ); ?></strong></span>
                                <button type="submit" class="button button-primary btn-blue">Request to Play</button>
                            </form>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="flms-muted">No team to request with.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_my_requests( $user_id ) {
        // Query directly (raw rows) so listing is not blocked by CPT caps or get_post() filters
        global $wpdb;
        $apps = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d ORDER BY post_date DESC",
            'flms_friendly_app',
            $user_id
        ) );
        if ( empty( $apps ) ) {
            return '<div class="flms-empty-box">You have not sent any friendly match requests.</div>';
        }
        ob_start();
        ?>
        <div class="flms-table-responsive">
            <table class="flms-league-table">
                <thead>
                    <tr><th>Host Team</th><th>Date / Time / Place</th><th>Your Team</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ( $apps as $app ) :
                        $fid = (int) $app->post_parent;
                        $friendly = get_post( $fid );
                        if ( ! $friendly ) continue;
                        $host_id = (int) get_post_meta( $fid, 'flms_host_team_id', true );
                        $status = get_post_meta( $app->ID, 'flms_app_status', true ) ?: 'pending';
                        $my_team_id = (int) get_post_meta( $app->ID, 'flms_app_team_id', true );
                        $friendly_status = get_post_meta( $fid, 'flms_friendly_status', true ) ?: 'open';
                        $paid_host = get_post_meta( $fid, '_flms_friendly_paid_host', true ) === 'yes';
                        $paid_away = get_post_meta( $fid, '_flms_friendly_paid_away', true ) === 'yes';
                        $chosen_id = (int) get_post_meta( $fid, 'flms_chosen_team_id', true );
                        $can_view_match = $this->user_can_view_friendly_match( $fid );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( get_permalink( $host_id ) ); ?>"><?php echo esc_html( get_the_title( $host_id ) ); ?></a></td>
                        <td>
                            <?php
                            echo esc_html( get_post_meta( $fid, 'flms_friendly_date', true ) ? date( 'd M Y', strtotime( get_post_meta( $fid, 'flms_friendly_date', true ) ) ) : '-' );
                            echo ' ' . esc_html( get_post_meta( $fid, 'flms_friendly_time', true ) ?: '' );
                            echo ' @ ' . esc_html( get_post_meta( $fid, 'flms_friendly_place', true ) ?: '-' );
                            ?>
                        </td>
                        <td><?php echo esc_html( get_the_title( $my_team_id ) ); ?></td>
                        <td>
                            <?php
                            if ( $status === 'accepted' ) {
                                echo '<span class="flms-status-badge status-accepted">Accepted</span>';
                            } elseif ( $status === 'rejected' ) {
                                echo '<span class="flms-status-badge status-rejected">Rejected</span>';
                            } elseif ( $status === 'withdrawn' ) {
                                echo '<span class="flms-status-badge status-completed">Withdrawn</span>';
                            } else {
                                echo '<span class="flms-status-badge status-pending">Pending</span>';
                            }
                            ?>
                        </td>
                        <td class="flms-actions-cell">
                            <?php if ( $can_view_match ) : ?>
                                <a class="button btn-tiny" href="<?php echo esc_url( self::get_friendly_match_details_url( $fid ) ); ?>">View match</a>
                            <?php endif; ?>
                            <?php
                            $can_withdraw_pending = $status === 'pending' && $friendly_status === 'open' && ! self::friendly_match_has_any_payment( $fid );
                            $can_withdraw_after_accept = $status === 'accepted'
                                && $friendly_status === 'pending_admin'
                                && $chosen_id
                                && (int) $my_team_id === (int) $chosen_id
                                && ! self::friendly_match_has_any_payment( $fid );
                            if ( $can_withdraw_pending || $can_withdraw_after_accept ) :
                                $confirm_msg = $can_withdraw_after_accept
                                    ? 'Withdraw from this match? The slot will reopen for other teams (before admin approval).'
                                    : 'Withdraw your request to play?';
                                ?>
                                <form method="post" action="" style="display:inline-block;margin-left:6px;" onsubmit="return confirm('<?php echo esc_js( $confirm_msg ); ?>');">
                                    <input type="hidden" name="flms_action" value="friendly_withdraw_request">
                                    <input type="hidden" name="friendly_id" value="<?php echo (int) $fid; ?>">
                                    <input type="hidden" name="application_id" value="<?php echo (int) $app->ID; ?>">
                                    <?php wp_nonce_field( 'flms_friendly_withdraw' ); ?>
                                    <button type="submit" class="button btn-tiny" style="background:#666;border-color:#666;color:#fff;"><?php echo $can_withdraw_after_accept ? esc_html__( 'Withdraw & reopen slot', 'flms' ) : esc_html__( 'Withdraw', 'flms' ); ?></button>
                                </form>
                                <?php
                            endif;
                            // Payment button (host OR opponent) in My Requests, so opponent can easily pay from here too.
                            if ( self::friendly_status_allows_fee_payment( $friendly_status ) ) {
                                if ( $chosen_id && (int) $my_team_id === (int) $chosen_id && ! $paid_away ) {
                                    echo ' ' . self::render_friendly_pay_button( $fid, $my_team_id );
                                } elseif ( (int) $my_team_id === (int) $host_id && ! $paid_host ) {
                                    echo ' ' . self::render_friendly_pay_button( $fid, $my_team_id );
                                }
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_my_slots( $user_id ) {
        $my_team_ids = self::get_current_manager_team_ids();
        $slots_by_author = get_posts([
            'post_type'      => 'flms_friendly',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ]);
        $slot_ids = wp_list_pluck( $slots_by_author, 'ID' );
        if ( ! empty( $my_team_ids ) ) {
            $slots_where_im_host = get_posts([
                'post_type'      => 'flms_friendly',
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'meta_query'     => [
                    [ 'key' => 'flms_host_team_id', 'value' => $my_team_ids, 'compare' => 'IN' ],
                ],
            ]);
            foreach ( $slots_where_im_host as $s ) {
                if ( ! in_array( $s->ID, $slot_ids, true ) ) {
                    $slot_ids[] = $s->ID;
                    $slots_by_author[] = $s;
                }
            }
        }
        $slots = $slots_by_author;
        if ( empty( $slots ) ) {
            return '<div class="flms-empty-box">You have not created any friendly match slots, and no slots where your team is the host.</div>';
        }
        $intro = '<p class="flms-slot-intro">Slots you created or where your team is the host. <strong>Open slots with requests appear below — accept one to confirm the match.</strong></p>';
        ob_start();
        echo $intro;
        foreach ( $slots as $s ) {
            $status = get_post_meta( $s->ID, 'flms_friendly_status', true ) ?: 'open';
            $host_id = (int) get_post_meta( $s->ID, 'flms_host_team_id', true );
            $chosen_id = (int) get_post_meta( $s->ID, 'flms_chosen_team_id', true );
            $date = get_post_meta( $s->ID, 'flms_friendly_date', true );
            $time = get_post_meta( $s->ID, 'flms_friendly_time', true );
            $place = get_post_meta( $s->ID, 'flms_friendly_place', true );

            global $wpdb;
            $applications = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d ORDER BY post_date ASC",
                'flms_friendly_app',
                $s->ID
            ) );
            $pending_apps = array_filter( (array) $applications, function( $app ) {
                return ( get_post_meta( $app->ID, 'flms_app_status', true ) ?: 'pending' ) === 'pending';
            });
            $pending_count = count( $pending_apps );
            ?>
            <div class="flms-friendly-slot-card">
                <div class="flms-slot-header">
                    <strong><?php echo esc_html( get_the_title( $host_id ) ); ?></strong> — <?php echo esc_html( $date ? date( 'd M Y', strtotime( $date ) ) : '' ); ?> <?php echo esc_html( $time ); ?> @ <?php echo esc_html( $place ); ?>
                    <span class="flms-status-badge status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ); ?></span>
                    <?php if ( $status === 'open' && $pending_count > 0 ) : ?>
                        <span class="flms-request-count" style="margin-left:8px; color:#0a0; font-weight:bold;"><?php echo (int) $pending_count; ?> request(s) to play — accept below</span>
                    <?php endif; ?>
                </div>
                <?php if ( $status === 'open' && $pending_count > 0 ) : ?>
                    <p class="flms-slot-sub">Requests from other teams. <strong>Accept one to confirm the match</strong> (others will be rejected).</p>
                    <div class="flms-table-responsive">
                        <table class="flms-league-table">
                            <thead><tr><th>Team</th><th>Players</th><th>Friendly Pts</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php foreach ( $pending_apps as $app ) :
                                    $req_team_id = (int) get_post_meta( $app->ID, 'flms_app_team_id', true );
                                    $req_team = $req_team_id ? get_post( $req_team_id ) : null;
                                    $team_link = $req_team ? get_permalink( $req_team_id ) : '#';
                                    $team_name = $req_team ? $req_team->post_title : 'Team #' . $req_team_id;
                                    $players = $req_team_id ? self::get_team_player_count( $req_team_id ) : 0;
                                    $pts = $req_team_id ? self::get_team_friendly_points( $req_team_id ) : 0;
                                ?>
                                <tr>
                                    <td><?php echo $req_team ? '<a href="' . esc_url( $team_link ) . '">' . esc_html( $team_name ) . '</a>' : esc_html( $team_name ); ?></td>
                                    <td><?php echo (int) $players; ?></td>
                                    <td><?php echo (int) $pts; ?></td>
                                    <td>
                                        <form method="post" action="" style="display:inline;">
                                            <input type="hidden" name="flms_action" value="friendly_accept">
                                            <input type="hidden" name="friendly_id" value="<?php echo (int) $s->ID; ?>">
                                            <input type="hidden" name="application_id" value="<?php echo (int) $app->ID; ?>">
                                            <?php wp_nonce_field( 'flms_friendly_accept' ); ?>
                                            <button type="submit" class="button btn-green" name="flms_accept_application" value="<?php echo (int) $app->ID; ?>">Accept</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ( $status === 'open' && ! empty( $applications ) ) : ?>
                    <p class="flms-slot-sub">No pending requests (all were accepted or rejected).</p>
                <?php elseif ( $chosen_id && $status === 'pending_admin' ) : ?>
                    <p class="flms-slot-sub">Waiting for admin approval. Opponent: <a href="<?php echo esc_url( get_permalink( $chosen_id ) ); ?>"><?php echo esc_html( get_the_title( $chosen_id ) ); ?></a>
                        <a href="<?php echo esc_url( self::get_friendly_match_details_url( $s->ID ) ); ?>" class="button btn-tiny" style="margin-left:8px;">View match</a>
                    </p>
                    <?php
                    $paid_host = get_post_meta( $s->ID, '_flms_friendly_paid_host', true ) === 'yes';
                    $paid_away = get_post_meta( $s->ID, '_flms_friendly_paid_away', true ) === 'yes';
                    $my_team_ids_slot = self::get_current_manager_team_ids();
                    if ( in_array( $host_id, $my_team_ids_slot, true ) && ! $paid_host ) :
                        ?><p class="flms-slot-sub"><?php echo self::render_friendly_pay_button( $s->ID, $host_id, 'Pay RM500 (Host)' ); ?></p><?php
                    endif;
                    if ( in_array( $chosen_id, $my_team_ids_slot, true ) && ! $paid_away ) :
                        ?><p class="flms-slot-sub"><?php echo self::render_friendly_pay_button( $s->ID, $chosen_id, 'Pay RM500 (Away)' ); ?></p><?php
                    endif;
                    ?>
                <?php elseif ( $chosen_id && ( $status === 'approved' || $status === 'completed' ) ) : ?>
                    <p class="flms-slot-sub">Match: <?php echo esc_html( get_the_title( $host_id ) ); ?> vs <a href="<?php echo esc_url( get_permalink( $chosen_id ) ); ?>"><?php echo esc_html( get_the_title( $chosen_id ) ); ?></a>
                        <a href="<?php echo esc_url( self::get_friendly_match_details_url( $s->ID ) ); ?>" class="button btn-tiny" style="margin-left:8px;">View match</a>
                    </p>
                    <?php
                    $paid_host = get_post_meta( $s->ID, '_flms_friendly_paid_host', true ) === 'yes';
                    $paid_away = get_post_meta( $s->ID, '_flms_friendly_paid_away', true ) === 'yes';
                    $my_team_ids = self::get_current_manager_team_ids();
                    if ( in_array( $host_id, $my_team_ids, true ) && ! $paid_host ) :
                        ?><p class="flms-slot-sub"><?php echo self::render_friendly_pay_button( $s->ID, $host_id, 'Pay RM500 (Host)' ); ?></p><?php
                    endif;
                    if ( in_array( $chosen_id, $my_team_ids, true ) && ! $paid_away ) :
                        ?><p class="flms-slot-sub"><?php echo self::render_friendly_pay_button( $s->ID, $chosen_id, 'Pay RM500 (Away)' ); ?></p><?php
                    endif;
                    ?>
                <?php elseif ( $status === 'open' ) : ?>
                    <p class="flms-slot-sub">No requests yet. Other managers see this slot under <strong>Open Requests</strong> and can click &quot;Request to Play&quot; — then you will see them here.</p>
                <?php endif; ?>
                <?php
                $is_slot_author = (int) $s->post_author === get_current_user_id();
                $host_team_post = $host_id ? get_post( $host_id ) : null;
                $is_host_manager = $host_team_post && $host_team_post->post_type === 'flms_team' && (int) $host_team_post->post_author === get_current_user_id();
                $can_host_cancel_slot = ( $is_slot_author || $is_host_manager )
                    && ! self::friendly_match_has_any_payment( $s->ID )
                    && in_array( $status, [ 'open', 'pending_admin', 'approved' ], true );
                if ( $can_host_cancel_slot ) :
                    ?>
                    <div class="flms-friendly-host-cancel" style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.08);">
                        <form method="post" action="" onsubmit="return confirm('Cancel this friendly match?');">
                            <input type="hidden" name="flms_action" value="friendly_host_cancel_match">
                            <input type="hidden" name="friendly_id" value="<?php echo (int) $s->ID; ?>">
                            <?php wp_nonce_field( 'flms_friendly_host_cancel' ); ?>
                            <button type="submit" class="button btn-tiny" style="background:#c0392b;border-color:#c0392b;color:#fff;">Cancel match (host)</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    private function render_notifications( $user_id, $team_ids ) {
        $notifications = [];

        // Add active admin announcements to the Notifications tab.
        $now_ts = current_time( 'timestamp' );
        $active_announcements = $this->get_active_inbox_announcements( $now_ts );
        foreach ( $active_announcements as $announcement_item ) {
            $notifications[] = $announcement_item;
        }

        global $wpdb;
        $app_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d ORDER BY post_date DESC LIMIT 50",
            'flms_friendly_app',
            $user_id
        ) );
        $apps = [];
        foreach ( (array) $app_ids as $id ) {
            $p = get_post( (int) $id );
            if ( $p && $p->post_type === 'flms_friendly_app' ) {
                $apps[] = $p;
            }
        }
        foreach ( $apps as $app ) {
            $status = get_post_meta( $app->ID, 'flms_app_status', true ) ?: 'pending';
            if ( $status === 'pending' ) continue;
            $fid = (int) $app->post_parent;
            $friendly = get_post( $fid );
            if ( ! $friendly ) continue;
            $host_id = (int) get_post_meta( $fid, 'flms_host_team_id', true );
            $my_team_id = (int) get_post_meta( $app->ID, 'flms_app_team_id', true );
            $date = get_post_meta( $fid, 'flms_friendly_date', true );
            $time = get_post_meta( $fid, 'flms_friendly_time', true );
            $place = get_post_meta( $fid, 'flms_friendly_place', true );
            $notifications[] = [
                'type'   => $status === 'accepted' ? 'accepted' : 'rejected',
                'host'   => get_the_title( $host_id ),
                'date'   => $date,
                'time'   => $time,
                'place'  => $place,
                'my_team' => get_the_title( $my_team_id ),
            ];
        }
        $slots = get_posts([
            'post_type'   => 'flms_friendly',
            'author'     => $user_id,
            'posts_per_page' => 50,
            'post_status'=> 'any',
        ]);
        foreach ( $slots as $s ) {
            $status = get_post_meta( $s->ID, 'flms_friendly_status', true ) ?: 'open';
            if ( $status === 'approved' ) {
                $chosen_id = (int) get_post_meta( $s->ID, 'flms_chosen_team_id', true );
                $host_id = (int) get_post_meta( $s->ID, 'flms_host_team_id', true );
                $notifications[] = [
                    'type' => 'admin_approved',
                    'host' => get_the_title( $host_id ),
                    'opponent' => $chosen_id ? get_the_title( $chosen_id ) : '',
                    'date' => get_post_meta( $s->ID, 'flms_friendly_date', true ),
                    'time' => get_post_meta( $s->ID, 'flms_friendly_time', true ),
                    'place' => get_post_meta( $s->ID, 'flms_friendly_place', true ),
                    'friendly_id' => $s->ID,
                    'my_team_id' => $host_id,
                ];
            }
        }
        foreach ( $apps as $app ) {
            $status = get_post_meta( $app->ID, 'flms_app_status', true ) ?: 'pending';
            if ( $status !== 'accepted' ) continue;
            $fid = (int) $app->post_parent;
            $friendly = get_post( $fid );
            if ( ! $friendly || get_post_meta( $fid, 'flms_friendly_status', true ) !== 'approved' ) continue;
            $my_team_id = (int) get_post_meta( $app->ID, 'flms_app_team_id', true );
            $host_id = (int) get_post_meta( $fid, 'flms_host_team_id', true );
            $chosen_id = (int) get_post_meta( $fid, 'flms_chosen_team_id', true );
            $notifications[] = [
                'type' => 'admin_approved',
                'host' => get_the_title( $host_id ),
                'opponent' => $chosen_id ? get_the_title( $chosen_id ) : '',
                'date' => get_post_meta( $fid, 'flms_friendly_date', true ),
                'time' => get_post_meta( $fid, 'flms_friendly_time', true ),
                'place' => get_post_meta( $fid, 'flms_friendly_place', true ),
                'friendly_id' => $fid,
                'my_team_id' => $my_team_id,
            ];
        }
        usort( $notifications, function ( $a, $b ) {
            $da = isset( $a['date'] ) ? $a['date'] : '';
            $db = isset( $b['date'] ) ? $b['date'] : '';
            return strcmp( $db, $da );
        });
        if ( empty( $notifications ) ) {
            return '<div class="flms-empty-box">No notifications yet.</div>';
        }
        ob_start();
        ?>
        <ul class="flms-notification-list">
            <?php foreach ( array_slice( $notifications, 0, 20 ) as $n ) : ?>
                <li class="flms-notif-item flms-notif-<?php echo esc_attr( $n['type'] ); ?>">
                    <?php if ( $n['type'] === 'accepted' ) : ?>
                        Your request to play <strong><?php echo esc_html( $n['host'] ); ?></strong> (<?php echo esc_html( $n['date'] . ' ' . $n['time'] . ' @ ' . $n['place'] ); ?>) with <strong><?php echo esc_html( $n['my_team'] ); ?></strong> was <strong>Accepted</strong>. Waiting for admin approval.
                    <?php elseif ( $n['type'] === 'rejected' ) : ?>
                        Your request to play <strong><?php echo esc_html( $n['host'] ); ?></strong> with <strong><?php echo esc_html( $n['my_team'] ); ?></strong> was <strong>Rejected</strong>.
                    <?php elseif ( $n['type'] === 'announcement' ) : ?>
                        <strong>Announcement:</strong>
                        <?php if ( ! empty( $n['title'] ) ) : ?>
                            <span><?php echo esc_html( $n['title'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( ! empty( $n['date'] ) ) : ?>
                            <div style="font-size:12px; color:#777; margin-top:6px;">
                                Showing since <?php echo esc_html( $n['date'] ); ?>
                            </div>
                        <?php endif; ?>
                        <div class="flms-announcement-content" style="margin-top:8px;">
                            <?php echo $n['content_html'] ?? ''; ?>
                        </div>
                    <?php elseif ( $n['type'] === 'admin_approved' ) : ?>
                        Friendly match confirmed: <strong><?php echo esc_html( $n['host'] ); ?></strong> vs <strong><?php echo esc_html( $n['opponent'] ); ?></strong> on <?php echo esc_html( $n['date'] . ' ' . $n['time'] . ' @ ' . $n['place'] ); ?>.
                        <?php
                        if ( ! empty( $n['friendly_id'] ) ) {
                            $fid = (int) $n['friendly_id'];
                            echo ' <a href="' . esc_url( self::get_friendly_match_details_url( $fid ) ) . '" class="button btn-tiny" style="margin-left:6px;">View match</a>';
                            if ( ! empty( $n['my_team_id'] ) ) {
                                $tid = (int) $n['my_team_id'];
                                $host_id = (int) get_post_meta( $fid, 'flms_host_team_id', true );
                                $paid_host = get_post_meta( $fid, '_flms_friendly_paid_host', true ) === 'yes';
                                $paid_away = get_post_meta( $fid, '_flms_friendly_paid_away', true ) === 'yes';
                                $need_pay = ( $tid === $host_id && ! $paid_host ) || ( $tid !== $host_id && ! $paid_away );
                                if ( $need_pay ) {
                                    echo ' ' . self::render_friendly_pay_button( $fid, $tid, 'Pay RM500' );
                                }
                            }
                        }
                        ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        return ob_get_clean();
    }

    /** Fetch announcements that are currently active for display. */
    private function get_active_inbox_announcements( $now_ts ) {
        $out = [];

        $announcement_ids = get_posts([
            'post_type'      => 'flms_inbox_notice',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'meta_value',
            'meta_key'       => 'flms_announce_start_ts',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => 'flms_announce_start_ts',
                    'value'   => (int) $now_ts,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => 'flms_announce_end_ts',
                        'value'   => 0,
                        'compare' => '=',
                        'type'    => 'NUMERIC',
                    ],
                    [
                        'key'     => 'flms_announce_end_ts',
                        'value'   => (int) $now_ts,
                        'compare' => '>=',
                        'type'    => 'NUMERIC',
                    ],
                ],
            ],
        ]);

        foreach ( (array) $announcement_ids as $aid ) {
            $aid = (int) $aid;
            if ( ! $aid ) continue;

            $start_ts = (int) get_post_meta( $aid, 'flms_announce_start_ts', true );
            if ( $start_ts <= 0 ) continue;

            $title = get_the_title( $aid );
            $raw_content = (string) get_post_field( 'post_content', $aid );
            $content_html = wp_kses_post( do_shortcode( wpautop( $raw_content ) ) );

            $out[] = [
                'type'         => 'announcement',
                'title'        => $title,
                'content_html' => $content_html,
                'date'         => wp_date( 'Y-m-d', $start_ts ),
                'time'         => wp_date( 'H:i', $start_ts ),
            ];
        }

        return $out;
    }

    // ---------- SHORTCODE: SCHEDULE (list) ----------

    /**
     * Friendly matches involving the current manager (host, chosen opponent, or applicant).
     * Attributes: upcoming_only="1", limit="50"
     */
    public function shortcode_schedule( $atts ) {
        self::enqueue_friendly_ui_assets();
        $atts = shortcode_atts(
            [
                'upcoming_only' => '0',
                'limit'         => '50',
            ],
            $atts,
            'flms_friendly_schedule'
        );
        $upcoming_only = filter_var( $atts['upcoming_only'], FILTER_VALIDATE_BOOLEAN );
        $limit         = max( 1, min( 200, (int) $atts['limit'] ) );

        if ( ! is_user_logged_in() ) {
            return '<div class="flms-dashboard-wrapper flms-friendly-schedule dark-mode"><p class="flms-error">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to view your friendly schedule.</p></div>';
        }

        $team_ids = self::get_current_manager_team_ids();
        $user_id  = get_current_user_id();
        global $wpdb;

        $app_parent_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d AND post_status = 'publish'",
            'flms_friendly_app',
            $user_id
        ) );
        $app_parent_ids = array_map( 'intval', (array) $app_parent_ids );

        if ( empty( $team_ids ) && empty( $app_parent_ids ) ) {
            return '<div class="flms-dashboard-wrapper flms-friendly-schedule dark-mode"><p class="flms-error">You need a team (or a friendly request) to see a schedule.</p></div>';
        }

        $posts = [];
        if ( ! empty( $team_ids ) ) {
            $posts = get_posts([
                'post_type'      => 'flms_friendly',
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'orderby'        => 'meta_value',
                'meta_key'       => 'flms_friendly_date',
                'order'          => $upcoming_only ? 'ASC' : 'DESC',
                'meta_query'     => [
                    'relation' => 'OR',
                    [
                        'key'     => 'flms_host_team_id',
                        'value'   => array_map( 'intval', $team_ids ),
                        'compare' => 'IN',
                    ],
                    [
                        'key'     => 'flms_chosen_team_id',
                        'value'   => array_map( 'intval', $team_ids ),
                        'compare' => 'IN',
                    ],
                ],
            ]);
        }

        if ( ! empty( $app_parent_ids ) ) {
            $extra = get_posts([
                'post_type'      => 'flms_friendly',
                'post__in'       => $app_parent_ids,
                'posts_per_page' => $limit,
                'post_status'    => 'publish',
                'orderby'        => 'meta_value',
                'meta_key'       => 'flms_friendly_date',
                'order'          => $upcoming_only ? 'ASC' : 'DESC',
            ]);
            $by_id = [];
            foreach ( array_merge( $posts, $extra ) as $p ) {
                $by_id[ $p->ID ] = $p;
            }
            $posts = array_values( $by_id );
            usort(
                $posts,
                function ( $a, $b ) use ( $upcoming_only ) {
                    $da = get_post_meta( $a->ID, 'flms_friendly_date', true ) ?: '';
                    $db = get_post_meta( $b->ID, 'flms_friendly_date', true ) ?: '';
                    $cmp = strcmp( $da, $db );
                    return $upcoming_only ? $cmp : -$cmp;
                }
            );
            $posts = array_slice( $posts, 0, $limit );
        }

        if ( $upcoming_only && ! empty( $posts ) ) {
            $today = current_time( 'Y-m-d' );
            $posts = array_values(
                array_filter(
                    $posts,
                    function ( $p ) use ( $today ) {
                        $d = get_post_meta( $p->ID, 'flms_friendly_date', true );
                        return $d && $d >= $today;
                    }
                )
            );
        }

        ob_start();
        ?>
        <div class="flms-dashboard-wrapper flms-friendly-schedule dark-mode">
            <h2 class="flms-section-title">Friendly match schedule</h2>
            <?php if ( empty( $posts ) ) : ?>
                <div class="flms-empty-box">No friendly matches to show yet.</div>
            <?php else : ?>
                <div class="flms-table-responsive">
                    <table class="flms-league-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Place</th>
                                <th>Match</th>
                                <th>Your role</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ( $posts as $p ) :
                                $fid       = (int) $p->ID;
                                $status    = get_post_meta( $fid, 'flms_friendly_status', true ) ?: 'open';
                                $host_id   = (int) get_post_meta( $fid, 'flms_host_team_id', true );
                                $chosen_id = (int) get_post_meta( $fid, 'flms_chosen_team_id', true );
                                $date      = get_post_meta( $fid, 'flms_friendly_date', true );
                                $time      = get_post_meta( $fid, 'flms_friendly_time', true );
                                $place     = get_post_meta( $fid, 'flms_friendly_place', true );
                                $host_name = $host_id ? get_the_title( $host_id ) : '—';
                                $away_name = $chosen_id ? get_the_title( $chosen_id ) : 'TBD';
                                $role      = '';
                                if ( in_array( $host_id, $team_ids, true ) ) {
                                    $role = 'Host';
                                } elseif ( in_array( $chosen_id, $team_ids, true ) ) {
                                    $role = 'Opponent';
                                } elseif ( in_array( $fid, $app_parent_ids, true ) ) {
                                    $role = 'Applicant';
                                }
                                $view_url = self::get_friendly_match_details_url( $fid );
                                ?>
                            <tr>
                                <td><?php echo esc_html( $date ? date( 'd M Y', strtotime( $date ) ) : '—' ); ?></td>
                                <td><?php echo esc_html( $time ?: '—' ); ?></td>
                                <td><?php echo esc_html( $place ?: '—' ); ?></td>
                                <td><?php echo esc_html( $host_name ); ?> vs <?php echo esc_html( $away_name ); ?></td>
                                <td><?php echo esc_html( $role ); ?></td>
                                <td><span class="flms-status-badge status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ); ?></span></td>
                                <td class="flms-actions-cell">
                                    <?php if ( $this->user_can_view_friendly_match( $fid ) ) : ?>
                                        <a class="button btn-tiny" href="<?php echo esc_url( add_query_arg( 'schedule_back', '1', $view_url ) ); ?>">View match</a>
                                    <?php else : ?>
                                        <span class="flms-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ---------- SHORTCODE: MATCH DETAILS ----------

    public function shortcode_match_details( $atts ) {
        self::enqueue_friendly_ui_assets();
        $atts = shortcode_atts( [ 'id' => 0 ], $atts, 'flms_friendly_match' );
        $friendly_id = (int) ( $atts['id'] ?: ( $_GET['friendly_id'] ?? 0 ) );
        if ( ! $friendly_id ) {
            return '<div class="flms-dashboard-wrapper flms-friendly-match-details dark-mode"><p class="flms-error">No match specified. Use the link from your inbox or My Slots.</p></div>';
        }
        $friendly = get_post( $friendly_id );
        if ( ! $friendly || $friendly->post_type !== 'flms_friendly' ) {
            return '<div class="flms-dashboard-wrapper flms-friendly-match-details dark-mode"><p class="flms-error">Match not found.</p></div>';
        }
        if ( ! $this->user_can_view_friendly_match( $friendly_id ) ) {
            return '<div class="flms-dashboard-wrapper flms-friendly-match-details dark-mode"><p class="flms-error">You do not have permission to view this match.</p></div>';
        }

        $status = get_post_meta( $friendly_id, 'flms_friendly_status', true ) ?: 'open';
        $host_id = (int) get_post_meta( $friendly_id, 'flms_host_team_id', true );
        $chosen_id = (int) get_post_meta( $friendly_id, 'flms_chosen_team_id', true );
        $date = get_post_meta( $friendly_id, 'flms_friendly_date', true );
        $time = get_post_meta( $friendly_id, 'flms_friendly_time', true );
        $place = get_post_meta( $friendly_id, 'flms_friendly_place', true );
        $h_score = get_post_meta( $friendly_id, 'flms_friendly_home_score', true );
        $a_score = get_post_meta( $friendly_id, 'flms_friendly_away_score', true );
        $pay_status = get_post_meta( $friendly_id, '_flms_friendly_payment_status', true ) ?: 'unpaid';
        $paid_host = get_post_meta( $friendly_id, '_flms_friendly_paid_host', true ) === 'yes';
        $paid_away = get_post_meta( $friendly_id, '_flms_friendly_paid_away', true ) === 'yes';
        $host_jersey_color = get_post_meta( $friendly_id, 'flms_host_jersey_color', true );
        $away_jersey_color = get_post_meta( $friendly_id, 'flms_away_jersey_color', true );
        $my_team_ids_md   = self::get_current_manager_team_ids();
        $show_host_jersey_form = $host_id
            && in_array( $host_id, $my_team_ids_md, true )
            && $status !== 'cancelled';
        $show_away_jersey_form = $chosen_id
            && in_array( $chosen_id, $my_team_ids_md, true )
            && in_array( $status, [ 'pending_admin', 'approved', 'completed' ], true );

        $user = wp_get_current_user();
        $is_referee = in_array( 'referee', (array) $user->roles, true );
        $is_admin = current_user_can( 'edit_post', $friendly_id );
        $can_enter_result = ( $is_referee || $is_admin ) && $status === 'approved' && $host_id && $chosen_id;

        $ratings = get_post_meta( $friendly_id, '_flms_friendly_ratings', true );
        if ( ! is_array( $ratings ) ) $ratings = [];
        $avg_rating = count( $ratings ) > 0 ? array_sum( $ratings ) / count( $ratings ) : 0;

        $msg = isset( $_GET['flms_friendly_msg'] ) ? sanitize_text_field( $_GET['flms_friendly_msg'] ) : '';

        $host_name = $host_id ? get_the_title( $host_id ) : 'TBD';
        $away_name = $chosen_id ? get_the_title( $chosen_id ) : 'TBD';
        $date_display = $date ? date( 'd M Y', strtotime( $date ) ) : '';
        $time_display = $time ? ( strlen( $time ) <= 5 ? date( 'h:i A', strtotime( '2000-01-01 ' . $time ) ) : date( 'h:i A', strtotime( $time ) ) ) : '';
        $h_logo = ( $host_id && class_exists( 'FLMS_Image_Helper' ) ) ? FLMS_Image_Helper::get_team_logo( $host_id ) : '';
        $a_logo = ( $chosen_id && class_exists( 'FLMS_Image_Helper' ) ) ? FLMS_Image_Helper::get_team_logo( $chosen_id ) : '';
        $inbox_url    = home_url( '/inbox/' );
        $schedule_url = apply_filters( 'flms_friendly_schedule_page_url', home_url( '/' ) );
        $back_url     = ( isset( $_GET['schedule_back'] ) && $_GET['schedule_back'] === '1' ) ? $schedule_url : $inbox_url;
        $back_label   = ( isset( $_GET['schedule_back'] ) && $_GET['schedule_back'] === '1' ) ? 'Back to schedule' : 'Back to Inbox';
        $err          = isset( $_GET['flms_friendly_error'] ) ? sanitize_text_field( wp_unslash( $_GET['flms_friendly_error'] ) ) : '';
        $host_team    = $host_id ? get_post( $host_id ) : null;
        $uid          = get_current_user_id();
        $is_slot_author = (int) $friendly->post_author === $uid;
        $is_host_manager = $host_team && $host_team->post_type === 'flms_team' && (int) $host_team->post_author === $uid;
        $can_host_cancel = ( $is_slot_author || $is_host_manager )
            && ! self::friendly_match_has_any_payment( $friendly_id )
            && in_array( $status, [ 'open', 'pending_admin', 'approved' ], true );

        ob_start();
        ?>
        <div class="flms-match-center flms-friendly-match-details dark-mode">
            <div class="flms-back-nav" style="margin-bottom: 20px;">
                <a href="<?php echo esc_url( $back_url ); ?>" class="btn-back">&larr; <?php echo esc_html( $back_label ); ?></a>
            </div>

            <?php if ( $err === 'consent_required' ) : ?>
                <div class="flms-notice flms-notice-error">You must agree to the participation terms before paying.</div>
            <?php elseif ( $err === 'cancel_payment' ) : ?>
                <div class="flms-notice flms-notice-error">This match cannot be cancelled because a payment has been recorded.</div>
            <?php elseif ( $err === 'cannot_cancel_completed' ) : ?>
                <div class="flms-notice flms-notice-error">Completed matches cannot be cancelled.</div>
            <?php endif; ?>

            <?php if ( $status === 'cancelled' ) : ?>
                <div class="flms-notice flms-notice-success">This friendly match has been cancelled.</div>
            <?php endif; ?>

            <?php if ( $msg === 'rating_saved' ) : ?>
                <div class="flms-notice flms-notice-success">Your comment and rating have been saved.</div>
            <?php elseif ( $msg === 'result_saved' ) : ?>
                <div class="flms-notice flms-notice-success">Result has been recorded and match marked as completed.</div>
            <?php elseif ( $msg === 'match_cancelled' ) : ?>
                <div class="flms-notice flms-notice-success">The match has been cancelled.</div>
            <?php elseif ( $msg === 'away_jersey_saved' ) : ?>
                <div class="flms-notice flms-notice-success"><?php esc_html_e( 'Away jersey colour saved.', 'flms' ); ?></div>
            <?php elseif ( $msg === 'host_jersey_saved' ) : ?>
                <div class="flms-notice flms-notice-success"><?php esc_html_e( 'Home jersey colour saved.', 'flms' ); ?></div>
            <?php endif; ?>

            <div class="flms-scoreboard">
                <div class="fs-match-info">
                    <?php echo esc_html( $date_display ); ?><?php echo $time_display ? ' | ' . esc_html( $time_display ) : ''; ?>
                    <?php if ( $place ) : ?><br><span class="fs-venue">@ <?php echo esc_html( $place ); ?></span><?php endif; ?>
                </div>
                <div class="fs-board">
                    <div class="fs-team home">
                        <?php if ( $h_logo ) : ?><img src="<?php echo esc_url( $h_logo ); ?>" alt="Home"><?php endif; ?>
                        <h3><?php echo $host_id ? '<a href="' . esc_url( get_permalink( $host_id ) ) . '">' . esc_html( $host_name ) . '</a>' : esc_html( $host_name ); ?></h3>
                        <span class="fs-role">Home</span>
                        <div class="fs-kit"><?php esc_html_e( 'Kit:', 'flms' ); ?> <?php echo self::format_jersey_color_display( $host_jersey_color ); ?></div>
                    </div>
                    <div class="fs-result">
                        <?php if ( $status === 'completed' && $h_score !== '' && $a_score !== '' ) : ?>
                            <div class="fs-score-box">
                                <span class="score-num"><?php echo esc_html( $h_score ); ?></span>
                                <span class="score-sep">-</span>
                                <span class="score-num"><?php echo esc_html( $a_score ); ?></span>
                            </div>
                            <div class="fs-status full-time">Full Time</div>
                        <?php else : ?>
                            <div class="fs-vs-box">VS</div>
                            <div class="fs-status upcoming"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="fs-team away">
                        <?php if ( $a_logo ) : ?><img src="<?php echo esc_url( $a_logo ); ?>" alt="Away"><?php endif; ?>
                        <h3><?php echo $chosen_id ? '<a href="' . esc_url( get_permalink( $chosen_id ) ) . '">' . esc_html( $away_name ) . '</a>' : esc_html( $away_name ); ?></h3>
                        <span class="fs-role">Away</span>
                        <div class="fs-kit"><?php esc_html_e( 'Kit:', 'flms' ); ?> <?php echo $chosen_id ? self::format_jersey_color_display( $away_jersey_color ) : '<span class="flms-jersey-na">—</span>'; ?></div>
                    </div>
                </div>
            </div>

            <?php if ( $show_host_jersey_form ) : ?>
            <div class="flms-friendly-kit-edit-box flms-friendly-host-jersey-box" style="margin:16px 0; padding:16px; background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.35); border-radius:8px;">
                <h3 style="margin:0 0 10px; font-size:1rem;"><?php esc_html_e( 'Home jersey colour', 'flms' ); ?></h3>
                <p class="flms-muted" style="margin:0 0 12px; font-size:14px;"><?php esc_html_e( 'Pick your kit colour (default white). You can update this any time.', 'flms' ); ?></p>
                <form method="post" action="" class="flms-friendly-kit-form" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
                    <input type="hidden" name="flms_action" value="friendly_set_host_jersey">
                    <input type="hidden" name="friendly_id" value="<?php echo (int) $friendly_id; ?>">
                    <?php wp_nonce_field( 'flms_friendly_host_jersey' ); ?>
                    <input type="color" name="host_jersey_color" id="flms_match_host_jersey_color" value="<?php echo esc_attr( self::get_effective_jersey_color( $host_jersey_color ) ); ?>" class="flms-kit-color-input" aria-label="<?php esc_attr_e( 'Home jersey colour', 'flms' ); ?>">
                    <button type="submit" class="button button-primary btn-gold"><?php esc_html_e( 'Save home kit', 'flms' ); ?></button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ( $show_away_jersey_form ) : ?>
            <div class="flms-friendly-kit-edit-box flms-friendly-away-jersey-box" style="margin:16px 0; padding:16px; background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.35); border-radius:8px;">
                <h3 style="margin:0 0 10px; font-size:1rem;"><?php esc_html_e( 'Away jersey colour', 'flms' ); ?></h3>
                <p class="flms-muted" style="margin:0 0 12px; font-size:14px;"><?php esc_html_e( 'Pick your kit colour (default white). You can change it any time.', 'flms' ); ?></p>
                <form method="post" action="" class="flms-friendly-kit-form" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
                    <input type="hidden" name="flms_action" value="friendly_set_away_jersey">
                    <input type="hidden" name="friendly_id" value="<?php echo (int) $friendly_id; ?>">
                    <?php wp_nonce_field( 'flms_friendly_away_jersey' ); ?>
                    <input type="color" name="away_jersey_color" id="flms_match_away_jersey_color" value="<?php echo esc_attr( self::get_effective_jersey_color( $away_jersey_color ) ); ?>" class="flms-kit-color-input" aria-label="<?php esc_attr_e( 'Away jersey colour', 'flms' ); ?>">
                    <button type="submit" class="button button-primary btn-gold"><?php esc_html_e( 'Save away kit', 'flms' ); ?></button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ( self::friendly_status_allows_fee_payment( $status ) ) : ?>
            <div class="flms-friendly-payment-bar" style="margin:20px 0; padding:16px; background:rgba(255,255,255,0.04); border-radius:8px;">
                <p style="margin:0 0 10px 0;"><strong>Payment:</strong> <?php echo esc_html( ucfirst( str_replace( '_', ' ', $pay_status ) ) ); ?> — Host: <?php echo $paid_host ? 'Paid' : 'Unpaid'; ?> | Away: <?php echo $paid_away ? 'Paid' : 'Unpaid'; ?></p>
                <?php
                if ( in_array( $host_id, $my_team_ids_md, true ) && ! $paid_host ) {
                    echo '<span style="margin-right:8px;display:inline-block;">' . self::render_friendly_pay_button( $friendly_id, $host_id, 'Pay RM500 (Host)' ) . '</span>';
                }
                if ( in_array( $chosen_id, $my_team_ids_md, true ) && ! $paid_away ) {
                    echo self::render_friendly_pay_button( $friendly_id, $chosen_id, 'Pay RM500 (Away)' );
                }
                ?>
            </div>
            <?php endif; ?>

            <?php if ( $can_host_cancel ) : ?>
                <div class="flms-friendly-host-cancel" style="margin:16px 0; padding:12px; border:1px solid rgba(255,100,100,0.35); border-radius:8px;">
                    <p style="margin:0 0 8px 0;"><strong>Host:</strong> Cancel this friendly match (cannot be undone; applications will be rejected).</p>
                    <form method="post" action="" onsubmit="return confirm('Cancel this match?');">
                        <input type="hidden" name="flms_action" value="friendly_host_cancel_match">
                        <input type="hidden" name="friendly_id" value="<?php echo (int) $friendly_id; ?>">
                        <?php wp_nonce_field( 'flms_friendly_host_cancel' ); ?>
                        <button type="submit" class="button" style="background:#c0392b;border-color:#c0392b;color:#fff;">Cancel match</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ( $can_enter_result ) : ?>
                <div class="flms-friendly-result-form" style="margin:1em 0; padding:1em; border:1px solid #ddd; border-radius:6px;">
                    <h3>Record result (Admin / Referee)</h3>
                    <form method="post">
                        <input type="hidden" name="flms_action" value="friendly_save_result">
                        <input type="hidden" name="friendly_id" value="<?php echo (int) $friendly_id; ?>">
                        <?php wp_nonce_field( 'flms_friendly_save_result' ); ?>
                        <p>
                            <label><?php echo esc_html( get_the_title( $host_id ) ); ?></label>
                            <input type="number" name="flms_friendly_home_score" value="<?php echo esc_attr( $h_score ); ?>" min="0" style="width:60px;">
                            –
                            <input type="number" name="flms_friendly_away_score" value="<?php echo esc_attr( $a_score ); ?>" min="0" style="width:60px;">
                            <label><?php echo esc_html( get_the_title( $chosen_id ) ); ?></label>
                        </p>
                        <p><button type="submit" class="button button-primary">Save result &amp; mark completed</button></p>
                    </form>
                </div>
            <?php endif; ?>

            <div class="flms-friendly-rate-comment" style="margin:1.5em 0;">
                <h3>Rate &amp; Comment</h3>
                <?php if ( count( $ratings ) > 0 ) : ?>
                    <p class="flms-avg-rating">Average rating: <?php echo esc_html( number_format( $avg_rating, 1 ) ); ?> (<?php echo count( $ratings ); ?> rating(s))</p>
                <?php endif; ?>
                <?php
                $comments = get_comments( [ 'post_id' => $friendly_id, 'status' => 'approve', 'orderby' => 'comment_date_gmt', 'order' => 'ASC' ] );
                if ( ! empty( $comments ) ) :
                    echo '<ul class="flms-friendly-comment-list" style="list-style:none; padding:0;">';
                    foreach ( $comments as $c ) :
                        ?><li class="flms-comment-item" style="margin:0.5em 0; padding:0.5em; background:#f5f5f5; border-radius:4px;">
                            <strong><?php echo esc_html( $c->comment_author ); ?></strong> <?php echo esc_html( date( 'd M Y H:i', strtotime( $c->comment_date_gmt ) ) ); ?>
                            <p><?php echo wp_kses_post( $c->comment_content ); ?></p>
                        </li><?php
                    endforeach;
                    echo '</ul>';
                else :
                    echo '<p class="flms-muted">No comments yet.</p>';
                endif;

                if ( is_user_logged_in() ) {
                    global $post;
                    $post = $friendly;
                    self::$comment_form_friendly_id = $friendly_id;
                    add_filter( 'comment_form_field_comment', [ $this, 'add_rating_to_comment_form' ] );
                    comment_form( [ 'title_reply' => 'Add a comment &amp; rate', 'id_submit' => 'flms-friendly-comment-submit' ], $friendly_id );
                    remove_filter( 'comment_form_field_comment', [ $this, 'add_rating_to_comment_form' ] );
                    self::$comment_form_friendly_id = 0;
                    wp_reset_postdata();
                } else {
                    echo '<p class="flms-muted">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to comment.</p>';
                }
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ---------- PROCESS ACTIONS ----------

    public function process_actions() {
        if ( ! is_user_logged_in() ) return;

        if ( isset( $_POST['flms_action'] ) && $_POST['flms_action'] === 'friendly_create' ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'flms_friendly_create' ) ) return;
            $host_team_id = intval( $_POST['host_team_id'] ?? 0 );
            $team_ids = self::get_current_manager_team_ids();
            if ( ! in_array( $host_team_id, $team_ids, true ) ) wp_die( 'Invalid team.' );
            $date = sanitize_text_field( $_POST['friendly_date'] ?? '' );
            $time = sanitize_text_field( $_POST['friendly_time'] ?? '' );
            $place = sanitize_text_field( $_POST['friendly_place'] ?? '' );
            $host_jersey = self::sanitize_jersey_color( $_POST['host_jersey_color'] ?? '#ffffff' );
            if ( ! $date || ! $time || ! $place ) wp_die( 'Date, time and place are required.' );
            $team_title = get_the_title( $host_team_id );
            $post_id = wp_insert_post([
                'post_type'       => 'flms_friendly',
                'post_title'      => 'Friendly: ' . $team_title . ' - ' . $date . ' ' . $time,
                'post_status'     => 'publish',
                'post_author'     => get_current_user_id(),
                'comment_status'  => 'open',
            ]);
            if ( $post_id && ! is_wp_error( $post_id ) ) {
                update_post_meta( $post_id, 'flms_host_team_id', $host_team_id );
                update_post_meta( $post_id, 'flms_friendly_date', $date );
                update_post_meta( $post_id, 'flms_friendly_time', $time );
                update_post_meta( $post_id, 'flms_friendly_place', $place );
                update_post_meta( $post_id, 'flms_host_jersey_color', $host_jersey );
                update_post_meta( $post_id, 'flms_friendly_status', 'open' );
            }
            wp_redirect( add_query_arg( 'flms_friendly_msg', 'created', wp_get_referer() ?: get_permalink() ) );
            exit;
        }

        if ( isset( $_POST['flms_action'] ) && $_POST['flms_action'] === 'friendly_set_host_jersey' ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'flms_friendly_host_jersey' ) ) {
                return;
            }
            $friendly_id = (int) ( $_POST['friendly_id'] ?? 0 );
            $friendly    = get_post( $friendly_id );
            if ( ! $friendly || $friendly->post_type !== 'flms_friendly' ) {
                wp_die( 'Invalid match.' );
            }
            $host_tid = (int) get_post_meta( $friendly_id, 'flms_host_team_id', true );
            $team_ids = self::get_current_manager_team_ids();
            if ( ! $host_tid || ! in_array( $host_tid, $team_ids, true ) ) {
                wp_die( 'Only the host team manager can update this.' );
            }
            $status = get_post_meta( $friendly_id, 'flms_friendly_status', true ) ?: 'open';
            if ( $status === 'cancelled' ) {
                wp_die( 'This match was cancelled.' );
            }
            $color = self::sanitize_jersey_color( $_POST['host_jersey_color'] ?? '#ffffff' );
            update_post_meta( $friendly_id, 'flms_host_jersey_color', $color );
            wp_safe_redirect( add_query_arg( [ 'flms_friendly_msg' => 'host_jersey_saved' ], self::get_friendly_match_details_url( $friendly_id ) ) );
            exit;
        }

        if ( isset( $_POST['flms_action'] ) && $_POST['flms_action'] === 'friendly_set_away_jersey' ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'flms_friendly_away_jersey' ) ) {
                return;
            }
            $friendly_id = (int) ( $_POST['friendly_id'] ?? 0 );
            $friendly      = get_post( $friendly_id );
            if ( ! $friendly || $friendly->post_type !== 'flms_friendly' ) {
                wp_die( 'Invalid match.' );
            }
            $away_id = (int) get_post_meta( $friendly_id, 'flms_chosen_team_id', true );
            $team_ids = self::get_current_manager_team_ids();
            if ( ! $away_id || ! in_array( $away_id, $team_ids, true ) ) {
                wp_die( 'Only the away team manager can update this.' );
            }
            $status = get_post_meta( $friendly_id, 'flms_friendly_status', true ) ?: 'open';
            if ( ! in_array( $status, [ 'pending_admin', 'approved', 'completed' ], true ) ) {
                wp_die( 'Away kit cannot be changed at this stage.' );
            }
            $color = self::sanitize_jersey_color( $_POST['away_jersey_color'] ?? '#ffffff' );
            update_post_meta( $friendly_id, 'flms_away_jersey_color', $color );
            wp_safe_redirect( add_query_arg( [ 'flms_friendly_msg' => 'away_jersey_saved' ], self::get_friendly_match_details_url( $friendly_id ) ) );
            exit;
        }

        if ( isset( $_POST['flms_action'] ) && $_POST['flms_action'] === 'friendly_request_play' ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'flms_friendly_request' ) ) return;
            $friendly_id = intval( $_POST['friendly_id'] ?? 0 );
            $applicant_team_id = intval( $_POST['applicant_team_id'] ?? 0 );
            $team_ids = self::get_current_manager_team_ids();
            if ( ! in_array( $applicant_team_id, $team_ids, true ) ) wp_die( 'Invalid team.' );
            $friendly = get_post( $friendly_id );
            if ( ! $friendly || $friendly->post_type !== 'flms_friendly' ) wp_die( 'Invalid friendly.' );
            $fs = get_post_meta( $friendly_id, 'flms_friendly_status', true ) ?: 'open';
            if ( $fs === 'cancelled' ) wp_die( 'This match was cancelled.' );
            if ( $fs !== 'open' ) wp_die( 'This slot is no longer open.' );
            $host_id = (int) get_post_meta( $friendly_id, 'flms_host_team_id', true );
            if ( $applicant_team_id === $host_id ) wp_die( 'You cannot request to play your own slot.' );
            global $wpdb;
            // Block only if this manager already has an active request (pending or accepted).
            // Withdrawn/rejected apps do not block — slot stays open for others and they can request again.
            $existing_app_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_author = %d",
                'flms_friendly_app',
                $friendly_id,
                get_current_user_id()
            ) );
            foreach ( (array) $existing_app_ids as $eid ) {
                $est = get_post_meta( (int) $eid, 'flms_app_status', true ) ?: 'pending';
                if ( in_array( $est, [ 'pending', 'accepted' ], true ) ) {
                    wp_die( 'You already sent a request for this slot.' );
                }
            }

            // Insert directly to bypass capability check (team_manager often lacks CPT caps)
            $now = current_time( 'mysql' );
            $now_gmt = current_time( 'mysql', 1 );
            $title = 'Request: ' . get_the_title( $applicant_team_id ) . ' vs ' . get_the_title( $host_id );
            $post_name = sanitize_title( $title );
            if ( ! $post_name ) {
                $post_name = 'request-' . $friendly_id . '-' . get_current_user_id();
            }
            $insert_data = [
                'post_author'            => get_current_user_id(),
                'post_date'              => $now,
                'post_date_gmt'          => $now_gmt,
                'post_content'           => ' ',
                'post_title'             => $title,
                'post_excerpt'           => '',
                'post_status'            => 'publish',
                'comment_status'         => 'closed',
                'ping_status'            => 'closed',
                'post_password'          => '',
                'post_name'              => substr( $post_name, 0, 200 ),
                'to_ping'                => '',
                'pinged'                 => '',
                'post_modified'          => $now,
                'post_modified_gmt'      => $now_gmt,
                'post_content_filtered'  => '',
                'post_parent'            => $friendly_id,
                'guid'                   => '',
                'menu_order'             => 0,
                'post_type'              => 'flms_friendly_app',
                'post_mime_type'         => '',
                'comment_count'          => 0,
            ];
            $result = $wpdb->insert( $wpdb->posts, $insert_data, null );
            $app_id = 0;
            if ( $result !== false ) {
                $app_id = (int) $wpdb->insert_id;
                if ( ! $app_id ) {
                    $app_id = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
                }
            }

            if ( $app_id ) {
                $wpdb->update( $wpdb->posts, [ 'guid' => get_permalink( $app_id ) ], [ 'ID' => $app_id ], [ '%s' ], [ '%d' ] );
                update_post_meta( $app_id, 'flms_app_team_id', $applicant_team_id );
                update_post_meta( $app_id, 'flms_app_status', 'pending' );
                clean_post_cache( $app_id );
                $redirect_url = wp_get_referer() ?: get_permalink();
                $redirect_url = add_query_arg( [ 'flms_friendly_msg' => 'friendly_request_sent', 'inbox_tab' => 'my_requests' ], $redirect_url );
                wp_redirect( $redirect_url );
                exit;
            }
            $err = $wpdb->last_error ? $wpdb->last_error : ( $result === false ? 'insert failed' : 'insert_id=0' );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
                error_log( 'FLMS Friendly Request failed: ' . $err . ' query: ' . ( $wpdb->last_query ?: '(none)' ) );
            }
            $show_err = current_user_can( 'manage_options' ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
            wp_die( 'Could not save your request. Please try again or contact the site admin.' . ( $show_err ? ' [DB: ' . esc_html( $err ) . ']' : '' ) );
        }

        if ( isset( $_POST['flms_action'] ) && $_POST['flms_action'] === 'friendly_accept' ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'flms_friendly_accept' ) ) return;
            $friendly_id = intval( $_POST['friendly_id'] ?? 0 );
            $application_id = intval( $_POST['application_id'] ?? 0 );
            $friendly = get_post( $friendly_id );
            if ( ! $friendly || $friendly->post_type !== 'flms_friendly' ) wp_die( 'Invalid slot.' );
            $host_id = (int) get_post_meta( $friendly_id, 'flms_host_team_id', true );
            $host_team = $host_id ? get_post( $host_id ) : null;
            $is_slot_author = (int) $friendly->post_author === get_current_user_id();
            $is_host_manager = $host_team && $host_team->post_type === 'flms_team' && (int) $host_team->post_author === get_current_user_id();
            if ( ! $is_slot_author && ! $is_host_manager ) wp_die( 'Not your slot.' );
            $slot_status = get_post_meta( $friendly_id, 'flms_friendly_status', true ) ?: 'open';
            if ( $slot_status === 'cancelled' ) wp_die( 'This match was cancelled.' );
            if ( $slot_status !== 'open' ) wp_die( 'Slot already closed.' );
            $app = get_post( $application_id );
            if ( ! $app || $app->post_parent != $friendly_id ) wp_die( 'Invalid application.' );
            $chosen_team_id = (int) get_post_meta( $application_id, 'flms_app_team_id', true );
            update_post_meta( $application_id, 'flms_app_status', 'accepted' );
            $all_apps = get_posts([
                'post_type'   => 'flms_friendly_app',
                'post_parent' => $friendly_id,
                'posts_per_page' => -1,
            ]);
            foreach ( $all_apps as $a ) {
                if ( (int) $a->ID === (int) $application_id ) continue;
                update_post_meta( $a->ID, 'flms_app_status', 'rejected' );
            }
            update_post_meta( $friendly_id, 'flms_chosen_team_id', $chosen_team_id );
            update_post_meta( $friendly_id, 'flms_away_jersey_color', '#ffffff' );
            update_post_meta( $friendly_id, 'flms_friendly_status', 'pending_admin' );
            wp_update_post([
                'ID'         => $friendly_id,
                'post_title' => get_the_title( get_post_meta( $friendly_id, 'flms_host_team_id', true ) ) . ' vs ' . get_the_title( $chosen_team_id ),
            ]);
            $redirect_url = wp_get_referer() ?: get_permalink();
            $redirect_url = add_query_arg( [ 'inbox_tab' => 'my_slots', 'flms_friendly_msg' => 'friendly_accepted' ], $redirect_url );
            wp_redirect( $redirect_url );
            exit;
        }

        if ( isset( $_POST['flms_action'] ) && $_POST['flms_action'] === 'friendly_save_result' ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'flms_friendly_save_result' ) ) return;
            $friendly_id = (int) ( $_POST['friendly_id'] ?? 0 );
            $friendly = get_post( $friendly_id );
            if ( ! $friendly || $friendly->post_type !== 'flms_friendly' ) return;
            $user = wp_get_current_user();
            $is_referee = in_array( 'referee', (array) $user->roles, true );
            $is_admin = current_user_can( 'edit_post', $friendly_id );
            if ( ! $is_referee && ! $is_admin ) return;
            if ( get_post_meta( $friendly_id, 'flms_friendly_status', true ) !== 'approved' ) return;
            $host_id = (int) get_post_meta( $friendly_id, 'flms_host_team_id', true );
            $chosen_id = (int) get_post_meta( $friendly_id, 'flms_chosen_team_id', true );
            if ( ! $host_id || ! $chosen_id ) return;
            $h = (int) ( $_POST['flms_friendly_home_score'] ?? 0 );
            $a = (int) ( $_POST['flms_friendly_away_score'] ?? 0 );
            update_post_meta( $friendly_id, 'flms_friendly_home_score', $h );
            update_post_meta( $friendly_id, 'flms_friendly_away_score', $a );
            self::apply_friendly_result( $host_id, $chosen_id, $h, $a );
            update_post_meta( $friendly_id, 'flms_friendly_status', 'completed' );
            $redirect_url = wp_get_referer() ?: get_permalink();
            wp_redirect( add_query_arg( [ 'friendly_id' => $friendly_id, 'flms_friendly_msg' => 'result_saved' ], $redirect_url ) );
            exit;
        }

        if ( isset( $_POST['flms_action'] ) && $_POST['flms_action'] === 'friendly_consent_and_pay' ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'flms_friendly_consent_pay' ) ) {
                wp_die( 'Security check failed.', '', [ 'response' => 403 ] );
            }
            $friendly_id = (int) ( $_POST['friendly_id'] ?? 0 );
            $team_id     = (int) ( $_POST['team_id'] ?? 0 );
            if ( empty( $_POST['flms_friendly_consent_agree'] ) ) {
                wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'consent_required', wp_get_referer() ?: home_url( '/' ) ) );
                exit;
            }
            if ( ! self::friendly_fee_consent_required() ) {
                wp_safe_redirect( self::get_friendly_pay_url( $friendly_id, $team_id ) );
                exit;
            }
            if ( ! $friendly_id || ! $team_id ) {
                wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'invalid', home_url( '/' ) ) );
                exit;
            }
            $friendly = get_post( $friendly_id );
            if ( ! $friendly || $friendly->post_type !== 'flms_friendly' ) {
                wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'invalid', home_url( '/' ) ) );
                exit;
            }
            $friendly_status = get_post_meta( $friendly_id, 'flms_friendly_status', true ) ?: 'open';
            if ( ! self::friendly_status_allows_fee_payment( $friendly_status ) ) {
                wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'not_approved', home_url( '/' ) ) );
                exit;
            }
            $host_id = (int) get_post_meta( $friendly_id, 'flms_host_team_id', true );
            $away_id = (int) get_post_meta( $friendly_id, 'flms_chosen_team_id', true );
            if ( $team_id !== $host_id && $team_id !== $away_id ) {
                wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'invalid', home_url( '/' ) ) );
                exit;
            }
            $team = get_post( $team_id );
            if ( ! $team || $team->post_type !== 'flms_team' || (int) $team->post_author !== get_current_user_id() ) {
                wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'unauthorized', home_url( '/' ) ) );
                exit;
            }
            self::set_friendly_pay_consent_ok( $friendly_id, $team_id );
            wp_safe_redirect( self::get_friendly_pay_url( $friendly_id, $team_id ) );
            exit;
        }

        if ( isset( $_POST['flms_action'] ) && $_POST['flms_action'] === 'friendly_withdraw_request' ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'flms_friendly_withdraw' ) ) {
                return;
            }
            $application_id = (int) ( $_POST['application_id'] ?? 0 );
            $friendly_id    = (int) ( $_POST['friendly_id'] ?? 0 );
            $app            = get_post( $application_id );
            if ( ! $app || $app->post_type !== 'flms_friendly_app' || (int) $app->post_parent !== $friendly_id ) {
                wp_die( 'Invalid request.' );
            }
            if ( (int) $app->post_author !== get_current_user_id() ) {
                wp_die( 'Not your request.' );
            }
            $app_st          = get_post_meta( $application_id, 'flms_app_status', true ) ?: 'pending';
            $friendly_status = get_post_meta( $friendly_id, 'flms_friendly_status', true ) ?: 'open';

            // A) Pending request, slot still open — simple withdraw.
            if ( $app_st === 'pending' && $friendly_status === 'open' ) {
                update_post_meta( $application_id, 'flms_app_status', 'withdrawn' );
                wp_safe_redirect( add_query_arg( [ 'flms_friendly_msg' => 'request_withdrawn', 'inbox_tab' => 'my_requests' ], self::get_friendly_inbox_url() ) );
                exit;
            }

            // B) Host already accepted this team; waiting for admin — opponent can withdraw and slot goes open again.
            if ( $app_st === 'accepted' && $friendly_status === 'pending_admin' ) {
                if ( self::friendly_match_has_any_payment( $friendly_id ) ) {
                    wp_safe_redirect( add_query_arg( [ 'flms_friendly_error' => 'withdraw_requires_no_payment', 'inbox_tab' => 'my_requests' ], self::get_friendly_inbox_url() ) );
                    exit;
                }
                $chosen_id = (int) get_post_meta( $friendly_id, 'flms_chosen_team_id', true );
                $my_team_id = (int) get_post_meta( $application_id, 'flms_app_team_id', true );
                if ( ! $chosen_id || $chosen_id !== $my_team_id ) {
                    wp_die( 'Invalid withdrawal for this match.' );
                }
                $team_ids = self::get_current_manager_team_ids();
                if ( ! in_array( $my_team_id, $team_ids, true ) ) {
                    wp_die( 'Invalid team.' );
                }
                update_post_meta( $application_id, 'flms_app_status', 'withdrawn' );
                delete_post_meta( $friendly_id, 'flms_chosen_team_id' );
                delete_post_meta( $friendly_id, 'flms_away_jersey_color' );
                update_post_meta( $friendly_id, 'flms_friendly_status', 'open' );

                $all_apps = get_posts([
                    'post_type'      => 'flms_friendly_app',
                    'post_parent'    => $friendly_id,
                    'posts_per_page' => -1,
                    'post_status'    => 'any',
                ]);
                foreach ( $all_apps as $a ) {
                    if ( (int) $a->ID === (int) $application_id ) {
                        continue;
                    }
                    $ost = get_post_meta( $a->ID, 'flms_app_status', true ) ?: 'pending';
                    if ( $ost === 'rejected' ) {
                        update_post_meta( $a->ID, 'flms_app_status', 'pending' );
                    }
                }

                $host_tid = (int) get_post_meta( $friendly_id, 'flms_host_team_id', true );
                $date     = get_post_meta( $friendly_id, 'flms_friendly_date', true );
                $time     = get_post_meta( $friendly_id, 'flms_friendly_time', true );
                $host_nm  = $host_tid ? get_the_title( $host_tid ) : 'Friendly';
                wp_update_post([
                    'ID'         => $friendly_id,
                    'post_title' => 'Friendly: ' . $host_nm . ' - ' . ( $date ?: '' ) . ' ' . ( $time ?: '' ),
                ] );

                wp_safe_redirect( add_query_arg( [ 'flms_friendly_msg' => 'opponent_withdraw_slot_reopened', 'inbox_tab' => 'my_requests' ], self::get_friendly_inbox_url() ) );
                exit;
            }

            wp_die( 'This request can no longer be withdrawn.' );
        }

        if ( isset( $_POST['flms_action'] ) && $_POST['flms_action'] === 'friendly_host_cancel_match' ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'flms_friendly_host_cancel' ) ) {
                return;
            }
            $friendly_id = (int) ( $_POST['friendly_id'] ?? 0 );
            $friendly    = get_post( $friendly_id );
            if ( ! $friendly || $friendly->post_type !== 'flms_friendly' ) {
                wp_die( 'Invalid match.' );
            }
            if ( self::friendly_match_has_any_payment( $friendly_id ) ) {
                wp_safe_redirect( add_query_arg( [ 'flms_friendly_error' => 'cancel_payment', 'inbox_tab' => 'my_slots' ], self::get_friendly_inbox_url() ) );
                exit;
            }
            $uid       = get_current_user_id();
            $host_id   = (int) get_post_meta( $friendly_id, 'flms_host_team_id', true );
            $host_team = $host_id ? get_post( $host_id ) : null;
            $is_slot_author = (int) $friendly->post_author === $uid;
            $is_host_manager = $host_team && $host_team->post_type === 'flms_team' && (int) $host_team->post_author === $uid;
            if ( ! $is_slot_author && ! $is_host_manager ) {
                wp_die( 'Only the host can cancel this match.' );
            }
            $status = get_post_meta( $friendly_id, 'flms_friendly_status', true ) ?: 'open';
            if ( $status === 'cancelled' ) {
                wp_safe_redirect( add_query_arg( [ 'flms_friendly_msg' => 'already_cancelled', 'inbox_tab' => 'my_slots' ], self::get_friendly_inbox_url() ) );
                exit;
            }
            if ( $status === 'completed' ) {
                wp_safe_redirect( add_query_arg( [ 'flms_friendly_error' => 'cannot_cancel_completed', 'inbox_tab' => 'my_slots' ], self::get_friendly_inbox_url() ) );
                exit;
            }
            if ( ! in_array( $status, [ 'open', 'pending_admin', 'approved' ], true ) ) {
                wp_die( 'This match cannot be cancelled from the current status.' );
            }
            $all_apps = get_posts([
                'post_type'      => 'flms_friendly_app',
                'post_parent'    => $friendly_id,
                'posts_per_page' => -1,
                'post_status'    => 'any',
            ]);
            foreach ( $all_apps as $a ) {
                update_post_meta( $a->ID, 'flms_app_status', 'rejected' );
            }
            delete_post_meta( $friendly_id, 'flms_chosen_team_id' );
            delete_post_meta( $friendly_id, 'flms_away_jersey_color' );
            update_post_meta( $friendly_id, 'flms_friendly_status', 'cancelled' );
            update_post_meta( $friendly_id, 'flms_friendly_cancelled_at', current_time( 'mysql' ) );
            update_post_meta( $friendly_id, 'flms_friendly_cancelled_by', $uid );
            wp_safe_redirect( add_query_arg( [ 'flms_friendly_msg' => 'match_cancelled', 'inbox_tab' => 'my_slots' ], self::get_friendly_inbox_url() ) );
            exit;
        }
    }

    // ---------- PAY LINK HANDLER (add to cart + redirect to checkout) ----------

    public function handle_friendly_pay_link() {
        if ( is_admin() || ! isset( $_GET['flms_friendly_pay'] ) || ! isset( $_GET['friendly_id'] ) || ! isset( $_GET['team_id'] ) ) {
            return;
        }
        $friendly_id = (int) $_GET['friendly_id'];
        $team_id     = (int) $_GET['team_id'];
        if ( ! $friendly_id || ! $team_id ) {
            wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'invalid', home_url( '/' ) ) );
            exit;
        }
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'login', wp_login_url( add_query_arg( [ 'flms_friendly_pay' => 1, 'friendly_id' => $friendly_id, 'team_id' => $team_id ], home_url( '/' ) ) ) ) );
            exit;
        }
        $friendly = get_post( $friendly_id );
        if ( ! $friendly || $friendly->post_type !== 'flms_friendly' ) {
            wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'invalid', home_url( '/' ) ) );
            exit;
        }
        $friendly_status = get_post_meta( $friendly_id, 'flms_friendly_status', true ) ?: 'open';
        if ( $friendly_status === 'cancelled' ) {
            wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'cancelled', home_url( '/' ) ) );
            exit;
        }
        // Allow payment even if admin marked the friendly as completed,
        // as long as the team hasn't paid yet.
        if ( ! self::friendly_status_allows_fee_payment( $friendly_status ) ) {
            wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'not_approved', home_url( '/' ) ) );
            exit;
        }
        $host_id = (int) get_post_meta( $friendly_id, 'flms_host_team_id', true );
        $away_id = (int) get_post_meta( $friendly_id, 'flms_chosen_team_id', true );
        if ( $team_id !== $host_id && $team_id !== $away_id ) {
            wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'invalid', home_url( '/' ) ) );
            exit;
        }
        $team = get_post( $team_id );
        if ( ! $team || $team->post_type !== 'flms_team' || (int) $team->post_author !== get_current_user_id() ) {
            wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'unauthorized', home_url( '/' ) ) );
            exit;
        }
        $paid_host = get_post_meta( $friendly_id, '_flms_friendly_paid_host', true ) === 'yes';
        $paid_away = get_post_meta( $friendly_id, '_flms_friendly_paid_away', true ) === 'yes';
        if ( ( $team_id === $host_id && $paid_host ) || ( $team_id === $away_id && $paid_away ) ) {
            wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'already_paid', home_url( '/' ) ) );
            exit;
        }
        $product_id = self::get_friendly_fee_product_id();
        if ( self::friendly_fee_consent_required() && ! self::verify_friendly_pay_consent_ok( $friendly_id, $team_id ) ) {
            wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'consent_required', home_url( '/' ) ) );
            exit;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_safe_redirect( add_query_arg( 'flms_friendly_error', 'cart_unavailable', home_url( '/' ) ) );
            exit;
        }
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart( $product_id, 1, 0, [], [
            'friendly_fee_id'   => $friendly_id,
            'friendly_fee_team' => $team_id,
        ] );
        self::clear_friendly_pay_consent_ok( $friendly_id, $team_id );
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    // ---------- TEAM DIRECTORY SHORTCODE ----------

    public function shortcode_team_directory() {
        // Settings: per-page options
        $per_page_options = [ 10, 25, 50, 100 ];
        $search_query = isset( $_GET['team_search'] ) ? sanitize_text_field( $_GET['team_search'] ) : '';
        $per_page = isset( $_GET['team_per_page'] ) ? (int) $_GET['team_per_page'] : 25;
        if ( ! in_array( $per_page, $per_page_options, true ) ) {
            $per_page = 25;
        }
        $page_param = 'team_page';
        $paged = isset( $_GET[ $page_param ] ) ? max( 1, (int) $_GET[ $page_param ] ) : 1;

        // Load all teams, oldest first, so we can pick first created per manager.
        $all_teams = get_posts([
            'post_type'      => 'flms_team',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);
        if ( empty( $all_teams ) ) {
            return '<div class="flms-empty-box">No teams found.</div>';
        }

        // Only first team per manager (post_author)
        $first_by_manager = [];
        foreach ( $all_teams as $t ) {
            $author_id = (int) $t->post_author;
            if ( ! isset( $first_by_manager[ $author_id ] ) ) {
                $first_by_manager[ $author_id ] = $t;
            }
        }
        $teams = array_values( $first_by_manager );

        // Filter by team name if search query provided
        if ( $search_query !== '' ) {
            $q = mb_strtolower( $search_query );
            $teams = array_filter( $teams, function( $t ) use ( $q ) {
                return mb_strpos( mb_strtolower( $t->post_title ), $q ) !== false;
            } );
        }

        // Sort by highest Friendly Pts first, then team name
        usort( $teams, function( $a, $b ) {
            $a_pts = self::get_team_friendly_points( $a->ID );
            $b_pts = self::get_team_friendly_points( $b->ID );

            if ( $a_pts === $b_pts ) {
                return strcasecmp( $a->post_title, $b->post_title );
            }

            return ( $a_pts > $b_pts ) ? -1 : 1;
        } );

        $total_items = count( $teams );
        if ( $total_items === 0 ) {
            return '<div class="flms-empty-box">No teams found.</div>';
        }
        $total_pages = (int) ceil( $total_items / $per_page );
        $offset = ( $paged - 1 ) * $per_page;
        $paged_teams = array_slice( $teams, $offset, $per_page );

        ob_start();
        ?>
        <div class="flms-dashboard-wrapper flms-team-directory">
            <h2 class="flms-section-title">Team Directory</h2>
            <?php echo $this->render_team_directory_search_form( $search_query, $per_page, $per_page_options, $page_param ); ?>

            <?php if ( empty( $paged_teams ) ) : ?>
                <div class="flms-empty-box">No teams found.</div>
            <?php else : ?>
                <div class="flms-table-responsive">
                    <table class="flms-league-table flms-player-table">
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th>Manager</th>
                                <th title="Friendly Points">Friendly Pts</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $paged_teams as $t ) :
                                $link = get_permalink( $t->ID );
                                $pts = self::get_team_friendly_points( $t->ID );
                                $manager_id = (int) $t->post_author;
                                $manager_name = get_the_author_meta( 'display_name', $manager_id );
                                $logo = class_exists( 'FLMS_Image_Helper' ) ? FLMS_Image_Helper::get_team_logo( $t->ID, 'thumbnail' ) : '';
                            ?>
                            <tr>
                                <td class="club">
                                    <a href="<?php echo esc_url( $link ); ?>" class="flms-team-name-with-logo">
                                        <?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="" class="flms-team-logo"><?php endif; ?>
                                        <span><?php echo esc_html( $t->post_title ); ?></span>
                                    </a>
                                </td>
                                <td style="font-size:13px;"><?php echo esc_html( $manager_name ?: '—' ); ?></td>
                                <td class="pts"><?php echo (int) $pts; ?></td>
                                <td class="flms-actions-cell"><a href="<?php echo esc_url( $link ); ?>" class="flms-team-view-btn">View</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="flms-pagination">
                        <?php
                        echo paginate_links([
                            'base'      => add_query_arg( $page_param, '%#%' ),
                            'format'    => '',
                            'current'   => $paged,
                            'total'     => $total_pages,
                            'prev_text' => '&laquo; Prev',
                            'next_text' => 'Next &raquo;',
                            'mid_size'  => 2,
                            'type'      => 'list',
                        ]);
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_team_directory_search_form( $search, $per_page, $per_page_options, $page_param ) {
        ob_start();
        ?>
        <div class="flms-directory-wrapper">
            <form method="get" action="<?php echo esc_url( get_permalink() ); ?>" class="flms-dir-search-form">
                <input type="text" name="team_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Search team name..." class="search-input">
                <select name="team_per_page" class="search-select">
                    <?php foreach ( $per_page_options as $opt ) : ?>
                        <option value="<?php echo (int) $opt; ?>" <?php selected( $per_page, $opt ); ?>>Show <?php echo (int) $opt; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="search-btn">Filter</button>
                <?php if ( $search || ( isset( $_GET['team_per_page'] ) && (int) $_GET['team_per_page'] !== 25 ) ) : ?>
                    <a href="<?php echo esc_url( get_permalink() ); ?>" class="reset-link">Reset</a>
                <?php endif; ?>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // ---------- ADMIN METABOXES ----------

    public function add_inbox_announcement_metabox() {
        add_meta_box(
            'flms_inbox_announcement_period',
            'Inbox Announcement Period',
            [ $this, 'render_inbox_announcement_period_metabox' ],
            'flms_inbox_notice',
            'normal',
            'high'
        );
    }

    public function render_inbox_announcement_period_metabox( $post ) {
        $start_ts = (int) get_post_meta( $post->ID, 'flms_announce_start_ts', true );
        $end_ts   = (int) get_post_meta( $post->ID, 'flms_announce_end_ts', true );

        $start_val = $start_ts > 0 ? wp_date( 'Y-m-d\TH:i', $start_ts ) : '';
        $end_val   = $end_ts > 0 ? wp_date( 'Y-m-d\TH:i', $end_ts ) : '';

        wp_nonce_field( 'flms_inbox_announcement_period_save', 'flms_inbox_announcement_period_nonce' );
        ?>
        <p class="description" style="margin-top:0;">
            Set when this announcement should appear in the Friendly Inbox <strong>Notifications</strong> tab.
            Leave the end time blank to show it indefinitely.
        </p>
        <p>
            <label for="flms_announce_start"><strong>Start (required)</strong></label><br>
            <input type="datetime-local" id="flms_announce_start" name="flms_announce_start" value="<?php echo esc_attr( $start_val ); ?>" style="width:100%;">
        </p>
        <p>
            <label for="flms_announce_end"><strong>End (optional)</strong></label><br>
            <input type="datetime-local" id="flms_announce_end" name="flms_announce_end" value="<?php echo esc_attr( $end_val ); ?>" style="width:100%;">
        </p>
        <?php
    }

    public function save_inbox_announcement_metaboxes( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['flms_inbox_announcement_period_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['flms_inbox_announcement_period_nonce'], 'flms_inbox_announcement_period_save' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $start_raw = isset( $_POST['flms_announce_start'] ) ? sanitize_text_field( wp_unslash( $_POST['flms_announce_start'] ) ) : '';
        $end_raw   = isset( $_POST['flms_announce_end'] ) ? sanitize_text_field( wp_unslash( $_POST['flms_announce_end'] ) ) : '';

        $start_ts = $start_raw ? (int) strtotime( $start_raw ) : 0;
        $end_ts = $end_raw ? (int) strtotime( $end_raw ) : 0;

        if ( $start_ts <= 0 ) {
            // If start is missing, keep announcement inactive.
            update_post_meta( $post_id, 'flms_announce_start_ts', 0 );
            update_post_meta( $post_id, 'flms_announce_end_ts', 0 );
            return;
        }

        if ( $end_ts > 0 && $end_ts < $start_ts ) {
            $end_ts = $start_ts;
        }

        update_post_meta( $post_id, 'flms_announce_start_ts', $start_ts );
        update_post_meta( $post_id, 'flms_announce_end_ts', $end_ts );
    }

    public function add_admin_metaboxes() {
        add_meta_box(
            'flms_friendly_admin',
            'Friendly Match – Admin',
            [ $this, 'render_admin_metabox' ],
            'flms_friendly',
            'normal',
            'high'
        );
    }

    public function render_admin_metabox( $post ) {
        $status = get_post_meta( $post->ID, 'flms_friendly_status', true ) ?: 'open';
        $host_id = (int) get_post_meta( $post->ID, 'flms_host_team_id', true );
        $chosen_id = (int) get_post_meta( $post->ID, 'flms_chosen_team_id', true );
        $date = get_post_meta( $post->ID, 'flms_friendly_date', true );
        $time = get_post_meta( $post->ID, 'flms_friendly_time', true );
        $place = get_post_meta( $post->ID, 'flms_friendly_place', true );
        $h_score = get_post_meta( $post->ID, 'flms_friendly_home_score', true );
        $a_score = get_post_meta( $post->ID, 'flms_friendly_away_score', true );
        $pay_status = get_post_meta( $post->ID, '_flms_friendly_payment_status', true ) ?: 'unpaid';
        $paid_host = get_post_meta( $post->ID, '_flms_friendly_paid_host', true ) === 'yes';
        $paid_away = get_post_meta( $post->ID, '_flms_friendly_paid_away', true ) === 'yes';
        $all_teams = get_posts( [ 'post_type' => 'flms_team', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'any' ] );
        wp_nonce_field( 'flms_friendly_admin_save', 'flms_friendly_admin_nonce' );
        ?>
        <div class="flms-admin-friendly-panel">
            <?php if ( $status === 'open' ) : ?>
                <p><strong>Edit slot (so it appears in other managers’ Open Requests):</strong></p>
                <p>
                    <label>Host Team (required)</label><br>
                    <select name="flms_host_team_id" style="width:100%;">
                        <option value="">— Select host team —</option>
                        <?php foreach ( $all_teams as $t ) : ?>
                            <option value="<?php echo (int) $t->ID; ?>" <?php selected( $host_id, $t->ID ); ?>><?php echo esc_html( $t->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label>Date</label><br>
                    <input type="date" name="flms_friendly_date" value="<?php echo esc_attr( $date ); ?>" style="width:100%;">
                </p>
                <p>
                    <label>Time</label><br>
                    <input type="time" name="flms_friendly_time" value="<?php echo esc_attr( $time ); ?>" style="width:100%;">
                </p>
                <p>
                    <label>Place</label><br>
                    <input type="text" name="flms_friendly_place" value="<?php echo esc_attr( $place ); ?>" style="width:100%;">
                </p>
                <p class="description">Save/Update this post so the slot appears under “Open Requests” for other team managers.</p>
                <hr>
            <?php endif; ?>
            <p><strong>Status:</strong> <?php echo esc_html( $status ); ?></p>
            <p><strong>Host:</strong> <?php echo $host_id ? esc_html( get_the_title( $host_id ) ) : '-'; ?></p>
            <p><strong>Opponent:</strong> <?php echo $chosen_id ? esc_html( get_the_title( $chosen_id ) ) : '-'; ?></p>
            <p><strong>Date / Time / Place:</strong> <?php echo esc_html( $date . ' ' . $time . ' @ ' . $place ); ?></p>
            <?php if ( $status === 'approved' || $status === 'completed' ) : ?>
                <p><strong>Payment status:</strong> <?php echo esc_html( ucfirst( str_replace( '_', ' ', $pay_status ) ) ); ?></p>
                <p>Host paid: <?php echo $paid_host ? 'Yes' : 'No'; ?> | Away paid: <?php echo $paid_away ? 'Yes' : 'No'; ?></p>
            <?php endif; ?>
            <?php if ( $status === 'pending_admin' ) : ?>
                <p>
                    <label><input type="checkbox" name="flms_friendly_approve" value="1"> Approve match (send email to both teams)</label>
                </p>
            <?php endif; ?>
            <?php if ( $status === 'approved' && $host_id && $chosen_id ) : ?>
                <hr>
                <p><strong>Record Result (Friendly points: +3 win, +1 draw, -1 loss)</strong></p>
                <p>
                    <label><?php echo esc_html( get_the_title( $host_id ) ); ?></label>
                    <input type="number" name="flms_friendly_home_score" value="<?php echo esc_attr( $h_score ); ?>" min="0" style="width:60px;">
                    &nbsp; – &nbsp;
                    <input type="number" name="flms_friendly_away_score" value="<?php echo esc_attr( $a_score ); ?>" min="0" style="width:60px;">
                    <label><?php echo esc_html( get_the_title( $chosen_id ) ); ?></label>
                </p>
                <p>
                    <label><input type="checkbox" name="flms_friendly_complete" value="1"> Mark as completed (update friendly points)</label>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_admin_metaboxes( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['flms_friendly_admin_nonce'] ) || ! wp_verify_nonce( $_POST['flms_friendly_admin_nonce'], 'flms_friendly_admin_save' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $status = get_post_meta( $post_id, 'flms_friendly_status', true ) ?: 'open';
        if ( $status === 'open' ) {
            if ( isset( $_POST['flms_host_team_id'] ) ) {
                $hid = (int) $_POST['flms_host_team_id'];
                if ( $hid ) update_post_meta( $post_id, 'flms_host_team_id', $hid );
            }
            if ( isset( $_POST['flms_friendly_date'] ) ) update_post_meta( $post_id, 'flms_friendly_date', sanitize_text_field( $_POST['flms_friendly_date'] ) );
            if ( isset( $_POST['flms_friendly_time'] ) ) update_post_meta( $post_id, 'flms_friendly_time', sanitize_text_field( $_POST['flms_friendly_time'] ) );
            if ( isset( $_POST['flms_friendly_place'] ) ) update_post_meta( $post_id, 'flms_friendly_place', sanitize_text_field( $_POST['flms_friendly_place'] ) );
        }

        if ( ! empty( $_POST['flms_friendly_approve'] ) ) {
            $status = get_post_meta( $post_id, 'flms_friendly_status', true );
            if ( $status === 'pending_admin' ) {
                $this->approve_friendly_match( $post_id );
            }
        }

        if ( ! empty( $_POST['flms_friendly_complete'] ) ) {
            $status = get_post_meta( $post_id, 'flms_friendly_status', true );
            if ( $status === 'approved' ) {
                $host_id = (int) get_post_meta( $post_id, 'flms_host_team_id', true );
                $chosen_id = (int) get_post_meta( $post_id, 'flms_chosen_team_id', true );
                $h = (int) ( $_POST['flms_friendly_home_score'] ?? 0 );
                $a = (int) ( $_POST['flms_friendly_away_score'] ?? 0 );
                update_post_meta( $post_id, 'flms_friendly_home_score', $h );
                update_post_meta( $post_id, 'flms_friendly_away_score', $a );
                self::apply_friendly_result( $host_id, $chosen_id, $h, $a );
                update_post_meta( $post_id, 'flms_friendly_status', 'completed' );
            }
        }
    }

    /**
     * Full HTML for ALL-STAR LEAGUE participation terms (modal / scroll area).
     *
     * @return string
     */
    public static function get_participation_consent_terms_html() {
        ob_start();
        ?>
        <div class="flms-friendly-terms-doc">
            <p><strong>ALL-STAR LEAGUE</strong><br><strong>PARTICIPATION CONSENT &amp; TERMS AND CONDITIONS</strong></p>
            <p>This document serves as an official agreement between the participating team/player (&quot;Participant&quot;) and <strong>ALL-STAR LEAGUE</strong> (&quot;Organizer&quot;). By confirming participation and/or placing an order to join the league, the Participant agrees to abide by the following terms and conditions:</p>
            <hr>
            <h4>1. PAYMENT TERMS</h4>
            <p>1.1 All participation fees must be paid promptly, either before or immediately after the match.<br>
            1.2 Failure to complete payment may result in suspension or disqualification from the league.</p>
            <h4>2. WITHDRAWAL POLICY</h4>
            <p>2.1 Any team or participant intending to withdraw from the league must notify the Organizer at least <strong>six (6) days in advance</strong>.<br>
            2.2 Failure to provide sufficient notice may result in penalties or restrictions from future participation.</p>
            <h4>3. LIABILITY &amp; COMPENSATION</h4>
            <p>3.1 Any participant found to have caused financial loss, damage, or disruption to the Organizer will be held responsible.<br>
            3.2 The Participant agrees to fully compensate the Organizer for any losses incurred due to their actions.</p>
            <h4>4. DISCIPLINARY ACTION (VIOLENCE &amp; MISCONDUCT)</h4>
            <p>4.1 Any form of violence, aggressive behavior, or hooliganism is strictly prohibited.<br>
            4.2 Any participant involved in violent conduct that results in injury to any individual within the event area (including players, officials, or spectators) may be held liable.<br>
            4.3 The Organizer reserves the right to impose penalties, including a fine of RM100 per incident, suspension, or permanent ban, subject to management decision.<br>
            4.4 The Organizer&rsquo;s decision regarding disciplinary action is final.</p>
            <h4>5. MATCH FIXING &amp; INTEGRITY</h4>
            <p>5.1 Any involvement in <strong>match-fixing or manipulation of results</strong> is strictly prohibited.<br>
            5.2 The Organizer reserves the right to take serious action, including banning and reporting, against any party involved.</p>
            <h4>6. REFEREE DECISIONS</h4>
            <p>6.1 All participants must respect and accept the referee&rsquo;s decisions during matches.<br>
            6.2 Appeals will only be considered if supported by <strong>clear video or photographic evidence</strong>, subject to Organizer review.</p>
            <h4>7. FINAL AUTHORITY</h4>
            <p>7.1 The Organizer reserves the right to amend rules, impose penalties, and make final decisions in all matters related to the league.<br>
            7.2 All decisions made by the Organizer are final.</p>
            <h4>DECLARATION</h4>
            <p>By participating in the ALL-STAR LEAGUE, the Participant acknowledges that they have read, understood, and agreed to all terms and conditions stated above by ticking the box to agree to the PARTICIPATION CONSENT &amp; TERMS AND CONDITIONS.</p>
            <p><strong>ALL-STAR LEAGUE</strong><br><em>Providing competitive and professional football experiences</em></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** Print consent modal + terms (once per page) when pay buttons need consent. */
    public function maybe_print_friendly_consent_modal() {
        if ( ! self::$friendly_ui_needed || ! self::friendly_fee_consent_required() || ! is_user_logged_in() ) {
            return;
        }
        $action = esc_url( home_url( '/' ) );
        ?>
        <div id="flms-friendly-consent-modal" class="flms-friendly-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="flms-friendly-consent-title">
            <div class="flms-friendly-modal-overlay" tabindex="-1"></div>
            <div class="flms-friendly-modal-box">
                <button type="button" class="flms-friendly-modal-close" aria-label="<?php esc_attr_e( 'Close', 'flms' ); ?>">&times;</button>
                <h2 id="flms-friendly-consent-title"><?php esc_html_e( 'Participation consent &amp; terms', 'flms' ); ?></h2>
                <p class="flms-friendly-modal-intro"><?php esc_html_e( 'You must read and agree before proceeding to payment.', 'flms' ); ?>
                    <button type="button" class="button-link flms-friendly-open-terms"><?php esc_html_e( 'View full terms in popup', 'flms' ); ?></button>
                </p>
                <div id="flms-friendly-terms-scroll" class="flms-friendly-terms-scroll" style="display:none;">
                    <?php echo self::get_participation_consent_terms_html(); ?>
                </div>
                <form id="flms-friendly-consent-form" method="post" action="<?php echo esc_attr( $action ); ?>">
                    <?php wp_nonce_field( 'flms_friendly_consent_pay' ); ?>
                    <input type="hidden" name="flms_action" value="friendly_consent_and_pay">
                    <input type="hidden" name="friendly_id" id="flms-friendly-consent-fid" value="">
                    <input type="hidden" name="team_id" id="flms-friendly-consent-tid" value="">
                    <p class="flms-friendly-consent-check">
                        <label>
                            <input type="checkbox" name="flms_friendly_consent_agree" id="flms-friendly-consent-agree" value="1" required>
                            <?php esc_html_e( 'I agree to the PARTICIPATION CONSENT & TERMS AND CONDITIONS', 'flms' ); ?>
                        </label>
                    </p>
                    <p class="flms-friendly-modal-actions">
                        <button type="button" class="button flms-friendly-modal-cancel"><?php esc_html_e( 'Cancel', 'flms' ); ?></button>
                        <button type="submit" class="button button-primary btn-gold" id="flms-friendly-consent-submit"><?php esc_html_e( 'Proceed to payment', 'flms' ); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <div id="flms-friendly-terms-only-modal" class="flms-friendly-modal" style="display:none;" role="dialog" aria-labelledby="flms-friendly-terms-only-title">
            <div class="flms-friendly-modal-overlay" tabindex="-1"></div>
            <div class="flms-friendly-modal-box flms-friendly-modal-wide">
                <button type="button" class="flms-friendly-modal-close-terms" aria-label="<?php esc_attr_e( 'Close', 'flms' ); ?>">&times;</button>
                <h2 id="flms-friendly-terms-only-title"><?php esc_html_e( 'PARTICIPATION CONSENT & TERMS AND CONDITIONS', 'flms' ); ?></h2>
                <div class="flms-friendly-terms-scroll flms-friendly-terms-scroll-visible">
                    <?php echo self::get_participation_consent_terms_html(); ?>
                </div>
                <p><button type="button" class="button flms-friendly-close-terms-btn"><?php esc_html_e( 'Close', 'flms' ); ?></button></p>
            </div>
        </div>
        <?php
    }
}
