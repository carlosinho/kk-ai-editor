<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

////////////////////////////////////
// TOTALS AND USAGE LOG
////////////////////////////////////

/**
 * Append a message to the usage log for a post
 * 
 * @param int $post_id The ID of the post
 * @param string $message The message to append
 */
function kk_ai_editor_append_to_usage_log($post_id, $message) {
    $current_log = get_post_meta($post_id, 'kk_ai_editor_usage_log', true);
    $new_log = empty($current_log) ? '- ' . $message : $current_log . "\n- " . $message;

    // Clear the cache before updates
    //wp_cache_delete($post_id, 'post_meta');

    update_post_meta($post_id, 'kk_ai_editor_usage_log', $new_log);

    // Clear the cache again after updates
    //wp_cache_delete($post_id, 'post_meta');
    
    return $new_log;
}

/**
 * Update the usage totals for a post
 * 
 * @param int $post_id The ID of the post
 * @param int $prompt_tokens The number of prompt tokens used
 * @param int $completion_tokens The number of completion tokens used
 * @param float $total_cost The total cost of the usage
 */
function kk_ai_editor_update_usage_totals($post_id, $prompt_tokens, $completion_tokens, $total_cost) {
    //error_log("=== Updating usage totals for post $post_id ===");
    
    // Get current values with explicit defaults
    $current_prompt_tokens = get_post_meta($post_id, 'kk_ai_editor_total_prompt_tokens', true);
    $current_completion_tokens = get_post_meta($post_id, 'kk_ai_editor_total_completion_tokens', true);
    $current_total_cost = get_post_meta($post_id, 'kk_ai_editor_total_total_cost', true);
    
    //error_log("Current values from DB:");
    //error_log("- Prompt tokens: " . var_export($current_prompt_tokens, true));
    //error_log("- Completion tokens: " . var_export($current_completion_tokens, true));
    //error_log("- Total cost: " . var_export($current_total_cost, true));
    
    // Convert to proper types with explicit defaults
    $current_prompt_tokens = $current_prompt_tokens !== '' ? (int)$current_prompt_tokens : 0;
    $current_completion_tokens = $current_completion_tokens !== '' ? (int)$current_completion_tokens : 0;
    $current_total_cost = $current_total_cost !== '' ? (float)$current_total_cost : 0.0;
    
    // Calculate new values
    $new_prompt_tokens = $current_prompt_tokens + (int)$prompt_tokens;
    $new_completion_tokens = $current_completion_tokens + (int)$completion_tokens;
    $new_total_cost = $current_total_cost + (float)$total_cost;
    
    //error_log("New values to save:");
    //error_log("- Prompt tokens: $new_prompt_tokens (adding $prompt_tokens)");
    //error_log("- Completion tokens: $new_completion_tokens (adding $completion_tokens)");
    //error_log("- Total cost: $new_total_cost (adding $total_cost)");
    
    // Clear the cache before updates
    //wp_cache_delete($post_id, 'post_meta');
    
    // Update values with result checking
    $result1 = update_post_meta($post_id, 'kk_ai_editor_total_prompt_tokens', $new_prompt_tokens);
    $result2 = update_post_meta($post_id, 'kk_ai_editor_total_completion_tokens', $new_completion_tokens);
    $result3 = update_post_meta($post_id, 'kk_ai_editor_total_total_cost', $new_total_cost);
    
    // Clear the cache again after updates
    //wp_cache_delete($post_id, 'post_meta');
    
    //error_log("Update results:");
    //error_log("- Prompt tokens update: " . var_export($result1, true));
    //error_log("- Completion tokens update: " . var_export($result2, true));
    //error_log("- Total cost update: " . var_export($result3, true));
    
    // Verify the updates
    //$verify_prompt = get_post_meta($post_id, 'kk_ai_editor_total_prompt_tokens', true);
    //$verify_completion = get_post_meta($post_id, 'kk_ai_editor_total_completion_tokens', true);
    //$verify_cost = get_post_meta($post_id, 'kk_ai_editor_total_total_cost', true);
    
    //error_log("Verification - values after update:");
    //error_log("- Prompt tokens: $verify_prompt");
    //error_log("- Completion tokens: $verify_completion");
    //error_log("- Total cost: $verify_cost");
    //error_log("=== Update complete ===");

    // Return the new totals
    return array(
        'prompt_tokens' => $new_prompt_tokens,
        'completion_tokens' => $new_completion_tokens,
        'total_cost' => $new_total_cost
    );
}

////////////////////////////////////
// META BOX, UI FUNCTIONS
////////////////////////////////////

/**
 * Render the meta box that shows the main buttons of the plugin.
 */
function kk_ai_editor_meta_box_callback( $post ) {
    // Use nonce for security.
    wp_nonce_field( 'ai_generate_content_nonce', 'ai_generate_content_nonce_field' );
    ?>
    <p>
        <button type="button" id="ai_generate_body_button" class="button button-primary">
            <span class="dashicons dashicons-editor-paragraph"></span>
            Edit Post
        </button>
    </p>
    <p id="ai_generate_status"></p>
    <?php
}

/**
 * Render the meta box below editor - showing usage stats and generated post parts.
 */
function kk_ai_editor_below_editor_meta_box_callback($post) {
    ?>
    <div class="ai-log-content">
        <?php
        // Usage Statistics Section
        $usage_log = get_post_meta($post->ID, 'kk_ai_editor_usage_log', true);
        $total_prompt_tokens = get_post_meta($post->ID, 'kk_ai_editor_total_prompt_tokens', true);
        $total_completion_tokens = get_post_meta($post->ID, 'kk_ai_editor_total_completion_tokens', true);
        $total_cost = get_post_meta($post->ID, 'kk_ai_editor_total_total_cost', true);
        ?>
        <div class="ai-log-group">
            <h3 class="ai-log-group-title">Usage statistics</h3>
            <!-- Stats Row -->
            <div class="ai-stats-row">
                <div class="ai-stat-box">
                    <h4>Total prompt tokens</h4>
                    <div class="ai-stat-value"><?php echo number_format((int)$total_prompt_tokens); ?></div>
                </div>
                <div class="ai-stat-box">
                    <h4>Total completion tokens</h4>
                    <div class="ai-stat-value"><?php echo number_format((int)$total_completion_tokens); ?></div>
                </div>
                <div class="ai-stat-box">
                    <h4>Total cost</h4>
                    <div class="ai-stat-value">$<?php echo number_format((float)$total_cost, 4); ?></div>
                </div>
            </div>
            <!-- Log Row -->
            <div class="ai-log-section">
                <h4>Usage log</h4>
                <div class="ai-log-content-box <?php echo empty($usage_log) ? 'ai-log-empty-field' : ''; ?>">
                    <?php 
                    if (!empty($usage_log)) {
                        echo wp_kses_post(nl2br($usage_log));
                    } else {
                        echo '<p class="ai-log-empty">No usage data available yet.</p>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

////////////////////////////////////
// SETTINGS PAGE FUNCTIONS
////////////////////////////////////

/**
 * The main plugin settings page.
 */
function kk_ai_editor_plugin_settings_page() {
    // Get the image content for the larger icon
    $icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
        file_get_contents(plugin_dir_path(__FILE__) . 'assets/ai-editor-color.svg')
    );
    
    // Create a larger version by wrapping in a div with larger dimensions
    $large_icon = '<div style="width: 50px; height: 50px;"><img src="' . esc_attr($icon_svg) . '" alt="AI Editor Icon" style="width: 100%; height: 100%; object-fit: contain;"></div>';
    ?>
    <div class="wrap">
        <div class="ai-settings-header">
            <h1 class="ai-settings-title">AI Editor Settings</h1>
            <?php echo $large_icon; ?>
        </div>
        <form method="post" action="options.php">
            <?php
            settings_fields('kk_ai_editor_options_group');
            do_settings_sections('kk-ai-editor');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

/**
 * Render the usage statistics section
 */
function kk_ai_editor_plugin_render_usage_stats() {
    $total_prompt_tokens = get_option('kk_ai_editor_total_prompt_tokens', false);
    $total_completion_tokens = get_option('kk_ai_editor_total_completion_tokens', false);
    $total_cost = get_option('kk_ai_editor_total_cost', false);
    ?>
    <div class="ai-stats-row">
        <div class="ai-stat-box">
            <h4>Total prompt tokens</h4>
            <div class="ai-stat-value">
                <?php echo $total_prompt_tokens !== false ? number_format((int)$total_prompt_tokens) : 'No data'; ?>
            </div>
        </div>
        <div class="ai-stat-box">
            <h4>Total completion tokens</h4>
            <div class="ai-stat-value">
                <?php echo $total_completion_tokens !== false ? number_format((int)$total_completion_tokens) : 'No data'; ?>
            </div>
        </div>
        <div class="ai-stat-box">
            <h4>Total cost</h4>
            <div class="ai-stat-value">
                <?php echo $total_cost !== false ? '$' . number_format((float)$total_cost, 4) : 'No data'; ?>
            </div>
        </div>
    </div>
    <p>
        <button type="button" id="ai_recalculate_totals" class="button button-secondary">
            <span class="dashicons dashicons-update dashicons-update-ai"></span>
            Recalculate usage totals
        </button>
        <span id="ai_recalculate_status" class="ai-recalculate-status"></span>
    </p>
    <?php
}

////////////////////////////////////
// SETTINGS FIELD CALLBACKS
////////////////////////////////////

/**
 * Callback for the OpenAI API key field.
 */
function kk_ai_editor_openai_key_callback() {
    $api_key = get_option('kk_ai_editor_api_key');
    echo '<input type="text" name="kk_ai_editor_api_key" value="' . esc_attr($api_key) . '" class="regular-text">';
}

/**
 * Callback for the OpenRouter API key field.
 */
function kk_ai_editor_openrouter_key_callback() {
    $api_key = get_option('kk_ai_editor_openrouter_key');
    echo '<input type="text" name="kk_ai_editor_openrouter_key" value="' . esc_attr($api_key) . '" class="regular-text">';
}

/**
 * Callback function for toggle switches
 */
function kk_ai_editor_toggle_callback($args) {
    $option = $args['option'];
    $value = get_option($option, true);
    // Convert to boolean for comparison
    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    ?>
    <fieldset class="ai-plugin-radio-group">
        <label class="ai-label-spacing">
            <input type="radio" name="<?php echo esc_attr($option); ?>" 
                   value="1" <?php checked(true, $value); ?>>
            <span>Enabled</span>
        </label>
        <label>
            <input type="radio" name="<?php echo esc_attr($option); ?>" 
                   value="0" <?php checked(false, $value); ?>>
            <span>Disabled</span>
        </label>
    </fieldset>
    <?php
}

/**
 * Callback for the LLM model selection dropdown.
 */
function kk_ai_editor_model_dropdown_callback() {
    $models = [
        'gpt-4o-2024-11-20' => 'OpenAI',
        'gpt-4o' => 'OpenAI',
        'gpt-4o-mini' => 'OpenAI',
        //'gpt-4.1' => 'OpenAI',
        //'gpt-4.1-mini' => 'OpenAI',
        'anthropic/claude-3.7-sonnet' => 'OpenRouter',
        'google/gemini-2.0-flash-001' => 'OpenRouter',
    ];
    $selected = get_option('kk_ai_editor_model', 'gpt-4o');
    echo '<select name="kk_ai_editor_model">';
    foreach ($models as $model => $provider) {
        printf(
            '<option value="%s" %s>%s (%s)</option>',
            esc_attr($model),
            selected($selected, $model, false),
            esc_html($model),
            esc_html($provider)
        );
    }
    echo '</select>';
}

/**
 * Callback for the prompt style selection dropdown.
 */
function kk_ai_editor_prompt_style_dropdown_callback() {
    $options = [
        'strict' => 'Strict editing',
        'loose' => 'Loose editing',
        'looser' => 'Even looser editing',
    ];
    $selected = get_option('kk_ai_editor_prompt_style', 'strict');
    echo '<select name="kk_ai_editor_prompt_style">';
    foreach ($options as $value => $label) {
        printf(
            '<option value="%s" %s>%s</option>',
            esc_attr($value),
            selected($selected, $value, false),
            esc_html($label)
        );
    }
    echo '</select>';
}

////////////////////////////////////
// DEBUG/CLEANUP FUNCTIONS, SANITIZATION
////////////////////////////////////

/**
 * Debug cron status
 */
function kk_ai_editor_debug_cron_status($process_id) {
    $next_run = wp_next_scheduled('kk_ai_editor_process_content_generation', array($process_id));
    error_log('Next scheduled run for ' . $process_id . ': ' . ($next_run ? date('Y-m-d H:i:s', $next_run) : 'Not scheduled'));
}

/**
 * Cleanup generation data from the database
 */
function kk_ai_editor_cleanup_generation_data() {
    global $wpdb;
    $expired_options = $wpdb->get_results(
        "SELECT option_name FROM $wpdb->options WHERE (option_name LIKE 'ai_gen_data_%' OR option_name LIKE 'ai_sources_data_%') AND option_name NOT IN (
            SELECT CONCAT('ai_gen_data_', argument) 
            FROM $wpdb->options 
            WHERE option_name = '_transient_timeout_scheduled-action_%'
        )"
    );
    
    foreach ($expired_options as $option) {
        delete_option($option->option_name);
    }
}

/**
 * Add cleanup action
 */
function kk_ai_editor_cleanup_ai_generation_data($process_id) {
    $option_name = 'ai_gen_data_' . $process_id;
    delete_option($option_name);
    error_log('Cleaned up data for process ID: ' . $process_id);
}

/**
 * Sanitize checkbox value
 */
function kk_ai_editor_sanitize_checkbox($value) {
    // Convert string "0" and "1" to proper boolean
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}
