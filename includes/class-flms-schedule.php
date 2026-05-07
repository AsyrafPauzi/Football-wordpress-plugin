<?php
class FLMS_Schedule {
    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_schedule_box' ] );
        add_action( 'save_post_flms_match', [ $this, 'save_schedule' ] );
    }

    public function add_schedule_box() {
        add_meta_box( 
            'flms_schedule_box', 
            'Match Schedule', 
            [ $this, 'render_ui' ], 
            'flms_match', 
            'side', // Show on the side panel
            'high' 
        );
    }

    public function render_ui( $post ) {
        $date = get_post_meta( $post->ID, 'flms_match_date', true );
        $time = get_post_meta( $post->ID, 'flms_match_time', true );
        ?>
        <div class="flms-schedule-wrap">
            <p>
                <label><strong>Date:</strong></label><br>
                <input type="date" name="flms_match_date" value="<?php echo esc_attr($date); ?>" style="width:100%;">
            </p>
            <p>
                <label><strong>Time:</strong></label><br>
                <input type="time" name="flms_match_time" value="<?php echo esc_attr($time); ?>" style="width:100%;">
            </p>
            <p class="description">
                Select the Venue from the "Venues" box above/below.
            </p>
        </div>
        <?php
    }

    public function save_schedule( $post_id ) {
        if ( isset( $_POST['flms_match_date'] ) ) {
            update_post_meta( $post_id, 'flms_match_date', sanitize_text_field( $_POST['flms_match_date'] ) );
        }
        if ( isset( $_POST['flms_match_time'] ) ) {
            update_post_meta( $post_id, 'flms_match_time', sanitize_text_field( $_POST['flms_match_time'] ) );
        }
    }
}