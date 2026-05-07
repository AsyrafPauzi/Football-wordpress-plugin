<?php
class FLMS_Team_Settings {
    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post_flms_team', [ $this, 'save_meta' ] );
    }

    public function add_meta_box() {
        add_meta_box( 'flms_team_identity', 'Team Identity (Kit Colors)', [ $this, 'render_ui' ], 'flms_team', 'side', 'default' );
    }

    public function render_ui( $post ) {
        $home_color = get_post_meta( $post->ID, 'flms_home_color', true ) ?: '#000000';
        $away_color = get_post_meta( $post->ID, 'flms_away_color', true ) ?: '#ffffff';
        ?>
        <p>
            <label><strong>Home Kit Color:</strong></label><br>
            <input type="color" name="flms_home_color" value="<?php echo esc_attr($home_color); ?>" style="width:100%; height:40px;">
        </p>
        <p>
            <label><strong>Away Kit Color:</strong></label><br>
            <input type="color" name="flms_away_color" value="<?php echo esc_attr($away_color); ?>" style="width:100%; height:40px;">
        </p>
        <p class="description">Set the Featured Image (on the right) as the Team Logo.</p>
        <?php
    }

    public function save_meta( $post_id ) {
        if ( isset( $_POST['flms_home_color'] ) ) {
            update_post_meta( $post_id, 'flms_home_color', sanitize_hex_color( $_POST['flms_home_color'] ) );
        }
        if ( isset( $_POST['flms_away_color'] ) ) {
            update_post_meta( $post_id, 'flms_away_color', sanitize_hex_color( $_POST['flms_away_color'] ) );
        }
    }
}