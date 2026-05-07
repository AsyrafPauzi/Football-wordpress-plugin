<?php
$post_id = get_the_ID(); // Get the current match ID

// Get Scores
$home_score = get_post_meta( $post_id, 'flms_home_score', true );
$away_score = get_post_meta( $post_id, 'flms_away_score', true );

// Get Team IDs
$home_id = get_post_meta( $post_id, 'flms_home_team', true );
$away_id = get_post_meta( $post_id, 'flms_away_team', true );

// Get Team Names (with TBD fallback)
$home_name = $home_id ? get_the_title($home_id) : 'HOME TEAM (TBD)';
$away_name = $away_id ? get_the_title($away_id) : 'AWAY TEAM (TBD)';
?>

<div class="flms-score-input" style="background: #fcfcfc; padding: 20px; border:1px solid #eee; border-radius: 8px; text-align: center;">
    
    <div style="display: flex; justify-content: space-around; align-items: flex-end;">
        
        <!-- HOME TEAM -->
        <div style="flex: 1; margin: 0 10px;">
            <h3 style="margin: 0 0 10px 0; color: #37003c; font-size: 16px;"><?php echo esc_html($home_name); ?></h3>
            <label style="display:block; font-size:12px; color:#555;">Home Score:</label>
            <input type="number" name="flms_home_score" value="<?php echo esc_attr($home_score); ?>" style="font-size: 36px; width: 80px; text-align: center; padding: 10px; border: 2px solid #37003c;">
        </div>

        <div style="font-size: 40px; font-weight: 300; color: #ccc; flex-shrink: 0;">VS</div>

        <!-- AWAY TEAM -->
        <div style="flex: 1; margin: 0 10px;">
            <h3 style="margin: 0 0 10px 0; color: #37003c; font-size: 16px;"><?php echo esc_html($away_name); ?></h3>
            <label style="display:block; font-size:12px; color:#555;">Away Score:</label>
            <input type="number" name="flms_away_score" value="<?php echo esc_attr($away_score); ?>" style="font-size: 36px; width: 80px; text-align: center; padding: 10px; border: 2px solid #37003c;">
        </div>
    </div>
    
    <p style="margin-top: 20px; text-align: center; color: #888; font-style: italic; font-size: 12px;">
        Note: This determines the result for the League Table.
        <br>
        Please scroll down to "Match Events" to assign goals to specific players.
    </p>
</div>