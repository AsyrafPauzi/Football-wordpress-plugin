<?php
class FLMS_Manager_Dashboard {

    public function __construct() {
        // Register Shortcode
        add_shortcode( 'flms_my_team', [ $this, 'render_dashboard' ] );
        
        // Handle Form Submissions
        add_action( 'init', [ $this, 'process_actions' ] );
    }

    /**
     * 1. RENDER THE DASHBOARD
     */
    public function render_dashboard() {
        if ( ! is_user_logged_in() ) return '<p>Please <a href="/manager/login">login</a>.</p>';
        $user_id = get_current_user_id();

        // 1. GET ALL TEAMS OWNED BY THIS MANAGER
        $my_teams = get_posts([
            'post_type' => 'flms_team', 
            'author' => $user_id, 
            'posts_per_page' => -1, 
            'post_status' => 'any'
        ]);

        if ( empty( $my_teams ) ) return $this->render_create_team_form();

        // 2. DETERMINE ACTIVE TEAM
        $active_team_id = isset($_GET['manage_team']) ? intval($_GET['manage_team']) : $my_teams[0]->ID;
        
        // Security Check
        $active_team = get_post($active_team_id);
        if ( ! $active_team || $active_team->post_author != $user_id ) {
            $active_team = $my_teams[0];
            $active_team_id = $active_team->ID;
        }

        // 3. GET DATA FOR ACTIVE TEAM ONLY
        $players = get_posts(['post_type'=>'flms_player', 'meta_key'=>'flms_team_id', 'meta_value'=>$active_team_id, 'posts_per_page'=>-1, 'orderby'=>'title', 'order'=>'ASC']);
        
        $home_color = get_post_meta($active_team_id, 'flms_home_color', true) ?: '#cccccc';
        $away_color = get_post_meta($active_team_id, 'flms_away_color', true) ?: '#ffffff';
        $logo_url = function_exists('get_the_post_thumbnail_url') ? get_the_post_thumbnail_url($active_team_id, 'thumbnail') : '';

        // Tournament Stage
        $tournament_id = get_post_meta($active_team_id, 'flms_tournament_id', true);
        $tour_name = $tournament_id ? get_the_title($tournament_id) : 'Unassigned (Profile Mode)';
        $stage = ($tournament_id && class_exists('FLMS_Competitions')) ? FLMS_Competitions::get_current_stage($tournament_id) : 'open';

        // Stage Status Text
        $status_text = '🟢 Open';
        if($stage==='locked') $status_text = '🔒 Locked';
        if($stage==='paid') $status_text = '💰 Paid';

        // Data
        $next_match = get_posts([ 'post_type' => 'flms_match', 'posts_per_page' => 1, 'meta_query' => [ 'relation' => 'AND', [ 'key' => 'flms_match_status', 'value' => 'pending' ], [ 'relation' => 'OR', ['key'=>'flms_home_team','value'=>$active_team_id], ['key'=>'flms_away_team','value'=>$active_team_id] ] ], 'orderby' => 'meta_value', 'meta_key' => 'flms_match_date', 'order' => 'ASC' ]);
        $incoming_requests = get_posts(['post_type'=>'flms_transfer','meta_key'=>'_from_team','meta_value'=>$active_team_id,'post_status'=>'pending','posts_per_page'=>-1]);

        $inbox_url = home_url( '/inbox/' );
        $create_url = home_url( '/create-friendly-matches/' );
        $history_tab = isset( $_GET['history_tab'] ) ? sanitize_key( wp_unslash( $_GET['history_tab'] ) ) : 'friendly';
        if ( ! in_array( $history_tab, [ 'friendly', 'league' ], true ) ) {
            $history_tab = 'friendly';
        }

        // -------- Inbox badge counts (Open Requests + Active Announcements) --------
        $my_team_ids = array_map( function( $t ) { return (int) $t->ID; }, (array) $my_teams );
        $user_id = (int) $user_id;

        $today = current_time( 'Y-m-d' );

        // Count open friendly slots visible in Friendly Inbox -> Open Requests tab.
        $open_slots = get_posts([
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

        $open_requests_count = 0;
        foreach ( (array) $open_slots as $sid ) {
            $sid = (int) $sid;
            if ( ! $sid ) continue;

            // Skip slots created by this manager (same as frontend inbox logic).
            $slot_author = (int) get_post_field( 'post_author', $sid );
            if ( $slot_author === $user_id ) continue;

            // Skip if any of manager's teams is the host for that slot.
            $host_team_id = (int) get_post_meta( $sid, 'flms_host_team_id', true );
            if ( $host_team_id && in_array( $host_team_id, $my_team_ids, true ) ) continue;

            // Skip if slot date is already in the past (frontend inbox logic).
            $date = get_post_meta( $sid, 'flms_friendly_date', true );
            if ( $date && $date < $today ) continue;

            $open_requests_count++;
        }

        // Count active inbox announcements (start/end window).
        $now_ts = current_time( 'timestamp' );
        $announcement_ids = get_posts([
            'post_type'      => 'flms_inbox_notice',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => 'flms_announce_start_ts',
            'orderby'        => 'meta_value',
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

        $announcement_count = (int) count( (array) $announcement_ids );
        $total_inbox_badge = (int) $open_requests_count + (int) $announcement_count;

        ob_start();
        ?>
        <div class="flms-dashboard-wrapper">
            <div class="flms-dash-quick-links" style="margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid #eee;">
                <a href="<?php echo esc_url( $inbox_url ); ?>" style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px; margin-right:8px; background:#f5f5f5; color:#333; text-decoration:none; border-radius:6px; font-size:13px; font-weight:600;">
                    Inbox
                    <span class="flms-tab-badge" style="margin-left:0;" title="Open requests + active announcements"><?php echo (int) $total_inbox_badge; ?></span>
                </a>
                <a href="<?php echo esc_url( $create_url ); ?>" style="display:inline-block; padding:8px 16px; background:#f5f5f5; color:#333; text-decoration:none; border-radius:6px; font-size:13px; font-weight:600;">Create Friendly Match</a>
            </div>
            
            <!-- TEAM SWITCHER -->
            <?php if(count($my_teams) > 1): ?>
            <div class="flms-team-switcher" style="background:#f0f0f0; padding:15px; border-radius:8px 8px 0 0; border-bottom:1px solid #ddd; margin:-20px -20px 20px -20px;">
                <form method="get" style="display:flex; align-items:center; gap:10px; justify-content:space-between;">
                    <label style="font-weight:bold; color:#333;">Select Tournament / Team:</label>
                    <select name="manage_team" onchange="this.form.submit()" style="padding:8px; border-radius:4px; border:1px solid #ccc; max-width:300px;">
                        <?php foreach($my_teams as $t): 
                            $t_tour_id = get_post_meta($t->ID, 'flms_tournament_id', true);
                            $t_tour_name = $t_tour_id ? get_the_title($t_tour_id) : 'Unassigned';
                        ?>
                            <option value="<?php echo $t->ID; ?>" <?php selected($active_team_id, $t->ID); ?>>
                                <?php echo esc_html($t_tour_name . ' - ' . $t->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php endif; ?>

            <!-- HEADER -->
            <div class="flms-dash-header">
                <div style="display:flex; align-items:center; gap:15px;">
                    <?php if($logo_url): ?>
                        <img src="<?php echo esc_url($logo_url); ?>" class="team-dash-logo">
                    <?php else: ?>
                        <div class="team-dash-logo-placeholder">Logo</div>
                    <?php endif; ?>
                    <div>
                        <h2 style="margin:0; font-size:24px;"><?php echo esc_html($active_team->post_title); ?></h2>
                        <div style="font-size:12px; color:#888; font-weight:bold; margin-bottom:5px;"><?php echo esc_html($tour_name); ?></div>
                        <div class="flms-kit-display">
                            <span class="kit-badge" style="background:<?php echo esc_attr($home_color); ?>;" title="Home">H</span>
                            <span class="kit-badge" style="background:<?php echo esc_attr($away_color); ?>; border:1px solid #ddd; color:#333;" title="Away">A</span>
                            <span style="font-size:11px; margin-left:10px; color:#666;">Window: <?php echo $status_text; ?></span>
                        </div>
                    </div>
                </div>
                
                <form method="post" enctype="multipart/form-data" style="text-align:right;">
                    <label class="btn-tiny" style="cursor:pointer;">
                        Update Logo <input type="file" name="team_logo" accept="image/*" style="display:none;" onchange="this.form.submit()">
                    </label>
                    <input type="hidden" name="flms_action" value="update_logo">
                    <input type="hidden" name="team_id" value="<?php echo $active_team_id; ?>">
                    <?php wp_nonce_field('flms_logo_nonce'); ?>
                </form>
            </div>

            <div class="flms-history-tabs">
                <?php
                $friendly_tab_url = esc_url( add_query_arg(
                    [ 'manage_team' => $active_team_id, 'history_tab' => 'friendly' ],
                    remove_query_arg( [ 'paged' ] )
                ) );
                $league_tab_url = esc_url( add_query_arg(
                    [ 'manage_team' => $active_team_id, 'history_tab' => 'league' ],
                    remove_query_arg( [ 'paged' ] )
                ) );
                ?>
                <a class="flms-history-tab <?php echo $history_tab === 'friendly' ? 'is-active' : ''; ?>" href="<?php echo $friendly_tab_url; ?>">Completed Friendly Matches</a>
                <a class="flms-history-tab <?php echo $history_tab === 'league' ? 'is-active' : ''; ?>" href="<?php echo $league_tab_url; ?>">Completed League Matches</a>
            </div>

            <?php echo $this->render_completed_matches_panel( $active_team_id, $history_tab ); ?>

            <!-- 1. REQUESTS -->
            <?php if (!empty($incoming_requests)): ?>
            <div class="flms-requests-box">
                <h3>⚠️ Transfer Requests</h3>
                <table class="flms-league-table">
                    <thead><tr><th>Player</th><th>From</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($incoming_requests as $req): $pid = get_post_meta($req->ID, '_player_id', true); $to_team = get_post_meta($req->ID, '_to_team', true); $p_name = get_the_title($pid); $t_name = get_the_title($to_team); ?>
                        <tr><td><strong><?php echo esc_html($p_name); ?></strong></td><td><?php echo esc_html($t_name); ?></td><td><form method="post" style="display:inline;"><input type="hidden" name="req_id" value="<?php echo $req->ID; ?>"><?php wp_nonce_field('flms_approve_nonce'); ?><button type="submit" name="flms_transfer_act" value="approve" class="button btn-green">Approve</button><button type="submit" name="flms_transfer_act" value="reject" class="button btn-red">Reject</button></form></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- 2. PENDING PAYMENTS -->
            <?php 
            $my_pending_transfers = get_posts([ 'post_type' => 'flms_transfer', 'meta_key' => '_to_team', 'meta_value' => $active_team_id, 'post_status' => 'private', 'posts_per_page' => -1 ]);
            if ( ! empty($my_pending_transfers) ) : ?>
            <div class="flms-finance-box" style="border-color: #f39c12; background: #fffdf5;">
                <h3 style="color:#d35400;">⚠️ Transfers Awaiting Payment</h3>
                <table class="flms-league-table">
                    <thead><tr><th>Player</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($my_pending_transfers as $trans): $pid = get_post_meta($trans->ID, '_player_id', true); $p_name = get_the_title($pid); ?>
                        <tr><td><strong><?php echo esc_html($p_name); ?></strong></td><td style="color:#e67e22; font-weight:bold;">Approved (Fee Required)</td><td><form method="post"><input type="hidden" name="flms_action" value="pay_transfer_fee"><input type="hidden" name="transfer_id" value="<?php echo $trans->ID; ?>"><input type="hidden" name="player_id" value="<?php echo $pid; ?>"><input type="hidden" name="target_team" value="<?php echo $active_team_id; ?>"><?php wp_nonce_field('flms_pay_transfer_nonce'); ?><button type="submit" class="button" style="background:#d35400; color:#fff; border:none;">Pay Fee (RM5)</button></form></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- LINEUP -->
            <?php if(!empty($next_match)): $mid = $next_match[0]->ID; $hid = get_post_meta($mid, 'flms_home_team', true); $aid = get_post_meta($mid, 'flms_away_team', true); $is_home = ($hid == $active_team_id); $opponent_id = $is_home ? $aid : $hid; $opponent_name = get_the_title($opponent_id); $date = get_post_meta($mid, 'flms_match_date', true); $meta_key = $is_home ? '_flms_lineup_home' : '_flms_lineup_away'; $saved_lineup = get_post_meta($mid, $meta_key, true) ?: []; ?>
            <div class="flms-lineup-box">
                <h3>📋 Next Match Lineup</h3>
                <p><strong>VS <?php echo $opponent_name; ?></strong> (<?php echo $date ? date('d M', strtotime($date)) : 'Date TBD'; ?>)</p>
                <form method="post">
                    <div class="lineup-list">
                        <?php foreach($players as $p): $checked = in_array($p->ID, $saved_lineup) ? 'checked' : ''; ?>
                        <label><input type="checkbox" name="match_lineup[]" value="<?php echo $p->ID; ?>" <?php echo $checked; ?>> <?php echo esc_html($p->post_title); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="flms_action" value="save_lineup"><input type="hidden" name="match_id" value="<?php echo $mid; ?>"><input type="hidden" name="is_home" value="<?php echo $is_home ? '1' : '0'; ?>"><?php wp_nonce_field('flms_lineup_nonce'); ?>
                    <button type="submit" class="button btn-blue">Save Lineup</button>
                </form>
            </div>
            <?php endif; ?>

            <!-- MATCH FEES -->
           <div class="flms-finance-box">
                <h3>💰 Match Fees</h3>
                <div class="flms-table-responsive">
                    <table class="flms-league-table">
                        <thead><tr><th>Match</th><th>Date</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php 
                            // UPDATED QUERY: Removed the check that hid completed matches.
                            // Now it shows all matches (Limit 10) so you can pay for past games too.
                            $finance_matches = get_posts([
                                'post_type' => 'flms_match',
                                'posts_per_page' => 10, // Increased limit to show history
                                'meta_query' => [
                                    'relation' => 'AND',
                                    // Removed the "!= completed" check here
                                    [ 
                                        'relation' => 'OR', 
                                        ['key'=>'flms_home_team','value'=>$active_team_id], 
                                        ['key'=>'flms_away_team','value'=>$active_team_id] 
                                    ] 
                                ],
                                'orderby' => 'meta_value',
                                'meta_key' => 'flms_match_date',
                                'order' => 'DESC' // Show newest/upcoming first (or change to ASC if you want oldest debt first)
                            ]);

                            if(empty($finance_matches)): ?>
                                <tr><td colspan="4" style="text-align:center;">No match records found.</td></tr>
                            <?php else: 
                                foreach($finance_matches as $fm): 
                                    $mid = $fm->ID; 
                                    $date = get_post_meta($mid, 'flms_match_date', true); 
                                    $hid = get_post_meta($mid, 'flms_home_team', true); 
                                    $aid = get_post_meta($mid, 'flms_away_team', true); 
                                    
                                    $is_home = ($active_team_id == $hid);
                                    $opponent_id = $is_home ? $aid : $hid;
                                    $opponent_name = get_the_title($opponent_id);

                                    // Payment Check
                                    $paid = $is_home ? get_post_meta($mid, '_flms_paid_home', true) : get_post_meta($mid, '_flms_paid_away', true); 
                                    $fee = get_post_meta($mid, '_flms_match_fee', true) ?: '100'; 
                            ?>
                            <tr>
                                <td>vs <strong><?php echo esc_html($opponent_name); ?></strong></td>
                                <td><?php echo esc_html($date); ?></td>
                                <td>
                                    <?php if($paid === 'yes'): ?>
                                        <span style="color:green; font-weight:bold;">✅ Paid</span>
                                    <?php else: ?>
                                        <span style="color:red; font-weight:bold;">❌ Unpaid (RM<?php echo $fee; ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($paid !== 'yes'): ?>
                                        <form method="post">
                                            <input type="hidden" name="flms_action" value="pay_match_fee">
                                            <input type="hidden" name="match_id" value="<?php echo $mid; ?>">
                                            <input type="hidden" name="team_id" value="<?php echo $active_team_id; ?>">
                                            <?php wp_nonce_field('flms_pay_fee_nonce'); ?>
                                            <button type="submit" class="button btn-tiny">Pay Now</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#ccc;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>


            <!-- ROSTER TABLE -->
            <h3>Full Squad</h3>
            <div class="flms-table-responsive" style="overflow-x:auto;">
                <table class="flms-league-table flms-squad-table">
                    <thead><tr><th width="50">Img</th><th width="40">No.</th><th class="col-name">Name</th><th>Pos</th><th>Age</th><th width="120" style="text-align:right;">Action</th></tr></thead>
                    <tbody>
                        <?php foreach($players as $p): $pos=get_post_meta($p->ID,'flms_position',true)?:'-'; $age=get_post_meta($p->ID,'flms_age',true)?:'-'; $ic=get_post_meta($p->ID,'flms_ic',true)?:''; $num=get_post_meta($p->ID,'flms_number',true)?:'-'; $p_img = get_the_post_thumbnail_url($p->ID, 'thumbnail'); ?>
                        <tr data-id="<?php echo $p->ID; ?>" data-name="<?php echo esc_attr($p->post_title); ?>" data-num="<?php echo esc_attr($num); ?>" data-pos="<?php echo esc_attr($pos); ?>" data-age="<?php echo esc_attr($age); ?>" data-ic="<?php echo esc_attr($ic); ?>">
                            <td style="text-align:center;">
                                <?php if($p_img): ?><img src="<?php echo esc_url($p_img); ?>" style="width:35px; height:35px; border-radius:50%; object-fit:cover; border:1px solid #eee;">
                                <?php else: ?>
                                    <form method="post" enctype="multipart/form-data" class="flms-upload-mini"><label class="upload-icon-btn" title="Upload Photo">📷<input type="file" name="player_photo" accept="image/*" onchange="this.form.submit()"></label><input type="hidden" name="flms_action" value="upload_player_photo"><input type="hidden" name="player_id" value="<?php echo $p->ID; ?>"><input type="hidden" name="team_id" value="<?php echo $active_team_id; ?>"><?php wp_nonce_field('flms_player_photo_nonce'); ?></form>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($num); ?></td><td class="player-name"><?php echo esc_html($p->post_title); ?></td><td><span class="flms-pos-badge"><?php echo esc_html($pos); ?></span></td><td><?php echo esc_html($age); ?></td>
                            <td style="text-align:right; white-space:nowrap;">
                                <button type="button" class="btn-text-blue edit-player-btn">Edit</button> 
                                <?php if($stage !== 'locked'): ?><a href="?flms_action=remove_player&pid=<?php echo $p->ID; ?>&_wpnonce=<?php echo wp_create_nonce('flms_remove_'.$p->ID); ?>" class="btn-text-red" onclick="return confirm('Remove?');">Remove</a><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- EDIT MODAL (UPDATED LABEL) -->
            <div id="flms-edit-modal" class="flms-modal" style="display:none;">
                <div class="flms-modal-content">
                    <span class="close-modal">&times;</span>
                    <h3>Edit Player Details</h3>
                    <form method="post" enctype="multipart/form-data">
                        <div class="flms-form-grid">
                            <div class="form-group" style="grid-column:span 2;"><label>Profile Photo</label><input type="file" name="edit_photo" accept="image/*" style="border:1px solid #ddd; padding:5px; width:100%;"></div>
                            <div class="form-group" style="grid-column:span 2;"><label>Full Name</label><input type="text" name="edit_name" id="edit_name" required></div>
                            <div class="form-group"><label>Number</label><input type="number" name="edit_number" id="edit_number"></div>
                            <div class="form-group"><label>Age</label><input type="number" name="edit_age" id="edit_age"></div>
                            <div class="form-group"><label>Position</label><select name="edit_pos" id="edit_pos"><option value="GK">GK</option><option value="DEF">DEF</option><option value="MID">MID</option><option value="FWD">FWD</option></select></div>
                            <div class="form-group" style="grid-column:span 2;"><label>IC / Passport Number</label><input type="text" name="edit_ic" id="edit_ic"></div>
                        </div>
                        <input type="hidden" name="edit_pid" id="edit_pid">
                        <input type="hidden" name="flms_action" value="update_player">
                        <?php wp_nonce_field('flms_update_player_nonce'); ?>
                        <div style="margin-top:15px; text-align:right;"><button type="submit" class="button btn-blue">Save Changes</button></div>
                    </form>
                </div>
            </div>

            <!-- ACTIONS (ADD/TRANSFER) -->
            <?php if($stage !== 'locked'): ?>
                <div class="flms-add-player-box">
                    <h4>Register New Player</h4>
                    <form method="post">
                        <div class="flms-form-grid">
                            <div class="form-group" style="grid-column: span 2;"><label>Name</label><input type="text" name="p_name" required></div>
                            <div class="form-group"><label>No.</label><input type="number" name="p_number"></div>
                            <div class="form-group"><label>Pos</label><select name="p_pos"><option value="GK">GK</option><option value="DEF">DEF</option><option value="MID">MID</option><option value="FWD">FWD</option></select></div>
                            <div class="form-group"><label>Age</label><input type="number" name="p_age"></div>
                            <!-- UPDATED LABEL -->
                            <div class="form-group"><label>IC / Passport (Required)</label><input type="text" name="p_ic" required placeholder="e.g. A1234567 or 901010121234"></div>
                        </div>
                        <input type="hidden" name="flms_action" value="add_player"><input type="hidden" name="team_id" value="<?php echo $active_team_id; ?>"><?php wp_nonce_field('flms_add_player_nonce'); ?>
                        <button type="submit" class="button button-primary" style="margin-top:15px; width:100%;"><?php echo ($stage==='paid')?'Pay & Add (RM5)':'Add Player'; ?></button>
                    </form>
                </div>
                
                <div class="flms-transfer-box">
                    <h4 style="color:#856404; margin-top:0;">Transfer Window (Sign Player)</h4>
                    <form method="post" id="flms-transfer-form">
                        <div class="flms-form-grid" style="margin-bottom:10px;">
                            <!-- UPDATED LABEL -->
                            <div class="form-group"><label>Player IC / Passport (Required)</label><input type="text" name="player_ic" id="trans_ic" required placeholder="Enter ID to search"></div>
                            <div class="form-group"><label>Full Name (Optional)</label><input type="text" name="player_name_opt" placeholder="For your reference"></div>
                        </div>
                        <input type="hidden" name="my_team_id" value="<?php echo $active_team_id; ?>">
                        <input type="hidden" name="flms_transfer_act" value="request">
                        <?php wp_nonce_field('flms_request_nonce'); ?>
                        <button type="button" id="btn-check-transfer" class="button transfer-btn" style="width:100%;">Sign Player</button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- ACCOUNT SETTINGS -->
            <div class="flms-account-box" style="margin-top:30px; background:#fff; padding:25px; border-radius:8px; border:1px solid #ddd; border-top:3px solid #333;">
                <h3>Account Settings</h3>
                <form method="post" style="max-width:400px;">
                    <p><label style="display:block; font-weight:bold; margin-bottom:5px;">Current Password</label><input type="password" name="old_pass" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;"></p>
                    <p><label style="display:block; font-weight:bold; margin-bottom:5px;">New Password</label><input type="password" name="new_pass" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;"></p>
                    <p><label style="display:block; font-weight:bold; margin-bottom:5px;">Confirm New Password</label><input type="password" name="confirm_pass" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;"></p>
                    <input type="hidden" name="flms_action" value="change_password"><?php wp_nonce_field('flms_password_nonce'); ?>
                    <button type="submit" class="button button-secondary">Change Password</button>
                </form>
            </div>
        </div>

        <style>
            /* STYLING FOR DASHBOARD */
            .flms-dashboard-wrapper { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); max-width: 1000px; margin: 0 auto; }
            .flms-dash-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 20px; }
            .team-dash-logo { width: 60px; height: 60px; border-radius: 50%; border: 2px solid #eee; object-fit: cover; }
            .team-dash-logo-placeholder { width: 60px; height: 60px; background: #eee; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #999; }
            .flms-kit-display { display: flex; gap: 10px; align-items:center; }
            .kit-badge { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: bold; font-size: 10px; box-shadow:0 2px 5px rgba(0,0,0,0.1); }
            .flms-requests-box, .flms-lineup-box, .flms-finance-box, .flms-add-player-box, .flms-transfer-box { padding: 20px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #eee; }
            .flms-lineup-box { background: #f0f9ff; border-color: #bbdefb; }
            .flms-add-player-box { background: #f9f9f9; }
            .flms-transfer-box { background: #fff3cd; border-color: #ffeeba; }
            .lineup-list { max-height: 200px; overflow-y: auto; background: #fff; padding: 10px; border: 1px solid #ddd; margin-bottom: 10px; }
            .flms-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; }
            .form-group label { display: block; font-weight: bold; font-size: 11px; margin-bottom: 5px; text-transform:uppercase; color:#555; }
            .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
            .btn-tiny { font-size: 10px; padding: 4px 8px; background: #333; color: #fff; text-decoration:none; border-radius:4px; }
            .btn-green { background: #2ecc71; color: #fff; border:none; padding:4px 8px; border-radius:4px; font-size:11px; }
            .btn-red { background: #e74c3c; color: #fff; border:none; padding:4px 8px; border-radius:4px; font-size:11px; }
            .btn-blue { background: #0d47a1; color: #fff; border:none; }
            .btn-text-red { color: #e74c3c; font-size: 11px; text-decoration: none; border: 1px solid #e74c3c; padding: 2px 6px; border-radius: 3px; margin-left:5px; }
            .btn-text-blue { color: #0d47a1; font-size: 11px; text-decoration: none; border: 1px solid #0d47a1; padding: 2px 6px; border-radius: 3px; background:none; cursor:pointer; }
            .transfer-btn { background: #856404 !important; color: #fff !important; border:none !important; }
            .upload-icon-btn { cursor: pointer; background: #eee; padding: 4px 8px; border-radius: 4px; font-size: 16px; line-height: 1; border: 1px solid #ddd; display:inline-block; }
            .upload-icon-btn input { display: none; }
            .col-name { width: auto; } .flms-squad-table th, .flms-squad-table td { padding: 12px 8px; }
            .flms-modal { position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; }
            .flms-modal-content { background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 90%; max-width: 500px; border-radius: 8px; position:relative; }
            .close-modal { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
            .flms-history-tabs { display:flex; gap:8px; margin: 0 0 20px 0; flex-wrap:wrap; }
            .flms-history-tab { display:inline-block; padding:8px 12px; border:1px solid #ddd; border-radius:6px; text-decoration:none; color:#333; font-size:13px; font-weight:600; background:#f8f8f8; }
            .flms-history-tab.is-active { background:#37003c; color:#fff; border-color:#37003c; }
            @media (max-width: 768px) { .flms-table-responsive { display: block; overflow-x: auto; width: 100%; border: 1px solid #eee; } .flms-league-table { min-width: 700px; } }
        </style>

        <script>
        jQuery(document).ready(function($){
            $('#btn-check-transfer').click(function(e){ e.preventDefault(); var ic = $('#trans_ic').val(); var btn = $(this); if(!ic) { alert('Please enter the Player IC / Passport.'); return; } btn.text('Checking...').prop('disabled', true); $.post('<?php echo admin_url("admin-ajax.php"); ?>', { action: 'flms_check_player', ic: ic }, function(res) { if(res.success) { var pName = res.data.name; var pTeam = res.data.team; if(typeof Swal !== 'undefined') { Swal.fire({ title: 'Confirm Transfer?', html: "Do you want to sign <strong>" + pName + "</strong>?<br>Current Team: " + pTeam, icon: 'question', showCancelButton: true, confirmButtonColor: '#37003c', confirmButtonText: 'Yes, Sign!', cancelButtonText: 'Cancel' }).then((result) => { if (result.isConfirmed) { $('#flms-transfer-form').submit(); } else { btn.text('Sign Player').prop('disabled', false); } }); } else { if(confirm("Found: " + pName + " (" + pTeam + ")\n\nProceed with transfer?")) { $('#flms-transfer-form').submit(); } else { btn.text('Sign Player').prop('disabled', false); } } } else { if(typeof Swal !== 'undefined') { Swal.fire('Error', res.data, 'error'); } else { alert(res.data); } btn.text('Sign Player').prop('disabled', false); } }); });
            $('.edit-player-btn').click(function(){ var tr = $(this).closest('tr'); $('#edit_pid').val( tr.data('id') ); $('#edit_name').val( tr.data('name') ); $('#edit_number').val( tr.data('num') ); $('#edit_age').val( tr.data('age') ); $('#edit_ic').val( tr.data('ic') ); $('#edit_pos').val( tr.data('pos') ); $('#flms-edit-modal').fadeIn(); });
            $('.close-modal').click(function(){ $('#flms-edit-modal').fadeOut(); });
            $(window).click(function(event) { if ($(event.target).is('#flms-edit-modal')) { $('#flms-edit-modal').fadeOut(); } });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    private function render_completed_matches_panel( $team_id, $history_tab ) {
        if ( $history_tab === 'league' ) {
            $league_rows = $this->get_completed_league_matches_grouped( $team_id );
            $html = '<div class="flms-finance-box"><h3>🏆 Completed League Matches (by Competition)</h3>';

            if ( empty( $league_rows ) ) {
                return $html . '<p style="margin:0; color:#666;">No completed league matches yet.</p></div>';
            }

            foreach ( $league_rows as $competition_name => $rows ) {
                $html .= '<h4 style="margin:18px 0 8px 0; color:#37003c;">' . esc_html( $competition_name ) . '</h4>';
                $html .= '<div class="flms-table-responsive"><table class="flms-league-table">';
                $html .= '<thead><tr><th>Date</th><th>Match</th><th>Score</th></tr></thead><tbody>';

                foreach ( $rows as $r ) {
                    $html .= '<tr>';
                    $html .= '<td>' . esc_html( $r['date'] ) . '</td>';
                    $html .= '<td>' . esc_html( $r['match'] ) . '</td>';
                    $html .= '<td><strong>' . esc_html( $r['score'] ) . '</strong></td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table></div>';
            }

            return $html . '</div>';
        }

        $friendly_rows = $this->get_completed_friendly_matches( $team_id );
        $html = '<div class="flms-finance-box"><h3>🤝 Completed Friendly Matches</h3>';

        if ( empty( $friendly_rows ) ) {
            return $html . '<p style="margin:0; color:#666;">No completed friendly matches yet.</p></div>';
        }

        $html .= '<div class="flms-table-responsive"><table class="flms-league-table">';
        $html .= '<thead><tr><th>Date</th><th>Match</th><th>Score</th></tr></thead><tbody>';
        foreach ( $friendly_rows as $r ) {
            $html .= '<tr>';
            $html .= '<td>' . esc_html( $r['date'] ) . '</td>';
            $html .= '<td>' . esc_html( $r['match'] ) . '</td>';
            $html .= '<td><strong>' . esc_html( $r['score'] ) . '</strong></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div></div>';

        return $html;
    }

    private function get_completed_friendly_matches( $team_id ) {
        $friendlies = get_posts([
            'post_type'      => 'flms_friendly',
            'posts_per_page' => 20,
            'post_status'    => 'publish',
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => 'flms_friendly_status', 'value' => 'completed', 'compare' => '=' ],
                [
                    'relation' => 'OR',
                    [ 'key' => 'flms_host_team_id', 'value' => $team_id ],
                    [ 'key' => 'flms_chosen_team_id', 'value' => $team_id ],
                ],
            ],
            'meta_key'       => 'flms_friendly_date',
            'orderby'        => 'meta_value',
            'order'          => 'DESC',
        ]);

        $rows = [];
        foreach ( (array) $friendlies as $f ) {
            $home_id = (int) get_post_meta( $f->ID, 'flms_host_team_id', true );
            $away_id = (int) get_post_meta( $f->ID, 'flms_chosen_team_id', true );
            $home_score = get_post_meta( $f->ID, 'flms_friendly_home_score', true );
            $away_score = get_post_meta( $f->ID, 'flms_friendly_away_score', true );
            if ( $home_score === '' || $away_score === '' ) continue;

            $date_raw = get_post_meta( $f->ID, 'flms_friendly_date', true );
            $rows[] = [
                'date'  => $date_raw ? date( 'd M Y', strtotime( $date_raw ) ) : '-',
                'match' => get_the_title( $home_id ) . ' vs ' . get_the_title( $away_id ),
                'score' => $home_score . ' - ' . $away_score,
            ];
        }

        return $rows;
    }

    private function get_completed_league_matches_grouped( $team_id ) {
        $matches = get_posts([
            'post_type'      => 'flms_match',
            'posts_per_page' => 50,
            'post_status'    => 'publish',
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => 'flms_match_status', 'value' => 'completed', 'compare' => '=' ],
                [
                    'relation' => 'OR',
                    [ 'key' => 'flms_home_team', 'value' => $team_id ],
                    [ 'key' => 'flms_away_team', 'value' => $team_id ],
                ],
            ],
            'meta_key'       => 'flms_match_date',
            'orderby'        => 'meta_value',
            'order'          => 'DESC',
        ]);

        $grouped = [];
        foreach ( (array) $matches as $m ) {
            $home_id = (int) get_post_meta( $m->ID, 'flms_home_team', true );
            $away_id = (int) get_post_meta( $m->ID, 'flms_away_team', true );
            $home_score = get_post_meta( $m->ID, 'flms_home_score', true );
            $away_score = get_post_meta( $m->ID, 'flms_away_score', true );
            if ( $home_score === '' || $away_score === '' ) continue;

            $competition_id = (int) get_post_meta( $m->ID, 'flms_tournament_id', true );
            $competition_name = $competition_id ? get_the_title( $competition_id ) : 'No Competition';
            if ( empty( $grouped[ $competition_name ] ) ) {
                $grouped[ $competition_name ] = [];
            }

            $date_raw = get_post_meta( $m->ID, 'flms_match_date', true );
            $grouped[ $competition_name ][] = [
                'date'  => $date_raw ? date( 'd M Y', strtotime( $date_raw ) ) : '-',
                'match' => get_the_title( $home_id ) . ' vs ' . get_the_title( $away_id ),
                'score' => $home_score . ' - ' . $away_score,
            ];
        }

        return $grouped;
    }

    private function render_create_team_form() {
        ob_start();
        ?>
        <div class="flms-setup-box" style="max-width:500px; margin:0 auto; background:#fff; padding:30px; border:1px solid #ddd;"><h2>Create Team Profile</h2><form method="post"><p><label>Club Name</label><br><input type="text" name="team_name" required style="width:100%"></p><div style="display:flex; gap:10px;"><p><label>Home Kit</label><br><input type="color" name="home_color" value="#ff0000"></p><p><label>Away Kit</label><br><input type="color" name="away_color" value="#ffffff"></p></div><input type="hidden" name="flms_action" value="create_team"><?php wp_nonce_field('flms_create_team_nonce'); ?><button type="submit" class="button button-primary">Create Team</button></form></div>
        <?php
        return ob_get_clean();
    }

    public function process_actions() {
        if ( ! is_user_logged_in() ) return;
        
        // 1. Pay Fee (MATCH)
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'pay_match_fee' ) {
            check_admin_referer('flms_pay_fee_nonce');
            $fee_product_id = 19182; WC()->cart->empty_cart(); WC()->cart->add_to_cart( $fee_product_id, 1, 0, [], ['match_fee_id' => intval($_POST['match_id']), 'match_fee_team' => intval($_POST['team_id'])]); wp_redirect( wc_get_checkout_url() ); exit;
        }
        
        // 2. Pay Fee (TRANSFER)
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'pay_transfer_fee' ) {
            check_admin_referer('flms_pay_transfer_nonce');
            $fee_product_id = 16022; $pid = intval($_POST['player_id']); $tid = intval($_POST['target_team']); WC()->cart->empty_cart(); WC()->cart->add_to_cart( $fee_product_id, 1, 0, [], ['transfer_pid' => $pid, 'transfer_target_team' => $tid]); wp_redirect( wc_get_checkout_url() ); exit;
        }

        // 3. Save Lineup
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'save_lineup' ) {
            check_admin_referer('flms_lineup_nonce');
            $mid = intval($_POST['match_id']); $is_home = ($_POST['is_home'] === '1'); $lineup = isset($_POST['match_lineup']) ? array_map('intval', $_POST['match_lineup']) : [];
            $meta_key = $is_home ? '_flms_lineup_home' : '_flms_lineup_away';
            update_post_meta($mid, $meta_key, $lineup);
            if ( class_exists('FLMS_Player_Stats') ) { $eng = new FLMS_Player_Stats(); foreach($lineup as $pid) $eng->recalculate_single_player($pid); }
            wp_redirect( add_query_arg('msg', 'lineup_saved', remove_query_arg('flms_action')) ); exit;
        }
        // 4. Update Logo
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'update_logo' ) {
            check_admin_referer('flms_logo_nonce');
            $tid = intval($_POST['team_id']);
            if ( ! empty( $_FILES['team_logo']['name'] ) ) {
                require_once( ABSPATH . 'wp-admin/includes/image.php' ); require_once( ABSPATH . 'wp-admin/includes/file.php' ); require_once( ABSPATH . 'wp-admin/includes/media.php' );
                $aid = media_handle_upload( 'team_logo', $tid );
                if ( ! is_wp_error( $aid ) ) set_post_thumbnail( $tid, $aid );
            }
            wp_redirect( add_query_arg('msg', 'logo_updated', remove_query_arg('flms_action')) ); exit;
        }
        // 5. Create Team
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'create_team' ) {
            check_admin_referer('flms_create_team_nonce');
            $uid = get_current_user_id();
            update_user_meta( $uid, 'flms_club_name', sanitize_text_field($_POST['team_name']) );
            $tid = wp_insert_post([ 'post_type'=>'flms_team', 'post_title'=>sanitize_text_field($_POST['team_name']), 'post_status'=>'publish', 'post_author'=>$uid ]);
            update_post_meta($tid, 'flms_home_color', sanitize_hex_color($_POST['home_color']));
            update_post_meta($tid, 'flms_away_color', sanitize_hex_color($_POST['away_color']));
            wp_redirect( remove_query_arg('flms_action') ); exit;
        }
        
        // 6. Add Player (UPDATED: IC CLEANING & COLLISION CHECK)
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'add_player' ) {
            check_admin_referer('flms_add_player_nonce');
            
            $raw_ic = sanitize_text_field($_POST['p_ic']);
            // CLEAN IC: Uppercase + Remove Non-Alphanumeric
            $ic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $raw_ic)); 

            if ( empty($ic) ) wp_die('Error: Player IC/Passport is Required.');

            $tid = intval($_POST['team_id']);
            $current_tour_id = get_post_meta($tid, 'flms_tournament_id', true);

            // CONFIG: Division 1 ID (Change this if needed)
            $div1_tournament_id = 20500; 

            // A. Check Max Players (30)
            $team_roster = get_posts(['post_type'=>'flms_player', 'meta_key'=>'flms_team_id', 'meta_value'=>$tid, 'posts_per_page'=>-1]);
            if (count($team_roster) >= 35) wp_die('Max 35 players allowed.');

            // B. CHECK COLLISION & QUOTA
            if ( ! empty($current_tour_id) ) {
                $existing_players = get_posts([ 'post_type' => 'flms_player', 'meta_key' => 'flms_ic', 'meta_value' => $ic, 'posts_per_page' => -1 ]);
                $is_div1_player = false;
                
                foreach ($existing_players as $p) {
                    $other_tid = get_post_meta($p->ID, 'flms_team_id', true);
                    if(!$other_tid) continue;
                    $other_tour_id = get_post_meta($other_tid, 'flms_tournament_id', true);

                    // 1. Same Tournament Collision
                    if ( $current_tour_id == $other_tour_id ) {
                        $other_team_name = get_the_title($other_tid);
                        wp_die("Error: This ID ($raw_ic) is already registered to <strong>$other_team_name</strong> in this tournament.");
                    }
                    // 2. Check if Div 1 Player
                    if ( $other_tour_id == $div1_tournament_id ) $is_div1_player = true;
                }

                // 3. Quota Check (Max 3 Div 1 Players)
                if ( $is_div1_player && $current_tour_id != $div1_tournament_id ) {
                    $div1_imports_count = 0;
                    foreach ($team_roster as $teammate) {
                        $teammate_ic = get_post_meta($teammate->ID, 'flms_ic', true);
                        $teammate_profiles = get_posts(['post_type'=>'flms_player','meta_key'=>'flms_ic','meta_value'=>$teammate_ic,'posts_per_page'=>-1,'fields'=>'ids']);
                        foreach($teammate_profiles as $t_pid) {
                            $t_tid = get_post_meta($t_pid, 'flms_team_id', true);
                            if($t_tid && get_post_meta($t_tid, 'flms_tournament_id', true) == $div1_tournament_id) { $div1_imports_count++; break; }
                        }
                    }
                    if ( $div1_imports_count >= 3 ) wp_die("Error: Max 3 Division 1 players allowed (Quota Exceeded).");
                }
            }

            // Check Stage Logic (Paid/Free)
            $stage = ($current_tour_id && class_exists('FLMS_Competitions')) ? FLMS_Competitions::get_current_stage($current_tour_id) : 'open';
            if($stage==='locked') wp_die('Locked');
            
            if($stage==='paid') {
                $fee_id = 16022; 
                WC()->cart->empty_cart(); 
                WC()->cart->add_to_cart($fee_id, 1, 0, [], ['action_type'=>'add_player', 'new_p_data'=>[ 
                    'name'=>$_POST['p_name'], 
                    'pos'=>$_POST['p_pos'], 
                    'age'=>$_POST['p_age'], 
                    'ic'=>$ic, // SAVE CLEAN
                    'num'=>$_POST['p_number'], 
                    'tid'=>$tid 
                ]]);
                wp_redirect( wc_get_checkout_url() ); exit;
            }
            
            $pid = wp_insert_post(['post_type'=>'flms_player', 'post_title'=>sanitize_text_field($_POST['p_name']), 'post_status'=>'publish', 'post_author'=>get_current_user_id()]);
            if($pid) { 
                update_post_meta($pid, 'flms_team_id', $tid); 
                update_post_meta($pid, 'flms_position', $_POST['p_pos']); 
                update_post_meta($pid, 'flms_age', $_POST['p_age']); 
                update_post_meta($pid, 'flms_ic', $ic); // SAVE CLEAN
                update_post_meta($pid, 'flms_number', $_POST['p_number']); 
            }
            wp_redirect( remove_query_arg('flms_action') ); exit;
        }
        
        // 7. Remove Player
        if ( isset($_GET['flms_action']) && $_GET['flms_action'] === 'remove_player' ) {
            $pid = intval($_GET['pid']); check_admin_referer('flms_remove_'.$pid);
            $tid = get_post_meta($pid, 'flms_team_id', true);
            $tournament_id = get_post_meta($tid, 'flms_tournament_id', true);
            $stage = ($tournament_id && class_exists('FLMS_Competitions')) ? FLMS_Competitions::get_current_stage($tournament_id) : 'open';
            if($stage==='locked') wp_die('Locked');
            if(get_post_field('post_author', $tid) == get_current_user_id()) update_post_meta($pid, 'flms_team_id', '');
            wp_redirect( remove_query_arg(['flms_action','pid','_wpnonce']) ); exit;
        }
        
        // 8. Upload Player Photo
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'upload_player_photo' ) {
            check_admin_referer('flms_player_photo_nonce');
            $pid = intval($_POST['player_id']);
            $tid = intval($_POST['team_id']);
            $team = get_post($tid);
            if( ! $team || $team->post_author != get_current_user_id() ) wp_die('Unauthorized');
            
            if ( ! empty( $_FILES['player_photo']['name'] ) ) {
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/media.php' );
                $attach_id = media_handle_upload( 'player_photo', $pid );
                if ( ! is_wp_error( $attach_id ) ) set_post_thumbnail( $pid, $attach_id );
            }
            wp_redirect( add_query_arg('msg', 'photo_updated', remove_query_arg('flms_action')) );
            exit;
        }

        // 9. Update Player Details (UPDATED: IC CLEANING)
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'update_player' ) {
            check_admin_referer('flms_update_player_nonce');
            
            $pid = intval($_POST['edit_pid']);
            $tid = get_post_meta($pid, 'flms_team_id', true);
            
            // Verify Ownership
            if(get_post_field('post_author', $tid) != get_current_user_id()) wp_die('Unauthorized');

            // Sanitize & Update
            $name = sanitize_text_field($_POST['edit_name']);
            if(!empty($name)) {
                wp_update_post(['ID' => $pid, 'post_title' => $name]);
            }
            
            // CLEAN IC
            $ic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['edit_ic']));

            update_post_meta($pid, 'flms_number', sanitize_text_field($_POST['edit_number']));
            update_post_meta($pid, 'flms_age', sanitize_text_field($_POST['edit_age']));
            update_post_meta($pid, 'flms_position', sanitize_text_field($_POST['edit_pos']));
            update_post_meta($pid, 'flms_ic', $ic);

            if ( ! empty( $_FILES['edit_photo']['name'] ) ) {
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/media.php' );
                $aid = media_handle_upload( 'edit_photo', $pid );
                if ( ! is_wp_error( $aid ) ) set_post_thumbnail( $pid, $aid );
            }

            wp_redirect( add_query_arg('msg', 'player_updated', remove_query_arg('flms_action')) );
            exit;
        }

        // 10. Change Password
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'change_password' ) {
            check_admin_referer('flms_password_nonce');
            $user = wp_get_current_user();
            
            $old = $_POST['old_pass'];
            $new = $_POST['new_pass'];
            $conf = $_POST['confirm_pass'];

            if ( ! wp_check_password( $old, $user->user_pass, $user->ID ) ) {
                wp_die('Error: Current password is incorrect.');
            }

            if ( $new !== $conf ) {
                wp_die('Error: New passwords do not match.');
            }

            wp_set_password( $new, $user->ID );
            
            wp_redirect( home_url('/login/?msg=password_changed') );
            exit;
        }
    }
}