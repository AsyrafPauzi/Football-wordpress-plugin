<?php
class FLMS_Notifications {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_footer', [ $this, 'render_popup_logic' ] );
        add_action( 'admin_footer', [ $this, 'render_popup_logic' ] );
    }

    public function enqueue_assets() {
        wp_enqueue_script( 'sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], '11.0', true );
    }

    public function render_popup_logic() {
        $msg_code = isset( $_GET['msg'] ) ? sanitize_text_field( $_GET['msg'] ) : '';
        $login_err = isset( $_GET['login_error'] ) ? sanitize_text_field( $_GET['login_error'] ) : '';
        $reg_stat = isset( $_GET['registered'] ) ? sanitize_text_field( $_GET['registered'] ) : '';
        $updated = isset( $_GET['updated'] ) ? sanitize_text_field( $_GET['updated'] ) : '';

        $popup = null;

        if ( $reg_stat === 'pending' ) {
            $popup = [ 'icon' => 'info', 'title' => 'Registration Successful', 'text' => 'Your account is pending Admin approval. You will be notified via email.' ];
        }
        elseif ( $login_err === 'pending' ) {
            $popup = [ 'icon' => 'warning', 'title' => 'Access Denied', 'text' => 'Your account is still pending approval.' ];
        }
        elseif ( $login_err === 'invalid' ) {
            $popup = [ 'icon' => 'error', 'title' => 'Login Failed', 'text' => 'Invalid username or password.' ];
        }
        elseif ( $msg_code === 'req_sent' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Request Sent', 'text' => 'Transfer request has been sent to the current manager.' ];
        }
        elseif ( $msg_code === 'transfer_success' || $msg_code === 'success_free' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Transfer Complete', 'text' => 'Player has been successfully added to your team!' ];
        }
        elseif ( $msg_code === 'approved_moved' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Approved', 'text' => 'Transfer approved and player moved.' ];
        }
        elseif ( $msg_code === 'approved_awaiting_pay' ) {
            $popup = [ 'icon' => 'info', 'title' => 'Approved', 'text' => 'Request approved. The buying team must now pay the fee to finalize.' ];
        }
        elseif ( $msg_code === 'rejected' ) {
            $popup = [ 'icon' => 'error', 'title' => 'Rejected', 'text' => 'Transfer request has been rejected.' ];
        }
        elseif ( $msg_code === 'lineup_saved' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Lineup Saved', 'text' => 'Your starting lineup for the next match has been submitted.' ];
        }
        elseif ( $msg_code === 'logo_updated' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Logo Updated', 'text' => 'Your team logo has been updated successfully.' ];
        }
        elseif ( $msg_code === 'player_updated' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Player Updated', 'text' => 'Player details saved successfully.' ];
        }
        elseif ( $msg_code === 'photo_updated' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Photo Uploaded', 'text' => 'Player photo has been updated.' ];
        }
        elseif ( $updated === 'true' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Updated', 'text' => 'Data saved successfully.' ];
        }
        elseif ( $msg_code === 'generated' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Success', 'text' => 'Matches have been generated successfully.' ];
        }
        elseif ( $msg_code === 'feedback_sent' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Thank You', 'text' => 'Your feedback has been submitted.' ];
        }
        // NEW: Password Changed
        elseif ( $msg_code === 'password_changed' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Success', 'text' => 'Password changed successfully. Please login again.' ];
        }
        // Friendly match
        elseif ( $msg_code === 'friendly_request_sent' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Request Sent', 'text' => 'Your friendly match request has been sent. The host manager will review and accept or reject.' ];
        }
        elseif ( $msg_code === 'friendly_accepted' ) {
            $popup = [ 'icon' => 'success', 'title' => 'Team Accepted', 'text' => 'You have accepted the team. The match is now pending admin approval. Both teams will be notified by email when approved.' ];
        }

        if ( $popup ) {
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: '<?php echo $popup['icon']; ?>',
                    title: '<?php echo $popup['title']; ?>',
                    text: '<?php echo $popup['text']; ?>',
                    confirmButtonColor: '#D4AF37', // Gold Theme
                    background: '#fff',
                    color: '#333'
                });
                
                const url = new URL(window.location);
                url.searchParams.delete('msg');
                url.searchParams.delete('login_error');
                url.searchParams.delete('registered');
                url.searchParams.delete('updated');
                window.history.replaceState({}, document.title, url);
            });
            </script>
            <?php
        }
    }
}