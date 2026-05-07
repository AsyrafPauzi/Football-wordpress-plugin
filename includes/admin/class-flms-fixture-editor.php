<?php
class FLMS_Fixture_Editor {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'process_updates' ] );
    }

    public function add_menu_page() {
        add_submenu_page(
            'edit.php?post_type=flms_match',
            'Edit Matchups',
            'Fixture Editor', 
            'manage_options',
            'flms-fixture-editor',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() {
        $selected_tour = isset($_GET['tour_id']) ? intval($_GET['tour_id']) : 0;
        $tournaments = wc_get_products(['limit' => -1]); 
        ?>
        <div class="wrap">
            <h1>📋 Fixture Editor</h1>
            <p>Edit Matchups and Round Numbers. (Includes Completed Matches)</p>
            
            <form method="get" style="background:#fff; padding:15px; border:1px solid #ddd; margin-bottom:20px;">
                <input type="hidden" name="post_type" value="flms_match">
                <input type="hidden" name="page" value="flms-fixture-editor">
                <label><strong>Select Tournament:</strong></label>
                <select name="tour_id">
                    <option value="">-- Choose --</option>
                    <?php foreach($tournaments as $t): ?>
                        <option value="<?php echo $t->get_id(); ?>" <?php selected($selected_tour, $t->get_id()); ?>>
                            <?php echo $t->get_name(); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">Load Matches</button>
            </form>

            <?php if($selected_tour > 0): 
                $this->render_match_list($selected_tour);
            endif; ?>
        </div>
        <?php
    }

    private function render_match_list($tour_id) {
        // Show All Matches (Pending & Completed)
        $matches = get_posts([
            'post_type' => 'flms_match',
            'posts_per_page' => -1,
            'meta_key' => 'flms_round',
            'orderby' => 'meta_value_num',
            'order' => 'ASC',
            'meta_query' => [
                [ 'key' => 'flms_tournament_id', 'value' => $tour_id ]
            ]
        ]);

        if(empty($matches)) {
            echo '<div class="notice notice-warning"><p>No matches found for this tournament.</p></div>';
            return;
        }

        $teams = get_posts([
            'post_type' => 'flms_team',
            'posts_per_page' => -1,
            'meta_key' => 'flms_tournament_id',
            'meta_value' => $tour_id,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        $teams_options = [];
        foreach($teams as $t) { $teams_options[$t->ID] = $t->post_title; }
        $teams_options[0] = 'BYE / TBD'; 

        ?>
        <form method="post">
            <input type="hidden" name="flms_action" value="update_fixtures">
            <input type="hidden" name="tour_id" value="<?php echo $tour_id; ?>">
            <?php wp_nonce_field('flms_fix_editor'); ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="100">Round</th>
                        <th width="150">Date</th>
                        <th>Home Team</th>
                        <th width="50" style="text-align:center;">VS</th>
                        <th>Away Team</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($matches as $m): 
                        $hid = get_post_meta($m->ID, 'flms_home_team', true);
                        $aid = get_post_meta($m->ID, 'flms_away_team', true);
                        $round = get_post_meta($m->ID, 'flms_round', true);
                        $date = get_post_meta($m->ID, 'flms_match_date', true);
                        $status = get_post_meta($m->ID, 'flms_match_status', true);
                        
                        $date_display = ($date && $date != '0000-00-00') ? date('d M Y', strtotime($date)) : '<span style="color:#999; font-style:italic;">No Date Set</span>';
                        
                        $status_badge = ($status === 'completed') ? '<br><span style="color:green; font-weight:bold; font-size:10px;">✅ Done</span>' : '';
                        $row_opacity = ($status === 'completed') ? 'opacity:0.8; background:#f9f9f9;' : '';
                    ?>
                    <tr style="<?php echo $row_opacity; ?>">
                        <td>
                            <input type="number" name="matches[<?php echo $m->ID; ?>][round]" value="<?php echo esc_attr($round); ?>" style="width:60px;">
                            <input type="hidden" name="matches[<?php echo $m->ID; ?>][id]" value="<?php echo $m->ID; ?>">
                            <?php echo $status_badge; ?>
                        </td>
                        <td>
                            <a href="<?php echo get_edit_post_link($m->ID); ?>" target="_blank" style="font-weight:bold; text-decoration:none;">
                                <?php echo $date_display; ?> ✎
                            </a>
                        </td>
                        <td>
                            <select name="matches[<?php echo $m->ID; ?>][home]" style="width:100%;">
                                <?php foreach($teams_options as $tid => $name): ?>
                                    <option value="<?php echo $tid; ?>" <?php selected($hid, $tid); ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td style="text-align:center; font-weight:bold; color:#ccc;">VS</td>
                        <td>
                            <select name="matches[<?php echo $m->ID; ?>][away]" style="width:100%;">
                                <?php foreach($teams_options as $tid => $name): ?>
                                    <option value="<?php echo $tid; ?>" <?php selected($aid, $tid); ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="margin-top:20px; text-align:right;">
                <button type="submit" class="button button-primary button-large">Save All Changes</button>
            </div>
        </form>
        <?php
    }

    public function process_updates() {
        if ( isset($_POST['flms_action']) && $_POST['flms_action'] === 'update_fixtures' ) {
            check_admin_referer('flms_fix_editor');
            
            if(!current_user_can('manage_options')) return;

            $matches = $_POST['matches'];
            $tour_id = intval($_POST['tour_id']);

            foreach($matches as $mid => $data) {
                $home = intval($data['home']);
                $away = intval($data['away']);
                $round = intval($data['round']);

                update_post_meta($mid, 'flms_home_team', $home);
                update_post_meta($mid, 'flms_away_team', $away);
                update_post_meta($mid, 'flms_round', $round);

                $h_name = $home ? get_the_title($home) : 'BYE';
                $a_name = $away ? get_the_title($away) : 'BYE';
                
                // --- FIX: REGENERATE URL SLUG ---
                // By setting 'post_name' to empty, WordPress creates a new URL based on the new Title.
                wp_update_post([
                    'ID' => $mid,
                    'post_title' => "Matchday: $h_name vs $a_name",
                    'post_name'  => '' 
                ]);
            }

            wp_redirect( admin_url("edit.php?post_type=flms_match&page=flms-fixture-editor&tour_id=$tour_id&msg=saved") );
            exit;
        }
    }
}