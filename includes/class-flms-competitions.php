<?php
class FLMS_Competitions {

    public function __construct() {
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_fields' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_fields' ] );
        add_shortcode( 'flms_competitions_list', [ $this, 'render_list' ] );
    }

    public function add_fields() {
        echo '<div class="options_group">';
        
        echo '<h4>🏆 Tournament Configuration</h4>';
        woocommerce_wp_text_input([ 'id' => '_flms_start_date', 'label' => 'Start Date', 'type' => 'date' ]);
        woocommerce_wp_text_input([ 'id' => '_flms_end_date', 'label' => 'End Date', 'type' => 'date' ]);
        woocommerce_wp_select([ 'id' => '_flms_format', 'label' => 'Format', 'options' => [ 'round_robin' => 'League (Round Robin)', 'knockout' => 'Knockout', 'group_knockout' => 'Group Stage + Knockout' ] ]);
        woocommerce_wp_text_input([ 'id' => '_flms_num_groups', 'label' => 'Number of Groups', 'type' => 'number', 'description' => 'Only used for "Group Stage + Knockout" format. e.g. 4 groups for 16 teams.', 'desc_tip' => true, 'custom_attributes' => [ 'min' => '2', 'step' => '1' ] ]);

        echo '<h4>📅 Transfer Windows (3 Stages)</h4>';
        woocommerce_wp_text_input([ 'id' => '_flms_window_open_start', 'label' => 'Stage 1: Open (Start)', 'type' => 'date' ]);
        woocommerce_wp_text_input([ 'id' => '_flms_window_open_end', 'label' => 'Stage 1: Open (End)', 'type' => 'date', 'description' => 'Free transfers. Manager Approval required.' ]);

        woocommerce_wp_text_input([ 'id' => '_flms_window_locked_start', 'label' => 'Stage 2: Locked (Start)', 'type' => 'date' ]);
        woocommerce_wp_text_input([ 'id' => '_flms_window_locked_end', 'label' => 'Stage 2: Locked (End)', 'type' => 'date', 'description' => 'No activity allowed.' ]);

        woocommerce_wp_text_input([ 'id' => '_flms_window_paid_start', 'label' => 'Stage 3: Paid (Start)', 'type' => 'date' ]);
        woocommerce_wp_text_input([ 'id' => '_flms_window_paid_end', 'label' => 'Stage 3: Paid (End)', 'type' => 'date', 'description' => 'RM25 Fee. Manager Approval required.' ]);

        echo '</div>';
    }

    public function save_fields( $post_id ) {
        $fields = [
            '_flms_start_date', '_flms_end_date', '_flms_format', '_flms_num_groups',
            '_flms_window_open_start', '_flms_window_open_end',
            '_flms_window_locked_start', '_flms_window_locked_end',
            '_flms_window_paid_start', '_flms_window_paid_end'
        ];
        foreach ( $fields as $field ) {
            if ( isset( $_POST[$field] ) ) update_post_meta( $post_id, $field, sanitize_text_field( $_POST[$field] ) );
        }
    }

    // Helper: Determine Current Stage based on Date
    public static function get_current_stage( $tournament_id ) {
        $today = current_time('Y-m-d');
        
        $open_s = get_post_meta($tournament_id, '_flms_window_open_start', true);
        $open_e = get_post_meta($tournament_id, '_flms_window_open_end', true);
        
        $lock_s = get_post_meta($tournament_id, '_flms_window_locked_start', true);
        $lock_e = get_post_meta($tournament_id, '_flms_window_locked_end', true);
        
        $paid_s = get_post_meta($tournament_id, '_flms_window_paid_start', true);
        $paid_e = get_post_meta($tournament_id, '_flms_window_paid_end', true);

        if ( $open_s && $today >= $open_s && $today <= $open_e ) return 'open';
        if ( $lock_s && $today >= $lock_s && $today <= $lock_e ) return 'locked';
        if ( $paid_s && $today >= $paid_s && $today <= $paid_e ) return 'paid';

        return 'closed'; // Default
    }

    // --- FRONTEND LIST ---
    public function render_list( $atts ) {
        $transfer_fee_id = 16022; 
        $match_fee_id = 19182; 
        $friendly_fee_id = 23058;
        $current_status = isset($_GET['comp_status']) ? sanitize_text_field($_GET['comp_status']) : 'active';
        $paged = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;
        $posts_per_page = 9; 

        $all_products = get_posts([
            'post_type' => 'product', 'posts_per_page' => -1, 'post_status' => 'publish',
            'post__not_in' => [ $transfer_fee_id, $match_fee_id, $friendly_fee_id ],
            'tax_query' => [[ 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'simple' ]],
        ]);

        $today = date( 'Y-m-d' );
        $all_categorized = ['active' => [], 'upcoming' => [], 'archive' => []];

        foreach ( $all_products as $p ) {
            $start = get_post_meta( $p->ID, '_flms_start_date', true );
            $end   = get_post_meta( $p->ID, '_flms_end_date', true );
            if ( $start && $start > $today ) { $all_categorized['upcoming'][] = $p; }
            elseif ( $end && $end < $today ) { $all_categorized['archive'][] = $p; }
            else { $all_categorized['active'][] = $p; }
        }

        $final_list = $all_categorized[$current_status] ?? $all_categorized['active'];
        $total_items = count($final_list);
        $total_pages = ceil($total_items / $posts_per_page);
        $start_index = ( $paged - 1 ) * $posts_per_page;
        $paginated_list = array_slice($final_list, $start_index, $posts_per_page);

        ob_start();
        ?>
        <div class="flms-comp-wrapper">
            <div class="flms-tabs-filter-bar">
                <?php foreach (['active', 'upcoming', 'archive'] as $slug) : 
                    $label = ucfirst($slug); $count = count($all_categorized[$slug]);
                    $active_class = ($current_status === $slug) ? 'active' : '';
                    $link = esc_url(add_query_arg('comp_status', $slug, get_permalink()));
                    echo "<a href='$link' class='tab-filter-btn $active_class'>$label ($count)</a>";
                endforeach; ?>
            </div>

            <?php if ( empty($paginated_list) ) : ?>
                <p style="text-align:center;">No tournaments found.</p>
            <?php else : ?>
                <div class="comp-grid">
                    <?php foreach($paginated_list as $post) {
                        $img = get_the_post_thumbnail_url($post->ID, 'medium');
                        if(!$img && class_exists('FLMS_Image_Helper')) $img = FLMS_Image_Helper::get_team_logo($post->ID);
                        $date = get_post_meta($post->ID, '_flms_start_date', true);
                        $link = get_permalink($post->ID);
                        $status_class = strtolower($current_status);
                        
                        echo "<div class='comp-card $status_class'>";
                        echo "<div class='comp-status $status_class'>" . ucfirst($current_status) . "</div>";
                        echo "<div class='comp-img'><img src='$img'></div>";
                        echo "<div class='comp-body'><h3>".esc_html($post->post_title)."</h3><div class='comp-meta'>Start: $date</div></div>";
                        echo "<div class='comp-footer'><a href='$link' class='btn-view'>View Details</a></div>";
                        echo "</div>";
                    } ?>
                </div>
            <?php endif; ?>

            <?php if ($total_pages > 1) : ?>
                <div class="flms-pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++) : ?>
                        <a href="<?php echo esc_url(add_query_arg(['comp_status' => $current_status, 'paged' => $i], get_permalink())); ?>" 
                           class="page-number <?php echo ($i == $paged) ? 'current' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}