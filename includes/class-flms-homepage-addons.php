<?php
class FLMS_Homepage_Addons {

    public function __construct() {
        add_shortcode( 'flms_latest_news', [ $this, 'render_news' ] );
        add_shortcode( 'flms_match_highlights', [ $this, 'render_highlights' ] );
    }

    /**
     * 1. MATCH HIGHLIGHTS (Matches with Galleries)
     */
    public function render_highlights( $atts ) {
        $args = [
            'post_type' => 'flms_match',
            'posts_per_page' => 4,
            'meta_query' => [
                [
                    'key' => 'flms_match_gallery',
                    'value' => '',
                    'compare' => '!=' // Only show matches that actually HAVE photos
                ]
            ],
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        $query = new WP_Query( $args );
        
        if ( ! $query->have_posts() ) return '<p style="text-align:center; padding:20px; color:#999;">No match highlights available yet.</p>';

        ob_start();
        echo '<div class="flms-highlights-scroll">';
        while ( $query->have_posts() ) {
            $query->the_post();
            $mid = get_the_ID();
            
            // Get Match Info
            $h_id = get_post_meta($mid, 'flms_home_team', true);
            $a_id = get_post_meta($mid, 'flms_away_team', true);
            $h_name = get_the_title($h_id);
            $a_name = get_the_title($a_id);
            $score = get_post_meta($mid, 'flms_home_score', true) . ' - ' . get_post_meta($mid, 'flms_away_score', true);
            
            // Get First Image from Gallery for Thumbnail
            $gallery = get_post_meta($mid, 'flms_match_gallery', true);
            $ids = explode(',', $gallery);
            $thumb = '';
            
            if(!empty($ids) && isset($ids[0])) {
                $thumb = wp_get_attachment_image_url($ids[0], 'medium_large');
            }
            
            if(!$thumb) $thumb = FLMS_URL . 'assets/images/stadium-bg.jpg'; // Fallback
            
            ?>
            <div class="highlight-card">
                <a href="<?php the_permalink(); ?>" class="hl-link">
                    <div class="hl-img" style="background-image: url('<?php echo esc_url($thumb); ?>');">
                        <div class="hl-overlay">
                            <span class="hl-icon">📷 Gallery</span>
                        </div>
                    </div>
                    <div class="hl-info">
                        <span class="hl-teams"><?php echo "$h_name vs $a_name"; ?></span>
                        <span class="hl-score"><?php echo $score; ?></span>
                    </div>
                </a>
            </div>
            <?php
        }
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    /**
     * 2. LATEST NEWS (Optional)
     */
    public function render_news( $atts ) {
        return ''; // Placeholder if you aren't using news yet
    }
}