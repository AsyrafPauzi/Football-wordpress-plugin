<?php
class FLMS_Transfer_System {

    public function __construct() {
        // Core Transfer Logic
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'init', [ $this, 'handle_actions' ] );

        // WooCommerce Hooks (For Fees & Paid Actions)
        add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_data' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'add_order_item_data' ], 10, 4 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'execute_paid_actions' ], 10, 1 );

        // AJAX Lookup for Popup Confirmation
        add_action( 'wp_ajax_flms_check_player', [ $this, 'ajax_check_player_details' ] );
        add_action( 'wp_ajax_nopriv_flms_check_player', [ $this, 'ajax_check_player_details' ] );
    }

    public function register_cpt() {
        register_post_type( 'flms_transfer', [
            'label' => 'Transfers', 'public' => false, 'show_ui' => true, 
            'supports' => ['title', 'custom-fields']
        ]);
    }

    // --- NEW: AJAX FUNCTION TO FIND PLAYER BY IC (UPDATED: PASSPORT SUPPORT) ---
    public function ajax_check_player_details() {
        $raw_ic = isset($_POST['ic']) ? sanitize_text_field($_POST['ic']) : '';
        
        // CLEAN IC: Uppercase + Remove Non-Alphanumeric (Allows Letters for Passport)
        $ic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $raw_ic)); 
        
        if ( empty($ic) ) {
            wp_send_json_error( 'Please enter an IC/Passport Number.' );
        }

        // Search for the Player
        $players = get_posts([
            'post_type' => 'flms_player',
            'meta_key' => 'flms_ic',
            'meta_value' => $ic,
            'posts_per_page' => 1
        ]);

        if ( empty($players) ) {
            wp_send_json_error( 'No player found with ID: ' . $raw_ic );
        }

        // Return the Name and Team
        $player = $players[0];
        $team_id = get_post_meta( $player->ID, 'flms_team_id', true );
        $team_name = $team_id ? get_the_title( $team_id ) : 'Free Agent';

        wp_send_json_success([
            'name' => $player->post_title,
            'team' => $team_name
        ]);
    }

    // --- 1. HANDLE REQUESTS / APPROVALS (Manager Dashboard) ---
    public function handle_actions() {
        if ( ! is_user_logged_in() || ! isset($_POST['flms_transfer_act']) ) return;

        // A. REQUEST TRANSFER
        if ( $_POST['flms_transfer_act'] === 'request' ) {
            check_admin_referer( 'flms_request_nonce' );
            
            $raw_ic = sanitize_text_field( $_POST['player_ic'] );
            // CLEAN IC: Uppercase + Allow Letters
            $ic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $raw_ic)); 

            $requesting_team_id = intval( $_POST['my_team_id'] );
            $req_team = get_post( $requesting_team_id );
            if ( ! $req_team || $req_team->post_type !== 'flms_team' || (int) $req_team->post_author !== get_current_user_id() ) {
                wp_die( esc_html__( 'Unauthorized: invalid team.', 'flms' ), '', [ 'response' => 403 ] );
            }

            // Check Stage
            $tournament_id = get_post_meta($requesting_team_id, 'flms_tournament_id', true);
            $stage = class_exists('FLMS_Competitions') ? FLMS_Competitions::get_current_stage($tournament_id) : 'open';
            
            if ( $stage === 'locked' ) wp_die('Transfer Window is locked.');

            // Find Player
            $players = get_posts(['post_type'=>'flms_player', 'meta_key'=>'flms_ic', 'meta_value'=>$ic, 'posts_per_page'=>1]);
            if(empty($players)) wp_die('Player not found.');
            $player = $players[0];

            $current_team_id = get_post_meta($player->ID, 'flms_team_id', true);
            if( $current_team_id == $requesting_team_id ) wp_die('Player is already on your team.');

            // Create Request
            $req_id = wp_insert_post([
                'post_type' => 'flms_transfer',
                'post_status' => 'pending', 
                'post_title' => 'Transfer: ' . $player->post_title
            ]);

            update_post_meta($req_id, '_player_id', $player->ID);
            update_post_meta($req_id, '_from_team', $current_team_id);
            update_post_meta($req_id, '_to_team', $requesting_team_id);
            update_post_meta($req_id, '_stage', $stage); // 'open' or 'paid'

            // Notify Current Manager
            if($current_team_id) {
                $mgr_id = (int) get_post_field('post_author', $current_team_id);
                $mgr = $mgr_id ? get_userdata($mgr_id) : false;
                $req_team_name = get_the_title($requesting_team_id);
                if ( $mgr && ! empty( $mgr->user_email ) && is_email( $mgr->user_email ) ) {
                    wp_mail( $mgr->user_email, 'Transfer Request', "$req_team_name wants to sign {$player->post_title}. Please login to Approve/Reject." );
                }
            } else {
                // Free Agent
                if($stage === 'open') {
                    $this->finalize_transfer($req_id);
                } else {
                    // Paid Free Agent Sign
                    wp_update_post(['ID' => $req_id, 'post_status' => 'private']);
                    update_post_meta($req_id, '_payment_status', 'pending');
                    $this->notify_requesting_manager_for_payment($req_id);
                }
            }

            wp_redirect( add_query_arg( 'msg', 'req_sent', remove_query_arg('flms_transfer_act') ) );
            exit;
        }

        // B. APPROVE TRANSFER
        if ( $_POST['flms_transfer_act'] === 'approve' ) {
            check_admin_referer( 'flms_approve_nonce' );
            $req_id = intval($_POST['req_id']);
            
            // Verify Owner
            $from_team = (int) get_post_meta($req_id, '_from_team', true);
            $team_post = $from_team ? get_post($from_team) : null;
            if ( ! $team_post || $team_post->post_type !== 'flms_team' || (int) $team_post->post_author !== get_current_user_id() ) {
                wp_die( esc_html__( 'Unauthorized', 'flms' ), '', [ 'response' => 403 ] );
            }

            $stage = get_post_meta($req_id, '_stage', true);

            if ( $stage === 'open' ) {
                $this->finalize_transfer($req_id);
                wp_redirect( add_query_arg( 'msg', 'approved_moved', remove_query_arg('flms_transfer_act') ) );
            } else {
                // Change to Awaiting Payment
                wp_update_post(['ID' => $req_id, 'post_status' => 'private']);
                update_post_meta($req_id, '_payment_status', 'pending');
                $this->notify_requesting_manager_for_payment($req_id);
                wp_redirect( add_query_arg( 'msg', 'approved_awaiting_pay', remove_query_arg('flms_transfer_act') ) );
            }
            exit;
        }
        
        // C. REJECT TRANSFER
        if ( $_POST['flms_transfer_act'] === 'reject' ) {
            check_admin_referer( 'flms_approve_nonce' );
            $req_id = intval($_POST['req_id']);
            wp_update_post(['ID' => $req_id, 'post_status' => 'draft']); // Draft = Rejected
            
            wp_redirect( add_query_arg( 'msg', 'rejected', remove_query_arg('flms_transfer_act') ) );
            exit;
        }
    }

    // Helper: Execute Move
    public function finalize_transfer( $req_id ) {
        $pid = get_post_meta($req_id, '_player_id', true);
        $to_team = get_post_meta($req_id, '_to_team', true);
        update_post_meta($pid, 'flms_team_id', $to_team);
        wp_update_post(['ID' => $req_id, 'post_status' => 'publish']);
    }
    
    // Helper: Notify to Pay
    private function notify_requesting_manager_for_payment($req_id) {
        $to_team = (int) get_post_meta($req_id, '_to_team', true);
        if ( ! $to_team ) {
            return;
        }
        $mgr_id = (int) get_post_field('post_author', $to_team);
        $mgr = $mgr_id ? get_userdata($mgr_id) : false;
        if ( $mgr && ! empty( $mgr->user_email ) && is_email( $mgr->user_email ) ) {
            wp_mail( $mgr->user_email, 'Transfer Approved - Payment Required', 'Your request was approved. Please proceed with payment.' );
        }
    }

    // --- 2. WOOCOMMERCE CART DISPLAY ---
    public function display_cart_item_data( $item_data, $cart_item ) {
        if ( isset( $cart_item['transfer_pid'] ) ) {
            $p_name = get_the_title( $cart_item['transfer_pid'] );
            $item_data[] = [ 'key' => 'Transferring Player', 'value' => $p_name ];
        }
        if ( isset( $cart_item['action_type'] ) && $cart_item['action_type'] === 'add_player' ) {
             $item_data[] = [ 'key' => 'Type', 'value' => 'New Player Registration' ];
             $item_data[] = [ 'key' => 'Name', 'value' => $cart_item['new_p_data']['name'] ];
        }
        return $item_data;
    }

    // --- 3. SAVE META TO ORDER ---
    public function add_order_item_data( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['transfer_pid'] ) ) {
            $item->add_meta_data( '_transfer_pid', $values['transfer_pid'] );
            $item->add_meta_data( '_transfer_target_team', $values['transfer_target_team'] );
        }
        if ( isset( $values['action_type'] ) && $values['action_type'] === 'add_player' ) {
            $item->add_meta_data( '_action_type', 'add_player' );
            $item->add_meta_data( '_new_p_data', json_encode($values['new_p_data']) );
        }
    }

    // --- 4. EXECUTE PAID ACTIONS (Transfer OR Add Player) ---
    public function execute_paid_actions( $order_id ) {
        $order = wc_get_order( $order_id );
        
        foreach ( $order->get_items() as $item ) {
            
            // A. PAID TRANSFER
            $pid = $item->get_meta( '_transfer_pid' );
            $tid = $item->get_meta( '_transfer_target_team' );

            if ( $pid && $tid ) {
                $buyer_id = (int) $order->get_user_id();
                $target_team = get_post( (int) $tid );
                if ( ! $target_team || $target_team->post_type !== 'flms_team' || (int) $target_team->post_author !== $buyer_id ) {
                    $order->add_order_note( 'Transfer payment skipped: order user does not own target team.' );
                } else {
                    update_post_meta( (int) $pid, 'flms_team_id', (int) $tid );
                    if ( class_exists( 'FLMS_Player_Stats' ) ) {
                        ( new FLMS_Player_Stats() )->recalculate_single_player( (int) $pid );
                    }
                    $order->add_order_note( 'Paid Transfer complete.' );
                }
            }

            // B. PAID ADD PLAYER
            $action = $item->get_meta( '_action_type' );
            if ( $action === 'add_player' ) {
                $json_data = $item->get_meta('_new_p_data');
                $data = json_decode( $json_data, true );
                if ( ! $data ) { $data = json_decode( wp_unslash($json_data), true ); }

                if ( $data && !empty($data['name']) ) {
                    $new_pid = wp_insert_post(
                        [
                            'post_type'   => 'flms_player',
                            'post_title'  => sanitize_text_field( $data['name'] ),
                            'post_status' => 'publish',
                            'post_author' => $order->get_user_id(),
                        ],
                        true
                    );
                    if ( ! is_wp_error( $new_pid ) && $new_pid ) {
                        update_post_meta($new_pid, 'flms_team_id', $data['tid']);
                        update_post_meta($new_pid, 'flms_position', $data['pos']);
                        update_post_meta($new_pid, 'flms_age', $data['age']);
                        // CLEAN IC SAVED HERE
                        update_post_meta($new_pid, 'flms_ic', $data['ic']);
                        update_post_meta($new_pid, 'flms_number', $data['num']);
                        update_post_meta($new_pid, 'flms_total_goals', 0);
                        update_post_meta($new_pid, 'flms_ranking_points', 0);
                        $order->add_order_note("Paid Player Registration complete: " . $data['name']);
                    } elseif ( is_wp_error( $new_pid ) ) {
                        $order->add_order_note( 'Paid player registration failed: ' . $new_pid->get_error_message() );
                    }
                }
            }
        }
    }
}