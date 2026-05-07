<?php
class FLMS_Multi_View {

    public function __construct() {
        add_shortcode( 'flms_division_tabs', [ $this, 'render_tabs' ] );
    }

    public function render_tabs( $atts ) {
        // Usage: [flms_division_tabs ids="150,151" title="Super League" view="matches"]
        // view options: 'matches', 'standings', 'split' (default)
        $atts = shortcode_atts( [ 
            'ids' => '', 
            'title' => '', 
            'view' => 'split' 
        ], $atts );
        
        if ( empty($atts['ids']) ) return '';

        $ids = explode( ',', $atts['ids'] );
        $unique_id = uniqid('flms_tab_'); 
        $view_mode = $atts['view'];

        ob_start();
        ?>
        <div class="flms-multi-view-wrapper" id="<?php echo $unique_id; ?>">
            
            <div class="flms-mv-header">
              

                <!-- TABS NAVIGATION -->
                <div class="flms-mv-tabs">
                    <?php foreach($ids as $index => $tid): 
                        $tid = intval($tid);
                        $t_title = get_the_title($tid);
                        $active = ($index === 0) ? 'active' : '';
                        
                        // Clean title logic (Optional)
                        $label = $t_title; 
                    ?>
                        <button class="mv-tab-btn <?php echo $active; ?>" onclick="flmsSwitchDiv('<?php echo $unique_id; ?>', 'div_<?php echo $tid; ?>', this)">
                            <?php echo esc_html($label); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- TABS CONTENT -->
            <div class="flms-mv-container">
                <?php foreach($ids as $index => $tid): 
                    $tid = intval($tid);
                    $display = ($index === 0) ? 'block' : 'none';
                    // If split, use flex. If single view, use block.
                    if($view_mode === 'split' && $index === 0) $display = 'flex';
                    
                    $layout_class = ($view_mode === 'split') ? 'split-layout' : 'full-layout';
                ?>
                    <div id="div_<?php echo $tid; ?>" class="mv-pane <?php echo $layout_class; ?>" style="display:<?php echo $display; ?>;">
                        
                        <!-- VIEW: MATCHES ONLY -->
                        <?php if($view_mode === 'matches'): ?>
                            <div class="mv-full-content">
                                <?php echo do_shortcode( '[flms_matches id="'.$tid.'" limit="4" sort_homepage="true"]' ); ?>
                                <div style="text-align:right; margin-top:10px;">
                                    <a href="<?php echo get_permalink($tid); ?>" class="mv-link">All Matches &rarr;</a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- VIEW: STANDINGS ONLY -->
                        <?php if($view_mode === 'standings'): ?>
                            <div class="mv-full-content">
                                <?php echo do_shortcode( '[flms_league_table id="'.$tid.'" limit="10"]' ); ?>
                                <div style="text-align:right; margin-top:10px;">
                                    <a href="<?php echo get_permalink($tid); ?>" class="mv-link">Full Table &rarr;</a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- VIEW: SPLIT (Original) -->
                        <?php if($view_mode === 'split'): ?>
                            <!-- LEFT: MATCHES -->
                            <div class="mv-col-left">
                                <div class="mv-section-head">
                            
                                    <a href="<?php echo get_permalink($tid); ?>" class="mv-link">Visit Hub &rarr;</a>
                                </div>
                                <?php echo do_shortcode( '[flms_matches id="'.$tid.'" limit="4" sort_homepage="true"]' ); ?>
                            </div>

                            <!-- RIGHT: STANDINGS -->
                            <div class="mv-col-right">
                                <div class="mv-section-head"><h3>Standings</h3></div>
                                <div class="mv-table-box">
                                    <?php echo do_shortcode( '[flms_league_table id="'.$tid.'" limit="5" layout="sidebar"]' ); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            </div>

        </div>

        <script>
        function flmsSwitchDiv(wrapperId, targetId, btn) {
            var wrapper = document.getElementById(wrapperId);
            var viewMode = '<?php echo $view_mode; ?>';

            // Hide all panes
            var panes = wrapper.getElementsByClassName('mv-pane');
            for (var i = 0; i < panes.length; i++) {
                panes[i].style.display = 'none';
            }
            
            // Remove active class from buttons
            var buttons = wrapper.getElementsByClassName('mv-tab-btn');
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].classList.remove('active');
            }

            // Show Target
            var displayStyle = (viewMode === 'split') ? 'flex' : 'block';
            document.getElementById(targetId).style.display = displayStyle;
            
            btn.classList.add('active');
        }
        </script>
        <?php
        return ob_get_clean();
    }
}