<?php
class FLMS_Match_Cleanup {
    public function __construct() {
        add_action('admin_head', [$this, 'clean_ui']);
    }

    public function clean_ui() {
        global $post_type;
        if($post_type == 'flms_match') {
            echo '<style>
                /* Hide sidebar clutter */
                #side-sortables, #slugdiv, #authordiv, #postcustom { display:none; }
                /* Make main column full width */
                #post-body-content { margin-right: 0 !important; width: 100% !important; }
                #postbox-container-1 { display:none; } 
                /* Style the Save Button to be huge and fixed at bottom right */
                #major-publishing-actions { background: #fff; border-top: 1px solid #ccc; padding: 10px; }
                #publish { font-size: 18px; height: 40px; line-height: 38px; width: 100%; }
            </style>';
        }
    }
}
// Remember to require this in your main file if you use it.