<?php
class FLMS_Match_Frontend {
    public function __construct() {
        // Override Frontend Content
        add_filter( 'the_content', [ $this, 'render_match_page' ] );
        
        // Admin Features (Gallery)
        add_action( 'add_meta_boxes', [ $this, 'gallery_metabox' ] );
        add_action( 'save_post_flms_match', [ $this, 'save_gallery' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_media_lib' ] );
    }

    public function enqueue_media_lib() { wp_enqueue_media(); }

    /**
     * 1. MAIN RENDER FUNCTION
     */
    public function render_match_page( $content ) {
        if ( ! is_singular( 'flms_match' ) ) return $content;

        $id = get_the_ID();
        
        // Get Basic Match Info
        $home_id = get_post_meta( $id, 'flms_home_team', true );
        $away_id = get_post_meta( $id, 'flms_away_team', true );
        $home_name = $home_id ? get_the_title($home_id) : 'TBD';
        $away_name = $away_id ? get_the_title($away_id) : 'TBD';
        $home_score = get_post_meta( $id, 'flms_home_score', true );
        $away_score = get_post_meta( $id, 'flms_away_score', true );
        $status = get_post_meta( $id, 'flms_match_status', true );
        
        // Date & Time
        $date_raw = get_post_meta( $id, 'flms_match_date', true );
        $time_raw = get_post_meta( $id, 'flms_match_time', true );
        $date_display = $date_raw ? date( 'd M Y', strtotime($date_raw) ) : '';
        $time_display = $time_raw ? date( 'h:i A', strtotime($time_raw) ) : '';
        
        // Venue
        $venues = get_the_terms( $id, 'flms_venue' );
        $venue_name = ( $venues && ! is_wp_error( $venues ) ) ? $venues[0]->name : 'Venue TBD';
        
        // Logos
        $h_logo = class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_team_logo($home_id) : '';
        $a_logo = class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_team_logo($away_id) : '';

        // Referee
        $ref_id = get_post_meta($id, 'flms_referee_id', true);
        $ref_name = $ref_id ? get_userdata($ref_id)->display_name : 'To be assigned';

        // Back Button Logic
        $tour_id = get_post_meta($id, 'flms_tournament_id', true);
        $back_url = $tour_id ? get_permalink($tour_id) : 'javascript:history.back()';
        $tour_name = $tour_id ? get_the_title($tour_id) : 'Tournament';

        ob_start();
        ?>
        <div class="flms-match-center">
            
            <!-- BACK BUTTON -->
            <div class="flms-back-nav" style="margin-bottom: 20px;">
                <a href="<?php echo esc_url($back_url); ?>" class="btn-back">
                    &larr; Back to <?php echo esc_html($tour_name); ?>
                </a>
            </div>

            <!-- SCOREBOARD -->
            <div class="flms-scoreboard">
                <div class="fs-match-info">
                    <?php echo esc_html($date_display); ?> | <?php echo esc_html($time_display); ?>
                    <br><span class="fs-venue">@ <?php echo esc_html($venue_name); ?></span>
                </div>
                <div class="fs-board">
                    <!-- Home -->
                    <div class="fs-team home">
                        <img src="<?php echo esc_url($h_logo); ?>" alt="Home">
                        <h3><a href="<?php echo get_permalink($home_id); ?>"><?php echo esc_html($home_name); ?></a></h3>
                    </div>

                    <!-- Result -->
                    <div class="fs-result">
                        <?php if ( $status === 'completed' && $home_id && $away_id ) : ?>
                            <div class="fs-score-box">
                                <span class="score-num"><?php echo esc_html($home_score); ?></span>
                                <span class="score-sep">-</span>
                                <span class="score-num"><?php echo esc_html($away_score); ?></span>
                            </div>
                            <div class="fs-status full-time">Full Time</div>
                        <?php else : ?>
                            <div class="fs-vs-box">VS</div>
                            <div class="fs-status upcoming">Upcoming</div>
                        <?php endif; ?>
                    </div>

                    <!-- Away -->
                    <div class="fs-team away">
                        <img src="<?php echo esc_url($a_logo); ?>" alt="Away">
                        <h3><a href="<?php echo get_permalink($away_id); ?>"><?php echo esc_html($away_name); ?></a></h3>
                    </div>
                </div>
                
                <div class="match-officials" style="margin-top:20px; font-size:13px; color:#ccc;">
                    Official: <?php echo esc_html($ref_name); ?>
                </div>
            </div>

            <!-- TEAM STATS BAR -->
            <?php echo $this->render_stats_bar($id); ?>

            <!-- MATCH EVENTS TIMELINE (EPL STYLE) -->
            <div class="flms-match-details">
                <h3 class="section-title">Match Events</h3>
                <?php echo $this->get_timeline_html( $id, $home_id, $away_id ); ?>
            </div>

            <!-- PHOTO GALLERY -->
            <?php 
            $gallery_ids = (string) get_post_meta($id, 'flms_match_gallery', true);
            if ( ! empty($gallery_ids) ) : 
                $ids_array = explode(',', $gallery_ids);
            ?>
                <div class="flms-match-gallery">
                    <h3 class="section-title">Match Gallery</h3>
                    <div class="flms-gallery-grid">
                        <?php foreach($ids_array as $img_id): 
                            $img_url = wp_get_attachment_image_url($img_id, 'large');
                            if($img_url): ?>
                                <div class="gallery-item">
                                    <a href="<?php echo $img_url; ?>" target="_blank">
                                        <img src="<?php echo wp_get_attachment_image_url($img_id, 'medium'); ?>" loading="lazy">
                                    </a>
                                </div>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
        
        <!-- INLINE CSS (Fallback) - Main styles are in flms-style.css -->
        <style>
            .flms-match-center { max-width: 900px; margin: 0 auto; font-family: sans-serif; }
            .flms-scoreboard { background: radial-gradient(circle, #222 0%, #000 100%); color: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); text-align: center; margin-bottom: 40px; border-bottom: 4px solid #D4AF37; }
            .fs-board { display: flex; align-items: center; justify-content: space-between; }
            .fs-team img { width: 90px; height: 90px; object-fit: contain; background: #fff; border-radius: 50%; padding: 8px; border: 2px solid #D4AF37; }
            .fs-team h3 a { color: #fff; text-decoration: none; }
            .fs-score-box { font-size: 56px; font-weight: 800; }
            .flms-match-stats { margin-bottom: 40px; background:#fff; padding:20px; border-radius:8px; border:1px solid #eee; }
            .stat-row { margin-bottom: 20px; }
            .stat-vals { display: flex; justify-content: space-between; font-size: 14px; font-weight: bold; margin-bottom: 5px; }
            .stat-bar { height: 8px; background: #333; border-radius: 4px; overflow: hidden; position: relative; }
            .sb-fill { height: 100%; background: #D4AF37; }
            .flms-gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; }
            .gallery-item img { width: 100%; height: 150px; object-fit: cover; border-radius: 6px; }
            .btn-back { display: inline-block; padding: 8px 15px; background: #f0f0f0; color: #333; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 13px; transition:0.2s; }
            .btn-back:hover { background: #D4AF37; color: #000; }
            @media (max-width: 600px) { .fs-board { flex-direction: column; gap: 20px; } }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * 2. RENDER TEAM STATS
     */
    private function render_stats_bar( $match_id ) {
        $stats_map = [
            'Possession %' => 'possession',
            'Shots on Target' => 'shots',
            'Corners' => 'corners',
            'Offsides' => 'offside',
            'Fouls' => 'fouls',
            'Saves' => 'saves'
        ];

        ob_start();
        ?>
        <div class="flms-match-stats">
            <h3 class="section-title">Team Statistics</h3>
            <?php foreach($stats_map as $label => $key): 
                $h_val = (int) get_post_meta($match_id, "flms_home_$key", true);
                
                if($key === 'possession') {
                    $a_val = 100 - $h_val;
                    if($h_val == 0) $a_val = 0; 
                } else {
                    $a_val = (int) get_post_meta($match_id, "flms_away_$key", true);
                }

                if($h_val + $a_val == 0) continue; 

                $total = $h_val + $a_val;
                $h_pct = ($total > 0) ? ($h_val / $total) * 100 : 50;
            ?>
            <div class="stat-row">
                <div class="stat-vals">
                    <span><?php echo $h_val; ?></span> 
                    <strong><?php echo strtoupper($label); ?></strong> 
                    <span><?php echo $a_val; ?></span>
                </div>
                <div class="stat-bar"><div class="sb-fill" style="width:<?php echo $h_pct; ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * 3. RENDER TIMELINE (EPL STYLE)
     */
    private function get_timeline_html( $match_id, $hid, $aid ) {
        $events = get_post_meta( $match_id, '_flms_match_events', true );
        
        if ( empty( $events ) || ! is_array( $events ) ) {
            return '<div style="text-align:center; padding:40px; background:#f9f9f9; border-radius:8px; color:#999; font-style:italic;">No match events recorded yet.</div>';
        }

        usort( $events, function($a, $b) { return (int)$a['minute'] - (int)$b['minute']; });

        $html = '<div class="flms-epl-timeline">';
        $html .= '<div class="timeline-line"></div>'; // Center Line

        foreach ( $events as $e ) {
            $pid = isset($e['player_id']) ? $e['player_id'] : 0;
            if ( ! $pid ) continue;

            $p_name = get_the_title( $pid );
            $p_team = get_post_meta( $pid, 'flms_team_id', true );
            
            $is_home = ( $p_team == $hid );
            $side_class = $is_home ? 'home-event' : 'away-event';
            
            // Icon Styling
            $icon = '⚽'; // Goal
            $type_label = 'Goal';
            
            if ( $e['type'] === 'yellow' ) { 
                $icon = '<span class="card yellow-card" style="display:inline-block; width:12px; height:16px; background:#f1c40f; border-radius:2px;"></span>'; 
                $type_label = 'Yellow Card'; 
            }
            if ( $e['type'] === 'red' ) { 
                $icon = '<span class="card red-card" style="display:inline-block; width:12px; height:16px; background:#e74c3c; border-radius:2px;"></span>'; 
                $type_label = 'Red Card'; 
            }
            if ( $e['type'] === 'assist' ) { 
                $icon = '👟'; 
                $type_label = 'Assist'; 
            }

            $html .= '<div class="tl-row ' . $side_class . '">';
            
            // Left Side (Home)
            $html .= '<div class="tl-side home">';
            if ( $is_home ) {
                $html .= '<span class="tl-player">' . esc_html($p_name) . '</span>';
                $html .= '<span class="tl-icon" title="' . $type_label . '" style="margin-left:10px;">' . $icon . '</span>';
            }
            $html .= '</div>';

            // Center Time
            $html .= '<div class="tl-time">' . esc_html($e['minute']) . '<span style="font-size:10px;">\'</span></div>';

            // Right Side (Away)
            $html .= '<div class="tl-side away">';
            if ( ! $is_home ) {
                $html .= '<span class="tl-icon" title="' . $type_label . '" style="margin-right:10px;">' . $icon . '</span>';
                $html .= '<span class="tl-player">' . esc_html($p_name) . '</span>';
            }
            $html .= '</div>';
            
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * 4. ADMIN GALLERY UPLOAD
     */
    public function gallery_metabox() {
        add_meta_box('flms_match_gallery_box', 'Match Gallery', function($post) {
            $val = get_post_meta($post->ID, 'flms_match_gallery', true);
            echo '<div id="flms_gallery_container"><input type="hidden" name="flms_match_gallery" id="flms_match_gallery" value="'.esc_attr($val).'"><div id="flms_gallery_thumbs" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">';
            if($val) { 
                $ids = explode(',', $val); 
                foreach($ids as $img_id) { 
                    echo '<img src="'.wp_get_attachment_image_url($img_id, 'thumbnail').'" style="width:60px; height:60px; border-radius:4px; border:1px solid #ccc;">'; 
                } 
            }
            echo '</div><button type="button" class="button button-secondary" id="flms_upload_gallery_btn">Select Images</button></div>';
            ?>
            <script>jQuery(document).ready(function($){var frame;$('#flms_upload_gallery_btn').click(function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:'Select Photos',button:{text:'Use'},multiple:true});frame.on('select',function(){var attachment=frame.state().get('selection').toJSON();var ids=[];var html='';$(attachment).each(function(i,item){ids.push(item.id);html+='<img src="'+item.sizes.thumbnail.url+'" style="width:60px; height:60px; border:1px solid #ccc;">';});$('#flms_match_gallery').val(ids.join(','));$('#flms_gallery_thumbs').html(html);});frame.open();});});</script>
            <?php
        }, 'flms_match', 'side', 'default');
    }

    public function save_gallery( $post_id ) {
        if ( isset( $_POST['flms_match_gallery'] ) ) {
            update_post_meta( $post_id, 'flms_match_gallery', sanitize_text_field( $_POST['flms_match_gallery'] ) );
        }
    }
}