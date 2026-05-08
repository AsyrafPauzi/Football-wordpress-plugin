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
        $allowed_views = [ 'dashboard', 'players', 'settings', 'friendly', 'league' ];
        $active_view   = isset( $_GET['mgr_view'] ) ? sanitize_key( wp_unslash( $_GET['mgr_view'] ) ) : 'dashboard';
        if ( ! in_array( $active_view, $allowed_views, true ) ) {
            $active_view = 'dashboard';
        }
        if ( in_array( $active_view, [ 'friendly', 'league' ], true ) ) {
            $history_tab = $active_view;
        }
        $base_manager_url = remove_query_arg( [ 'paged', 'history_tab', 'mgr_view' ] );
        $dashboard_url    = esc_url( add_query_arg( [ 'manage_team' => $active_team_id, 'mgr_view' => 'dashboard' ], $base_manager_url ) );
        $players_url      = esc_url( add_query_arg( [ 'manage_team' => $active_team_id, 'mgr_view' => 'players' ], $base_manager_url ) );
        $settings_url     = esc_url( add_query_arg( [ 'manage_team' => $active_team_id, 'mgr_view' => 'settings' ], $base_manager_url ) );
        $friendly_view_url = esc_url( add_query_arg( [ 'manage_team' => $active_team_id, 'mgr_view' => 'friendly' ], $base_manager_url ) );
        $league_view_url   = esc_url( add_query_arg( [ 'manage_team' => $active_team_id, 'mgr_view' => 'league' ], $base_manager_url ) );
        $my_pending_transfers = get_posts(
            [
                'post_type'      => 'flms_transfer',
                'meta_key'       => '_to_team',
                'meta_value'     => $active_team_id,
                'post_status'    => 'private',
                'posts_per_page' => -1,
            ]
        );
        $finance_matches = get_posts(
            [
                'post_type'      => 'flms_match',
                'posts_per_page' => 10,
                'meta_query'     => [
                    'relation' => 'AND',
                    [
                        'relation' => 'OR',
                        [ 'key' => 'flms_home_team', 'value' => $active_team_id ],
                        [ 'key' => 'flms_away_team', 'value' => $active_team_id ],
                    ],
                ],
                'orderby'        => 'meta_value',
                'meta_key'       => 'flms_match_date',
                'order'          => 'DESC',
            ]
        );
        $unpaid_match_fee_count = 0;
        foreach ( $finance_matches as $fm_count_item ) {
            $match_id    = $fm_count_item->ID;
            $home_id     = get_post_meta( $match_id, 'flms_home_team', true );
            $is_home_row = ( (int) $active_team_id === (int) $home_id );
            $paid_status = $is_home_row ? get_post_meta( $match_id, '_flms_paid_home', true ) : get_post_meta( $match_id, '_flms_paid_away', true );
            if ( 'yes' !== $paid_status ) {
                $unpaid_match_fee_count++;
            }
        }

        ob_start();
        ?>
        <div class="flms-dashboard-wrapper flms-mgr-dashboard" id="flms-mgr-dashboard">
            <div class="flms-mgr-shell">
                <aside class="flms-mgr-shell__sidebar">
                    <h2 class="flms-mgr-sidebar__title"><?php esc_html_e( 'Manager Menu', 'flms' ); ?></h2>
                    <nav class="flms-mgr-sidebar__nav" aria-label="<?php esc_attr_e( 'Manager navigation', 'flms' ); ?>">
                        <a class="flms-mgr-sidebar__link <?php echo 'dashboard' === $active_view ? 'is-active' : ''; ?>" href="<?php echo $dashboard_url; ?>"><?php esc_html_e( 'Dashboard', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link <?php echo 'players' === $active_view ? 'is-active' : ''; ?>" href="<?php echo $players_url; ?>"><?php esc_html_e( 'Players', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link" href="<?php echo esc_url( $create_url ); ?>"><?php esc_html_e( 'Create Friendly Match', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link" href="<?php echo esc_url( $inbox_url ); ?>"><?php esc_html_e( 'Inbox', 'flms' ); ?> <span class="flms-mgr-badge"><?php echo (int) $total_inbox_badge; ?></span></a>
                        <a class="flms-mgr-sidebar__link <?php echo 'settings' === $active_view ? 'is-active' : ''; ?>" href="<?php echo $settings_url; ?>"><?php esc_html_e( 'Team Settings', 'flms' ); ?></a>
                        <a class="flms-mgr-sidebar__link <?php echo 'dashboard' === $active_view ? 'is-active' : ''; ?>" href="<?php echo $dashboard_url; ?>#flms-mgr-fees"><?php esc_html_e( 'Match Fees', 'flms' ); ?> <span class="flms-mgr-badge"><?php echo (int) $unpaid_match_fee_count; ?></span></a>
                        <div class="flms-mgr-sidebar__group">
                            <span class="flms-mgr-sidebar__group-label"><?php esc_html_e( 'Matches', 'flms' ); ?></span>
                            <a class="flms-mgr-sidebar__sublink <?php echo 'friendly' === $active_view ? 'is-active' : ''; ?>" href="<?php echo $friendly_view_url; ?>"><?php esc_html_e( 'Friendly', 'flms' ); ?></a>
                            <a class="flms-mgr-sidebar__sublink <?php echo 'league' === $active_view ? 'is-active' : ''; ?>" href="<?php echo $league_view_url; ?>"><?php esc_html_e( 'League', 'flms' ); ?></a>
                        </div>
                    </nav>
                </aside>
                <div class="flms-mgr-shell__main">
            <div class="flms-mgr-dashboard__inner">

            <?php if ( count( $my_teams ) > 1 ) : ?>
            <section class="flms-mgr-card flms-mgr-card--switcher" aria-labelledby="flms-mgr-switcher-heading">
                <h2 id="flms-mgr-switcher-heading" class="flms-mgr-card__title flms-mgr-card__title--sm"><?php esc_html_e( 'Switch team', 'flms' ); ?></h2>
                <p class="flms-mgr-card__hint"><?php esc_html_e( 'Choose which tournament team you are managing.', 'flms' ); ?></p>
                <form method="get" class="flms-mgr-field-row">
                    <label class="flms-mgr-label" for="flms-mgr-manage-team"><?php esc_html_e( 'Tournament / team', 'flms' ); ?></label>
                    <select class="flms-mgr-input flms-mgr-input--select" id="flms-mgr-manage-team" name="manage_team" onchange="this.form.submit()">
                        <?php foreach ( $my_teams as $t ) :
                            $t_tour_id   = get_post_meta( $t->ID, 'flms_tournament_id', true );
                            $t_tour_name = $t_tour_id ? get_the_title( $t_tour_id ) : __( 'Unassigned', 'flms' );
                            ?>
                            <option value="<?php echo (int) $t->ID; ?>" <?php selected( $active_team_id, $t->ID ); ?>>
                                <?php echo esc_html( $t_tour_name . ' — ' . $t->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="mgr_view" value="<?php echo esc_attr( $active_view ); ?>">
                </form>
            </section>
            <?php endif; ?>

            <header class="flms-mgr-hero">
                <div class="flms-mgr-hero__identity">
                    <?php if ( $logo_url ) : ?>
                        <img src="<?php echo esc_url( $logo_url ); ?>" alt="" class="flms-mgr-hero__logo" width="72" height="72">
                    <?php else : ?>
                        <div class="flms-mgr-hero__logo flms-mgr-hero__logo--empty" aria-hidden="true"><?php esc_html_e( 'Logo', 'flms' ); ?></div>
                    <?php endif; ?>
                    <div class="flms-mgr-hero__copy">
                        <h1 class="flms-mgr-hero__title"><?php echo esc_html( $active_team->post_title ); ?></h1>
                        <p class="flms-mgr-hero__subtitle"><?php echo esc_html( $tour_name ); ?></p>
                        <div class="flms-mgr-hero__kits" role="group" aria-label="<?php esc_attr_e( 'Kits and transfer window', 'flms' ); ?>">
                            <span class="flms-mgr-kit" style="background:<?php echo esc_attr( $home_color ); ?>;" title="<?php esc_attr_e( 'Home kit', 'flms' ); ?>">H</span>
                            <span class="flms-mgr-kit flms-mgr-kit--away" style="background:<?php echo esc_attr( $away_color ); ?>;" title="<?php esc_attr_e( 'Away kit', 'flms' ); ?>">A</span>
                            <span class="flms-mgr-pill"><?php echo esc_html( $status_text ); ?></span>
                        </div>
                    </div>
                </div>
                <?php if ( 'settings' === $active_view ) : ?>
                <form method="post" enctype="multipart/form-data" class="flms-mgr-hero__actions">
                    <label class="flms-mgr-btn flms-mgr-btn--secondary">
                        <span class="flms-mgr-btn__label"><?php esc_html_e( 'Update logo', 'flms' ); ?></span>
                        <input type="file" name="team_logo" accept="image/*" class="flms-mgr-sr-only" onchange="this.form.submit()">
                    </label>
                    <input type="hidden" name="flms_action" value="update_logo">
                    <input type="hidden" name="team_id" value="<?php echo (int) $active_team_id; ?>">
                    <?php wp_nonce_field( 'flms_logo_nonce' ); ?>
                </form>
                <?php endif; ?>
            </header>

            <?php if ( 'dashboard' === $active_view ) : ?>
            <section class="flms-mgr-kpi-row" aria-label="<?php esc_attr_e( 'Dashboard quick stats', 'flms' ); ?>">
                <article class="flms-mgr-kpi">
                    <p class="flms-mgr-kpi__label"><?php esc_html_e( 'Unpaid match fees', 'flms' ); ?></p>
                    <p class="flms-mgr-kpi__value"><?php echo (int) $unpaid_match_fee_count; ?></p>
                    <a class="flms-mgr-kpi__link" href="#flms-mgr-fees"><?php esc_html_e( 'Review fees', 'flms' ); ?></a>
                </article>
                <article class="flms-mgr-kpi">
                    <p class="flms-mgr-kpi__label"><?php esc_html_e( 'Incoming requests', 'flms' ); ?></p>
                    <p class="flms-mgr-kpi__value"><?php echo (int) count( $incoming_requests ); ?></p>
                    <a class="flms-mgr-kpi__link" href="#flms-mgr-requests"><?php esc_html_e( 'Open requests', 'flms' ); ?></a>
                </article>
                <article class="flms-mgr-kpi">
                    <p class="flms-mgr-kpi__label"><?php esc_html_e( 'Transfers to pay', 'flms' ); ?></p>
                    <p class="flms-mgr-kpi__value"><?php echo (int) count( $my_pending_transfers ); ?></p>
                    <a class="flms-mgr-kpi__link" href="#flms-mgr-transfer-payments"><?php esc_html_e( 'Pay now', 'flms' ); ?></a>
                </article>
                <article class="flms-mgr-kpi">
                    <p class="flms-mgr-kpi__label"><?php esc_html_e( 'Quick actions', 'flms' ); ?></p>
                    <div class="flms-mgr-kpi__actions">
                        <a class="flms-mgr-btn flms-mgr-btn--sm flms-mgr-btn--secondary" href="<?php echo esc_url( $create_url ); ?>"><?php esc_html_e( 'Create Friendly', 'flms' ); ?></a>
                        <a class="flms-mgr-btn flms-mgr-btn--sm flms-mgr-btn--ghost" href="<?php echo esc_url( $inbox_url ); ?>"><?php esc_html_e( 'Open Inbox', 'flms' ); ?></a>
                    </div>
                </article>
            </section>
            <?php endif; ?>

            <?php if ( 'dashboard' === $active_view ) : ?>
                <?php echo $this->render_history_preview_panels( $active_team_id, $friendly_view_url, $league_view_url ); ?>
            <?php elseif ( in_array( $active_view, [ 'friendly', 'league' ], true ) ) : ?>
            <?php echo $this->render_completed_matches_panel( $active_team_id, $active_view ); ?>
            <?php endif; ?>

            <?php if ( 'dashboard' === $active_view && ! empty( $incoming_requests ) ) : ?>
            <section id="flms-mgr-requests" class="flms-mgr-card flms-mgr-card--alert" aria-labelledby="flms-mgr-incoming-heading">
                <div class="flms-mgr-card__head">
                    <h2 id="flms-mgr-incoming-heading" class="flms-mgr-card__title"><?php esc_html_e( 'Incoming transfer requests', 'flms' ); ?></h2>
                    <p class="flms-mgr-card__hint"><?php esc_html_e( 'Approve or reject players moving to your squad.', 'flms' ); ?></p>
                </div>
                <div class="flms-mgr-scroll">
                    <table class="flms-league-table flms-mgr-table">
                        <thead><tr><th><?php esc_html_e( 'Player', 'flms' ); ?></th><th><?php esc_html_e( 'From', 'flms' ); ?></th><th class="flms-mgr-table__actions"><?php esc_html_e( 'Action', 'flms' ); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ( $incoming_requests as $req ) :
                                $pid     = get_post_meta( $req->ID, '_player_id', true );
                                $from_id = (int) get_post_meta( $req->ID, '_from_team', true );
                                $p_name  = get_the_title( $pid );
                                $t_name  = $from_id ? get_the_title( $from_id ) : '—';
                                ?>
                            <tr>
                                <td data-label="<?php esc_attr_e( 'Player', 'flms' ); ?>"><strong><?php echo esc_html( $p_name ); ?></strong></td>
                                <td data-label="<?php esc_attr_e( 'From', 'flms' ); ?>"><?php echo esc_html( $t_name ); ?></td>
                                <td class="flms-mgr-table__actions" data-label="<?php esc_attr_e( 'Action', 'flms' ); ?>">
                                    <form method="post" class="flms-mgr-inline-actions">
                                        <input type="hidden" name="req_id" value="<?php echo (int) $req->ID; ?>">
                                        <?php wp_nonce_field( 'flms_approve_nonce' ); ?>
                                        <button type="submit" name="flms_transfer_act" value="approve" class="flms-mgr-btn flms-mgr-btn--success flms-mgr-btn--sm"><?php esc_html_e( 'Approve', 'flms' ); ?></button>
                                        <button type="submit" name="flms_transfer_act" value="reject" class="flms-mgr-btn flms-mgr-btn--danger flms-mgr-btn--sm"><?php esc_html_e( 'Reject', 'flms' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php
            if ( 'dashboard' === $active_view && ! empty( $my_pending_transfers ) ) :
                ?>
            <section id="flms-mgr-transfer-payments" class="flms-mgr-card flms-mgr-card--warning" aria-labelledby="flms-mgr-pending-transfer-heading">
                <div class="flms-mgr-card__head">
                    <h2 id="flms-mgr-pending-transfer-heading" class="flms-mgr-card__title"><?php esc_html_e( 'Transfers awaiting payment', 'flms' ); ?></h2>
                    <p class="flms-mgr-card__hint"><?php esc_html_e( 'Complete the fee to finalize approved signings.', 'flms' ); ?></p>
                </div>
                <div class="flms-mgr-scroll">
                    <table class="flms-league-table flms-mgr-table">
                        <thead><tr><th><?php esc_html_e( 'Player', 'flms' ); ?></th><th><?php esc_html_e( 'Status', 'flms' ); ?></th><th class="flms-mgr-table__actions"><?php esc_html_e( 'Action', 'flms' ); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ( $my_pending_transfers as $trans ) :
                                $pid   = get_post_meta( $trans->ID, '_player_id', true );
                                $p_name = get_the_title( $pid );
                                ?>
                            <tr>
                                <td data-label="<?php esc_attr_e( 'Player', 'flms' ); ?>"><strong><?php echo esc_html( $p_name ); ?></strong></td>
                                <td data-label="<?php esc_attr_e( 'Status', 'flms' ); ?>"><span class="flms-mgr-status flms-mgr-status--pending"><?php esc_html_e( 'Approved — fee required', 'flms' ); ?></span></td>
                                <td class="flms-mgr-table__actions" data-label="<?php esc_attr_e( 'Action', 'flms' ); ?>">
                                    <form method="post" class="flms-mgr-stack-form">
                                        <input type="hidden" name="flms_action" value="pay_transfer_fee">
                                        <input type="hidden" name="transfer_id" value="<?php echo (int) $trans->ID; ?>">
                                        <input type="hidden" name="player_id" value="<?php echo (int) $pid; ?>">
                                        <input type="hidden" name="target_team" value="<?php echo (int) $active_team_id; ?>">
                                        <?php wp_nonce_field( 'flms_pay_transfer_nonce' ); ?>
                                        <button type="submit" class="flms-mgr-btn flms-mgr-btn--accent"><?php esc_html_e( 'Pay fee (RM5)', 'flms' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ( 'dashboard' === $active_view && ! empty( $next_match ) ) :
                $mid           = $next_match[0]->ID;
                $hid           = get_post_meta( $mid, 'flms_home_team', true );
                $aid           = get_post_meta( $mid, 'flms_away_team', true );
                $is_home       = ( (int) $hid === (int) $active_team_id );
                $opponent_id   = $is_home ? $aid : $hid;
                $opponent_name = get_the_title( $opponent_id );
                $date          = get_post_meta( $mid, 'flms_match_date', true );
                $meta_key      = $is_home ? '_flms_lineup_home' : '_flms_lineup_away';
                $saved_lineup  = get_post_meta( $mid, $meta_key, true ) ?: [];
                ?>
            <section class="flms-mgr-card flms-mgr-card--lineup" aria-labelledby="flms-mgr-lineup-heading">
                <div class="flms-mgr-card__head flms-mgr-card__head--row">
                    <div>
                        <h2 id="flms-mgr-lineup-heading" class="flms-mgr-card__title"><?php esc_html_e( 'Next match lineup', 'flms' ); ?></h2>
                        <p class="flms-mgr-card__hint">
                            <?php
                            printf(
                                /* translators: 1: opponent name, 2: match date */
                                esc_html__( 'vs %1$s · %2$s', 'flms' ),
                                esc_html( $opponent_name ),
                                esc_html( $date ? date( 'd M', strtotime( $date ) ) : __( 'Date TBD', 'flms' ) )
                            );
                            ?>
                        </p>
                    </div>
                </div>
                <form method="post" class="flms-mgr-lineup-form">
                    <div class="flms-mgr-lineup-list" role="group" aria-label="<?php esc_attr_e( 'Select starting players', 'flms' ); ?>">
                        <?php foreach ( $players as $p ) :
                            $checked = in_array( $p->ID, $saved_lineup, true ) ? 'checked' : '';
                            ?>
                        <label class="flms-mgr-check">
                            <input type="checkbox" name="match_lineup[]" value="<?php echo (int) $p->ID; ?>" <?php echo $checked; ?>>
                            <span><?php echo esc_html( $p->post_title ); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="flms_action" value="save_lineup">
                    <input type="hidden" name="match_id" value="<?php echo (int) $mid; ?>">
                    <input type="hidden" name="is_home" value="<?php echo $is_home ? '1' : '0'; ?>">
                    <?php wp_nonce_field( 'flms_lineup_nonce' ); ?>
                    <button type="submit" class="flms-mgr-btn flms-mgr-btn--primary"><?php esc_html_e( 'Save lineup', 'flms' ); ?></button>
                </form>
            </section>
            <?php endif; ?>

            <?php if ( 'dashboard' === $active_view ) : ?>
            <section id="flms-mgr-fees" class="flms-mgr-card" aria-labelledby="flms-mgr-fees-heading">
                <div class="flms-mgr-card__head">
                    <h2 id="flms-mgr-fees-heading" class="flms-mgr-card__title"><?php esc_html_e( 'Match fees', 'flms' ); ?></h2>
                    <p class="flms-mgr-card__hint"><?php esc_html_e( 'Pay outstanding fees for recent fixtures.', 'flms' ); ?></p>
                </div>
                <div class="flms-mgr-scroll flms-table-responsive">
                    <table class="flms-league-table flms-mgr-table">
                        <thead><tr><th><?php esc_html_e( 'Match', 'flms' ); ?></th><th><?php esc_html_e( 'Date', 'flms' ); ?></th><th><?php esc_html_e( 'Status', 'flms' ); ?></th><th class="flms-mgr-table__actions"><?php esc_html_e( 'Action', 'flms' ); ?></th></tr></thead>
                        <tbody>
                            <?php
                            if ( empty( $finance_matches ) ) :
                                ?>
                                <tr><td colspan="4" class="flms-mgr-empty"><?php esc_html_e( 'No match records found.', 'flms' ); ?></td></tr>
                            <?php else : ?>
                                <?php
                                foreach ( $finance_matches as $fm ) :
                                    $mid           = $fm->ID;
                                    $date          = get_post_meta( $mid, 'flms_match_date', true );
                                    $hid           = get_post_meta( $mid, 'flms_home_team', true );
                                    $aid           = get_post_meta( $mid, 'flms_away_team', true );
                                    $is_home       = ( (int) $active_team_id === (int) $hid );
                                    $opponent_id   = $is_home ? $aid : $hid;
                                    $opponent_name = get_the_title( $opponent_id );
                                    $paid          = $is_home ? get_post_meta( $mid, '_flms_paid_home', true ) : get_post_meta( $mid, '_flms_paid_away', true );
                                    $fee           = get_post_meta( $mid, '_flms_match_fee', true ) ?: '100';
                                    ?>
                            <tr>
                                <td data-label="<?php esc_attr_e( 'Match', 'flms' ); ?>"><?php echo esc_html__( 'vs', 'flms' ); ?> <strong><?php echo esc_html( $opponent_name ); ?></strong></td>
                                <td data-label="<?php esc_attr_e( 'Date', 'flms' ); ?>"><?php echo esc_html( $date ); ?></td>
                                <td data-label="<?php esc_attr_e( 'Status', 'flms' ); ?>">
                                    <?php if ( 'yes' === $paid ) : ?>
                                        <span class="flms-mgr-status flms-mgr-status--ok"><?php esc_html_e( 'Paid', 'flms' ); ?></span>
                                    <?php else : ?>
                                        <span class="flms-mgr-status flms-mgr-status--due"><?php echo esc_html( sprintf( /* translators: %s fee amount */ __( 'Unpaid · RM%s', 'flms' ), $fee ) ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="flms-mgr-table__actions" data-label="<?php esc_attr_e( 'Action', 'flms' ); ?>">
                                    <?php if ( 'yes' !== $paid ) : ?>
                                        <form method="post" class="flms-mgr-stack-form">
                                            <input type="hidden" name="flms_action" value="pay_match_fee">
                                            <input type="hidden" name="match_id" value="<?php echo (int) $mid; ?>">
                                            <input type="hidden" name="team_id" value="<?php echo (int) $active_team_id; ?>">
                                            <?php wp_nonce_field( 'flms_pay_fee_nonce' ); ?>
                                            <button type="submit" class="flms-mgr-btn flms-mgr-btn--primary flms-mgr-btn--sm"><?php esc_html_e( 'Pay now', 'flms' ); ?></button>
                                        </form>
                                    <?php else : ?>
                                        <span class="flms-mgr-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ( 'players' === $active_view ) : ?>
            <section id="flms-mgr-players" class="flms-mgr-card" aria-labelledby="flms-mgr-squad-heading">
                <div class="flms-mgr-card__head">
                    <h2 id="flms-mgr-squad-heading" class="flms-mgr-card__title"><?php esc_html_e( 'Full squad', 'flms' ); ?></h2>
                    <p class="flms-mgr-card__hint"><?php esc_html_e( 'Edit roster details, photos, and remove players when the window allows.', 'flms' ); ?></p>
                </div>
                <div class="flms-mgr-scroll flms-table-responsive">
                    <table class="flms-league-table flms-squad-table flms-mgr-table">
                        <thead>
                            <tr>
                                <th class="flms-mgr-table__thumb"><?php esc_html_e( 'Photo', 'flms' ); ?></th>
                                <th><?php esc_html_e( 'No.', 'flms' ); ?></th>
                                <th class="col-name"><?php esc_html_e( 'Name', 'flms' ); ?></th>
                                <th><?php esc_html_e( 'Pos', 'flms' ); ?></th>
                                <th><?php esc_html_e( 'Age', 'flms' ); ?></th>
                                <th class="flms-mgr-table__actions"><?php esc_html_e( 'Actions', 'flms' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ( $players as $p ) :
                                $pos   = get_post_meta( $p->ID, 'flms_position', true ) ?: '-';
                                $age   = get_post_meta( $p->ID, 'flms_age', true ) ?: '-';
                                $ic    = get_post_meta( $p->ID, 'flms_ic', true ) ?: '';
                                $num   = get_post_meta( $p->ID, 'flms_number', true ) ?: '-';
                                $p_img = get_the_post_thumbnail_url( $p->ID, 'thumbnail' );
                                ?>
                            <tr data-id="<?php echo (int) $p->ID; ?>" data-name="<?php echo esc_attr( $p->post_title ); ?>" data-num="<?php echo esc_attr( $num ); ?>" data-pos="<?php echo esc_attr( $pos ); ?>" data-age="<?php echo esc_attr( $age ); ?>" data-ic="<?php echo esc_attr( $ic ); ?>">
                                <td class="flms-mgr-table__thumb" data-label="<?php esc_attr_e( 'Photo', 'flms' ); ?>">
                                    <?php if ( $p_img ) : ?>
                                        <img src="<?php echo esc_url( $p_img ); ?>" alt="" class="flms-mgr-avatar" width="40" height="40" loading="lazy">
                                    <?php else : ?>
                                        <form method="post" enctype="multipart/form-data" class="flms-upload-mini flms-mgr-upload-mini">
                                            <label class="flms-mgr-upload-trigger" title="<?php esc_attr_e( 'Upload photo', 'flms' ); ?>">
                                                <span aria-hidden="true">📷</span>
                                                <input type="file" name="player_photo" accept="image/*" class="flms-mgr-sr-only" onchange="this.form.submit()">
                                            </label>
                                            <input type="hidden" name="flms_action" value="upload_player_photo">
                                            <input type="hidden" name="player_id" value="<?php echo (int) $p->ID; ?>">
                                            <input type="hidden" name="team_id" value="<?php echo (int) $active_team_id; ?>">
                                            <?php wp_nonce_field( 'flms_player_photo_nonce' ); ?>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?php esc_attr_e( 'No.', 'flms' ); ?>"><?php echo esc_html( $num ); ?></td>
                                <td class="player-name" data-label="<?php esc_attr_e( 'Name', 'flms' ); ?>"><?php echo esc_html( $p->post_title ); ?></td>
                                <td data-label="<?php esc_attr_e( 'Pos', 'flms' ); ?>"><span class="flms-pos-badge"><?php echo esc_html( $pos ); ?></span></td>
                                <td data-label="<?php esc_attr_e( 'Age', 'flms' ); ?>"><?php echo esc_html( $age ); ?></td>
                                <td class="flms-mgr-table__actions" data-label="<?php esc_attr_e( 'Actions', 'flms' ); ?>">
                                    <div class="flms-mgr-row-actions">
                                        <button type="button" class="flms-mgr-btn flms-mgr-btn--ghost flms-mgr-btn--sm edit-player-btn"><?php esc_html_e( 'Edit', 'flms' ); ?></button>
                                        <?php if ( 'locked' !== $stage ) : ?>
                                            <a href="?flms_action=remove_player&amp;pid=<?php echo (int) $p->ID; ?>&amp;_wpnonce=<?php echo esc_attr( wp_create_nonce( 'flms_remove_' . $p->ID ) ); ?>" class="flms-mgr-btn flms-mgr-btn--danger flms-mgr-btn--sm flms-mgr-btn--link" onclick="return confirm('<?php echo esc_js( __( 'Remove this player?', 'flms' ) ); ?>');"><?php esc_html_e( 'Remove', 'flms' ); ?></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div id="flms-edit-modal" class="flms-modal flms-mgr-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="flms-mgr-edit-title">
                <div class="flms-modal-content flms-mgr-modal__dialog">
                    <button type="button" class="close-modal flms-mgr-modal__close" aria-label="<?php esc_attr_e( 'Close', 'flms' ); ?>">&times;</button>
                    <h3 id="flms-mgr-edit-title" class="flms-mgr-modal__title"><?php esc_html_e( 'Edit player', 'flms' ); ?></h3>
                    <form method="post" enctype="multipart/form-data" class="flms-mgr-modal__form">
                        <div class="flms-form-grid flms-mgr-form-grid">
                            <div class="form-group flms-mgr-field flms-mgr-field--full">
                                <label class="flms-mgr-label" for="edit_photo"><?php esc_html_e( 'Profile photo', 'flms' ); ?></label>
                                <input class="flms-mgr-input" id="edit_photo" type="file" name="edit_photo" accept="image/*">
                            </div>
                            <div class="form-group flms-mgr-field flms-mgr-field--full">
                                <label class="flms-mgr-label" for="edit_name"><?php esc_html_e( 'Full name', 'flms' ); ?></label>
                                <input class="flms-mgr-input" type="text" name="edit_name" id="edit_name" required>
                            </div>
                            <div class="form-group flms-mgr-field">
                                <label class="flms-mgr-label" for="edit_number"><?php esc_html_e( 'Number', 'flms' ); ?></label>
                                <input class="flms-mgr-input" type="number" name="edit_number" id="edit_number">
                            </div>
                            <div class="form-group flms-mgr-field">
                                <label class="flms-mgr-label" for="edit_age"><?php esc_html_e( 'Age', 'flms' ); ?></label>
                                <input class="flms-mgr-input" type="number" name="edit_age" id="edit_age">
                            </div>
                            <div class="form-group flms-mgr-field">
                                <label class="flms-mgr-label" for="edit_pos"><?php esc_html_e( 'Position', 'flms' ); ?></label>
                                <select class="flms-mgr-input flms-mgr-input--select" name="edit_pos" id="edit_pos">
                                    <option value="GK">GK</option>
                                    <option value="DEF">DEF</option>
                                    <option value="MID">MID</option>
                                    <option value="FWD">FWD</option>
                                </select>
                            </div>
                            <div class="form-group flms-mgr-field flms-mgr-field--full">
                                <label class="flms-mgr-label" for="edit_ic"><?php esc_html_e( 'IC / passport', 'flms' ); ?></label>
                                <input class="flms-mgr-input" type="text" name="edit_ic" id="edit_ic">
                            </div>
                        </div>
                        <input type="hidden" name="edit_pid" id="edit_pid">
                        <input type="hidden" name="flms_action" value="update_player">
                        <?php wp_nonce_field( 'flms_update_player_nonce' ); ?>
                        <div class="flms-mgr-modal__footer">
                            <button type="submit" class="flms-mgr-btn flms-mgr-btn--primary"><?php esc_html_e( 'Save changes', 'flms' ); ?></button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ( 'locked' !== $stage ) : ?>
            <div class="flms-mgr-split">
                <section class="flms-mgr-card flms-mgr-card--register flms-add-player-box" aria-labelledby="flms-mgr-add-heading">
                    <h2 id="flms-mgr-add-heading" class="flms-mgr-card__title"><?php esc_html_e( 'Register new player', 'flms' ); ?></h2>
                    <p class="flms-mgr-card__hint"><?php esc_html_e( 'Add a player to this squad. IC or passport is required.', 'flms' ); ?></p>
                    <form method="post" class="flms-mgr-stack">
                        <div class="flms-form-grid flms-mgr-form-grid">
                            <div class="form-group flms-mgr-field flms-mgr-field--full">
                                <label class="flms-mgr-label" for="p_name"><?php esc_html_e( 'Name', 'flms' ); ?></label>
                                <input class="flms-mgr-input" id="p_name" type="text" name="p_name" required>
                            </div>
                            <div class="form-group flms-mgr-field">
                                <label class="flms-mgr-label" for="p_number"><?php esc_html_e( 'No.', 'flms' ); ?></label>
                                <input class="flms-mgr-input" id="p_number" type="number" name="p_number">
                            </div>
                            <div class="form-group flms-mgr-field">
                                <label class="flms-mgr-label" for="p_pos"><?php esc_html_e( 'Position', 'flms' ); ?></label>
                                <select class="flms-mgr-input flms-mgr-input--select" name="p_pos" id="p_pos">
                                    <option value="GK">GK</option>
                                    <option value="DEF">DEF</option>
                                    <option value="MID">MID</option>
                                    <option value="FWD">FWD</option>
                                </select>
                            </div>
                            <div class="form-group flms-mgr-field">
                                <label class="flms-mgr-label" for="p_age"><?php esc_html_e( 'Age', 'flms' ); ?></label>
                                <input class="flms-mgr-input" id="p_age" type="number" name="p_age">
                            </div>
                            <div class="form-group flms-mgr-field flms-mgr-field--full">
                                <label class="flms-mgr-label" for="p_ic"><?php esc_html_e( 'IC / passport', 'flms' ); ?></label>
                                <input class="flms-mgr-input" id="p_ic" type="text" name="p_ic" required placeholder="<?php echo esc_attr__( 'e.g. A1234567', 'flms' ); ?>">
                            </div>
                        </div>
                        <input type="hidden" name="flms_action" value="add_player">
                        <input type="hidden" name="team_id" value="<?php echo (int) $active_team_id; ?>">
                        <?php wp_nonce_field( 'flms_add_player_nonce' ); ?>
                        <button type="submit" class="flms-mgr-btn flms-mgr-btn--primary flms-mgr-btn--block">
                            <?php echo 'paid' === $stage ? esc_html__( 'Pay & add (RM5)', 'flms' ) : esc_html__( 'Add player', 'flms' ); ?>
                        </button>
                    </form>
                </section>

                <section class="flms-mgr-card flms-mgr-card--transfer flms-transfer-box" aria-labelledby="flms-mgr-sign-heading">
                    <h2 id="flms-mgr-sign-heading" class="flms-mgr-card__title"><?php esc_html_e( 'Sign player (transfer)', 'flms' ); ?></h2>
                    <p class="flms-mgr-card__hint"><?php esc_html_e( 'Search by IC or passport to request a transfer into this team.', 'flms' ); ?></p>
                    <form method="post" id="flms-transfer-form" class="flms-mgr-stack">
                        <div class="flms-form-grid flms-mgr-form-grid">
                            <div class="form-group flms-mgr-field">
                                <label class="flms-mgr-label" for="trans_ic"><?php esc_html_e( 'Player IC / passport', 'flms' ); ?></label>
                                <input class="flms-mgr-input" type="text" name="player_ic" id="trans_ic" required placeholder="<?php echo esc_attr__( 'Enter ID to search', 'flms' ); ?>">
                            </div>
                            <div class="form-group flms-mgr-field">
                                <label class="flms-mgr-label" for="player_name_opt"><?php esc_html_e( 'Full name (optional)', 'flms' ); ?></label>
                                <input class="flms-mgr-input" id="player_name_opt" type="text" name="player_name_opt" placeholder="<?php echo esc_attr__( 'For your reference', 'flms' ); ?>">
                            </div>
                        </div>
                        <input type="hidden" name="my_team_id" value="<?php echo (int) $active_team_id; ?>">
                        <input type="hidden" name="flms_transfer_act" value="request">
                        <?php wp_nonce_field( 'flms_request_nonce' ); ?>
                        <button type="button" id="btn-check-transfer" class="flms-mgr-btn flms-mgr-btn--accent flms-mgr-btn--block transfer-btn"><?php esc_html_e( 'Sign player', 'flms' ); ?></button>
                    </form>
                </section>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ( 'settings' === $active_view ) : ?>
            <section id="flms-mgr-settings" class="flms-mgr-card flms-mgr-card--account" aria-labelledby="flms-mgr-account-heading">
                <h2 id="flms-mgr-account-heading" class="flms-mgr-card__title"><?php esc_html_e( 'Account security', 'flms' ); ?></h2>
                <p class="flms-mgr-card__hint"><?php esc_html_e( 'Update your login password for this manager account.', 'flms' ); ?></p>
                <form method="post" class="flms-mgr-stack flms-mgr-stack--narrow">
                    <div class="form-group flms-mgr-field">
                        <label class="flms-mgr-label" for="old_pass"><?php esc_html_e( 'Current password', 'flms' ); ?></label>
                        <input class="flms-mgr-input" id="old_pass" type="password" name="old_pass" required autocomplete="current-password">
                    </div>
                    <div class="form-group flms-mgr-field">
                        <label class="flms-mgr-label" for="new_pass"><?php esc_html_e( 'New password', 'flms' ); ?></label>
                        <input class="flms-mgr-input" id="new_pass" type="password" name="new_pass" required autocomplete="new-password">
                    </div>
                    <div class="form-group flms-mgr-field">
                        <label class="flms-mgr-label" for="confirm_pass"><?php esc_html_e( 'Confirm new password', 'flms' ); ?></label>
                        <input class="flms-mgr-input" id="confirm_pass" type="password" name="confirm_pass" required autocomplete="new-password">
                    </div>
                    <input type="hidden" name="flms_action" value="change_password">
                    <?php wp_nonce_field( 'flms_password_nonce' ); ?>
                    <button type="submit" class="flms-mgr-btn flms-mgr-btn--secondary"><?php esc_html_e( 'Change password', 'flms' ); ?></button>
                </form>
            </section>
            <?php endif; ?>

            </div>
                </div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($){
            var signLbl = <?php echo wp_json_encode( esc_html__( 'Sign player', 'flms' ) ); ?>;
            var checkLbl = <?php echo wp_json_encode( esc_html__( 'Checking…', 'flms' ) ); ?>;
            var needIc = <?php echo wp_json_encode( esc_html__( 'Please enter the player IC or passport.', 'flms' ) ); ?>;
            var foundTpl = <?php echo wp_json_encode( esc_html__( 'Found: %1$s (%2$s)', 'flms' ) ); ?>;
            var confirmBodyTpl = <?php echo wp_json_encode( esc_html__( 'Do you want to sign %1$s? Current team: %2$s', 'flms' ) ); ?>;
            var confirmTitle = <?php echo wp_json_encode( esc_html__( 'Confirm transfer?', 'flms' ) ); ?>;
            var confirmYes = <?php echo wp_json_encode( esc_html__( 'Yes, sign', 'flms' ) ); ?>;
            var confirmCancel = <?php echo wp_json_encode( esc_html__( 'Cancel', 'flms' ) ); ?>;
            var proceedFallback = <?php echo wp_json_encode( esc_html__( 'Proceed with transfer?', 'flms' ) ); ?>;
            var errorTitle = <?php echo wp_json_encode( esc_html__( 'Error', 'flms' ) ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( esc_url( admin_url( 'admin-ajax.php' ) ) ); ?>;

            $('#btn-check-transfer').on('click', function(e) {
                e.preventDefault();
                var ic = $('#trans_ic').val();
                var btn = $(this);
                if (!ic) { alert(needIc); return; }
                btn.text(checkLbl).prop('disabled', true);
                $.post(ajaxUrl, { action: 'flms_check_player', ic: ic }, function(res) {
                    if (res.success) {
                        var pName = res.data.name;
                        var pTeam = res.data.team;
                        var foundText = foundTpl.replace('%1$s', pName).replace('%2$s', pTeam);
                        var confirmBody = confirmBodyTpl.replace('%1$s', '<strong>' + pName + '</strong>').replace('%2$s', pTeam);
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                title: confirmTitle,
                                html: confirmBody,
                                icon: 'question',
                                showCancelButton: true,
                                confirmButtonColor: '#d4af37',
                                confirmButtonText: confirmYes,
                                cancelButtonText: confirmCancel
                            }).then(function(result) {
                                if (result.isConfirmed) { $('#flms-transfer-form').submit(); }
                                else { btn.text(signLbl).prop('disabled', false); }
                            });
                        } else {
                            if (window.confirm(foundText + '\n\n' + proceedFallback)) {
                                $('#flms-transfer-form').submit();
                            } else {
                                btn.text(signLbl).prop('disabled', false);
                            }
                        }
                    } else {
                        if (typeof Swal !== 'undefined') { Swal.fire(errorTitle, res.data, 'error'); }
                        else { alert(res.data); }
                        btn.text(signLbl).prop('disabled', false);
                    }
                });
            });

            $('.edit-player-btn').on('click', function() {
                var tr = $(this).closest('tr');
                $('#edit_pid').val(tr.data('id'));
                $('#edit_name').val(tr.data('name'));
                $('#edit_number').val(tr.data('num'));
                $('#edit_age').val(tr.data('age'));
                $('#edit_ic').val(tr.data('ic'));
                $('#edit_pos').val(tr.data('pos'));
                $('#flms-edit-modal').fadeIn();
            });
            $('.close-modal').on('click', function() { $('#flms-edit-modal').fadeOut(); });
            $(window).on('click', function(event) {
                if ($(event.target).is('#flms-edit-modal')) { $('#flms-edit-modal').fadeOut(); }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    private function render_completed_matches_panel( $team_id, $history_tab ) {
        if ( $history_tab === 'league' ) {
            $league_rows = $this->get_completed_league_matches_grouped( $team_id );
            $html          = '<section class="flms-mgr-card flms-mgr-card--history" aria-labelledby="flms-mgr-history-league-title">';
            $html         .= '<div class="flms-mgr-card__head"><h2 id="flms-mgr-history-league-title" class="flms-mgr-card__title">' . esc_html__( 'Completed league matches', 'flms' ) . '</h2>';
            $html         .= '<p class="flms-mgr-card__hint">' . esc_html__( 'Results grouped by competition.', 'flms' ) . '</p></div>';

            if ( empty( $league_rows ) ) {
                return $html . '<p class="flms-mgr-empty">' . esc_html__( 'No completed league matches yet.', 'flms' ) . '</p></section>';
            }

            foreach ( $league_rows as $competition_name => $rows ) {
                $html .= '<h3 class="flms-mgr-subheading">' . esc_html( $competition_name ) . '</h3>';
                $html .= '<div class="flms-mgr-scroll flms-table-responsive"><table class="flms-league-table flms-mgr-table">';
                $html .= '<thead><tr><th>' . esc_html__( 'Date', 'flms' ) . '</th><th>' . esc_html__( 'Match', 'flms' ) . '</th><th>' . esc_html__( 'Score', 'flms' ) . '</th></tr></thead><tbody>';

                foreach ( $rows as $r ) {
                    $html .= '<tr>';
                    $html .= '<td data-label="' . esc_attr__( 'Date', 'flms' ) . '">' . esc_html( $r['date'] ) . '</td>';
                    $html .= '<td data-label="' . esc_attr__( 'Match', 'flms' ) . '">' . esc_html( $r['match'] ) . '</td>';
                    $html .= '<td data-label="' . esc_attr__( 'Score', 'flms' ) . '"><strong>' . esc_html( $r['score'] ) . '</strong></td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table></div>';
            }

            return $html . '</section>';
        }

        $friendly_rows = $this->get_completed_friendly_matches( $team_id );
        $html          = '<section class="flms-mgr-card flms-mgr-card--history" aria-labelledby="flms-mgr-history-friendly-title">';
        $html         .= '<div class="flms-mgr-card__head"><h2 id="flms-mgr-history-friendly-title" class="flms-mgr-card__title">' . esc_html__( 'Completed friendly matches', 'flms' ) . '</h2>';
        $html         .= '<p class="flms-mgr-card__hint">' . esc_html__( 'Recent friendlies involving this team.', 'flms' ) . '</p></div>';

        if ( empty( $friendly_rows ) ) {
            return $html . '<p class="flms-mgr-empty">' . esc_html__( 'No completed friendly matches yet.', 'flms' ) . '</p></section>';
        }

        $html .= '<div class="flms-mgr-scroll flms-table-responsive"><table class="flms-league-table flms-mgr-table">';
        $html .= '<thead><tr><th>' . esc_html__( 'Date', 'flms' ) . '</th><th>' . esc_html__( 'Match', 'flms' ) . '</th><th>' . esc_html__( 'Score', 'flms' ) . '</th></tr></thead><tbody>';
        foreach ( $friendly_rows as $r ) {
            $html .= '<tr>';
            $html .= '<td data-label="' . esc_attr__( 'Date', 'flms' ) . '">' . esc_html( $r['date'] ) . '</td>';
            $html .= '<td data-label="' . esc_attr__( 'Match', 'flms' ) . '">' . esc_html( $r['match'] ) . '</td>';
            $html .= '<td data-label="' . esc_attr__( 'Score', 'flms' ) . '"><strong>' . esc_html( $r['score'] ) . '</strong></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div></section>';

        return $html;
    }

    private function render_history_preview_panels( $team_id, $friendly_view_url, $league_view_url ) {
        $friendly_rows = array_slice( $this->get_completed_friendly_matches( $team_id ), 0, 5 );
        $league_groups = $this->get_completed_league_matches_grouped( $team_id );
        $league_rows   = [];
        foreach ( $league_groups as $group_rows ) {
            foreach ( $group_rows as $row ) {
                $league_rows[] = $row;
            }
        }
        $league_rows = array_slice( $league_rows, 0, 5 );

        $html  = '<section id="flms-mgr-matches" class="flms-mgr-card flms-mgr-card--history" aria-labelledby="flms-mgr-preview-friendly-title">';
        $html .= '<div class="flms-mgr-card__head"><h2 id="flms-mgr-preview-friendly-title" class="flms-mgr-card__title">' . esc_html__( 'Recent friendly matches', 'flms' ) . '</h2></div>';
        if ( empty( $friendly_rows ) ) {
            $html .= '<p class="flms-mgr-empty">' . esc_html__( 'No completed friendly matches yet.', 'flms' ) . '</p>';
        } else {
            $html .= '<div class="flms-mgr-scroll flms-table-responsive"><table class="flms-league-table flms-mgr-table">';
            $html .= '<thead><tr><th>' . esc_html__( 'Date', 'flms' ) . '</th><th>' . esc_html__( 'Match', 'flms' ) . '</th><th>' . esc_html__( 'Score', 'flms' ) . '</th></tr></thead><tbody>';
            foreach ( $friendly_rows as $r ) {
                $html .= '<tr><td>' . esc_html( $r['date'] ) . '</td><td>' . esc_html( $r['match'] ) . '</td><td><strong>' . esc_html( $r['score'] ) . '</strong></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '<p class="flms-mgr-card__hint"><a href="' . esc_url( $friendly_view_url ) . '">' . esc_html__( 'See more friendly matches', 'flms' ) . '</a></p>';
        $html .= '</section>';

        $html .= '<section class="flms-mgr-card flms-mgr-card--history" aria-labelledby="flms-mgr-preview-league-title">';
        $html .= '<div class="flms-mgr-card__head"><h2 id="flms-mgr-preview-league-title" class="flms-mgr-card__title">' . esc_html__( 'Recent league matches', 'flms' ) . '</h2></div>';
        if ( empty( $league_rows ) ) {
            $html .= '<p class="flms-mgr-empty">' . esc_html__( 'No completed league matches yet.', 'flms' ) . '</p>';
        } else {
            $html .= '<div class="flms-mgr-scroll flms-table-responsive"><table class="flms-league-table flms-mgr-table">';
            $html .= '<thead><tr><th>' . esc_html__( 'Date', 'flms' ) . '</th><th>' . esc_html__( 'Match', 'flms' ) . '</th><th>' . esc_html__( 'Score', 'flms' ) . '</th></tr></thead><tbody>';
            foreach ( $league_rows as $r ) {
                $html .= '<tr><td>' . esc_html( $r['date'] ) . '</td><td>' . esc_html( $r['match'] ) . '</td><td><strong>' . esc_html( $r['score'] ) . '</strong></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '<p class="flms-mgr-card__hint"><a href="' . esc_url( $league_view_url ) . '">' . esc_html__( 'See more league matches', 'flms' ) . '</a></p>';
        $html .= '</section>';

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
        <div class="flms-dashboard-wrapper flms-mgr-dashboard flms-mgr-dashboard--setup">
            <div class="flms-mgr-dashboard__inner flms-mgr-dashboard__inner--narrow">
                <section class="flms-mgr-card flms-mgr-card--setup" aria-labelledby="flms-mgr-setup-title">
                    <h1 id="flms-mgr-setup-title" class="flms-mgr-card__title"><?php esc_html_e( 'Create your team', 'flms' ); ?></h1>
                    <p class="flms-mgr-card__hint"><?php esc_html_e( 'Set your club name and kit colours to access the manager dashboard.', 'flms' ); ?></p>
                    <form method="post" class="flms-mgr-stack">
                        <div class="form-group flms-mgr-field">
                            <label class="flms-mgr-label" for="flms-setup-team-name"><?php esc_html_e( 'Club name', 'flms' ); ?></label>
                            <input class="flms-mgr-input" id="flms-setup-team-name" type="text" name="team_name" required autocomplete="organization">
                        </div>
                        <div class="flms-mgr-kit-pickers">
                            <div class="form-group flms-mgr-field">
                                <label class="flms-mgr-label" for="flms-setup-home"><?php esc_html_e( 'Home kit', 'flms' ); ?></label>
                                <input class="flms-mgr-input flms-mgr-input--color" id="flms-setup-home" type="color" name="home_color" value="#ff0000">
                            </div>
                            <div class="form-group flms-mgr-field">
                                <label class="flms-mgr-label" for="flms-setup-away"><?php esc_html_e( 'Away kit', 'flms' ); ?></label>
                                <input class="flms-mgr-input flms-mgr-input--color" id="flms-setup-away" type="color" name="away_color" value="#ffffff">
                            </div>
                        </div>
                        <input type="hidden" name="flms_action" value="create_team">
                        <?php wp_nonce_field( 'flms_create_team_nonce' ); ?>
                        <button type="submit" class="flms-mgr-btn flms-mgr-btn--primary flms-mgr-btn--block"><?php esc_html_e( 'Create team', 'flms' ); ?></button>
                    </form>
                </section>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function process_actions() {
        if ( ! is_user_logged_in() ) return;
        
        // 1. Pay Fee (MATCH)
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'pay_match_fee' ) {
            check_admin_referer('flms_pay_fee_nonce');
            $mid = (int) $_POST['match_id'];
            $tid = (int) $_POST['team_id'];
            $uid = get_current_user_id();
            if ( ! $this->user_owns_team( $uid, $tid ) ) {
                wp_die( esc_html__( 'You are not allowed to pay fees for this team.', 'flms' ), '', [ 'response' => 403 ] );
            }
            $home = (int) get_post_meta( $mid, 'flms_home_team', true );
            $away = (int) get_post_meta( $mid, 'flms_away_team', true );
            if ( $tid !== $home && $tid !== $away ) {
                wp_die( esc_html__( 'This team is not part of this match.', 'flms' ), '', [ 'response' => 403 ] );
            }
            $fee_product_id = 19182;
            WC()->cart->empty_cart();
            WC()->cart->add_to_cart( $fee_product_id, 1, 0, [], [ 'match_fee_id' => $mid, 'match_fee_team' => $tid ] );
            wp_redirect( wc_get_checkout_url() );
            exit;
        }

        // 2. Pay Fee (TRANSFER)
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'pay_transfer_fee' ) {
            check_admin_referer('flms_pay_transfer_nonce');
            $pid = (int) $_POST['player_id'];
            $tid = (int) $_POST['target_team'];
            $req_id = isset( $_POST['transfer_id'] ) ? (int) $_POST['transfer_id'] : 0;
            $uid = get_current_user_id();
            if ( ! $this->user_owns_team( $uid, $tid ) ) {
                wp_die( esc_html__( 'You are not allowed to pay for this transfer target team.', 'flms' ), '', [ 'response' => 403 ] );
            }
            $transfer = $req_id ? get_post( $req_id ) : null;
            if ( ! $transfer || $transfer->post_type !== 'flms_transfer' ) {
                wp_die( esc_html__( 'Invalid transfer request.', 'flms' ), '', [ 'response' => 403 ] );
            }
            $meta_to = (int) get_post_meta( $req_id, '_to_team', true );
            $meta_player = (int) get_post_meta( $req_id, '_player_id', true );
            if ( $meta_to !== $tid || $meta_player !== $pid ) {
                wp_die( esc_html__( 'Transfer details do not match this payment.', 'flms' ), '', [ 'response' => 403 ] );
            }
            $fee_product_id = 16022;
            WC()->cart->empty_cart();
            WC()->cart->add_to_cart( $fee_product_id, 1, 0, [], [ 'transfer_pid' => $pid, 'transfer_target_team' => $tid, 'transfer_request_id' => $req_id ] );
            wp_redirect( wc_get_checkout_url() );
            exit;
        }

        // 3. Save Lineup
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'save_lineup' ) {
            check_admin_referer('flms_lineup_nonce');
            $mid = (int) $_POST['match_id'];
            $is_home = ( $_POST['is_home'] === '1' );
            $lineup = isset( $_POST['match_lineup'] ) ? array_map( 'intval', (array) $_POST['match_lineup'] ) : [];
            $home_id = (int) get_post_meta( $mid, 'flms_home_team', true );
            $away_id = (int) get_post_meta( $mid, 'flms_away_team', true );
            $side_team_id = $is_home ? $home_id : $away_id;
            $uid = get_current_user_id();
            if ( ! $side_team_id || ! $this->user_owns_team( $uid, $side_team_id ) ) {
                wp_die( esc_html__( 'You cannot edit lineup for this side of the match.', 'flms' ), '', [ 'response' => 403 ] );
            }
            foreach ( $lineup as $player_id ) {
                if ( ! $player_id ) {
                    continue;
                }
                $p_team = (int) get_post_meta( $player_id, 'flms_team_id', true );
                if ( $p_team !== $side_team_id ) {
                    wp_die( esc_html__( 'Lineup may only include players from your team.', 'flms' ), '', [ 'response' => 403 ] );
                }
            }
            $meta_key = $is_home ? '_flms_lineup_home' : '_flms_lineup_away';
            update_post_meta( $mid, $meta_key, $lineup );
            if ( class_exists( 'FLMS_Player_Stats' ) ) {
                $eng = new FLMS_Player_Stats();
                foreach ( $lineup as $pl_id ) {
                    $eng->recalculate_single_player( $pl_id );
                }
            }
            wp_redirect( add_query_arg( 'msg', 'lineup_saved', remove_query_arg( 'flms_action' ) ) );
            exit;
        }
        // 4. Update Logo
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'update_logo' ) {
            check_admin_referer('flms_logo_nonce');
            $tid = intval($_POST['team_id']);
            if ( ! $this->user_owns_team( get_current_user_id(), $tid ) ) {
                wp_die( esc_html__( 'You cannot update this team logo.', 'flms' ), '', [ 'response' => 403 ] );
            }
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

    /**
     * Whether the user is the post author (owner) of a flms_team.
     */
    private function user_owns_team( $user_id, $team_id ) {
        $user_id = (int) $user_id;
        $team_id = (int) $team_id;
        if ( ! $user_id || ! $team_id ) {
            return false;
        }
        $team = get_post( $team_id );
        if ( ! $team || $team->post_type !== 'flms_team' ) {
            return false;
        }
        return (int) $team->post_author === $user_id;
    }
}