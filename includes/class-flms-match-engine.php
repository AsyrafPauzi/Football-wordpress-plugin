<?php
class FLMS_Match_Engine {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_post_flms_generate_matches', [ $this, 'process_generation' ] );
        add_action( 'admin_post_flms_generate_knockout_stage', [ $this, 'process_knockout_stage' ] );
        
        // Admin List Columns
        add_filter( 'manage_flms_match_posts_columns', [ $this, 'add_match_details_columns' ] );
        add_action( 'manage_flms_match_posts_custom_column', [ $this, 'render_match_details_columns' ], 10, 2 );
        add_filter( 'manage_edit-flms_match_sortable_columns', [ $this, 'sortable_tournament_column' ] );
        add_action( 'pre_get_posts', [ $this, 'sort_by_tournament' ] );
    }

    public function add_menu() {
        add_submenu_page( 
            'edit.php?post_type=flms_match', 
            'Generate Matches', 
            'Generate Matches', 
            'manage_options', 
            'flms-match-gen', 
            [ $this, 'render_ui' ] 
        );
        add_submenu_page(
            'edit.php?post_type=flms_match',
            'Generate Knockout Stage',
            'Generate Knockout Stage',
            'manage_options',
            'flms-knockout-gen',
            [ $this, 'render_knockout_ui' ]
        );
    }

    public function render_ui() {
        require_once FLMS_PATH . 'includes/admin/match-generator-ui.php';
    }

    public function render_knockout_ui() {
        require_once FLMS_PATH . 'includes/admin/knockout-generator-ui.php';
    }

    public function process_generation() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        
        $tournament_id = intval( $_POST['tournament_id'] );
        $format = sanitize_text_field( $_POST['format'] );

        // --- FIX: GET TEAMS DIRECTLY LINKED TO TOURNAMENT ---
        $team_ids = get_posts([
            'post_type'      => 'flms_team',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => 'flms_tournament_id',
            'meta_value'     => $tournament_id,
            'post_status'    => 'publish'
        ]);

        if ( count( $team_ids ) < 2 ) {
            wp_die( 'Not enough teams found for this tournament. (Found: ' . count($team_ids) . ' teams. Teams must be registered and approved first.)' );
        }

        if ( $format === 'round_robin' ) {
            $this->generate_round_robin( $team_ids, $tournament_id );
        } elseif ( $format === 'knockout' ) {
            $this->generate_knockout( $team_ids, $tournament_id );
        } elseif ( $format === 'group_knockout' ) {
            $num_groups = max( 2, intval( $_POST['num_groups'] ?? 4 ) );
            $overwrite_groups = ! empty( $_POST['overwrite_groups'] );
            $group_meta_key = 'flms_group_' . $tournament_id;

            // Optional admin UI payload: explicit team -> group assignments.
            if ( ! $overwrite_groups && ! empty( $_POST['group_assignments'] ) && is_array( $_POST['group_assignments'] ) ) {
                $group_labels = array_map( function( $i ) { return chr( 65 + $i ); }, range( 0, $num_groups - 1 ) );
                foreach ( $_POST['group_assignments'] as $raw_team_id => $raw_group ) {
                    $team_id = (int) $raw_team_id;
                    if ( ! in_array( $team_id, $team_ids, true ) ) continue;
                    $group = strtoupper( trim( sanitize_text_field( wp_unslash( $raw_group ) ) ) );
                    if ( in_array( $group, $group_labels, true ) ) {
                        update_post_meta( $team_id, $group_meta_key, $group );
                    }
                }
            }

            $this->generate_group_stage( $team_ids, $tournament_id, $num_groups, $overwrite_groups );
        }

        wp_redirect( admin_url( 'edit.php?post_type=flms_match&msg=generated' ) );
        exit;
    }

    // ---------------------------------------------------------------
    // PHASE 1: GROUP STAGE GENERATION
    // Distributes teams into groups and generates round-robin matches
    // within each group. Stores group assignment on each team.
    // ---------------------------------------------------------------
    private function generate_group_stage( $team_ids, $tournament_id, $num_groups, $overwrite_groups = false ) {
        shuffle( $team_ids ); // randomize fallback assignment order

        $group_labels = array_map( function($i) { return chr( 65 + $i ); }, range( 0, $num_groups - 1 ) ); // A, B, C...
        $groups = array_fill_keys( $group_labels, [] );
        $group_meta_key = 'flms_group_' . $tournament_id;

        if ( $overwrite_groups ) {
            // Force overwrite: ignore any existing manual group assignment.
            foreach ( $team_ids as $index => $team_id ) {
                $group_label = $group_labels[ $index % $num_groups ];
                $groups[ $group_label ][] = $team_id;
                update_post_meta( $team_id, $group_meta_key, $group_label );
            }
        } else {
            // Respect pre-assigned manual groups, auto-fill only missing/invalid teams.
            $unassigned = [];

            foreach ( $team_ids as $team_id ) {
                $saved_group = strtoupper( trim( (string) get_post_meta( $team_id, $group_meta_key, true ) ) );
                if ( in_array( $saved_group, $group_labels, true ) ) {
                    $groups[ $saved_group ][] = $team_id;
                } else {
                    $unassigned[] = $team_id;
                }
            }

            foreach ( $unassigned as $team_id ) {
                // Keep groups balanced by assigning to the smallest group.
                $target_group = $group_labels[0];
                foreach ( $group_labels as $label ) {
                    if ( count( $groups[ $label ] ) < count( $groups[ $target_group ] ) ) {
                        $target_group = $label;
                    }
                }
                $groups[ $target_group ][] = $team_id;
                update_post_meta( $team_id, $group_meta_key, $target_group );
            }
        }

        // Generate round-robin matches within each group
        foreach ( $groups as $label => $group_teams ) {
            $count = count( $group_teams );
            if ( $count < 2 ) continue;
            
            if ( $count % 2 != 0 ) $group_teams[] = "BYE";
            $count = count( $group_teams );
            $rounds = $count - 1;
            $half = $count / 2;

            for ( $round = 0; $round < $rounds; $round++ ) {
                for ( $i = 0; $i < $half; $i++ ) {
                    $home = $group_teams[$i];
                    $away = $group_teams[$count - 1 - $i];

                    if ( $home !== "BYE" && $away !== "BYE" ) {
                        // Alternate home/away to be fair
                        if ( $round % 2 == 1 && $i == 0 ) {
                            $temp = $home; $home = $away; $away = $temp;
                        }
                        
                        $match_round = $round + 1;
                        $match_id = $this->create_match( $home, $away, $tournament_id, $match_round, 'Group ' . $label );
                        
                        // Tag this match with its group AND phase
                        update_post_meta( $match_id, 'flms_match_group', $label );
                        update_post_meta( $match_id, 'flms_match_phase', 'group' );
                    }
                }
                
                // Rotate teams for next round-robin round (leaving team 0 fixed)
                $teams_copy = $group_teams;
                $group_teams[1] = $teams_copy[$count - 1];
                for ( $k = 2; $k < $count; $k++ ) {
                    $group_teams[$k] = $teams_copy[$k - 1];
                }
            }
        }
    }

    // ---------------------------------------------------------------
    // PHASE 2: KNOCKOUT STAGE GENERATION FROM GROUP RESULTS
    // Called by the separate admin form on flms-knockout-gen page.
    // ---------------------------------------------------------------
    public function process_knockout_stage() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $tournament_id  = intval( $_POST['tournament_id'] );
        $qualifiers_raw = $_POST['qualifiers'] ?? []; // Array of team IDs in seeded order

        $qualifier_ids = array_map( 'intval', $qualifiers_raw );
        $qualifier_ids = array_filter( $qualifier_ids ); // Remove zeros / empty
        $qualifier_ids = array_values( $qualifier_ids );

        if ( count( $qualifier_ids ) < 2 ) {
            wp_die( 'Not enough qualifiers selected. Please select at least 2 teams.' );
        }

        // Generate a standard knockout bracket with the given teams (in the order submitted)
        $this->generate_knockout( $qualifier_ids, $tournament_id, true );

        wp_redirect( admin_url( 'edit.php?post_type=flms_match&msg=knockout_generated' ) );
        exit;
    }

    // ---------------------------------------------------------------
    // HELPER: Get group standings for a tournament
    // Returns [ 'A' => [ [team_id, pts, gd, gf], ... ], 'B' => [...] ]
    // ---------------------------------------------------------------
    public static function get_group_standings( $tournament_id ) {
        $tournament_id = (int) $tournament_id;

        $teams = get_posts(
            [
                'post_type'      => 'flms_team',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => [
                    'relation' => 'AND',
                    [ 'key' => 'flms_tournament_id', 'value' => $tournament_id ],
                    [ 'key' => 'flms_group_' . $tournament_id, 'compare' => 'EXISTS' ],
                ],
                'post_status'    => 'publish',
            ]
        );

        $team_to_group = [];
        foreach ( $teams as $team_id ) {
            $group = get_post_meta( $team_id, 'flms_group_' . $tournament_id, true );
            if ( $group ) {
                $team_to_group[ (int) $team_id ] = $group;
            }
        }

        if ( empty( $team_to_group ) ) {
            return [];
        }

        update_meta_cache( 'post', array_keys( $team_to_group ) );

        $all_matches = get_posts(
            [
                'post_type'      => 'flms_match',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => [
                    'relation' => 'AND',
                    [ 'key' => 'flms_tournament_id', 'value' => $tournament_id ],
                    [ 'key' => 'flms_match_status', 'value' => 'completed' ],
                    [ 'key' => 'flms_match_group', 'compare' => 'EXISTS' ],
                ],
            ]
        );

        update_meta_cache( 'post', $all_matches );

        $stats_map = [];
        foreach ( $team_to_group as $tid => $_g ) {
            $stats_map[ $tid ] = [ 'p' => 0, 'w' => 0, 'd' => 0, 'l' => 0, 'gf' => 0, 'ga' => 0, 'pts' => 0 ];
        }

        foreach ( $all_matches as $mid ) {
            $grp = get_post_meta( $mid, 'flms_match_group', true );
            if ( ! $grp ) {
                continue;
            }

            $h_id = (int) get_post_meta( $mid, 'flms_home_team', true );
            $a_id = (int) get_post_meta( $mid, 'flms_away_team', true );

            if ( ! isset( $team_to_group[ $h_id ], $team_to_group[ $a_id ] ) ) {
                continue;
            }
            if ( $team_to_group[ $h_id ] !== $grp || $team_to_group[ $a_id ] !== $grp ) {
                continue;
            }

            $hs = (int) get_post_meta( $mid, 'flms_home_score', true );
            $as = (int) get_post_meta( $mid, 'flms_away_score', true );

            foreach ( [ [ $h_id, $hs, $as ], [ $a_id, $as, $hs ] ] as $side ) {
                list( $tid, $my, $op ) = $side;
                if ( ! isset( $stats_map[ $tid ] ) ) {
                    continue;
                }
                $stats_map[ $tid ]['p']++;
                $stats_map[ $tid ]['gf'] += $my;
                $stats_map[ $tid ]['ga'] += $op;
                if ( $my > $op ) {
                    $stats_map[ $tid ]['w']++;
                    $stats_map[ $tid ]['pts'] += 3;
                } elseif ( $my === $op ) {
                    $stats_map[ $tid ]['d']++;
                    $stats_map[ $tid ]['pts'] += 1;
                } else {
                    $stats_map[ $tid ]['l']++;
                }
            }
        }

        $standings = [];
        foreach ( $team_to_group as $team_id => $group ) {
            $s = $stats_map[ $team_id ];
            $standings[ $group ][] = [
                'team_id' => $team_id,
                'name'    => get_the_title( $team_id ),
                'p'       => $s['p'],
                'w'       => $s['w'],
                'd'       => $s['d'],
                'l'       => $s['l'],
                'gf'      => $s['gf'],
                'ga'      => $s['ga'],
                'gd'      => $s['gf'] - $s['ga'],
                'pts'     => $s['pts'],
            ];
        }

        foreach ( $standings as $group => &$rows ) {
            usort(
                $rows,
                function ( $a, $b ) {
                    if ( $a['pts'] !== $b['pts'] ) {
                        return $b['pts'] - $a['pts'];
                    }
                    if ( $a['gd'] !== $b['gd'] ) {
                        return $b['gd'] - $a['gd'];
                    }
                    return $b['gf'] - $a['gf'];
                }
            );
        }
        ksort( $standings );

        return $standings;
    }

    private function generate_round_robin( $teams, $tournament_id ) {
        if ( count( $teams ) % 2 != 0 ) $teams[] = "BYE";
        $count = count( $teams );
        $rounds = $count - 1;
        $half = $count / 2;

        for ( $r = 0; $r < $rounds; $r++ ) {
            for ( $i = 0; $i < $half; $i++ ) {
                $home = $teams[$i];
                $away = $teams[$count - 1 - $i];

                if ( $home !== "BYE" && $away !== "BYE" ) {
                    $this->create_match( $home, $away, $tournament_id, $r + 1 );
                }
            }
            $temp = $teams;
            array_splice( $temp, 1, 0, array_pop( $temp ) );
            $teams = $temp;
        }
    }

    private function generate_knockout( $teams, $tournament_id, $is_playoff = false ) {
        // Only shuffle for pure-knockout format; preserve seeded order for group->playoff
        if ( ! $is_playoff ) {
            shuffle( $teams );
        }

        $pow = 1;
        $count = count($teams);
        while( $pow < $count ) $pow *= 2;
        while( count($teams) < $pow ) $teams[] = 'BYE';

        // Support sequential matchweeks: start knockout rounds where group stage left off
        $round = 1;
        if ( $is_playoff ) {
            $existing_matches = get_posts([
                'post_type'      => 'flms_match',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => [
                    [ 'key' => 'flms_tournament_id', 'value' => $tournament_id ]
                ]
            ]);
            $max_round = 0;
            foreach ( $existing_matches as $em_id ) {
                $r = (int) get_post_meta( $em_id, 'flms_round', true );
                if ( $r > $max_round ) $max_round = $r;
            }
            $round = $max_round + 1;
        }

        $current_round_match_ids = [];
        $phase = $is_playoff ? 'knockout' : 'knockout'; // always knockout for bracket

        for ( $i = 0; $i < count($teams); $i += 2 ) {
            $home = $teams[$i];
            $away = $teams[$i+1];

            if ($home === 'BYE' && $away === 'BYE') continue;
            
            // Format round name appropriately
            if ( $is_playoff && count($teams) == 8 ) $desc = "Quarter Final";
            elseif ( $is_playoff && count($teams) == 4 ) $desc = "Semi Final";
            elseif ( $is_playoff && count($teams) == 2 ) $desc = "Final";
            else $desc = "Round " . $round;

            $mid = $this->create_match( 
                ($home === 'BYE' ? 0 : $home), 
                ($away === 'BYE' ? 0 : $away), 
                $tournament_id, 
                $round, 
                $desc
            );
            update_post_meta( $mid, 'flms_match_phase', 'knockout' ); // <-- TAG
            $current_round_match_ids[] = $mid;
        }

        while ( count( $current_round_match_ids ) > 1 ) {
            $round++;
            $next_round_match_ids = [];

            for ( $i = 0; $i < count( $current_round_match_ids ); $i += 2 ) {
                
                $prev_match_1 = $current_round_match_ids[$i];
                $prev_match_2 = isset($current_round_match_ids[$i+1]) ? $current_round_match_ids[$i+1] : null;

                if ( $prev_match_2 ) {
                    $parent_mid = wp_insert_post([
                        'post_type'   => 'flms_match',
                        'post_title'  => "Round $round: TBD vs TBD",
                        'post_status' => 'publish'
                    ]);

                    update_post_meta( $parent_mid, 'flms_tournament_id', $tournament_id );
                    update_post_meta( $parent_mid, 'flms_round', $round );
                    update_post_meta( $parent_mid, 'flms_match_status', 'scheduled' );
                    update_post_meta( $parent_mid, 'flms_match_phase', 'knockout' ); // <-- TAG
                    update_post_meta( $parent_mid, 'flms_source_match_home', $prev_match_1 );
                    update_post_meta( $parent_mid, 'flms_source_match_away', $prev_match_2 );

                    $next_round_match_ids[] = $parent_mid;
                }
            }
            $current_round_match_ids = $next_round_match_ids;
        }
    }

    private function create_match( $home_id, $away_id, $tid, $round, $desc = '' ) {
        $home_name = $home_id ? get_the_title( $home_id ) : 'BYE';
        $away_name = $away_id ? get_the_title( $away_id ) : 'BYE';
        
        $title_prefix = $desc ? "$desc: " : "Matchday $round: ";

        $mid = wp_insert_post([
            'post_type'   => 'flms_match',
            'post_title'  => $title_prefix . $home_name . ' vs ' . $away_name,
            'post_status' => 'publish'
        ]);
        update_post_meta( $mid, 'flms_home_team', $home_id );
        update_post_meta( $mid, 'flms_away_team', $away_id );
        update_post_meta( $mid, 'flms_tournament_id', $tid );
        update_post_meta( $mid, 'flms_round', $round );
        update_post_meta( $mid, 'flms_match_status', 'pending' );
        
        return $mid;
    }
    
    // --- ADMIN COLUMN FUNCTIONS ---

    // 1. Add all new columns
    public function add_match_details_columns( $columns ) {
        $date = $columns['date']; 
        $title = $columns['title']; 
        unset( $columns['date'] ); 
        unset( $columns['title'] ); 
        
        // Add new columns
        $columns['flms_round'] = 'Round'; // NEW COLUMN
        $columns['title'] = $title;
        $columns['flms_tournament'] = 'Tournament';
        $columns['flms_score'] = 'Result';
        $columns['flms_datetime'] = 'Date/Time';
        $columns['date'] = $date; 
        
        return $columns;
    }

    // 2. Render content for all new columns
    public function render_match_details_columns( $column, $post_id ) {
        switch ( $column ) {
            
            // NEW CASE FOR ROUND
            case 'flms_round':
                $round = get_post_meta( $post_id, 'flms_round', true );
                echo '<div style="font-size:16px; font-weight:bold; background:#eee; width:30px; height:30px; line-height:30px; text-align:center; border-radius:50%;">' . esc_html($round) . '</div>';
                break;

            case 'flms_tournament':
                $tournament_id = get_post_meta( $post_id, 'flms_tournament_id', true );
                if ( $tournament_id ) {
                    $product = wc_get_product( $tournament_id );
                    if ( $product ) {
                        echo esc_html( $product->get_name() );
                        $format = get_post_meta($tournament_id, '_flms_format', true);
                        if($format) echo '<br><span style="font-size:11px; color:#555;">(' . ucfirst($format) . ')</span>';
                    } else {
                        echo 'ID: ' . $tournament_id;
                    }
                } else {
                    echo '<span style="color:red;">None Assigned</span>';
                }
                break;

            case 'flms_score':
                $status = get_post_meta( $post_id, 'flms_match_status', true );
                if ( $status === 'completed' ) {
                    $h_score = get_post_meta( $post_id, 'flms_home_score', true );
                    $a_score = get_post_meta( $post_id, 'flms_away_score', true );
                    echo '<span style="font-weight:bold; background:#000; color:#fff; padding:4px 8px; border-radius:3px;">' . esc_html($h_score) . ' - ' . esc_html($a_score) . '</span>';
                } else {
                    echo '<span style="color:#555;">Pending</span>';
                }
                break;

            case 'flms_datetime':
                $date = get_post_meta( $post_id, 'flms_match_date', true );
                $time = get_post_meta( $post_id, 'flms_match_time', true );
                if ( $date ) {
                    $date_display = date( 'd M Y', strtotime($date) );
                    echo esc_html( $date_display );
                    if ( $time ) {
                        $time_display = date( 'h:i A', strtotime($time) );
                        echo '<br><span style="font-size:11px; color:#555;">' . esc_html($time_display) . '</span>';
                    }
                } else {
                    echo '<span style="color:#999;">Not Set</span>';
                }
                break;
        }
    }

    // 3. Make Tournament and Round sortable
    public function sortable_tournament_column( $columns ) {
        $columns['flms_tournament'] = 'flms_tournament';
        $columns['flms_datetime'] = 'flms_datetime';
        $columns['flms_round'] = 'flms_round'; // Make Round Sortable
        return $columns;
    }

    // 4. Handle the custom sorting queries
    public function sort_by_tournament( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) return;
        
        if ( 'flms_match' === $query->get( 'post_type' ) ) {
            
            $orderby = $query->get( 'orderby' );

            if ( 'flms_tournament' === $orderby ) {
                $query->set( 'meta_key', 'flms_tournament_id' );
                $query->set( 'orderby', 'meta_value_num' );
            }

            if ( 'flms_datetime' === $orderby ) {
                $query->set( 'meta_key', 'flms_match_date' );
                $query->set( 'orderby', 'meta_value' ); 
            }

            if ( 'flms_round' === $orderby ) {
                $query->set( 'meta_key', 'flms_round' );
                $query->set( 'orderby', 'meta_value_num' );
            }
        }
    }
}