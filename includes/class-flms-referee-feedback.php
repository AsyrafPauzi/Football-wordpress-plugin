<?php
class FLMS_Referee_Feedback {

    public function __construct() {
        add_filter( 'the_content', [ $this, 'render_feedback_form' ], 20 );
        add_filter( 'the_content', [ $this, 'render_public_reviews' ], 30 );
        add_action( 'init', [ $this, 'process_feedback' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_admin_metabox' ] );
    }

    /**
     * 1. FRONTEND FORM (Dark Mode UI)
     */
    public function render_feedback_form( $content ) {
        if ( ! is_singular( 'flms_match' ) ) return $content;

        $id = get_the_ID();
        $status = get_post_meta( $id, 'flms_match_status', true );
        
        // Only show if match is completed
        if ( $status !== 'completed' ) return $content;

        // Get Referee Info
        $ref_id = get_post_meta( $id, 'flms_referee_id', true );
        $ref_name = 'Match Officials'; 
        
        if ( $ref_id ) {
            $ref_user = get_userdata( $ref_id );
            if ( $ref_user ) $ref_name = $ref_user->display_name;
        }

        ob_start();
        ?>
        <div class="flms-ref-ui-wrapper">
            <div class="flms-feedback-section">
                <div class="ref-header">
                    <h3>Rate the Referee</h3>
                    <div class="ref-badge">
                        <span class="ref-icon">👮</span> <?php echo esc_html($ref_name); ?>
                    </div>
                </div>
                
                <p class="ref-desc">Your feedback helps us improve the quality of officiating.</p>
                
                <form method="post" class="flms-rating-form">
                    <div class="rating-box">
                        <div class="rating-stars">
                            <input type="radio" name="rating" value="5" id="r5"><label for="r5">★</label>
                            <input type="radio" name="rating" value="4" id="r4"><label for="r4">★</label>
                            <input type="radio" name="rating" value="3" id="r3"><label for="r3">★</label>
                            <input type="radio" name="rating" value="2" id="r2"><label for="r2">★</label>
                            <input type="radio" name="rating" value="1" id="r1"><label for="r1">★</label>
                        </div>
                    </div>
                    
                    <textarea name="rating_comment" placeholder="Write your feedback here..." required></textarea>
                    
                    <input type="hidden" name="flms_action" value="submit_feedback">
                    <input type="hidden" name="match_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="ref_id" value="<?php echo esc_attr($ref_id); ?>">
                    
                    <button type="submit" class="button btn-gold">Submit Feedback</button>
                </form>
            </div>
        </div>

        <style>
            /* WRAPPER */
            .flms-ref-ui-wrapper { max-width: 1200px; margin: 40px auto; font-family: 'Segoe UI', sans-serif; }
            
            /* DARK CARD STYLE */
            .flms-feedback-section { 
                background: #0f0f0f; 
                border: 1px solid #333; 
                padding: 40px; 
                border-radius: 12px; 
                text-align: center; 
                color: #eee;
                box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            }

            /* HEADER */
            .ref-header h3 { color: #D4AF37; text-transform: uppercase; letter-spacing: 2px; margin: 0 0 15px 0; font-size: 24px; font-weight: 800; }
            .ref-badge { display: inline-block; background: #222; padding: 8px 20px; border-radius: 30px; font-weight: 700; border: 1px solid #444; color: #fff; }
            .ref-desc { color: #999; font-size: 14px; margin-top: 15px; margin-bottom: 25px; }

            /* STARS */
            .rating-box { margin-bottom: 20px; }
            .rating-stars { direction: rtl; font-size: 40px; display: inline-flex; gap: 5px; }
            .rating-stars input { display: none; }
            .rating-stars label { color: #444; cursor: pointer; transition: 0.2s; }
            .rating-stars label:hover, .rating-stars label:hover ~ label, .rating-stars input:checked ~ label { color: #D4AF37; text-shadow: 0 0 10px rgba(212, 175, 55, 0.5); }

            /* FORM */
            .flms-rating-form textarea { 
                width: 100%; height: 100px; 
                background: #1a1a1a; border: 1px solid #333; 
                padding: 15px; border-radius: 8px; color: #fff; font-size: 14px; outline: none;
                transition: 0.3s;
            }
            .flms-rating-form textarea:focus { border-color: #D4AF37; background: #222; }

            /* BUTTON */
            .btn-gold { 
                background: #D4AF37 !important; color: #000 !important; font-weight: 800 !important; 
                padding: 12px 30px !important; border-radius: 6px !important; border: none !important;
                text-transform: uppercase; margin-top: 20px; cursor: pointer; transition: 0.3s;
            }
            .btn-gold:hover { background: #fff !important; transform: translateY(-2px); }

            @media (max-width: 600px) {
                .flms-feedback-section { padding: 25px; }
                .rating-stars { font-size: 30px; }
            }
        </style>
        <?php
        $form_html = ob_get_clean();
        return $content . $form_html;
    }

    /**
     * 2. PUBLIC REVIEWS DISPLAY (Modern Grid)
     */
    public function render_public_reviews( $content ) {
        if ( ! is_singular( 'flms_match' ) ) return $content;

        $id = get_the_ID();
        $reviews = get_post_meta( $id, '_flms_referee_feedback', false );

        if ( empty( $reviews ) ) return $content;

        ob_start();
        ?>
        <div class="flms-ref-ui-wrapper">
            <div class="flms-public-reviews">
                <h3 class="reviews-title"><span class="icon">📢</span> Public Transparency Report</h3>
                <div class="reviews-grid">
                    <?php foreach ( $reviews as $r ) : 
                        $stars = str_repeat('★', intval($r['rating']));
                        $empty_stars = str_repeat('★', 5 - intval($r['rating']));
                        $date = date( 'd M Y', strtotime($r['date']) );
                    ?>
                    <div class="review-card">
                        <div class="rc-header">
                            <div class="rc-stars">
                                <span class="s-gold"><?php echo $stars; ?></span><span class="s-grey"><?php echo $empty_stars; ?></span>
                            </div>
                            <span class="rc-date"><?php echo $date; ?></span>
                        </div>
                        <div class="rc-body">
                            "<?php echo esc_html( $r['comment'] ); ?>"
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <style>
            .flms-public-reviews { margin-top: 30px; }
            .reviews-title { 
                color: #D4AF37; font-size: 16px; text-transform: uppercase; letter-spacing: 1px; 
                margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 800;
            }
            .reviews-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
            
            /* REVIEW CARD */
            .review-card { 
                background: #151515; border: 1px solid #333; padding: 25px; border-radius: 8px; 
                box-shadow: 0 4px 10px rgba(0,0,0,0.2); transition: 0.3s;
            }
            .review-card:hover { border-color: #555; transform: translateY(-3px); }

            .rc-header { display: flex; justify-content: space-between; margin-bottom: 15px; align-items: center; }
            .rc-stars { font-size: 14px; letter-spacing: 2px; }
            .s-gold { color: #D4AF37; }
            .s-grey { color: #444; }
            .rc-date { color: #777; font-size: 11px; font-weight: 600; text-transform: uppercase; }
            
            .rc-body { font-style: italic; color: #ccc; line-height: 1.6; font-size: 14px; }
        </style>
        <?php
        return $content . ob_get_clean();
    }

    /**
     * 3. PROCESS FEEDBACK
     */
    public function process_feedback() {
        if ( isset( $_POST['flms_action'] ) && $_POST['flms_action'] === 'submit_feedback' ) {
            $mid = intval($_POST['match_id']);
            $score = intval($_POST['rating']);
            $comment = sanitize_textarea_field($_POST['rating_comment']);

            $feedback = [
                'rating' => $score,
                'comment' => $comment,
                'date' => current_time('mysql'),
                'ip' => $_SERVER['REMOTE_ADDR']
            ];

            add_post_meta( $mid, '_flms_referee_feedback', $feedback );
            
            wp_redirect( get_permalink($mid) . '?msg=feedback_sent' );
            exit;
        }
    }

    /**
     * 4. ADMIN VIEW
     */
    public function add_admin_metabox() {
        add_meta_box('flms_ref_reviews', 'Referee Feedback', [$this, 'render_admin_reviews'], 'flms_match', 'normal', 'low');
    }

    public function render_admin_reviews($post) {
        $reviews = get_post_meta($post->ID, '_flms_referee_feedback', false);
        if(empty($reviews)) { echo '<p>No feedback submitted.</p>'; return; }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Rating</th><th>Comment</th><th>Date</th></tr></thead><tbody>';
        foreach($reviews as $r) {
            $stars = str_repeat('★', intval($r['rating']));
            echo "<tr><td style='color:#D4AF37; font-size:16px;'>$stars</td><td>".esc_html($r['comment'])."</td><td>{$r['date']}</td></tr>";
        }
        echo '</tbody></table>';
    }
}