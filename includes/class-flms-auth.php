<?php
class FLMS_Auth {
    public function __construct() {
        // Frontend Forms
        add_shortcode( 'flms_register', [ $this, 'register_form' ] );
        add_shortcode( 'flms_login', [ $this, 'login_form' ] );
        
        // Form Processing
        add_action( 'init', [ $this, 'handle_registration' ] );
        
        // 1. Block Login if Pending
        add_filter( 'wp_authenticate_user', [ $this, 'check_active_status' ], 10, 2 );
        add_action( 'wp_login_failed', [ $this, 'redirect_on_login_failure' ] );
        
        // 2. Custom Redirects
        add_filter( 'login_redirect', [ $this, 'custom_login_redirect' ], 10, 3 );
        
        // 3. Menu Restrictions
        add_action( 'admin_menu', [ $this, 'restrict_finance_menu' ], 999 );
        add_action( 'admin_menu', [ $this, 'hardcode_admin_block' ], 1000 );

        // SPECIAL: ORDER READ-ONLY FOR admin1@agdsports.com
        add_action( 'admin_head', [ $this, 'restrict_order_ui_css' ] );
        add_action( 'admin_footer', [ $this, 'restrict_order_ui_js' ] );
        add_action( 'woocommerce_before_order_object_save', [ $this, 'block_order_status_change' ], 10, 2 );

        // 4. Admin UI (Account Status & Approval)
        add_action( 'show_user_profile', [ $this, 'add_approval_field' ] );
        add_action( 'edit_user_profile', [ $this, 'add_approval_field' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_approval_field' ] );
        add_filter( 'manage_users_columns', [ $this, 'add_status_column' ] );
        add_action( 'manage_users_custom_column', [ $this, 'show_status_column_data' ], 10, 3 );
        add_action( 'admin_init', [ $this, 'process_quick_actions' ] );

        // PENDING USERS FILTER AT TOP OF USER LIST
        add_filter( 'views_users', [ $this, 'add_pending_users_view' ] );
        add_action( 'pre_get_users', [ $this, 'filter_users_by_pending_status' ] );
        
        // 5. UX Helpers
        add_action( 'after_setup_theme', [ $this, 'remove_admin_bar' ] );
        add_action( 'init', [ $this, 'bypass_logout_confirmation' ] );

        // 6. Impersonation (Login as Manager)
        add_filter( 'manage_flms_team_posts_columns', [ $this, 'add_impersonate_column' ] );
        add_action( 'manage_flms_team_posts_custom_column', [ $this, 'render_impersonate_column' ], 10, 2 );
        add_action( 'admin_action_flms_impersonate', [ $this, 'process_impersonation' ] );
    }

    /**
     * ADDS "Pending Approval" link to the top of the WordPress User List
     */
    public function add_pending_users_view( $views ) {
        $args = [
            'meta_key'    => 'flms_account_status',
            'meta_value'  => 'pending',
            'count_total' => true,
        ];
        $query = new WP_User_Query( $args );
        $count = $query->get_total();

        if ( $count > 0 ) {
            $class = ( isset($_GET['flms_view']) && $_GET['flms_view'] === 'pending' ) ? 'current' : '';
            $url = admin_url( 'users.php?flms_view=pending' );
            $views['pending_approval'] = '<a href="' . $url . '" class="' . $class . '">Pending Approval <span class="count">(' . $count . ')</span></a>';
        }

        return $views;
    }

    /**
     * Handles the database query when the "Pending Approval" filter is clicked
     */
    public function filter_users_by_pending_status( $query ) {
        if ( ! is_admin() ) return;
        if ( isset($_GET['flms_view']) && $_GET['flms_view'] === 'pending' ) {
            $meta_query = [
                [
                    'key'     => 'flms_account_status',
                    'value'   => 'pending',
                    'compare' => '='
                ]
            ];
            $query->set( 'meta_query', $meta_query );
        }
    }

    /**
     * CSS RESTRICTION FOR admin1@agdsports.com
     */
    public function restrict_order_ui_css() {
        $user = wp_get_current_user();
        if ( ! $user || $user->user_email !== 'admin1@agdsports.com' ) return;

        $screen = get_current_screen();
        if ( (isset($screen->post_type) && $screen->post_type === 'shop_order') || $screen->id === 'woocommerce_page_wc-orders' ) {
            echo '<style>
                #order_status, .order_status, select[name="order_status"], .wc-order-status, .wc-customer-edit { 
                    pointer-events: none !important; 
                    background-color: #f0f0f0 !important; 
                    opacity: 0.7 !important; 
                }
                .post-type-shop_order #major-publishing-actions,
                .woocommerce-page-wc-orders .form-actions,
                .order_actions button.save_order,
                .order_actions input.save_order,
                button.save_order,
                .wc-order-bulk-action-apply { 
                    display: none !important; 
                }
                .edit_address, .wc-order-edit-address { display: none !important; }
            </style>';
        }
    }

    /**
     * JS RESTRICTION FOR admin1@agdsports.com
     */
    public function restrict_order_ui_js() {
        $user = wp_get_current_user();
        if ( ! $user || $user->user_email !== 'admin1@agdsports.com' ) return;

        $screen = get_current_screen();
        if ( (isset($screen->post_type) && $screen->post_type === 'shop_order') || $screen->id === 'woocommerce_page_wc-orders' ) {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    function forceLockOrder() {
                        var $statusSelect = $('#order_status, .order_status, select[name="order_status"]');
                        $statusSelect.prop('disabled', true);
                        $('.save_order, button.save_order, input.save_order, #publish').hide();
                        $('.post-type-shop_order .bulkactions').hide();
                    }
                    forceLockOrder();
                    setInterval(function() {
                        forceLockOrder();
                    }, 500);
                });
            </script>
            <?php
        }
    }

    /**
     * PHP BLOCK FOR admin1@agdsports.com
     */
    public function block_order_status_change( $order, $data_store ) {
        $user = wp_get_current_user();
        if ( ! $user || $user->user_email !== 'admin1@agdsports.com' ) return;

        $changes = $order->get_changes();
        if ( isset( $changes['status'] ) ) {
            wp_die( '⛔ Access Denied: You do not have permission to modify order statuses.' );
        }
    }

    /**
     * MENU RESTRICTION FOR admin1@agdsports.com
     */
    public function hardcode_admin_block() {
        $user = wp_get_current_user();
        if ( ! $user || $user->user_email !== 'admin1@agdsports.com' ) return;
        remove_submenu_page( 'woocommerce', 'wc-reports' );
        remove_submenu_page( 'woocommerce', 'wc-status' );
        remove_submenu_page( 'woocommerce', 'wc-settings' );
        remove_menu_page( 'wc-admin&path=/analytics/overview' );
        remove_menu_page( 'woocommerce-marketing' );
    }

    public function restrict_finance_menu() {
        $user = wp_get_current_user();
        if ( ! $user || current_user_can( 'administrator' ) ) return;

        $restricted_roles = ['match_commissioner', 'tournament_official', 'finance_officer'];
        if ( array_intersect( $restricted_roles, (array) $user->roles ) ) {
            remove_menu_page( 'index.php' ); remove_menu_page( 'upload.php' ); remove_menu_page( 'edit.php' );
            remove_menu_page( 'edit.php?post_type=page' ); remove_menu_page( 'edit-comments.php' );
            remove_menu_page( 'themes.php' ); remove_menu_page( 'plugins.php' ); remove_menu_page( 'users.php' );
            remove_menu_page( 'tools.php' ); remove_menu_page( 'options-general.php' );
            remove_menu_page( 'elementor' ); remove_menu_page( 'edit.php?post_type=elementor_library' );
            remove_menu_page( 'crocoblock' ); remove_menu_page( 'jet-dashboard' ); remove_menu_page( 'jet-engine' );
            if ( in_array('tournament_official', (array) $user->roles) || in_array('match_commissioner', (array) $user->roles) ) {
                remove_menu_page( 'woocommerce' ); 
                remove_menu_page( 'wc-admin&path=/analytics/overview' );
                remove_menu_page( 'woocommerce-marketing' );
            }
        }
    }

    public function custom_login_redirect( $redirect_to, $request, $user ) {
        if ( isset( $user->roles ) && is_array( $user->roles ) ) {
            $dash_roles = ['match_commissioner', 'tournament_official', 'finance_officer'];
            if ( array_intersect($dash_roles, $user->roles) ) return admin_url( 'edit.php?post_type=flms_match' );
            elseif ( in_array( 'referee_leader', $user->roles ) ) return home_url( '/referee-leader-dashboard/' );
            elseif ( in_array( 'team_manager', $user->roles ) ) return home_url( '/my-team-dashboard/' );
            elseif ( in_array( 'referee', $user->roles ) ) return home_url( '/referee-dashboard/' );
        }
        return $redirect_to;
    }

    public function register_form() {
        if ( is_user_logged_in() ) return '<p>Already logged in.</p>';
        ob_start(); ?>
        <div class="flms-auth-box"><form method="post" style="max-width:600px; margin:0 auto; padding:20px; border:1px solid #ddd; background:#fff; border-radius:8px;"><h3>Create Account</h3><p><label>Register As:</label><br><select name="flms_role" required style="width:100%"><option value="team_manager">Team Manager</option><option value="referee">Referee</option></select></p><p><label>Username</label><br><input type="text" name="flms_username" required style="width:100%"></p><p><label>Email</label><br><input type="email" name="flms_email" required style="width:100%"></p><p><label>Password</label><br><input type="password" name="flms_password" required style="width:100%"></p><?php wp_nonce_field( 'flms_register_action', 'flms_register_nonce' ); ?><button type="submit" name="flms_register_submit" class="button button-primary" style="width:100%">Register</button></form></div>
        <?php return ob_get_clean();
    }

    public function handle_registration() {
        if ( isset( $_POST['flms_register_submit'] ) ) {
            if ( ! isset( $_POST['flms_register_nonce'] ) || ! wp_verify_nonce( $_POST['flms_register_nonce'], 'flms_register_action' ) ) wp_die( 'Security check failed' );
            $username = sanitize_user( $_POST['flms_username'] ); $email = sanitize_email( $_POST['flms_email'] ); $password = $_POST['flms_password'];
            $role = sanitize_text_field( $_POST['flms_role'] ); if ( ! in_array( $role, ['team_manager', 'referee'] ) ) $role = 'team_manager';
            $user_id = wp_create_user( $username, $password, $email );
            if ( is_wp_error( $user_id ) ) { wp_safe_redirect( home_url( '/register/?reg_error=' . urlencode($user_id->get_error_message()) ) ); exit; } 
            else { 
                $user = new WP_User( $user_id ); $user->set_role( $role );
                update_user_meta( $user_id, 'flms_account_status', 'pending' );
                wp_safe_redirect( home_url( '/login/?registered=pending' ) ); exit;
            }
        }
    }

    public function login_form() {
        if ( is_user_logged_in() ) return '<p>Logged in. <a href="'.wp_logout_url(home_url()).'">Logout</a></p>';
        if ( isset($_GET['registered']) && $_GET['registered'] == 'pending' ) echo '<div style="background:#d4edda; color:#155724; padding:15px; margin-bottom:20px; border-radius:5px;">Registration successful! Pending Approval.</div>';
        if ( isset($_GET['login_error']) ) { $err_msg = ($_GET['login_error'] == 'pending') ? 'Account pending approval.' : 'Invalid credentials.'; echo '<div style="background:#f8d7da; color:#721c24; padding:15px; margin-bottom:20px; border-radius:5px;"><strong>Error:</strong> '.$err_msg.'</div>'; }
        $args = [ 'form_id' => 'flms-login', 'label_username' => 'Username', 'label_log_in' => 'Login', 'remember' => true ];
        ob_start(); echo '<div style="max-width:600px; margin:0 auto; padding:20px; border:1px solid #ddd; background:#fff; border-radius:8px;">'; wp_login_form( $args ); echo '</div>'; return ob_get_clean();
    }

    public function check_active_status( $user, $password ) {
        if ( is_wp_error( $user ) ) return $user;
        $status = get_user_meta( $user->ID, 'flms_account_status', true );
        if ( $status === 'pending' && ! user_can( $user, 'administrator' ) ) { wp_redirect( home_url( '/login/?login_error=pending' ) ); exit; }
        return $user;
    }

    public function redirect_on_login_failure( $username ) {
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';
        if ( !empty($referrer) && !strstr($referrer,'wp-login') && !strstr($referrer,'wp-admin') ) { wp_redirect( $referrer . '?login_error=invalid' ); exit; }
    }

    public function add_status_column( $columns ) { $columns['flms_status'] = 'Account Status'; return $columns; }
    public function show_status_column_data( $value, $column_name, $user_id ) {
        if ( 'flms_status' == $column_name ) {
            $status = get_user_meta( $user_id, 'flms_account_status', true );
            $approve_url = wp_nonce_url( add_query_arg(['flms_action' => 'approve', 'user_id' => $user_id], admin_url('users.php')), 'flms_quick_action' );
            $reject_url = wp_nonce_url( add_query_arg(['flms_action' => 'reject', 'user_id' => $user_id], admin_url('users.php')), 'flms_quick_action' );
            if ( $status == 'pending' ) return '<span style="background:#f1c40f; color:#000; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:11px;">Pending</span><div style="font-size:12px;"><a href="'.$approve_url.'" style="color:green;">Approve</a> | <a href="'.$reject_url.'" style="color:red;">Reject</a></div>';
            elseif ( $status == 'active' ) return '<span style="color:green; font-weight:bold;">Active</span>';
            elseif ( $status == 'rejected' ) return '<span style="color:red; font-weight:bold;">Rejected</span>';
        }
        return $value;
    }

    public function process_quick_actions() {
        if ( ! isset( $_GET['flms_action'], $_GET['user_id'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'flms_quick_action' ) || ! current_user_can('edit_users') ) return;
        $user_id = intval( $_GET['user_id'] ); $action = sanitize_text_field( $_GET['flms_action'] );
        if ( $action === 'approve' ) { update_user_meta( $user_id, 'flms_account_status', 'active' ); } 
        elseif ( $action === 'reject' ) { update_user_meta( $user_id, 'flms_account_status', 'rejected' ); }
        wp_redirect( remove_query_arg(['flms_action', 'user_id', '_wpnonce'], wp_get_referer()) ); exit;
    }

    public function add_approval_field( $user ) {
        $allowed = ['team_manager', 'referee', 'referee_leader']; if ( ! array_intersect( $allowed, (array)$user->roles ) ) return;
        $status = get_user_meta( $user->ID, 'flms_account_status', true );
        echo '<h3>Account Status</h3><table class="form-table"><tr><th>Status</th><td><select name="flms_account_status"><option value="pending" '.selected($status,'pending',0).'>Pending</option><option value="active" '.selected($status,'active',0).'>Active</option><option value="rejected" '.selected($status,'rejected',0).'>Rejected</option></select></td></tr></table>';
    }

    public function save_approval_field( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;
        if ( isset( $_POST['flms_account_status'] ) ) update_user_meta( $user_id, 'flms_account_status', $_POST['flms_account_status'] );
    }

    public function remove_admin_bar() { if ( ! current_user_can( 'administrator' ) && ! is_admin() ) show_admin_bar( false ); }
    public function bypass_logout_confirmation() { if ( isset( $_GET['action'] ) && $_GET['action'] === 'logout' ) { wp_logout(); wp_redirect( home_url( '/login/?msg=logged_out' ) ); exit; } }

    public function add_impersonate_column( $columns ) { $columns['flms_access'] = 'Manager Access'; return $columns; }
    public function render_impersonate_column( $column, $post_id ) {
        if ( $column === 'flms_access' ) {
            $author_id = get_post_field( 'post_author', $post_id ); $user = get_userdata( $author_id );
            if ( $user && current_user_can('administrator') ) { $url = admin_url( 'admin.php?action=flms_impersonate&user_id=' . $author_id . '&_wpnonce=' . wp_create_nonce('flms_imp_user') ); echo '<a href="' . esc_url($url) . '" class="button button-small" style="background:#333; color:#fff; border:none;">Login as ' . esc_html($user->display_name) . '</a>'; }
        }
    }
    public function process_impersonation() { if ( ! current_user_can('administrator') ) wp_die('Unauthorized'); check_admin_referer( 'flms_imp_user' ); $user_id = intval( $_GET['user_id'] ); $user = get_userdata( $user_id ); if ( $user ) { wp_set_auth_cookie( $user_id ); wp_redirect( home_url( '/my-team-dashboard/' ) ); exit; } }
}