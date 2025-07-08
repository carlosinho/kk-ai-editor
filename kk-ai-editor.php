<?php
/**
 * Plugin Name: AI Editor at Your Service
 * Plugin URI: https://wpwork.shop/
 * Description: Your friendly AI editor - takes your post content and edits it.
 * Version: 0.9.0
 * Author: Karol K
 * Author URI: https://wpwork.shop/
 * License: GPL-2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

//////////////////////
// SETUP

// Include the AI connection client classes
require_once 'class-openai-client.php';
require_once 'helpers.php';
require_once 'prompt-lib-general.php';

//////////////////////
// DEFINE PARAMETERS

// Max sections generated. This helps to protect against infinite looping.
define('AI_EDIT_MAX_SECTIONS', 16);

// Model temperature
define('KK_AI_MODEL_EDIT_TEMP', 1);

//////////////////////
// FUNCTIONS

// Add model-endpoint mapping helper
function kk_ai_editor_get_model_endpoint($model) {
    $openai_models = [
        'gpt-4o-2024-11-20',
        'gpt-4o',
        'gpt-4o-mini',
        'gpt-4.1',
        'gpt-4.1-mini',
    ];
    $openrouter_models = [
        'anthropic/claude-3.7-sonnet',
        'anthropic/claude-sonnet-4',
        'google/gemini-2.0-flash-001',
        'google/gemini-2.5-flash-preview-05-20',
        'google/gemini-2.5-flash',
    ];
    if (in_array($model, $openai_models, true)) {
        return 'openai';
    } elseif (in_array($model, $openrouter_models, true)) {
        return 'openrouter';
    }
    return 'openai'; // fallback
}

// Add helper to get the correct prompt pair
function kk_ai_editor_get_working_prompts() {
    $style = get_option('kk_ai_editor_prompt_style', 'strict');
    switch ($style) {
        case 'strict':
            return [EDIT_SYS_PROMPT_V1, EDIT_PROMPT_V1];
        case 'loose':
            return [EDIT_SYS_PROMPT_V2, EDIT_PROMPT_V2];
        case 'looser':
            return [EDIT_SYS_PROMPT_V3, EDIT_PROMPT_V3];
        default:
            return [EDIT_SYS_PROMPT_V1, EDIT_PROMPT_V1];
    }
}

/**
 * AJAX handler for generating AI body content.
 */
function kk_ai_editor_ajax_generate_body() {
    // Add error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    // Basic security checks
    if (!wp_doing_ajax()) {
        wp_send_json_error('Not an AJAX request');
        return;
    }

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Unauthorized');
        return;
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_generate_content_nonce')) {
        wp_send_json_error('Invalid nonce');
        return;
    }
    
    $current_post = isset($_POST['content']) ? $_POST['content'] : '';
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    
    if (empty($current_post)) {
        wp_send_json_error('No current post provided');
        return;
    }
    if (empty($title)) {
        wp_send_json_error('No title provided');
        return;
    }
    if (empty($post_id)) {
        wp_send_json_error('No post ID provided');
        return;
    }

    try {
        // Initialize process
        $process_id = uniqid('ai_gen_');
        $generation_data = array(
            'status' => 'processing',
            'step' => 'intro',
            'progress' => 0,
            'content' => '',
            'sections' => array(),
            'current_section' => 0,
            'title' => $title,
            'new_totals' => array(),
            'pre_edit_content' => $current_post,
            'post_id' => $post_id
        );
        
        // Store initial state using options API instead of transients
        $option_name = 'ai_gen_data_' . $process_id;
        if (!update_option($option_name, $generation_data, false)) {
            wp_send_json_error('Failed to initialize process');
            return;
        }
        
        // Schedule the background process
        if (wp_schedule_single_event(time(), 'kk_ai_editor_process_content_generation', array($process_id))) {
            kk_ai_editor_debug_cron_status($process_id);
            wp_send_json_success(array(
                'process_id' => $process_id,
                'message' => 'Content generation started'
            ));
        } else {
            error_log('Failed to schedule process for ID: ' . $process_id);
            wp_send_json_error('Failed to schedule process');
        }
    } catch (Exception $e) {
        wp_send_json_error('Editing error: ' . $e->getMessage());
    }
}
add_action('wp_ajax_generate_ai_body', 'kk_ai_editor_ajax_generate_body');

/**
 * Background processing handler for AI content generation
 */
function kk_ai_editor_process_content_generation($process_id) {
    error_log('Starting content generation process for ID: ' . $process_id);
    
    $option_name = 'ai_gen_data_' . $process_id;
    $data = get_option($option_name);
    if (!$data) {
        error_log('No data found for process ID: ' . $process_id);
        return;
    }

    try {
        $api_key = sanitize_text_field(get_option('kk_ai_editor_api_key'));
        $openrouter_key = sanitize_text_field(get_option('kk_ai_editor_openrouter_key'));
        $model = get_option('kk_ai_editor_model', 'gpt-4o');
        $endpoint_type = kk_ai_editor_get_model_endpoint($model);
        if (empty($api_key) && $endpoint_type === 'openai') {
            throw new Exception('OpenAI API key not configured');
        }
        if (empty($openrouter_key) && $endpoint_type === 'openrouter') {
            throw new Exception('OpenRouter API key not configured');
        }
        error_log('Current step before processing: ' . $data['step']);
        //error_log('Current data state: ' . print_r($data, true));
        
        list($working_sys_prompt, $working_user_prompt) = kk_ai_editor_get_working_prompts();
        
        switch ($data['step']) {
            case 'intro':
                //error_log('Editing intro...');

                $pre_intro_content = '';
                if (preg_match('/^(.*?)(?=^[^\n]+\n-+\n)/ms', $data['pre_edit_content'], $intro_matches)) {
                    $pre_intro_content = trim($intro_matches[1]);
                } else if (!preg_match('/^[^\n]+\n-+\n/m', $data['pre_edit_content'])) {
                    // Only use entire content if there are no subheadings at all
                    $pre_intro_content = trim($data['pre_edit_content']);
                }

                // PREPARE EDIT PROMPT
                $prompt = $working_user_prompt . $pre_intro_content;
                
                //error_log('Edit prompt: ' . $prompt); // Debug log
                
                // GENERATE EDIT
                $edit1_gpt = '';
                if ($endpoint_type === 'openai')
                    $edit1_gpt = new KK_AI_Editor_OpenAI_Client($api_key, $model, KK_AI_MODEL_EDIT_TEMP, $working_sys_prompt);
                else
                    $edit1_gpt = new KK_AI_Editor_OpenRouter_Client($openrouter_key, $model, KK_AI_MODEL_EDIT_TEMP, $working_sys_prompt);
                $generated_edit = $edit1_gpt->generate_content($prompt);
                
                // Track usage stats
                $prompt_tokens = $edit1_gpt->get_last_prompt_tokens();
                $completion_tokens = $edit1_gpt->get_last_completion_tokens();
                $total_cost = $edit1_gpt->get_last_total_cost();
                //error_log("Edit intro - Prompt tokens: $prompt_tokens, Completion tokens: $completion_tokens, Cost: $$total_cost");
                if ($data['post_id'] > 0) {
                    $new_log = kk_ai_editor_append_to_usage_log($data['post_id'], "Edit intro - Prompt tokens: $prompt_tokens, Completion tokens: $completion_tokens, Cost: $$total_cost ($model)");
                    $new_totals = kk_ai_editor_update_usage_totals($data['post_id'], $prompt_tokens, $completion_tokens, $total_cost);
                    $new_totals['usage_log'] = $new_log;
                    $data['new_totals'] = $new_totals;
                }

                if (empty($generated_edit)) {
                    throw new Exception('Failed to generate introduction');
                }
                
                $data['content'] .= $generated_edit . "\n\n";
                
                // Parse sections - updated regex for dash-style headers
                // Do this to break up the content into subheads for the next sections
                if (preg_match_all('/^([^\n]+)\n-+\n(.*?)(?=\n[^\n]+\n-+|\z)/ms', $data['pre_edit_content'], $matches)) {
                    $data['sections'] = array_map(function($title, $content) {
                        return $title . "\n" . str_repeat('-', strlen($title)) . "\n" . $content;
                    }, $matches[1], $matches[2]);
                    
                    //error_log('Found sections: ' . print_r($data['sections'], true));
                } else {
                    error_log('No sections found in post: ' . $data['pre_edit_content']); // Debug log
                    throw new Exception('No sections found in post');
                }
                
                //error_log('Found ' . count($data['sections']) . ' sections');
                
                // Set next step, % progress
                $data['step'] = 'sections';
                $data['progress'] = 15;

                // Save progress
                if (!update_option($option_name, $data, false)) {
                    error_log('Failed to save progress after first edit. Data: ' . print_r($data, true));
                    throw new Exception('Failed to save progress after first edit');
                }
                break;

            case 'sections':
                error_log('Processing section ' . ($data['current_section'] + 1));
                if ($data['current_section'] < count($data['sections']) && $data['current_section'] < AI_EDIT_MAX_SECTIONS) {
                    $section = $data['sections'][$data['current_section']];
                    error_log('Current section content: ' . $section); // Debug log
                    
                    // PREPARE SECTION EDIT PROMPT
                    $prompt = $working_user_prompt . $section;
                    error_log('Section edit prompt: ' . $prompt); // Debug log

                    // GENERATE EDIT
                    $editn_gpt = '';
                    if ($endpoint_type === 'openai')
                        $editn_gpt = new KK_AI_Editor_OpenAI_Client($api_key, $model, KK_AI_MODEL_EDIT_TEMP, $working_sys_prompt);
                    else
                        $editn_gpt = new KK_AI_Editor_OpenRouter_Client($openrouter_key, $model, KK_AI_MODEL_EDIT_TEMP, $working_sys_prompt);
                    $section_content = $editn_gpt->generate_content($prompt);
                    
                    // Track usage stats
                    $prompt_tokens = $editn_gpt->get_last_prompt_tokens();
                    $completion_tokens = $editn_gpt->get_last_completion_tokens();
                    $total_cost = $editn_gpt->get_last_total_cost();
                    //error_log("Edit section " . ($data['current_section'] + 1) . " - Prompt tokens: $prompt_tokens, Completion tokens: $completion_tokens, Cost: $$total_cost");
                    if ($data['post_id'] > 0) {
                        $new_log = kk_ai_editor_append_to_usage_log($data['post_id'], "Edit section " . ($data['current_section'] + 1) . " - Prompt tokens: $prompt_tokens, Completion tokens: $completion_tokens, Cost: $$total_cost ($model)");
                        $new_totals = kk_ai_editor_update_usage_totals($data['post_id'], $prompt_tokens, $completion_tokens, $total_cost);
                        $new_totals['usage_log'] = $new_log;
                        $data['new_totals'] = $new_totals;
                    }

                    if (empty($section_content)) {
                        throw new Exception('Failed to generate section content');
                    }
                    
                    // ADD SECTION CONTENT TO THE POST
                    $data['content'] .= $section_content . "\n\n";
                    
                    $data['current_section']++;
                    $data['progress'] = round(15 + ($data['current_section'] / min(count($data['sections']), AI_EDIT_MAX_SECTIONS) * 80));
                    
                    if ($data['current_section'] >= count($data['sections']) || $data['current_section'] >= AI_EDIT_MAX_SECTIONS) {
                        $data['step'] = 'summary';
                    }
                }
                
                // Save progress with error checking
                $save_result = update_option($option_name, $data, false);
                if (!$save_result) {
                    error_log('Failed to save progress after section. Data size: ' . strlen(serialize($data)));
                    error_log('Data content: ' . print_r($data, true));
                    throw new Exception('Failed to save progress after section');
                }
                break;

            case 'summary':
                error_log('Final touches...');
                
                // Set next step, % progress, add new data
                $data['step'] = 'complete';
                $data['progress'] = 100;
                $data['status'] = 'complete';
                
                // Save final progress with error checking
                $save_result = update_option($option_name, $data, false);
                if (!$save_result) {
                    error_log('Failed to save progress after summary. Data size: ' . strlen(serialize($data)));
                    error_log('Data content: ' . print_r($data, true));
                    throw new Exception('Failed to save progress after summary');
                }
                
                error_log('Completed successfully.');

                break;
        }

        // Schedule next run if not complete
        if ($data['step'] !== 'complete') {
            error_log('Scheduling next run for process ID: ' . $process_id);
            wp_schedule_single_event(time(), 'kk_ai_editor_process_content_generation', array($process_id));
        } else {
            error_log('Process complete for ID: ' . $process_id);
            // Keep the data for 3 minutes after completion
            $data['expires_at'] = time() + 180; // 3 minutes
            update_option($option_name, $data, false);
            
            // Schedule cleanup
            wp_schedule_single_event(time() + 180, 'kk_ai_editor_cleanup_ai_generation_data', array($process_id));
        }

    } catch (Exception $e) {
        error_log('Error in content generation: ' . $e->getMessage());
        $data['status'] = 'error';
        $data['error'] = $e->getMessage();
        update_option($option_name, $data, false);
    }
}
add_action('kk_ai_editor_process_content_generation', 'kk_ai_editor_process_content_generation');

/**
 * AJAX handler for checking generation progress
 */
function kk_ai_editor_check_generation_progress() {
    // Add error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_generate_content_nonce')) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    if (!isset($_POST['process_id'])) {
        wp_send_json_error('No process ID provided');
        return;
    }
    
    $process_id = sanitize_text_field($_POST['process_id']);
    
    // Check if this is a sources generation process
    if (strpos($process_id, 'ai_sources_') === 0) {
        $option_name = 'ai_sources_data_' . $process_id;
    } else {
        $option_name = 'ai_gen_data_' . $process_id;
    }
    
    $data = get_option($option_name);
    
    if (!$data) {
        wp_send_json_error('Process not found or expired');
        return;
    }
    
    // Helper function to get progress message
    function kk_ai_editor_get_progress_message($step, $current_section = 0) {
        if (strpos($step, 'sources') === 0) {
            return 'Adding external sources...';
        }
        
        switch ($step) {
            case 'intro':
                return 'Starting editing...';
            case 'sections':
                return 'Editing section ' . ($current_section + 1) . '...';
            case 'summary':
                return 'Final touches...';
            case 'complete':
                return 'Edit complete!';
            default:
                return 'Processing...';
        }
    }
    
    // Get current totals from the stored data instead of post meta
    $new_totals = isset($data['new_totals']) ? $data['new_totals'] : null;

    wp_send_json_success(array(
        'status' => isset($data['status']) ? $data['status'] : 'processing',
        'progress' => isset($data['progress']) ? $data['progress'] : 0,
        'content' => isset($data['status']) && $data['status'] === 'complete' ? $data['content'] : '',
        'error' => isset($data['error']) ? $data['error'] : '',
        'message' => kk_ai_editor_get_progress_message(
            isset($data['step']) ? $data['step'] : '', 
            isset($data['current_section']) ? $data['current_section'] : 0
        ),
        'new_totals' => $new_totals ?? null
    ));
}
add_action('wp_ajax_check_generation_progress', 'kk_ai_editor_check_generation_progress');

/**
 * Add meta box to the post editing screen.
 */
function kk_ai_editor_ai_add_meta_box() {
    add_meta_box(
        'ai_content_generator',
        'AI Editor',
        'kk_ai_editor_meta_box_callback',
        'post',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes', 'kk_ai_editor_ai_add_meta_box' );

/**
 * Enqueue admin scripts for our meta box.
 */
function kk_ai_editor_enqueue_admin_scripts($hook) {
    // Load on post edit pages and plugin settings page
    if (!in_array($hook, ['post.php', 'post-new.php', 'toplevel_page_kk-ai-editor'])) {
        return;
    }
    
    wp_enqueue_style('ai-plugin-style', plugin_dir_url(__FILE__) . 'css/ai-plugin.css', array(), '1.0.0');
    wp_enqueue_script('marked', 'https://cdn.jsdelivr.net/npm/marked/marked.min.js', array(), '4.0.12', true);
    wp_enqueue_script('turndown', 'https://unpkg.com/turndown/dist/turndown.js', array(), '7.1.1', true);
    wp_enqueue_script('ai-plugin-script', plugin_dir_url(__FILE__) . 'js/ai-plugin.js', array('jquery', 'marked', 'turndown'), '1.0', true);
    wp_localize_script('ai-plugin-script', 'aiPlugin', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('ai_generate_content_nonce'),
    ));
}
add_action('admin_enqueue_scripts', 'kk_ai_editor_enqueue_admin_scripts');

/**
 * Register settings.
 */
function kk_ai_editor_register_settings() {
    // Register settings
    register_setting('kk_ai_editor_options_group', 'kk_ai_editor_api_key');
    register_setting('kk_ai_editor_options_group', 'kk_ai_editor_openrouter_key');
    // Register model setting with sanitization
    register_setting('kk_ai_editor_options_group', 'kk_ai_editor_model', [
        'type' => 'string',
        'sanitize_callback' => function($value) {
            $allowed = [
                'gpt-4o-2024-11-20',
                'gpt-4o',
                'gpt-4o-mini',
                'gpt-4.1',
                'gpt-4.1-mini',
                'anthropic/claude-3.7-sonnet',
                'google/gemini-2.0-flash-001',
                'google/gemini-2.5-flash-preview',
            ];
            return in_array($value, $allowed, true) ? sanitize_text_field($value) : 'gpt-4o';
        },
    ]);
    // Register prompt style setting with sanitization
    register_setting('kk_ai_editor_options_group', 'kk_ai_editor_prompt_style', [
        'type' => 'string',
        'sanitize_callback' => function($value) {
            $allowed = ['strict', 'loose', 'looser'];
            return in_array($value, $allowed, true) ? sanitize_text_field($value) : 'strict';
        },
    ]);

    // API Keys section
    add_settings_section(
        'kk_ai_editor_main_section', 
        'API Settings', 
        function() {
            echo '<p>Configure your API keys for different AI providers:</p>';
        }, 
        'kk-ai-editor'
    );
    
    // Add API key fields
    add_settings_field(
        'kk_ai_editor_api_key',
        'OpenAI API Key',
        'kk_ai_editor_openai_key_callback',
        'kk-ai-editor',
        'kk_ai_editor_main_section'
    );
    
    add_settings_field(
        'kk_ai_editor_openrouter_key',
        'OpenRouter API Key',
        'kk_ai_editor_openrouter_key_callback',
        'kk-ai-editor',
        'kk_ai_editor_main_section'
    );

    // Add model dropdown field
    add_settings_field(
        'kk_ai_editor_model',
        'LLM Model',
        'kk_ai_editor_model_dropdown_callback',
        'kk-ai-editor',
        'kk_ai_editor_main_section'
    );

    // Add prompt style dropdown field
    add_settings_field(
        'kk_ai_editor_prompt_style',
        'Editing Style',
        'kk_ai_editor_prompt_style_dropdown_callback',
        'kk-ai-editor',
        'kk_ai_editor_main_section'
    );

    add_settings_section(
        'kk_ai_editor_usage_section', 
        'Total Usage Statistics', 
        'kk_ai_editor_plugin_render_usage_stats', 
        'kk-ai-editor'
    );
}
add_action( 'admin_init', 'kk_ai_editor_register_settings' );

/**
 * AJAX handler for recalculating totals
 */
function kk_ai_editor_recalculate_totals() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
        return;
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_generate_content_nonce')) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    global $wpdb;

    // Initialize totals
    $total_prompt_tokens = 0;
    $total_completion_tokens = 0;
    $total_cost = 0;

    // Get all posts with AI usage data in batches
    $batch_size = 50;
    $offset = 0;

    do {
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key IN ('kk_ai_editor_total_prompt_tokens', 'kk_ai_editor_total_completion_tokens', 'kk_ai_editor_total_total_cost')
            LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        ));

        if (empty($post_ids)) {
            break;
        }

        foreach ($post_ids as $post_id) {
            $prompt_tokens = (int)get_post_meta($post_id, 'kk_ai_editor_total_prompt_tokens', true);
            $completion_tokens = (int)get_post_meta($post_id, 'kk_ai_editor_total_completion_tokens', true);
            $cost = (float)get_post_meta($post_id, 'kk_ai_editor_total_total_cost', true);

            $total_prompt_tokens += $prompt_tokens;
            $total_completion_tokens += $completion_tokens;
            $total_cost += $cost;
        }

        $offset += $batch_size;
    } while (true);

    // Store the totals in wp_options
    update_option('kk_ai_editor_total_prompt_tokens', $total_prompt_tokens);
    update_option('kk_ai_editor_total_completion_tokens', $total_completion_tokens);
    update_option('kk_ai_editor_total_cost', $total_cost);

    wp_send_json_success(array(
        'prompt_tokens' => number_format($total_prompt_tokens),
        'completion_tokens' => number_format($total_completion_tokens),
        'cost' => number_format($total_cost, 4)
    ));
}
add_action('wp_ajax_recalculate_totals', 'kk_ai_editor_recalculate_totals');

/**
 * Add admin menu.
 */
function kk_ai_editor_add_admin_menu() {
    $icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
        file_get_contents(plugin_dir_path(__FILE__) . 'assets/ai-editor.svg')
    );

    add_menu_page( 
        'AI Editor Settings', 
        'AI Editor', 
        'manage_options', 
        'kk-ai-editor', 
        'kk_ai_editor_plugin_settings_page',
        $icon_svg
    );
}
add_action( 'admin_menu', 'kk_ai_editor_add_admin_menu' );

/**
 * Add meta box below the editor.
 */
function kk_ai_editor_add_below_editor_meta_box() {
    add_meta_box(
        'ai_content_generator_below',
        'AI Editor Log',
        'kk_ai_editor_below_editor_meta_box_callback',
        'post',
        'normal', // This places it below the editor
        'high'    // High priority to place it above other meta boxes
    );
}
add_action('add_meta_boxes', 'kk_ai_editor_add_below_editor_meta_box');

// Final actions to add

add_action('wp_scheduled_delete', 'kk_ai_editor_cleanup_generation_data');

add_action('kk_ai_editor_cleanup_ai_generation_data', 'kk_ai_editor_cleanup_ai_generation_data');
