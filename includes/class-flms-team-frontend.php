<?php
class FLMS_Team_Frontend {

    public function __construct() {
        // Override the content for Single Team pages
        add_filter( 'the_content', [ $this, 'render_team_page' ] );
    }

    public function render_team_page( $content ) {
        // Only run on single 'flms_team' posts
        if ( ! is_singular( 'flms_team' ) ) return $content;

        $id = get_the_ID();

        // --- 1. GET TEAM DATA ---
        $manager_id = get_post_field( 'post_author', $id );
        $manager_name = get_the_author_meta( 'display_name', $manager_id );
        
        $logo = class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_team_logo($id) : '';

        $home_col = get_post_meta( $id, 'flms_home_color', true ) ?: '#ccc';
        $away_col = get_post_meta( $id, 'flms_away_color', true ) ?: '#fff';

        // League Stats
        $played = (int) get_post_meta( $id, 'flms_stats_played', true );
        $won    = (int) get_post_meta( $id, 'flms_stats_won', true );
        $drawn  = (int) get_post_meta( $id, 'flms_stats_drawn', true );
        $lost   = (int) get_post_meta( $id, 'flms_stats_lost', true );
        $gf     = (int) get_post_meta( $id, 'flms_stats_gf', true );
        $ga     = (int) get_post_meta( $id, 'flms_stats_ga', true );
        $points = (int) get_post_meta( $id, 'flms_stats_points', true );
        $form_data = get_post_meta( $id, 'flms_stats_form', true );
        $form = is_array( $form_data ) ? $form_data : [];
        // Friendly (separate from league): player count + friendly points
        $player_count = class_exists( 'FLMS_Friendly' ) ? FLMS_Friendly::get_team_player_count( $id ) : 0;
        $friendly_points = class_exists( 'FLMS_Friendly' ) ? FLMS_Friendly::get_team_friendly_points( $id ) : 0;

        // Base vs branch: base = no tournament; branch = in a league
        $tournament_id = get_post_meta( $id, 'flms_tournament_id', true );
        $is_base_team = empty( $tournament_id );
        $tournament_name = $tournament_id ? get_the_title( (int) $tournament_id ) : '';

        ob_start();
        ?>
        <div class="flms-team-profile">
            
            <!-- HEADER: Logo & Identity -->
            <div class="flms-team-header" style="border-top: 5px solid <?php echo esc_attr($home_col); ?>;">
                <div class="header-content">
                    <?php if($logo): ?>
                    <img src="<?php echo esc_url($logo); ?>" class="team-profile-logo">
                    <?php endif; ?>
                    <div class="team-info">
                        <h1><?php the_title(); ?></h1>
                        <?php if ( $is_base_team ) : ?>
                        <p class="flms-team-badge flms-badge-main">Main team — not in a league</p>
                        <?php else : ?>
                        <p class="flms-team-badge flms-badge-league">League: <?php echo esc_html( $tournament_name ); ?></p>
                        <?php endif; ?>
                        <p class="manager-name">Manager: <strong><?php echo esc_html($manager_name); ?></strong></p>
                        <div class="kit-badges">
                            <span class="k-badge" style="background:<?php echo esc_attr($home_col); ?>">Home</span>
                            <span class="k-badge" style="background:<?php echo esc_attr($away_col); ?>; color:#333; border:1px solid #ccc;">Away</span>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Stats Box (League + Friendly + Squad) -->
                <div class="header-stats">
                    <?php if ( ! $is_base_team ) : ?>
                    <div class="big-stat">
                        <span class="val"><?php echo $points; ?></span>
                        <span class="lbl">League Pts</span>
                    </div>
                    <div class="big-stat">
                        <span class="val"><?php echo $played; ?></span>
                        <span class="lbl">Played</span>
                    </div>
                    <?php endif; ?>
                    <div class="big-stat">
                        <span class="val"><?php echo (int) $friendly_points; ?></span>
                        <span class="lbl">Friendly Pts</span>
                    </div>
                    <div class="big-stat">
                        <span class="val"><?php echo (int) $player_count; ?></span>
                        <span class="lbl">Players</span>
                    </div>
                </div>
            </div>

            <?php
            // Base team: show links to league teams
            if ( $is_base_team && $manager_id ) {
                $league_teams = get_posts([
                    'post_type'      => 'flms_team',
                    'author'         => $manager_id,
                    'posts_per_page' => -1,
                    'post_status'    => 'any',
                    'post__not_in'   => [ $id ],
                    'meta_query'     => [ [ 'key' => 'flms_tournament_id', 'value' => '', 'compare' => '!=' ] ],
                ]);
                if ( ! empty( $league_teams ) ) :
            ?>
            <div class="flms-your-league-teams">
                <h3>Your league teams</h3>
                <ul>
                    <?php foreach ( $league_teams as $lt ) : ?>
                    <li><a href="<?php echo esc_url( get_permalink( $lt->ID ) ); ?>"><?php echo esc_html( get_the_title( $lt->ID ) ); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
                endif;
            }
            ?>

            <!-- SECTION: Form Guide (league teams only) -->
            <?php if ( ! empty($form) && ! $is_base_team ) : ?>
            <div class="flms-section" style="margin-top:20px;">
                <h3 style="font-size:14px; color:#666;">Current Form</h3>
                <div class="flms-form-row">
                    <?php foreach($form as $res): 
                         $bg = '#95a5a6';
                         if($res == 'W') $bg = '#2ecc71';
                         if($res == 'L') $bg = '#e74c3c';
                    ?>
                    <span class="form-bubble" style="background:<?php echo $bg; ?>"><?php echo $res; ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- SECTION: Detailed Stats (league teams only) -->
            <?php if ( $is_base_team ) : ?>
            <p class="flms-base-stats-note">League stats (wins, goals, etc.) appear when this team is in a tournament.</p>
            <?php else : ?>
            <div class="flms-stats-bar">
                <div class="s-box"><strong>Won:</strong> <?php echo $won; ?></div>
                <div class="s-box"><strong>Drawn:</strong> <?php echo $drawn; ?></div>
                <div class="s-box"><strong>Lost:</strong> <?php echo $lost; ?></div>
                <div class="s-box"><strong>GF:</strong> <?php echo $gf; ?></div>
                <div class="s-box"><strong>GA:</strong> <?php echo $ga; ?></div>
            </div>
            <?php endif; ?>

            <div class="flms-grid-layout">
                
                <!-- LEFT COL: SQUAD LIST -->
                <div class="flms-col-left">
                    <h3 style="color:white;">Squad List</h3>
                    <!-- Wrapped in responsive div for horizontal scrolling on mobile -->
                    <div class="flms-table-responsive">
                        <?php echo $this->get_squad_list($id); ?>
                    </div>
                </div>

                <!-- RIGHT COL: RECENT MATCHES -->
                <div class="flms-col-right">
                    <h3>Recent Matches</h3>
                    <?php echo $this->get_recent_matches($id); ?>
                    <h3 style="margin-top:18px;">Completed Friendly Matches</h3>
                    <?php echo $this->get_completed_friendly_matches($id); ?>
                </div>

            </div>

        </div>

        <style>
            /* CSS STYLES */
            .flms-team-profile { max-width: 1000px; margin: 0 auto; font-family: sans-serif; }
            
            /* Header */
            .flms-team-header { background: #fff; padding: 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-radius: 0 0 8px 8px; flex-wrap: wrap; gap: 20px; }
            .header-content { display: flex; align-items: center; gap: 20px; }
            .team-profile-logo { width: 100px; height: 100px; object-fit: contain; }
            .team-info h1 { margin: 0 0 5px 0; font-size: 32px; color: #333; }
            .flms-team-badge { margin: 4px 0 0 0; font-size: 13px; color: #666; }
            .flms-badge-main { font-style: italic; }
            .flms-badge-league { font-weight: 600; color: #37003c; }
            .flms-base-stats-note { margin: 20px 0; padding: 12px 16px; background: #f5f5f5; border-radius: 8px; color: #666; font-size: 14px; }
            .flms-your-league-teams { margin: 20px 0; padding: 16px; background: #fff; border: 1px solid #eee; border-radius: 8px; }
            .flms-your-league-teams h3 { margin: 0 0 10px 0; font-size: 14px; color: #333; text-transform: uppercase; letter-spacing: 0.5px; }
            .flms-your-league-teams ul { margin: 0; padding: 0; list-style: none; }
            .flms-your-league-teams li { margin-bottom: 6px; }
            .flms-your-league-teams a { color: #37003c; text-decoration: none; font-weight: 600; }
            .flms-your-league-teams a:hover { text-decoration: underline; }
            .kit-badges { margin-top: 10px; display: flex; gap: 10px; }
            .k-badge { padding: 3px 8px; font-size: 11px; color: #fff; border-radius: 4px; text-transform: uppercase; font-weight: bold; }
            
            .header-stats { display: flex; gap: 20px; }
            .big-stat { text-align: center; background: #f9f9f9; padding: 10px 20px; border-radius: 8px; }
            .big-stat .val { display: block; font-size: 32px; font-weight: 800; color: #37003c; line-height: 1; }
            .big-stat .lbl { font-size: 12px; text-transform: uppercase; color: #777; }

            /* Stats Bar */
            .flms-stats-bar { display: flex; background: #37003c; color: #fff; padding: 15px; border-radius: 8px; justify-content: space-around; margin: 20px 0; flex-wrap: wrap; }
            .s-box { font-size: 14px; }

            /* Form */
            .flms-form-row { display: flex; gap: 5px; }
            .form-bubble { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: bold; border-radius: 50%; }

            /* Grid Layout */
            .flms-grid-layout { display: flex; gap: 30px; margin-top: 30px; }
            .flms-col-left { flex: 2; min-width: 0; } /* min-width 0 prevents flex overflow */
            .flms-col-right { flex: 1; }

            /* Tables */
            .flms-squad-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #eee; }
            .flms-squad-table th, .flms-squad-table td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
            .flms-squad-table th { background: #f5f5f5; font-size: 13px; font-weight: bold; text-transform: uppercase; }
            .pos-tag { background: #eee; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; }
            
            /* Stat Columns Styling */
            .sq-stat { text-align: center !important; width: 40px; font-weight: bold; }
            .sq-goal { color: #2ecc71; }
            .sq-assist { color: #0d47a1; }
            .sq-yellow { color: #f1c40f; }
            .sq-red { color: #e74c3c; }

            /* Mini Match List */
            .mini-match-list { list-style: none; padding: 0; }
            .mini-match-row { background: #fff; padding: 10px; border: 1px solid #eee; margin-bottom: 8px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
            .mm-score { background: #333; color: #fff; padding: 2px 6px; border-radius: 3px; font-weight: bold; }

            @media (max-width: 768px) {
                .flms-grid-layout { flex-direction: column; }
                .flms-team-header { flex-direction: column; text-align: center; }
                .header-content { flex-direction: column; }
            }
        </style>
        <?php
        return ob_get_clean();
    }

    // HELPER: Get Squad List
    private function get_squad_list( $team_id ) {
        if ( ! $team_id ) return '<p>No team data found.</p>';

        $players = get_posts([
            'post_type' => 'flms_player',
            'meta_key' => 'flms_team_id',
            'meta_value' => $team_id,
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);

        if(empty($players)) return '<p>No players registered.</p>';

        $html = '<table class="flms-squad-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Pos</th>
                    <th>Age</th>
                    <th class="sq-stat" title="Goals">Goals</th>
                    <th class="sq-stat" title="Assists">Assists</th>
                    <th class="sq-stat" title="Yellow Cards">Yellow Cards</th>
                    <th class="sq-stat" title="Red Cards">Red Cards</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach($players as $p) {
            $pos = get_post_meta($p->ID, 'flms_position', true) ?: '-';
            $age = get_post_meta($p->ID, 'flms_age', true) ?: '-';
            
            // Get detailed stats
            $goals   = (int) get_post_meta($p->ID, 'flms_total_goals', true);
            $assists = (int) get_post_meta($p->ID, 'flms_total_assists', true);
            $yellow  = (int) get_post_meta($p->ID, 'flms_total_yellow', true);
            $red     = (int) get_post_meta($p->ID, 'flms_total_red', true);

            $link = get_permalink($p->ID);

            $html .= "<tr>
                <td><a href='$link' style='text-decoration:none; color:#333; font-weight:bold;'>{$p->post_title}</a></td>
                <td><span class='pos-tag'>".esc_html($pos)."</span></td>
                <td>".esc_html($age)."</td>
                <td class='sq-stat sq-goal'>"   . ($goals > 0 ? $goals : '-') . "</td>
                <td class='sq-stat sq-assist'>" . ($assists > 0 ? $assists : '-') . "</td>
                <td class='sq-stat sq-yellow'>" . ($yellow > 0 ? $yellow : '-') . "</td>
                <td class='sq-stat sq-red'>"    . ($red > 0 ? $red : '-') . "</td>
            </tr>";
        }
        $html .= '</tbody></table>';
        return $html;
    }

    // HELPER: RECENT MATCHES (Sorted by Match Date DESC)
    private function get_recent_matches( $team_id ) {
        if ( ! $team_id ) return '';

        // Query Matches where team is Home OR Away
        $matches = get_posts([
            'post_type' => 'flms_match',
            'posts_per_page' => 10,
            'meta_query' => [
                'relation' => 'OR',
                ['key'=>'flms_home_team', 'value'=>$team_id],
                ['key'=>'flms_away_team', 'value'=>$team_id]
            ],
            // --- FIX: SORT BY GAME DATE ---
            'meta_key' => 'flms_match_date',
            'orderby'  => 'meta_value', // Sorts chronologically by YYYY-MM-DD
            'order'    => 'DESC'        // Latest date first (Top)
        ]);

        if(empty($matches)) return '<p style="color:#666;">No matches found.</p>';

        $html = '<ul class="mini-match-list" style="padding:0; list-style:none;">';
        $processed_ids = []; 
        $visible_count = 0;

        foreach($matches as $m) {
            // Duplicate Check
            if ( in_array($m->ID, $processed_ids) ) continue;
            $processed_ids[] = $m->ID;

            $status = get_post_meta($m->ID, 'flms_match_status', true);
            
            // Only show completed matches
            if ( strtolower($status) !== 'completed' ) continue;

            $hid = get_post_meta($m->ID, 'flms_home_team', true);
            $aid = get_post_meta($m->ID, 'flms_away_team', true);
            $h_sc = get_post_meta($m->ID, 'flms_home_score', true);
            $a_sc = get_post_meta($m->ID, 'flms_away_score', true);
            $date_raw = get_post_meta($m->ID, 'flms_match_date', true);
            $date = $date_raw ? date('d M', strtotime($date_raw)) : '';
            
            // Determine Opponent
            if ($hid == $team_id) {
                $opp_id = $aid;
                $display_score = "$h_sc - $a_sc"; 
            } else {
                $opp_id = $hid;
                $display_score = "$h_sc - $a_sc"; 
            }

            $opp_name = get_the_title($opp_id);
            $visible_count++;

            $html .= "<li class='mini-match-row'>
                <div>
                    <span class='mm-date'>$date</span>
                    vs <strong >".esc_html($opp_name)."</strong>
                </div>
                <span class='mm-score'>$display_score</span>
            </li>";
            
            if($visible_count >= 5) break; 
        }
        $html .= '</ul>';
        
        if ($visible_count === 0) return '<p style="color:#666; font-style:italic;">No matches played yet.</p>';

        return $html;
    }

    // HELPER: COMPLETED FRIENDLY MATCHES (Latest first)
    private function get_completed_friendly_matches( $team_id ) {
        if ( ! $team_id ) return '';

        $friendlies = get_posts([
            'post_type'      => 'flms_friendly',
            'posts_per_page' => 20,
            'post_status'    => 'publish',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => 'flms_friendly_status',
                    'value'   => 'completed',
                    'compare' => '=',
                ],
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

        if ( empty( $friendlies ) ) {
            return '<p style="color:#666; font-style:italic;">No completed friendly matches yet.</p>';
        }

        $html = '<ul class="mini-match-list" style="padding:0; list-style:none;">';
        $shown = 0;

        foreach ( $friendlies as $f ) {
            $host_id = (int) get_post_meta( $f->ID, 'flms_host_team_id', true );
            $away_id = (int) get_post_meta( $f->ID, 'flms_chosen_team_id', true );
            $host_score = get_post_meta( $f->ID, 'flms_friendly_home_score', true );
            $away_score = get_post_meta( $f->ID, 'flms_friendly_away_score', true );

            if ( $host_score === '' || $away_score === '' ) continue;

            $opp_id = ( $host_id === (int) $team_id ) ? $away_id : $host_id;
            $opp_name = get_the_title( $opp_id );
            $date_raw = get_post_meta( $f->ID, 'flms_friendly_date', true );
            $date = $date_raw ? date( 'd M', strtotime( $date_raw ) ) : '';
            $friendly_link = class_exists( 'FLMS_Friendly' ) ? FLMS_Friendly::get_friendly_match_details_url( $f->ID ) : '';
            $left_text = "<span class='mm-date'>{$date}</span> vs <strong>" . esc_html( $opp_name ) . "</strong>";
            if ( $friendly_link ) {
                $left_text = '<a href="' . esc_url( $friendly_link ) . '" style="text-decoration:none; color:inherit;">' . $left_text . '</a>';
            }

            $html .= "<li class='mini-match-row'>
                <div>
                    {$left_text}
                </div>
                <span class='mm-score'>" . esc_html( $host_score . ' - ' . $away_score ) . "</span>
            </li>";

            $shown++;
            if ( $shown >= 5 ) break;
        }

        $html .= '</ul>';

        if ( $shown === 0 ) {
            return '<p style="color:#666; font-style:italic;">No completed friendly matches yet.</p>';
        }

        return $html;
    }
}