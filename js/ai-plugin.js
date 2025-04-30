// Modal lock for both Classic and Block Editor
window.myPluginShowLockModal = function(message = 'Processing, please wait...') {
    if (document.getElementById('my-plugin-lock-modal')) return;
    const modal = document.createElement('div');
    modal.id = 'my-plugin-lock-modal';
    modal.innerHTML = `
        <div class="my-plugin-lock-modal-content">
            <div class="my-plugin-spinner"></div>
            <p class="my-plugin-modal-status">${message}</p>
        </div>
    `;
    document.body.appendChild(modal);
};

window.myPluginHideLockModal = function() {
    const modal = document.getElementById('my-plugin-lock-modal');
    if (modal) modal.remove();
};

// Helper to update both sidebar and modal status
function updateAIStatus(message) {
    jQuery('#ai_generate_status').text(message);
    var modalStatus = document.querySelector('.my-plugin-modal-status');
    if (modalStatus) {
        modalStatus.textContent = message;
    }
}

jQuery(document).ready(function($) {
    $('#ai_generate_body_button').on('click', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var originalText = $btn.html();  // Store original text
        var $status = $('#ai_generate_status');
        
        // Clear previous status
        $status.text('');
        
        // Disable button and show spinner
        $btn.prop('disabled', true)
            .html('<span class="dashicons dashicons-update spin"></span> Editing...')
            .data('original-text', originalText);  // Store for later use

        // Show modal lock
        if (window.myPluginShowLockModal) {
            window.myPluginShowLockModal('Processing post, please wait...');
        }

        // Get current editor content and title
        var currentContent = '';
        var postTitle = '';
        
        if ($('#content').length) {
            currentContent = $('#content').val();
            postTitle = $('#title').val();
        } else if (wp.data && wp.data.select('core/editor')) {
            currentContent = wp.data.select('core/editor').getEditedPostContent();
            postTitle = wp.data.select('core/editor').getEditedPostAttribute('title');
        }

        if (!postTitle) {
            $status.text('Error: Post title is required');
            $btn.prop('disabled', false).html(originalText);
            return;
        }

        // Convert HTML to Markdown
        var turndownService = new TurndownService();
        var markdownContent = turndownService.turndown(currentContent);

        console.log('Sending request with:', {
            title: postTitle,
            content: markdownContent
        });

        // Start the generation
        $.ajax({
            url: aiPlugin.ajaxurl,
            method: 'POST',
            data: {
                action: 'generate_ai_body',
                nonce: aiPlugin.nonce,
                content: markdownContent,
                title: postTitle,
                post_id: wp.data.select('core/editor').getCurrentPostId()
            },
            success: function(response) {
                console.log('Response:', response);
                if (response.success) {
                    var processId = response.data.process_id;
                    checkProgress(processId);
                } else {
                    updateAIStatus('Error: ' + (response.data || 'Unknown error'));
                    $btn.prop('disabled', false).html(originalText);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', {xhr: xhr, status: status, error: error});
                updateAIStatus('AJAX error: ' + (error || 'Unknown error') + ' (Status: ' + status + ')');
                $btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // Move checkProgress function outside of button handlers so it can be shared
    function checkProgress(processId) {
        $.ajax({
            url: aiPlugin.ajaxurl,
            method: 'POST',
            data: {
                action: 'check_generation_progress',
                nonce: aiPlugin.nonce,
                process_id: processId
            },
            success: function(response) {
                console.log('Progress response:', response);
                if (response.success) {
                    if (response.data.status === 'complete') {
                        var generatedContent = response.data.content;
                        
                        // For Classic Editor
                        if ($('#content').length) {
                            $('#content').val(generatedContent);
                        } 
                        // For Gutenberg
                        else if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                            var htmlContent = marked.parse(generatedContent);
                            var blocks = wp.blocks.rawHandler ? 
                                wp.blocks.rawHandler({ HTML: htmlContent }) :
                                wp.blocks.parse(htmlContent);

                            if (blocks && blocks.length > 0) {
                                wp.data.dispatch('core/block-editor').resetBlocks(blocks);
                            }
                        }

                        updateAIStatus('Content edited successfully.');
                        
                        if (response.data.new_totals) {
                            updateCustomFieldsInUI(response.data.new_totals);
                        }

                        // Reset the button
                        $('#ai_generate_body_button')
                            .prop('disabled', false)
                            .html('<span class="dashicons dashicons-editor-paragraph"></span> Edit Post');

                        // Hide modal lock
                        if (window.myPluginHideLockModal) {
                            window.myPluginHideLockModal();
                        }
                    } else if (response.data.status === 'error') {
                        updateAIStatus('Error: ' + response.data.error);
                        $('.button').prop('disabled', false)
                            .html(function() {
                                return $(this).data('original-text');
                            });

                        // Hide modal lock
                        if (window.myPluginHideLockModal) {
                            window.myPluginHideLockModal();
                        }
                    } else {
                        // Update progress
                        updateAIStatus(response.data.message + ' (' + response.data.progress + '%)');
                        // Continue polling
                        setTimeout(function() {
                            checkProgress(processId);
                        }, 2000);
                    }
                } else {
                    updateAIStatus('Error: ' + (response.data || 'Unknown error'));
                    $('.button').prop('disabled', false)
                        .html(function() {
                            return $(this).data('original-text');
                        });

                    // Hide modal lock
                    if (window.myPluginHideLockModal) {
                        window.myPluginHideLockModal();
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Progress check error:', {xhr: xhr, status: status, error: error});
                updateAIStatus('Error checking progress: ' + error);
                $('.button').prop('disabled', false)
                    .html(function() {
                        return $(this).data('original-text');
                    });

                // Hide modal lock
                if (window.myPluginHideLockModal) {
                    window.myPluginHideLockModal();
                }
            }
        });
    }

    // Format log entries
    const formatLogEntries = function() {
        const logBox = $('.ai-log-section:contains("Usage log") .ai-log-content-box');
        if (!logBox.length) return;

        // Get the HTML content
        const content = logBox.html();
        
        // Skip if the content box is empty or contains the "No usage data" message
        if (!content || content.includes('No usage data available yet')) return;
        
        // Split by <br> and process each line
        const formattedContent = content.split('<br>').map(line => {
            line = line.trim();
            if (!line) return '';
            
            return line;
        }).join('<br>');
        
        logBox.html(formattedContent);
    };

    // Run on page load
    formatLogEntries();

    $('#ai_recalculate_totals').on('click', function() {
        const $button = $(this);
        const $status = $('#ai_recalculate_status');
        
        $button.prop('disabled', true);
        $button.find('.dashicons').addClass('spin');
        $status.html('<span class="ai-status-calculating">⏳ Calculating totals from all posts...</span>');
        
        $.ajax({
            url: aiPlugin.ajaxurl,
            type: 'POST',
            data: {
                action: 'recalculate_totals',
                nonce: aiPlugin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update the displayed values
                    $('.ai-stat-value').each(function() {
                        const $stat = $(this);
                        if ($stat.closest('.ai-stat-box').find('h4').text().includes('Total prompt')) {
                            $stat.text(response.data.prompt_tokens);
                        } else if ($stat.closest('.ai-stat-box').find('h4').text().includes('Total completion')) {
                            $stat.text(response.data.completion_tokens);
                        } else {
                            $stat.text('$' + response.data.cost);
                        }
                    });
                    $status.html('<span class="ai-status-success">✓ Totals updated</span>');
                } else {
                    $status.html('<span class="ai-status-error">❌ Error updating totals</span>');
                }
            },
            error: function() {
                $status.html('<span class="ai-status-error">❌ Error updating totals</span>');
            },
            complete: function() {
                $button.prop('disabled', false);
                $button.find('.dashicons').removeClass('spin');
                setTimeout(() => $status.html(''), 3000);
            }
        });
    });
});

// Add this function at the top level, outside of jQuery(document).ready
function updateCustomFieldsInUI(newTotals) {
    // Update the stats in the meta box below editor
    document.querySelectorAll('.ai-stat-value').forEach((element, index) => {
        if (index === 0) {
            element.textContent = newTotals.prompt_tokens.toLocaleString();
        } else if (index === 1) {
            element.textContent = newTotals.completion_tokens.toLocaleString();
        } else if (index === 2) {
            element.textContent = '$' + newTotals.total_cost.toFixed(4);
        }
    });

    // Update the usage log display in the plugin's meta box
    if (newTotals.usage_log) {
        const logContentBox = document.querySelector('.ai-log-content-box');
        if (logContentBox) {
            logContentBox.innerHTML = newTotals.usage_log.replace(/\n/g, '<br>');
            logContentBox.classList.remove('ai-log-empty-field');
            const emptyMessage = logContentBox.querySelector('.ai-log-empty');
            if (emptyMessage) {
                emptyMessage.remove();
            }
        }
    }

    // Update values in the Custom Fields meta box if it exists
    const theList = document.getElementById('the-list');
    if (!theList) {
        // Custom fields meta box is not present, skip this part
        return;
    }

    // Update the custom fields if they exist
    theList.querySelectorAll('tr').forEach(row => {
        const keyInput = row.querySelector('input[name^="meta"][name$="[key]"]');
        const valueTextarea = row.querySelector('textarea[name^="meta"][name$="[value]"]');
        
        if (!keyInput || !valueTextarea) {
            return; // Skip if either element is not found
        }

        switch (keyInput.value) {
            case 'kk_ai_editor_total_prompt_tokens':
                valueTextarea.value = newTotals.prompt_tokens;
                break;
            case 'kk_ai_editor_total_completion_tokens':
                valueTextarea.value = newTotals.completion_tokens;
                break;
            case 'kk_ai_editor_total_total_cost':
                valueTextarea.value = newTotals.total_cost;
                break;
            case 'kk_ai_editor_usage_log':
                if (newTotals.usage_log) {
                    valueTextarea.value = newTotals.usage_log;
                }
                break;
        }
    });
}