<?php
/**
 * Admin UI: Generate Knockout Stage from Group Results
 * Phase 2 of the Group Stage + Knockout format.
 */
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

// Get all tournaments that have group_knockout format
$all_products = wc_get_products( [ 'limit' => -1 ] );
$gk_tournaments = [];
foreach ( $all_products as $p ) {
    if ( get_post_meta( $p->get_id(), '_flms_format', true ) === 'group_knockout' ) {
        $gk_tournaments[] = $p;
    }
}

$selected_tid = isset( $_GET['tournament_id'] ) ? intval( $_GET['tournament_id'] ) : 0;
$standings           = [];
$qualifiers          = 2;            // Default: top 2 per group advance
$seeding_logic       = 'cross_half'; // Default: A vs C, B vs D
$third_place_enabled = 'yes';        // Default: include 3rd-place play-off

if ( $selected_tid ) {
    $standings           = FLMS_Match_Engine::get_group_standings( $selected_tid );
    $qualifiers          = intval( get_post_meta( $selected_tid, '_flms_ko_qualifiers_per_group', true ) ?: 2 );
    $seeding_logic       = get_post_meta( $selected_tid, '_flms_seeding_logic', true ) ?: 'cross_half';
    $third_place_raw     = get_post_meta( $selected_tid, '_flms_third_place_match', true );
    $third_place_enabled = $third_place_raw === '' ? 'yes' : $third_place_raw;
}

$seeding_logic_labels = [
    'cross_half' => 'A1 vs C2 · B1 vs D2 · C1 vs A2 · D1 vs B2 (Cross-half bracket — first half vs second half)',
    'adjacent'   => 'A1 vs B2 · B1 vs A2 · C1 vs D2 · D1 vs C2 (Classic cross-group bracket)',
    'manual'     => 'Manual — qualifiers listed in group order, override each seed below as needed.',
];

// Check for success message
$msg = isset( $_GET['msg'] ) ? sanitize_text_field( $_GET['msg'] ) : '';
?>
<div class="wrap">
    <h1>⚽ Generate Knockout Stage</h1>
    <p class="description">Select a Group Stage + Knockout tournament to view the current group standings and launch the knockout bracket from the qualifiers.</p>

    <?php if ( $msg === 'knockout_generated' ) : ?>
        <div class="notice notice-success is-dismissible"><p>✅ Knockout bracket matches have been generated successfully!</p></div>
    <?php endif; ?>

    <!-- Step 1: Select Tournament -->
    <form method="get" action="<?php echo admin_url('edit.php'); ?>">
        <input type="hidden" name="post_type" value="flms_match">
        <input type="hidden" name="page" value="flms-knockout-gen">
        <table class="form-table">
            <tr>
                <th><label>Select Tournament</label></th>
                <td>
                    <select name="tournament_id" onchange="this.form.submit()">
                        <option value="">— Choose a Tournament —</option>
                        <?php foreach ( $gk_tournaments as $p ) : ?>
                            <option value="<?php echo $p->get_id(); ?>" <?php selected( $selected_tid, $p->get_id() ); ?>>
                                <?php echo esc_html( $p->get_name() ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
    </form>

    <?php if ( $selected_tid && ! empty( $standings ) ) : ?>
        <hr>
        <h2>Group Standings — <?php echo esc_html( get_the_title( $selected_tid ) ); ?></h2>

        <!-- Group tables display -->
        <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:24px; margin-bottom:32px;">
        <?php foreach ( $standings as $group_label => $rows ) : ?>
            <div style="min-width:0;">
                <h3 style="background:#37003c; color:#D4AF37; padding:10px 16px; border-radius:6px 6px 0 0; margin:0;">
                    Group <?php echo esc_html( $group_label ); ?>
                </h3>
                <table class="wp-list-table widefat fixed" style="border-radius:0 0 6px 6px; overflow:hidden;">
                    <thead>
                        <tr>
                            <th>#</th><th>Team</th><th>P</th><th>W</th><th>D</th><th>L</th>
                            <th>GF</th><th>GA</th><th>GD</th><th>Pts</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $pos => $team ) :
                        $bg = ( $pos < $qualifiers ) ? 'background:#e8f5e9;' : '';
                        $badge = ( $pos < $qualifiers ) ? ' <span style="background:#2ecc71; color:#fff; font-size:10px; padding:1px 5px; border-radius:3px;">✓ Qualifies</span>' : '';
                    ?>
                        <tr style="<?php echo $bg; ?>">
                            <td><?php echo $pos + 1; ?></td>
                            <td><?php echo esc_html( $team['name'] ); ?><?php echo $badge; ?></td>
                            <td><?php echo $team['p']; ?></td>
                            <td><?php echo $team['w']; ?></td>
                            <td><?php echo $team['d']; ?></td>
                            <td><?php echo $team['l']; ?></td>
                            <td><?php echo $team['gf']; ?></td>
                            <td><?php echo $team['ga']; ?></td>
                            <td><?php echo $team['gd'] >= 0 ? '+' . $team['gd'] : $team['gd']; ?></td>
                            <td><strong><?php echo $team['pts']; ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
        </div>

        <!-- Step 2: Generate Knockout -->
        <hr>
        <h2>Generate Knockout Bracket</h2>
        <p>The system has pre-selected the <strong>top <?php echo $qualifiers; ?> teams from each group</strong> in the correct cross-seeding order below. You can adjust this before generating.</p>
        <p style="background:#fff8dc; padding:10px 15px; border-left:4px solid #D4AF37; border-radius:4px;">
            <strong>Seeding Logic:</strong> <?php echo esc_html( $seeding_logic_labels[ $seeding_logic ] ?? $seeding_logic_labels['cross_half'] ); ?>
            <br>
            <em style="font-size:12px; color:#666;">To change this logic, edit the tournament product and update the "Knockout Seeding Logic" setting.</em>
        </p>

        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
            <input type="hidden" name="action" value="flms_generate_knockout_stage">
            <input type="hidden" name="tournament_id" value="<?php echo $selected_tid; ?>">

            <?php
            /**
             * Build the seeded qualifier list according to the tournament's chosen
             * seeding logic. The order matters because the bracket pairs adjacent
             * seeds as first-round matches:
             *   QF1 = seeds 1-2, QF2 = seeds 3-4, QF3 = seeds 5-6, QF4 = seeds 7-8
             *   SF1 = winners of QF1 & QF2   |   SF2 = winners of QF3 & QF4
             *
             * Supported modes (set per tournament under product → "Knockout Seeding Logic"):
             *   - cross_half : Pair first-half groups with second-half groups so the same
             *                  group's #1 and #2 sit on opposite sides of the bracket and
             *                  can only meet again in the final.
             *                  4 groups (A,B,C,D)  -> A1·C2 / B1·D2 / C1·A2 / D1·B2
             *                  6 groups (A..F)     -> Pairs (A,D)(B,E)(C,F)
             *                  8 groups (A..H)     -> Pairs (A,E)(B,F)(C,G)(D,H)
             *   - adjacent   : Classic A vs B · C vs D logic (original behaviour).
             *   - manual     : Just lists Winner/Runner-up for each group in order;
             *                  admin uses the team override dropdowns to wire up seeds.
             */
            $group_keys   = array_keys( $standings );
            $num_groups   = count( $group_keys );
            $seeded_pairs = [];

            $make_seed = function( $role, $group, $team ) {
                return [ 'label' => $role . ' Group ' . $group, 'team' => $team ];
            };

            if ( $seeding_logic === 'adjacent' ) {
                // Pair groups: (0,1), (2,3), (4,5)...
                for ( $g = 0; $g < $num_groups; $g += 2 ) {
                    $g1 = $group_keys[ $g ] ?? null;
                    $g2 = $group_keys[ $g + 1 ] ?? null;

                    if ( $g1 && $g2 ) {
                        $seeded_pairs[] = $make_seed( 'Winner',   $g1, $standings[ $g1 ][0] ?? null );
                        $seeded_pairs[] = $make_seed( 'Runner-up', $g2, $standings[ $g2 ][1] ?? null );
                        $seeded_pairs[] = $make_seed( 'Winner',   $g2, $standings[ $g2 ][0] ?? null );
                        $seeded_pairs[] = $make_seed( 'Runner-up', $g1, $standings[ $g1 ][1] ?? null );
                    } elseif ( $g1 ) {
                        $seeded_pairs[] = $make_seed( 'Winner', $g1, $standings[ $g1 ][0] ?? null );
                    }
                }
            } elseif ( $seeding_logic === 'manual' ) {
                // Just list every qualifier (Winner then Runner-up) per group in order.
                foreach ( $group_keys as $g ) {
                    for ( $q = 0; $q < $qualifiers; $q++ ) {
                        $role = ( $q === 0 ) ? 'Winner' : ( $q === 1 ? 'Runner-up' : '#' . ( $q + 1 ) );
                        $seeded_pairs[] = $make_seed( $role, $g, $standings[ $g ][ $q ] ?? null );
                    }
                }
            } else {
                // Default: cross_half bracket (Nelvin's preferred logic).
                $half = (int) floor( $num_groups / 2 );

                // Build the half-pairs once: (first-half group, paired second-half group).
                $half_pairs = [];
                for ( $i = 0; $i < $half; $i++ ) {
                    $g1 = $group_keys[ $i ] ?? null;
                    $g2 = $group_keys[ $i + $half ] ?? null;
                    if ( $g1 && $g2 ) {
                        $half_pairs[] = [ $g1, $g2 ];
                    }
                }

                // Top half of bracket — Winner of first-half group vs Runner-up of second-half group.
                foreach ( $half_pairs as [ $g1, $g2 ] ) {
                    $seeded_pairs[] = $make_seed( 'Winner',   $g1, $standings[ $g1 ][0] ?? null );
                    $seeded_pairs[] = $make_seed( 'Runner-up', $g2, $standings[ $g2 ][1] ?? null );
                }
                // Bottom half of bracket — Winner of second-half group vs Runner-up of first-half group.
                foreach ( $half_pairs as [ $g1, $g2 ] ) {
                    $seeded_pairs[] = $make_seed( 'Winner',   $g2, $standings[ $g2 ][0] ?? null );
                    $seeded_pairs[] = $make_seed( 'Runner-up', $g1, $standings[ $g1 ][1] ?? null );
                }

                // Odd number of groups — append the leftover middle group's winner as a lone seed.
                if ( $num_groups % 2 === 1 ) {
                    $g_extra = $group_keys[ $half ] ?? null;
                    if ( $g_extra ) {
                        $seeded_pairs[] = $make_seed( 'Winner', $g_extra, $standings[ $g_extra ][0] ?? null );
                    }
                }
            }
            ?>

            <table class="wp-list-table widefat fixed" style="max-width:700px;">
                <thead><tr><th>Seed</th><th>Role</th><th>Team (overrideable)</th></tr></thead>
                <tbody>
                <?php foreach ( $seeded_pairs as $seed_idx => $entry ) :
                    $seed_num = $seed_idx + 1;
                    $team     = $entry['team'];
                ?>
                    <tr>
                        <td><strong>#<?php echo $seed_num; ?></strong></td>
                        <td><?php echo esc_html( $entry['label'] ); ?></td>
                        <td>
                            <select name="qualifiers[]" style="width:100%;">
                                <option value="">— None / BYE —</option>
                                <?php
                                // List all teams in this tournament as options
                                $all_teams = get_posts([
                                    'post_type'      => 'flms_team',
                                    'posts_per_page' => -1,
                                    'meta_key'       => 'flms_tournament_id',
                                    'meta_value'     => $selected_tid,
                                    'post_status'    => 'publish',
                                ]);
                                foreach ( $all_teams as $t ) {
                                    $sel = ( $team && $t->ID === $team['team_id'] ) ? 'selected' : '';
                                    echo "<option value='{$t->ID}' $sel>" . esc_html( $t->post_title ) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:20px;">
                <?php submit_button( '🏆 Generate Knockout Bracket', 'primary', 'submit', false ); ?>
                <span style="color:#888; font-size:13px; margin-left:15px;">
                    This will create all Quarter-Final, Semi-Final and Final placeholder matches<?php
                    if ( $third_place_enabled === 'yes' ) {
                        echo ', plus a <strong>Third-Place Play-off</strong> for 3rd/4th place';
                    }
                ?>.
                </span>
            </p>
        </form>

    <?php elseif ( $selected_tid && empty( $standings ) ) : ?>
        <div class="notice notice-warning"><p>⚠️ No group data found for this tournament. Make sure you have generated group matches first and that teams have a group assigned.</p></div>
    <?php endif; ?>
</div>
