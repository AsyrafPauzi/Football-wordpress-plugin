<?php
class FLMS_Table_Shortcode {
    public function __construct() {
        add_shortcode( 'flms_league_table', [ $this, 'render_table' ] );
    }

    public function render_table( $atts ) {
        $atts = shortcode_atts( [ 'id' => 0, 'limit' => -1, 'layout' => 'full' ], $atts );
        $tid = intval( $atts['id'] );

        // --- GROUP STAGE + KNOCKOUT: Render separate per-group tables ---
        $format = get_post_meta( $tid, '_flms_format', true );
        if ( $format === 'group_knockout' && class_exists( 'FLMS_Match_Engine' ) ) {
            return $this->render_group_tables( $tid );
        }

        $cache_key = 'flms_table_' . FLMS_Cache_Bump::version() . '_' . $tid . '_' . $atts['limit'] . '_' . $atts['layout'];
        $cached_html = get_transient( $cache_key );

        if ( false !== $cached_html ) {
            return $cached_html . '<!-- Cached -->';
        }

        $wrapper_class = ($atts['layout'] === 'sidebar') ? 'flms-sidebar-mode' : '';

        // --- FIX: QUERY TEAMS DIRECTLY ---
        $args = [ 
            'post_type' => 'flms_team', 
            'posts_per_page' => intval($atts['limit']), 
            'post_status' => 'publish' 
        ];
        
        if ( $tid > 0 ) {
            $args['meta_key'] = 'flms_tournament_id';
            $args['meta_value'] = $tid;
        }

        $teams_query = get_posts( $args );

        if ( empty( $teams_query ) ) {
            // Fallback for empty state
            return '<div class="flms-empty" style="text-align:center; padding:20px; color:#999;">No teams found for this tournament.</div>';
        }

        $team_data = [];
        
        foreach ( $teams_query as $team ) {
            $id = $team->ID;
            $all_meta = get_post_meta( $id ); 
            $val = function($k) use ($all_meta) { return isset($all_meta[$k][0]) ? (int)$all_meta[$k][0] : 0; };

            $team_data[] = [
                'id' => $id, 
                'name' => $team->post_title, 
                'logo' => class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_team_logo($id) : '',
                'color' => isset($all_meta['flms_home_color'][0]) ? $all_meta['flms_home_color'][0] : '#ccc',
                'p' => $val('flms_stats_played'), 'w' => $val('flms_stats_won'), 'd' => $val('flms_stats_drawn'),
                'l' => $val('flms_stats_lost'), 'gf' => $val('flms_stats_gf'), 'ga' => $val('flms_stats_ga'),
                'gd' => $val('flms_stats_gd'), 'pts' => $val('flms_stats_points'),
                'form' => get_post_meta($id, 'flms_stats_form', true) ?: []
            ];
        }

        usort( $team_data, function( $a, $b ) {
            if ( $a['pts'] != $b['pts'] ) return $b['pts'] - $a['pts'];
            if ( $a['gd'] != $b['gd'] ) return $b['gd'] - $a['gd'];
            return $b['gf'] - $a['gf'];
        });

        ob_start();
        ?>
        <div class="flms-table-responsive <?php echo $wrapper_class; ?>">
            <table class="flms-league-table">
                <thead>
                    <tr>
                        <th class="pos">#</th>
                        <th class="club">CLUB</th>
                        <th>P</th><th>W</th><th>D</th><th>L</th><th>GF</th><th>GA</th><th>GD</th>
                        <th class="pts">PTS</th>
                        <th class="form">FORM</th>
                    </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ( $team_data as $t ) : ?>
                    <tr>
                        <td class="pos"><?php echo $pos; ?></td>
                        <td class="club">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <?php if($t['logo']): ?><img src="<?php echo esc_url($t['logo']); ?>" style="width:25px; height:25px; object-fit:contain; border-bottom: 2px solid <?php echo $t['color']; ?>; border-radius:3px;"><?php endif; ?>
                                <a href="<?php echo get_permalink($t['id']); ?>" class="team-table-link"><?php echo esc_html( $t['name'] ); ?></a>
                            </div>
                        </td>
                        <td><?php echo $t['p']; ?></td><td><?php echo $t['w']; ?></td><td><?php echo $t['d']; ?></td><td><?php echo $t['l']; ?></td>
                        <td><?php echo $t['gf']; ?></td><td><?php echo $t['ga']; ?></td><td><?php echo $t['gd']; ?></td>
                        <td class="pts"><?php echo $t['pts']; ?></td>
                        <td class="form">
                            <div style="display:flex; gap:2px; justify-content:center;">
                                <?php foreach($t['form'] as $res): 
                                    $bg='#95a5a6'; if($res=='W')$bg='#2ecc71'; if($res=='L')$bg='#e74c3c';
                                    echo "<span class='flms-form-badge' style='background:$bg; width:18px; height:18px; font-size:9px; line-height:18px;'>$res</span>";
                                endforeach; ?>
                            </div>
                        </td>
                    </tr>
                <?php $pos++; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        $output = ob_get_clean();
        set_transient( $cache_key, $output, 12 * HOUR_IN_SECONDS );
        return $output;
    }

    // ---------------------------------------------------------------
    // GROUP TABLES RENDERER — called when format is group_knockout
    // Renders a compact group table per group, side by side
    // ---------------------------------------------------------------
    private function render_group_tables( $tid ) {
        $standings = FLMS_Match_Engine::get_group_standings( $tid );

        if ( empty( $standings ) ) {
            return '<div class="flms-empty" style="text-align:center; padding:20px; color:#999;">No group data yet. Matches may still be pending.</div>';
        }

        $qualifiers_per_group = intval( get_post_meta( $tid, '_flms_ko_qualifiers_per_group', true ) ?: 2 );

        ob_start();
        ?>
        <div class="flms-group-tables-wrapper">
        <?php foreach ( $standings as $group_label => $rows ) : ?>
            <div class="flms-group-block">
                <div class="flms-group-header">GROUP <?php echo esc_html( $group_label ); ?></div>
                <table class="flms-league-table flms-group-table">
                    <colgroup>
                        <col class="col-pos">
                        <col class="col-club">
                        <col class="col-stat"><col class="col-stat"><col class="col-stat"><col class="col-stat">
                        <col class="col-stat">
                        <col class="col-pts">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="col-pos">#</th>
                            <th class="col-club">CLUB</th>
                            <th class="col-stat" title="Played">P</th>
                            <th class="col-stat" title="Won">W</th>
                            <th class="col-stat" title="Drawn">D</th>
                            <th class="col-stat" title="Lost">L</th>
                            <th class="col-stat" title="Goal Difference">GD</th>
                            <th class="col-pts" title="Points">PTS</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $pos => $team ) :
                        $qualifies = ( $pos < $qualifiers_per_group );
                        $row_class = $qualifies ? 'flms-group-qualifier' : '';
                    ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td class="col-pos"><?php echo $pos + 1; ?></td>
                            <td class="col-club">
                                <?php
                                $logo = class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_team_logo( $team['team_id'] ) : '';
                                $link = get_permalink( $team['team_id'] );
                                ?>
                                <div class="club-cell">
                                    <?php if ( $logo ) : ?><img src="<?php echo esc_url($logo); ?>" alt=""><?php endif; ?>
                                    <a href="<?php echo esc_url($link); ?>" class="team-table-link" title="<?php echo esc_attr( $team['name'] ); ?>"><?php echo esc_html( $team['name'] ); ?></a>
                                    <?php if ( $qualifies ) : ?><span class="flms-qual-dot" title="Qualifies to next round"></span><?php endif; ?>
                                </div>
                            </td>
                            <td class="col-stat"><?php echo $team['p']; ?></td>
                            <td class="col-stat"><?php echo $team['w']; ?></td>
                            <td class="col-stat"><?php echo $team['d']; ?></td>
                            <td class="col-stat"><?php echo $team['l']; ?></td>
                            <td class="col-stat"><?php echo ( $team['gd'] >= 0 ? '+' : '' ) . $team['gd']; ?></td>
                            <td class="col-pts"><?php echo $team['pts']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
        </div>

        <style>
            .flms-group-tables-wrapper {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
                gap: 24px;
                margin-bottom: 30px;
            }
            .flms-group-block {
                background: #fff;
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 4px 15px rgba(0,0,0,0.07);
                border: 1px solid #eee;
                min-width: 0;
            }
            .flms-group-header {
                background: #37003c;
                color: #D4AF37;
                font-size: 13px;
                font-weight: 800;
                letter-spacing: 2px;
                text-transform: uppercase;
                padding: 10px 16px;
            }
            /* Make table scroll horizontally rather than compress */
            .flms-group-block { overflow-x: auto; }
            .flms-group-table {
                width: 100%;
                min-width: 320px;
                border-collapse: collapse;
                table-layout: fixed;
            }
            .flms-group-table colgroup col.col-pos  { width: 32px; }
            .flms-group-table colgroup col.col-club { width: auto; }
            .flms-group-table colgroup col.col-stat { width: 30px; }
            .flms-group-table colgroup col.col-pts  { width: 36px; }

            .flms-group-table thead tr th {
                background: #f4f4f4;
                font-size: 10px;
                font-weight: 700;
                color: #777;
                padding: 7px 6px;
                text-align: center;
                border-bottom: 2px solid #eee;
                white-space: nowrap;
            }
            .flms-group-table thead tr th.col-club { text-align: left; padding-left: 10px; }
            .flms-group-table thead tr th.col-pos  { text-align: left; }

            .flms-group-table tbody tr td {
                padding: 9px 6px;
                font-size: 13px;
                border-bottom: 1px solid #f2f2f2;
                text-align: center;
                vertical-align: middle;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .flms-group-table tbody tr td.col-club {
                text-align: left;
                padding-left: 10px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 160px;
            }
            .flms-group-table tbody tr td.col-pos { font-weight: 700; color: #999; }
            .flms-group-table tbody tr td.col-pts  { font-weight: 800; color: #222; }

            .flms-group-qualifier { background: #f0fff4 !important; }
            .flms-qual-dot {
                display: inline-block;
                width: 8px;
                height: 8px;
                background: #2ecc71;
                border-radius: 50%;
                margin-left: 5px;
                vertical-align: middle;
                flex-shrink: 0;
            }
            .flms-group-table tbody tr:last-child td { border-bottom: none; }

            /* Team name link */
            .flms-group-table .team-table-link {
                color: #333;
                text-decoration: none;
                font-weight: 600;
                font-size: 13px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                display: block;
                max-width: 130px;
            }
            .flms-group-table .team-table-link:hover { color: #37003c; }

            /* Club cell flex row */
            .flms-group-table .club-cell {
                display: flex;
                align-items: center;
                gap: 6px;
                overflow: hidden;
            }
            .flms-group-table .club-cell img {
                flex-shrink: 0;
                width: 22px;
                height: 22px;
                object-fit: contain;
                border-radius: 3px;
            }
        </style>
        <?php
        return ob_get_clean();
    }

}