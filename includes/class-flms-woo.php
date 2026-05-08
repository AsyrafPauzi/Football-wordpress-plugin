<?php
class FLMS_Woo {
    public function __construct() {
        // Tournament Registration
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_manager' ], 10, 3 );
        add_action( 'woocommerce_after_order_notes', [ $this, 'roster_selection_field' ] );
        add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_roster_count' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_roster_meta' ], 10, 2 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'process_tournament_entry' ], 10, 1 );
        
        // --- MATCH FEES & PAYMENTS ---
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'override_match_fee_price' ], 10, 1 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'display_match_fee_data' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'add_match_fee_meta' ], 10, 4 );
        
        // Unified Order Processing
        add_action( 'woocommerce_order_status_completed', [ $this, 'process_match_fee_payment' ], 10, 1 );

        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_friendly_fee_consent' ], 10, 6 );
    }

    /**
     * Block friendly fee checkout if participation consent was not confirmed (non-exempt products).
     */
    public function validate_friendly_fee_consent( $passed, $product_id = 0, $quantity = 0, $variation_id = 0, $variation = null, $cart_item_data = [] ) {
        $args = func_get_args();
        if ( isset( $args[5] ) && is_array( $args[5] ) ) {
            $cart_item_data = $args[5];
        }
        if ( ! $passed || ! is_array( $cart_item_data ) || empty( $cart_item_data['friendly_fee_id'] ) || empty( $cart_item_data['friendly_fee_team'] ) ) {
            return $passed;
        }
        if ( ! class_exists( 'FLMS_Friendly' ) ) {
            return $passed;
        }
        $fid = (int) $cart_item_data['friendly_fee_id'];
        $tid = (int) $cart_item_data['friendly_fee_team'];
        if ( ! FLMS_Friendly::friendly_fee_consent_required() ) {
            return $passed;
        }
        if ( ! FLMS_Friendly::verify_friendly_pay_consent_ok( $fid, $tid ) ) {
            wc_add_notice( __( 'Please agree to the participation terms before paying.', 'flms' ), 'error' );
            return false;
        }
        return $passed;
    }

    public function validate_manager( $passed ) {
        if ( current_user_can('administrator') ) return $passed;
        if ( ! is_user_logged_in() ) {
            wc_add_notice( 'Please login as a Team Manager.', 'error' );
            return false;
        }
        return $passed;
    }

    // --- 1. DYNAMIC MATCH FEE PRICE (and Friendly Fee RM500) ---
    public function override_match_fee_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        $friendly_product_id = defined( 'FLMS_FRIENDLY_FEE_PRODUCT_ID' ) ? (int) FLMS_FRIENDLY_FEE_PRODUCT_ID : 0;

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['match_fee_id'] ) ) {
                $match_id = $cart_item['match_fee_id'];
                $custom_price = get_post_meta( $match_id, '_flms_match_fee', true );
                if ( $custom_price ) {
                    $cart_item['data']->set_price( floatval( $custom_price ) );
                }
            }
            if ( isset( $cart_item['friendly_fee_id'] ) && $friendly_product_id && (int) $cart_item['product_id'] === $friendly_product_id ) {
                $cart_item['data']->set_price( 500 );
            }
        }
    }

    // --- 2. DISPLAY IN CART ---
    public function display_match_fee_data( $item_data, $cart_item ) {
        if ( isset( $cart_item['match_fee_id'] ) ) {
            $h_id = get_post_meta($cart_item['match_fee_id'], 'flms_home_team', true);
            $a_id = get_post_meta($cart_item['match_fee_id'], 'flms_away_team', true);
            $match_name = get_the_title($h_id) . ' vs ' . get_the_title($a_id);
            $item_data[] = [ 'key' => 'Match', 'value' => $match_name ];
            $item_data[] = [ 'key' => 'Date', 'value' => get_post_meta($cart_item['match_fee_id'], 'flms_match_date', true) ];
        }
        if ( isset( $cart_item['friendly_fee_id'] ) ) {
            $fid = (int) $cart_item['friendly_fee_id'];
            $tid = (int) ( $cart_item['friendly_fee_team'] ?? 0 );
            $host_id = (int) get_post_meta( $fid, 'flms_host_team_id', true );
            $away_id = (int) get_post_meta( $fid, 'flms_chosen_team_id', true );
            $host_name = $host_id ? get_the_title( $host_id ) : '';
            $away_name = $away_id ? get_the_title( $away_id ) : '';
            $item_data[] = [ 'key' => 'Friendly', 'value' => $host_name . ' vs ' . $away_name ];
            $item_data[] = [ 'key' => 'Date', 'value' => get_post_meta( $fid, 'flms_friendly_date', true ) ];
            $item_data[] = [ 'key' => 'Your team', 'value' => $tid ? get_the_title( $tid ) : '' ];
        }
        return $item_data;
    }

    // --- 3. SAVE META TO ORDER ---
    public function add_match_fee_meta( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['match_fee_id'] ) ) {
            $item->add_meta_data( '_match_fee_id', $values['match_fee_id'] );
            $item->add_meta_data( '_match_fee_team', $values['match_fee_team'] );
        }
        if ( isset( $values['friendly_fee_id'] ) && isset( $values['friendly_fee_team'] ) ) {
            $item->add_meta_data( '_friendly_fee_id', $values['friendly_fee_id'] );
            $item->add_meta_data( '_friendly_fee_team', $values['friendly_fee_team'] );
        }
    }

    // --- 4. PROCESS ORDER (ROUTER) ---
    public function process_match_fee_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        
        // A. Check for Match Fees
        foreach ( $order->get_items() as $item ) {
            $mid = $item->get_meta( '_match_fee_id' );
            $tid = $item->get_meta( '_match_fee_team' );

            if ( $mid && $tid ) {
                $mid = (int) $mid;
                $tid = (int) $tid;
                $buyer_id = (int) $order->get_user_id();
                $home_team_id = (int) get_post_meta( $mid, 'flms_home_team', true );
                $away_team_id = (int) get_post_meta( $mid, 'flms_away_team', true );
                $team_post = get_post( $tid );
                $owns_team = $team_post && $team_post->post_type === 'flms_team' && (int) $team_post->post_author === $buyer_id;
                $is_participant = ( $tid === $home_team_id || $tid === $away_team_id );
                if ( ! $owns_team || ! $is_participant ) {
                    $order->add_order_note( 'Match fee skipped: team ownership or match participation could not be verified.' );
                } elseif ( $tid === $home_team_id ) {
                    update_post_meta( $mid, '_flms_paid_home', 'yes' );
                    update_post_meta( $mid, "_fee_receipt_$tid", $order_id );
                } else {
                    update_post_meta( $mid, '_flms_paid_away', 'yes' );
                    update_post_meta( $mid, "_fee_receipt_$tid", $order_id );
                }
            }

            // A2. Friendly match fee (RM500 per team)
            $fid = $item->get_meta( '_friendly_fee_id' );
            $fteam = $item->get_meta( '_friendly_fee_team' );
            if ( $fid && $fteam ) {
                $friendly = get_post( $fid );
                if ( $friendly && $friendly->post_type === 'flms_friendly' ) {
                    $host_id = (int) get_post_meta( $fid, 'flms_host_team_id', true );
                    $away_id = (int) get_post_meta( $fid, 'flms_chosen_team_id', true );
                    $fteam = (int) $fteam;
                    if ( $fteam === $host_id ) {
                        update_post_meta( $fid, '_flms_friendly_paid_host', 'yes' );
                        update_post_meta( $fid, '_flms_friendly_receipt_host', $order_id );
                    } elseif ( $fteam === $away_id ) {
                        update_post_meta( $fid, '_flms_friendly_paid_away', 'yes' );
                        update_post_meta( $fid, '_flms_friendly_receipt_away', $order_id );
                    }
                    $paid_h = get_post_meta( $fid, '_flms_friendly_paid_host', true ) === 'yes';
                    $paid_a = get_post_meta( $fid, '_flms_friendly_paid_away', true ) === 'yes';
                    update_post_meta( $fid, '_flms_friendly_payment_status', ( $paid_h && $paid_a ) ? 'fully_paid' : 'partial_paid' );
                }
            }
        }

        // B. Check for Tournament Registration
        $this->process_tournament_entry($order_id);
    }

    // --- TOURNAMENT REGISTRATION LOGIC ---
    public function process_tournament_entry( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // 1. DUPLICATE CHECK
        if ( $order->get_meta( '_flms_team_created_flag' ) === 'yes' ) return;

        $user_id = $order->get_user_id();
        $selected_ids_json = $order->get_meta( '_flms_selected_ids' );
        $new_json = $order->get_meta( '_flms_new_json' );
        
        // If NO player data, do not create a team (This prevents empty teams from processing)
        if ( ! $selected_ids_json && ! $new_json ) return;

        $tournament_line = $this->get_tournament_line_from_order( $order );
        if ( ! $tournament_line ) {
            $order->add_order_note( __( 'Tournament registration skipped: no tournament product line found on this order.', 'flms' ) );
            return;
        }

        $tournament_id = (int) $tournament_line['product_id'];
        $tournament_item = $tournament_line['item'];

        // Create New Team
        $club_name = get_user_meta( $user_id, 'flms_club_name', true ) ?: $order->get_billing_last_name() . ' FC';
        $suffix = method_exists( $tournament_item, 'get_name' ) ? $tournament_item->get_name() : get_the_title( $tournament_id );
        $final_team_name = $club_name . ' (' . $suffix . ')';

        $new_team_id = wp_insert_post(
            [
                'post_type'   => 'flms_team',
                'post_title'  => $final_team_name,
                'post_status' => 'publish',
                'post_author' => $user_id,
            ],
            true
        );

        if ( is_wp_error( $new_team_id ) || ! $new_team_id ) {
            $msg = is_wp_error( $new_team_id ) ? $new_team_id->get_error_message() : 'unknown';
            $order->add_order_note( sprintf( __( 'Failed to create league team: %s', 'flms' ), $msg ) );
            return;
        }

        $new_team_id = (int) $new_team_id;
        update_post_meta( $new_team_id, 'flms_tournament_id', $tournament_id );

        // Mark processed only after team exists.
        $order->update_meta_data( '_flms_team_created_flag', 'yes' );
        $order->save();

        // Copy Colors
        $last_teams = get_posts(['post_type'=>'flms_team', 'author'=>$user_id, 'posts_per_page'=>1, 'exclude' => [$new_team_id]]);
        if(!empty($last_teams)) {
            $old_id = $last_teams[0]->ID;
            update_post_meta($new_team_id, 'flms_home_color', get_post_meta($old_id, 'flms_home_color', true));
            update_post_meta($new_team_id, 'flms_away_color', get_post_meta($old_id, 'flms_away_color', true));
            $logo_id = get_post_thumbnail_id($old_id); if($logo_id) set_post_thumbnail($new_team_id, $logo_id);
        }

        // --- PART A: CLONE EXISTING PLAYERS ---
        if ($selected_ids_json) { 
            $old_ids = json_decode($selected_ids_json, true); 
            if(is_array($old_ids)) {
                foreach($old_ids as $old_pid) {
                    $p_name = get_the_title($old_pid);
                    $p_ic   = get_post_meta($old_pid, 'flms_ic', true);
                    $p_ic_clean = $this->normalize_ic( $p_ic );
                    $p_age  = get_post_meta($old_pid, 'flms_age', true);
                    $p_pos  = get_post_meta($old_pid, 'flms_position', true);
                    $p_num  = get_post_meta($old_pid, 'flms_number', true);

                    // Defensive guard: never allow duplicate IC in same tournament, even if validation was bypassed.
                    if ( ! empty( $p_ic_clean ) ) {
                        $collision_team_id = $this->find_ic_team_in_tournament( $p_ic_clean, $tournament_id );
                        if ( $collision_team_id ) {
                            $order->add_order_note(
                                sprintf(
                                    'Skipped player "%s" (%s): IC already registered in this tournament under team "%s".',
                                    $p_name,
                                    $p_ic_clean,
                                    get_the_title( $collision_team_id )
                                )
                            );
                            continue;
                        }
                    }
                    
                    $new_pid = wp_insert_post(
                        [
                            'post_type'   => 'flms_player',
                            'post_title'  => $p_name,
                            'post_status' => 'publish',
                            'post_author' => $user_id,
                        ],
                        true
                    );

                    if ( is_wp_error( $new_pid ) || ! $new_pid ) {
                        $em = is_wp_error( $new_pid ) ? $new_pid->get_error_message() : 'unknown';
                        $order->add_order_note( sprintf( __( 'Skipped cloning player "%s": %s', 'flms' ), $p_name, $em ) );
                        continue;
                    }
                    $new_pid = (int) $new_pid;

                    update_post_meta($new_pid, 'flms_ic', $p_ic_clean);
                    update_post_meta($new_pid, 'flms_age', $p_age);
                    update_post_meta($new_pid, 'flms_position', $p_pos);
                    update_post_meta($new_pid, 'flms_number', $p_num);
                    update_post_meta($new_pid, 'flms_team_id', $new_team_id);
                    update_post_meta($new_pid, 'flms_total_goals', 0);
                    update_post_meta($new_pid, 'flms_ranking_points', 0);
                    
                    $thumb_id = get_post_thumbnail_id($old_pid);
                    if($thumb_id) set_post_thumbnail($new_pid, $thumb_id);
                }
            }
        }

        // --- PART B: NEW PLAYERS ---
        if ($new_json) {
            $new_data = json_decode($new_json, true);
            if(is_array($new_data)) {
                foreach($new_data as $p) {
                    if(empty($p['name'])) continue;
                    
                    $ic = $this->normalize_ic( $p['ic'] ?? '' );

                    if ( ! empty( $ic ) ) {
                        $collision_team_id = $this->find_ic_team_in_tournament( $ic, $tournament_id );
                        if ( $collision_team_id ) {
                            $order->add_order_note(
                                sprintf(
                                    'Skipped player "%s" (%s): IC already registered in this tournament under team "%s".',
                                    sanitize_text_field( $p['name'] ),
                                    $ic,
                                    get_the_title( $collision_team_id )
                                )
                            );
                            continue;
                        }
                    }

                    $pid = wp_insert_post(
                        [
                            'post_type'   => 'flms_player',
                            'post_title'  => sanitize_text_field( $p['name'] ),
                            'post_status' => 'publish',
                            'post_author' => $user_id,
                        ],
                        true
                    );
                    if ( is_wp_error( $pid ) || ! $pid ) {
                        $em = is_wp_error( $pid ) ? $pid->get_error_message() : 'unknown';
                        $order->add_order_note( sprintf( __( 'Skipped new player "%s": %s', 'flms' ), sanitize_text_field( $p['name'] ), $em ) );
                        continue;
                    }
                    $pid = (int) $pid;
                    update_post_meta($pid, 'flms_total_goals', 0);
                    update_post_meta($pid, 'flms_ranking_points', 0);

                    update_post_meta($pid, 'flms_ic', $ic);
                    update_post_meta($pid, 'flms_age', sanitize_text_field($p['age']));
                    update_post_meta($pid, 'flms_nickname', sanitize_text_field($p['nickname']));
                    update_post_meta($pid, 'flms_position', sanitize_text_field($p['pos']));
                    update_post_meta($pid, 'flms_team_id', $new_team_id);
                }
            }
        }
    }

    // --- VALIDATION: STRICT CHECK (Fixes the loophole) ---
    public function validate_roster_count( $data, $errors ) {
        $friendly_product_id = defined( 'FLMS_FRIENDLY_FEE_PRODUCT_ID' ) ? (int) FLMS_FRIENDLY_FEE_PRODUCT_ID : 0;
        // 1. Skip validation ONLY if it is a Fee Payment (Match Fee or Transfer or Friendly Fee)
        //    AND there are NO Tournament products in the cart. Friendly fee product 23058 never triggers roster.
        $is_tournament_purchase = false;
        $tournament_id = 0;

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product_id = (int) $cart_item['product_id'];
            if ( $friendly_product_id && $product_id === $friendly_product_id ) {
                continue; // Never treat friendly fee product as tournament
            }
            $start_date = get_post_meta($product_id, '_flms_start_date', true);
            if ( ! empty($start_date) ) {
                $is_tournament_purchase = true;
                $tournament_id = $product_id;
                break;
            }
        }

        // If NOT buying a tournament, skip validation
        if ( ! $is_tournament_purchase ) return;

        // If buying a tournament, enforce the rules
        $min = 20; 
        $max = 35;
        $selected_players = isset($_POST['flms_selected_players']) && is_array($_POST['flms_selected_players']) ? array_map( 'intval', $_POST['flms_selected_players'] ) : [];
        $count_existing = count( $selected_players );
        $count_new = 0;
        $submitted_ics = [];

        // Validate selected existing players (from previous seasons) against tournament IC collisions.
        foreach ( $selected_players as $selected_pid ) {
            $selected_ic = $this->normalize_ic( get_post_meta( $selected_pid, 'flms_ic', true ) );
            $selected_name = get_the_title( $selected_pid );
            if ( empty( $selected_ic ) ) {
                $errors->add( 'validation', "Player '{$selected_name}' is missing IC/Passport number. Please fill in IC first before checkout." );
                continue;
            }

            if ( isset( $submitted_ics[ $selected_ic ] ) ) {
                $errors->add( 'validation', "Duplicate IC detected in your roster: '{$selected_ic}'. One IC can only appear once in one registration submission." );
                continue;
            }
            $submitted_ics[ $selected_ic ] = true;
            $this->check_ic_collision( $selected_ic, $tournament_id, $selected_name, $errors );
        }

        // Count New Players
        if ( ! empty($_POST['flms_new_players_json']) ) {
            $json = wp_unslash($_POST['flms_new_players_json']);
            $new_players = json_decode($json, true);
            
            if ( is_array($new_players) ) {
                foreach ( $new_players as $index => $p ) {
                    if ( ! empty($p['name']) ) {
                        // Check Compulsory IC
                        $ic = $this->normalize_ic( $p['ic'] ?? '' );

                        if ( empty($ic) ) {
                            $errors->add( 'validation', "Player '{$p['name']}' is missing IC/Passport number. Please fill in IC first before checkout." );
                        } 
                        else {
                            if ( isset( $submitted_ics[ $ic ] ) ) {
                                $errors->add( 'validation', "Duplicate IC detected in your roster: '{$ic}'. One IC can only appear once in one registration submission." );
                                $count_new++;
                                continue;
                            }
                            $submitted_ics[ $ic ] = true;
                            $this->check_ic_collision($ic, $tournament_id, $p['name'], $errors);
                        }
                        $count_new++;
                    }
                }
            }
        }

        $total = $count_existing + $count_new;
        if ( $total < $min ) $errors->add('validation', "Roster Error: Minimum $min players required. You have selected $total.");
        if ( $total > $max ) $errors->add('validation', "Roster Error: Maximum $max players allowed.");
    }

    private function check_ic_collision($ic, $tournament_id, $player_name, $errors) {
        $collision_team_id = $this->find_ic_team_in_tournament( $ic, $tournament_id );
        if ( $collision_team_id ) {
            $other_team_name = get_the_title( $collision_team_id );
            $errors->add(
                'validation',
                "<strong>Registration blocked:</strong> Player '{$player_name}' (IC: {$ic}) is already registered under team '<strong>{$other_team_name}</strong>' in this tournament.<br>Please check IC again or remove this player from the roster. One IC is allowed for one team only in the same league."
            );
        }
    }

    private function normalize_ic( $raw_ic ) {
        return strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', sanitize_text_field( (string) $raw_ic ) ) );
    }

    /**
     * First line item whose product is a tournament (has _flms_start_date).
     *
     * @param \WC_Order $order Order object.
     * @return array{product_id:int,item:\WC_Order_Item_Product}|null
     */
    private function get_tournament_line_from_order( $order ) {
        foreach ( $order->get_items() as $item ) {
            if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
                continue;
            }
            $product_id = (int) $item->get_product_id();
            if ( $product_id && get_post_meta( $product_id, '_flms_start_date', true ) ) {
                return [ 'product_id' => $product_id, 'item' => $item ];
            }
        }
        return null;
    }

    private function find_ic_team_in_tournament( $ic, $tournament_id ) {
        if ( empty( $ic ) || empty( $tournament_id ) ) {
            return 0;
        }

        $existing_players = get_posts([
            'post_type' => 'flms_player',
            'meta_key' => 'flms_ic',
            'meta_value' => $ic,
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        foreach ( $existing_players as $found_pid ) {
            $their_team = (int) get_post_meta( $found_pid, 'flms_team_id', true );
            if ( ! $their_team ) {
                continue;
            }

            $their_tour = (int) get_post_meta( $their_team, 'flms_tournament_id', true );
            if ( $their_tour === (int) $tournament_id ) {
                return $their_team;
            }
        }

        return 0;
    }
    
    // --- DISPLAY UI (FIXED: NO DUPLICATES IN CHECKLIST) ---
    public function roster_selection_field( $checkout ) {
        $friendly_product_id = defined( 'FLMS_FRIENDLY_FEE_PRODUCT_ID' ) ? (int) FLMS_FRIENDLY_FEE_PRODUCT_ID : 0;
        // Only show if a Tournament is in the cart. Friendly fee product 23058 never shows roster (no player min/max).
        $show_roster = false;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product_id = (int) $cart_item['product_id'];
            if ( $friendly_product_id && $product_id === $friendly_product_id ) {
                continue;
            }
            if ( get_post_meta($product_id, '_flms_start_date', true) ) {
                $show_roster = true;
                break;
            }
        }

        if ( ! $show_roster ) return;

        $user_id = get_current_user_id();
        echo '<div id="flms-roster-section" class="flms-checkout-roster-section"><h3 class="flms-checkout-roster-title">' . esc_html__( 'Tournament Roster', 'flms' ) . '</h3><p class="flms-checkout-roster-req">⚠️ ' . esc_html__( 'Requirement: Min 20 / Max 35 Players', 'flms' ) . '</p>';
        
        $my_players = get_posts([
            'post_type' => 'flms_player', 
            'posts_per_page' => -1, 
            'author' => $user_id, 
            'post_status' => 'publish', 
            'orderby' => 'title', 
            'order' => 'ASC'
        ]);
        
        if(!empty($my_players)) {
            echo '<div class="flms-existing-players flms-checkout-existing-players"><strong>' . esc_html__( 'Select Existing (From Previous Seasons):', 'flms' ) . '</strong><hr>';
            
            // --- NEW LOGIC: TRACK UNIQUE ICs TO PREVENT DUPLICATE ROWS ---
            $shown_ics = [];

            foreach($my_players as $p) {
                $ic = get_post_meta($p->ID, 'flms_ic', true);
                
                // Clean IC for comparison (Remove dashes/spaces)
                $clean_ic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $ic));

                // If IC is empty OR already shown, SKIP this player row
                if ( empty($clean_ic) || in_array($clean_ic, $shown_ics) ) {
                    continue;
                }

                // Add to "already shown" list
                $shown_ics[] = $clean_ic;

                $pos = get_post_meta($p->ID, 'flms_position', true);
                
                echo '<label style="display:block; margin-bottom:8px; cursor:pointer;">
                        <input type="checkbox" name="flms_selected_players[]" value="'.$p->ID.'"> 
                        <strong>'.esc_html($p->post_title).'</strong>
                        <span style="color:#666; font-size:12px;"> ('.$pos.') - '.$ic.'</span>
                      </label>';
            }
            echo '</div>';
        }
        echo '<strong class="flms-register-new-label">' . esc_html__( 'Register New:', 'flms' ) . '</strong><div id="flms-roster-rows" class="flms-roster-rows-inner"></div><button type="button" id="add-player-row" class="button flms-add-player-row-btn">+ ' . esc_html__( 'Add New', 'flms' ) . '</button><textarea name="flms_new_players_json" id="flms_new_players_json" style="display:none;"></textarea></div>';
    }

    public function save_roster_meta( $order, $data ) {
        if(isset($_POST['flms_selected_players'])) $order->update_meta_data('_flms_selected_ids', json_encode($_POST['flms_selected_players']));
        if(isset($_POST['flms_new_players_json'])) $order->update_meta_data('_flms_new_json', wp_unslash($_POST['flms_new_players_json']));
    }
}