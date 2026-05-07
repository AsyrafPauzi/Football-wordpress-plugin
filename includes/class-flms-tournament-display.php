<?php
class FLMS_Tournament_Display {

    public function __construct() {
        add_action( 'wp', [ $this, 'override_product_layout' ] );
    }

    public function override_product_layout() {
        if ( ! is_product() ) return;

        // REMOVE DEFAULT WOOCOMMERCE ELEMENTS
        remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
        remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
        
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
        
        // Remove Default Add to Cart & Out of Stock Message
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
        add_filter( 'woocommerce_get_stock_html', '__return_empty_string', 100 );
        
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50 );
        
        remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
        remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
        remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );

        // ADD CUSTOM LAYOUT
        add_action( 'woocommerce_before_single_product_summary', [ $this, 'render_tournament_header' ], 5 );
        add_action( 'woocommerce_single_product_summary', [ $this, 'render_tournament_content' ], 10 );
    }

    // --- PART A: HEADER ---
    public function render_tournament_header() {
        global $product;
        $id = $product->get_id();
        $title = $product->get_name();
        
        $bg_image = get_the_post_thumbnail_url($id, 'full'); 
        if(!$bg_image) $bg_image = defined('FLMS_URL') ? FLMS_URL . 'assets/images/stadium-bg.jpg' : '';

        $start_date = get_post_meta($id, '_flms_start_date', true);
        $format_slug = get_post_meta($id, '_flms_format', true);
        if ( $format_slug === 'knockout' ) {
            $format_label = 'Knockout Cup';
        } elseif ( $format_slug === 'group_knockout' ) {
            $format_label = 'Group Stage + Knockout';
        } else {
            $format_label = 'League (Round Robin)';
        }

        $today = date('Y-m-d');
        $is_active = ($start_date && $today >= $start_date);
        
        $status_label = $is_active ? 'Active Season' : 'Registration Open';
        $status_class = $is_active ? 'active' : 'open';
        ?>
        <style>
            /* Force hide stock messages */
            p.stock, .stock.out-of-stock, .stock.in-stock { display: none !important; }
            
            .woocommerce div.product div.summary, .woocommerce div.product .product_title, .entry-summary { width: 100% !important; float: none !important; margin: 0 !important; max-width: 100% !important; }
            .flms-tour-hero { position: relative; height: 320px; display: flex; align-items: center; justify-content: center; background-size: cover; background-position: center; border-radius: 12px; overflow: hidden; margin-bottom: 40px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); }
            .hero-overlay { position: absolute; top:0; left:0; right:0; bottom:0; background: linear-gradient(to bottom, rgba(0,0,0,0.3), rgba(0,0,0,0.8)); }
            .hero-content { position: relative; z-index: 2; text-align: center; color: white; }
            .hero-status { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; text-transform: uppercase; margin-bottom: 10px; letter-spacing: 1px; }
            .hero-status.open { background: #f1c40f; color: #000; }
            .hero-status.active { background: #2ecc71; color: #fff; }
            .hero-title { font-size: 48px; margin: 0 0 10px 0; font-weight: 900; text-shadow: 0 4px 10px rgba(0,0,0,0.5); color: #fff; line-height: 1.1; }
            .hero-meta { font-size: 16px; opacity: 0.9; font-weight: 500; }
            .hero-sep { margin: 0 10px; opacity: 0.5; }
        </style>

        <div class="flms-tour-hero" style="background-image: url('<?php echo esc_url($bg_image); ?>');">
            <div class="hero-overlay"></div>
            <div class="hero-content">
                <span class="hero-status <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                <h1 class="hero-title"><?php echo esc_html($title); ?></h1>
                <div class="hero-meta">
                    📅 Start: <?php echo $start_date ? date('d M Y', strtotime($start_date)) : 'TBD'; ?>
                    <span class="hero-sep">|</span>
                    🏆 Format: <?php echo $format_label; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // --- PART B: CONTENT SWITCHER ---
    public function render_tournament_content() {
        global $product;
        $id = $product->get_id();
        $start_date = get_post_meta($id, '_flms_start_date', true);
        $today = date('Y-m-d');

        if ( $start_date && $today >= $start_date ) {
            $this->render_active_hub($id);
        } else {
            $this->render_registration_page($product);
        }
    }

    // --- LAYOUT 1: REGISTRATION PAGE ---
    private function render_registration_page($product) {
        $id = $product->get_id();
        $registered_teams = get_posts([ 'post_type' => 'flms_team', 'posts_per_page' => -1, 'meta_key' => 'flms_tournament_id', 'meta_value' => $id, 'post_status' => 'publish' ]);
        $count = count($registered_teams);
        ?>
        <div class="flms-reg-layout">
            <div class="flms-reg-content">
                <h3 class="section-heading">Tournament Information</h3>
                <div class="tour-description-text"><?php echo apply_filters('the_content', $product->get_description()); ?></div>
            </div>
            <div class="flms-reg-sidebar">
                <div class="flms-reg-box">
                    <h2 style="margin:0 0 10px 0; font-size: 22px;">Register Your Team</h2>
                    <div style="font-size:36px; font-weight:bold; color:#37003c; margin: 20px 0;"><?php echo $product->get_price_html(); ?></div>
                    <?php
                    $product_permalink = get_permalink( $id );
                    /** Public manager login page (production: https://agdsports.com/login/). */
                    $login_url = add_query_arg( 'redirect_to', rawurlencode( $product_permalink ), home_url( '/login/' ) );
                    if ( ! is_user_logged_in() ) :
                        ?>
                        <p class="flms-reg-guest-hint"><?php esc_html_e( 'Only logged-in team managers can register a team for this tournament.', 'flms' ); ?></p>
                        <a class="flms-reg-login-first-btn button" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Please log in or register first', 'flms' ); ?></a>
                        <?php
                        if ( get_option( 'users_can_register' ) && function_exists( 'wp_registration_url' ) ) :
                            ?>
                            <p class="flms-reg-guest-alt"><a href="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'Create a new account', 'flms' ); ?></a></p>
                            <?php
                        endif;
                    else :
                        woocommerce_template_single_add_to_cart();
                        ?>
                        <p class="flms-reg-loggedin-hint"><?php esc_html_e( 'You will go to checkout next, where you can read the participation terms in a popup before paying.', 'flms' ); ?></p>
                        <?php
                    endif;
                    ?>
                    <div style="margin-top:15px; padding-top:15px; border-top:1px solid #eee; font-size:12px; color:#888;"><p>🔒 Secure Payment via WooCommerce</p><p>✅ Minimum 20 Players Required</p></div>
                </div>
                <div class="flms-reg-list-section" style="margin-top:30px;">
                    <h3 class="section-heading" style="font-size:16px;">Confirmed Teams (<?php echo $count; ?>)</h3>
                    <?php if($count > 0): ?>
                        <div class="reg-team-grid"><?php foreach($registered_teams as $team): $logo = class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_team_logo($team->ID) : ''; ?><div class="reg-team-card"><img src="<?php echo esc_url($logo); ?>"><span><?php echo esc_html($team->post_title); ?></span></div><?php endforeach; ?></div>
                    <?php else: ?><div style="background:#f9f9f9; padding:20px; border-radius:8px; text-align:center; color:#666; font-style:italic;">No teams have registered yet. Be the first!</div><?php endif; ?>
                </div>
            </div>
        </div>
        <style>
            .flms-reg-layout { display: flex; gap: 40px; max-width: 1200px; margin: 0 auto; align-items: flex-start; }
            .flms-reg-content { flex: 2; background: white ; padding: 30px; border-radius: 8px; border: 1px solid #eee; }
            .flms-reg-sidebar { flex: 1; position: sticky; top: 20px; min-width: 320px; }
            .flms-reg-content h3 { color: black; }
            .flms-reg-box { background: #fff; padding: 30px; border-radius: 12px; border: 1px solid #eee; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border-top: 5px solid #D4AF37; }
            .section-heading { margin-top: 0; border-bottom: 2px solid #D4AF37; display: inline-block; padding-bottom: 5px; margin-bottom: 20px; color: #fff; font-size:18px; text-transform:uppercase; font-weight:700; }
            .tour-description-text { font-size: 15px; line-height: 1.8; color: #444; }
            .reg-team-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .reg-team-card { display: flex; flex-direction: column; align-items: center; text-align: center; padding: 15px; border: 1px solid #eee; border-radius: 8px; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
            .reg-team-card img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; margin-bottom: 8px; border:1px solid #eee; }
            .reg-team-card span { font-size: 12px; font-weight: 700; color: #333; line-height: 1.2; }
            .flms-reg-guest-hint { font-size: 14px; color: #555; margin: 0 0 14px; line-height: 1.5; }
            .flms-reg-login-first-btn { display: inline-block !important; width: 100%; padding: 14px 20px !important; font-size: 15px !important; font-weight: 700 !important; text-align: center; background: #D4AF37 !important; color: #111 !important; border: none !important; border-radius: 8px; text-decoration: none !important; box-sizing: border-box; }
            .flms-reg-login-first-btn:hover { filter: brightness(1.05); color: #111 !important; }
            .flms-reg-guest-alt { margin: 14px 0 0; font-size: 13px; }
            .flms-reg-guest-alt a { color: #37003c; font-weight: 600; }
            .flms-reg-loggedin-hint { margin: 12px 0 0; font-size: 12px; color: #666; line-height: 1.45; }
            @media(max-width: 800px) { .flms-reg-layout { flex-direction: column; } .flms-reg-sidebar { width: 100%; position: static; } .reg-team-grid { grid-template-columns: repeat(3, 1fr); } }
        </style>
        <?php
    }

    // --- LAYOUT 2: ACTIVE HUB (UPDATED) ---
    private function render_active_hub($id) {
        $format = get_post_meta($id, '_flms_format', true);
        $product = wc_get_product($id); // Get product to access Description
        ?>
        <div class="flms-hub-wrapper">
            <div class="flms-tabs">
                <?php if($format === 'round_robin'): ?><button class="tab-btn active" onclick="flmsOpenTab(event, 'tab-standings')">Standings</button><?php endif; ?>
                <?php if($format === 'group_knockout'): ?><button class="tab-btn active" onclick="flmsOpenTab(event, 'tab-standings')">Group Stage</button><?php endif; ?>
                <?php if($format === 'knockout'): ?><button class="tab-btn <?php echo ($format==='knockout')?'active':''; ?>" onclick="flmsOpenTab(event, 'tab-bracket')">Bracket</button><?php endif; ?>
                <?php if($format === 'group_knockout'): ?><button class="tab-btn" onclick="flmsOpenTab(event, 'tab-bracket')">Playoff Bracket</button><?php endif; ?>
                <button class="tab-btn" onclick="flmsOpenTab(event, 'tab-matches')">Matches</button>
                <button class="tab-btn" onclick="flmsOpenTab(event, 'tab-teams')">Teams</button>
                
                <!-- NEW TAB: TOURNAMENT INFO -->
                <button class="tab-btn" onclick="flmsOpenTab(event, 'tab-info')">Tournament Info</button>
            </div>

            <!-- Standings / Group Tables -->
            <?php if($format === 'round_robin'): ?><div id="tab-standings" class="flms-tab-content" style="display:block;"><?php echo do_shortcode('[flms_league_table id="'.$id.'"]'); ?></div><?php endif; ?>
            <?php if($format === 'group_knockout'): ?><div id="tab-standings" class="flms-tab-content" style="display:block;"><?php echo do_shortcode('[flms_league_table id="'.$id.'"]'); ?></div><?php endif; ?>
            <!-- Bracket -->
            <?php if($format === 'knockout'): ?><div id="tab-bracket" class="flms-tab-content" style="display:<?php echo ($format==='knockout')?'block':'none'; ?>;}"><div style="overflow-x:auto;"><?php echo do_shortcode('[flms_bracket id="'.$id.'"]'); ?></div></div><?php endif; ?>
            <?php if($format === 'group_knockout'): ?><div id="tab-bracket" class="flms-tab-content" style="display:none;"><div style="overflow-x:auto;"><?php echo do_shortcode('[flms_bracket id="'.$id.'"]'); ?></div></div><?php endif; ?>
            <!-- Matches -->
            <div id="tab-matches" class="flms-tab-content" style="display:none;"><?php echo do_shortcode('[flms_matches id="'.$id.'"]'); ?></div>
            <!-- Teams -->
            <div id="tab-teams" class="flms-tab-content" style="display:none;"><?php $this->render_teams_grid($id); ?></div>
            
            <!-- NEW CONTENT: TOURNAMENT INFO -->
            <div id="tab-info" class="flms-tab-content" style="display:none;">
                <div class="flms-info-box" style="background:#fff; padding:30px; border-radius:12px; border:1px solid #eee; box-shadow:0 4px 10px rgba(0,0,0,0.02);">
                    <h3 style="margin-top:0; border-bottom:2px solid #D4AF37; display:inline-block; padding-bottom:5px; margin-bottom:20px; color:#333;">About this Tournament</h3>
                    <div style="font-size:15px; line-height:1.6; color:#444;">
                        <?php echo apply_filters('the_content', $product->get_description()); ?>
                    </div>
                </div>
            </div>

        </div>

        <script>
        function flmsOpenTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("flms-tab-content");
            for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; }
            tablinks = document.getElementsByClassName("tab-btn");
            for (i = 0; i < tablinks.length; i++) { tablinks[i].className = tablinks[i].className.replace(" active", ""); }
            document.getElementById(tabName).style.display = "block";
            evt.currentTarget.className += " active";
        }
        </script>
        <style>
            .flms-hub-wrapper { max-width: 1000px; margin: 0 auto; width: 100%; }
            /* Mobile scrollable tabs */
            .flms-tabs { display: flex; border-bottom: 2px solid #eee; margin-bottom: 30px; overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; }
            .flms-tabs::-webkit-scrollbar { display: none; }
            
            .tab-btn { background: none; border: none; padding: 15px 30px; font-size: 16px; font-weight: 700; color: #999; cursor: pointer; border-bottom: 4px solid transparent; transition: all 0.3s; flex-shrink: 0; }
            .tab-btn:hover { color: var(--flms-gold, #D4AF37); }
            .tab-btn.active { color: var(--flms-gold, #D4AF37); border-bottom: 4px solid var(--flms-gold, #D4AF37); }
            
            .flms-tab-content { animation: fadeEffect 0.5s; width: 100%; }
            @keyframes fadeEffect { from {opacity: 0; transform: translateY(5px);} to {opacity: 1; transform: translateY(0);} }
            
            .team-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 20px; }
            .team-card { background: #fff; border: 1px solid #eee; border-radius: 12px; padding: 25px; text-align: center; transition: transform 0.2s; box-shadow: 0 4px 10px rgba(0,0,0,0.03); }
            .team-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
            .tc-logo { width: 70px; height: 70px; object-fit: contain; margin-bottom: 15px; border-radius: 50%; }
            .tc-name { display: block; font-weight: 700; color: #333; text-decoration: none; font-size: 15px; line-height: 1.3; }
        </style>
        <?php
    }

    private function render_teams_grid($tournament_id) {
        $teams = get_posts([
            'post_type' => 'flms_team',
            'posts_per_page' => -1,
            'meta_key' => 'flms_tournament_id',
            'meta_value' => $tournament_id,
            'post_status' => 'publish'
        ]);

        if(empty($teams)) { 
            echo '<p style="text-align:center; color:#999;">No teams registered yet.</p>'; 
            return; 
        }

        echo '<div class="team-grid">';
        foreach($teams as $t) {
            $logo = class_exists('FLMS_Image_Helper') ? FLMS_Image_Helper::get_team_logo($t->ID) : '';
            $link = get_permalink($t->ID);
            echo "<div class='team-card'><a href='$link' style='text-decoration:none;'><img src='$logo' class='tc-logo'><span class='tc-name'>".esc_html($t->post_title)."</span></a></div>";
        }
        echo '</div>';
    }
}