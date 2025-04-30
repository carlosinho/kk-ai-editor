<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class KK_AI_Editor_OpenAI_Client {
    // MAIN SETTINGS
    private $api_key;
    private $endpoint = 'https://api.openai.com/v1/chat/completions';
    private $model;
    private $max_tokens = 4096;
    private $temperature = 1;
    private $system_prompt = '';

    // TOKEN TRACKING
    private $last_prompt_tokens = 0;
    private $last_completion_tokens = 0;
    private $last_total_tokens = 0;
    private $last_total_cost = 0.0;

    // MODEL PRICING ($ per 1M tokens)
    private $model_pricing = [
        'gpt-4.1' => [
            'input' => 2.00,
            'output' => 8.00
        ],
        'gpt-4.1-mini' => [
            'input' => 0.40,
            'output' => 1.60
        ],
        'chatgpt-4o-latest' => [
            'input' => 5.00,
            'output' => 15.00
        ],
        'gpt-4o' => [
            'input' => 2.50,
            'output' => 10.00
        ],
        'gpt-4o-2024-11-20' => [
            'input' => 2.50,
            'output' => 10.00
        ],
        'gpt-4o-mini' => [
            'input' => 0.15,
            'output' => 0.60
        ]
    ];

    // System prompt for continuous API calls on long outputs
    //private $continuous_prompt = '[system: Please continue your response from exactly where you left off, maintaining the same context and format. Your response will be concatenated with the previous part.]';
    private $continuous_prompt = '[system: Kontynuuj odpowiedź dokładnie od miejsca gdzie skończyłeś, zachowując ten sam kontekst i format. Twoja odpowiedź zostanie dołączona do poprzedniej części.]';
    
    public function __construct($api_key, $model = 'gpt-4o', $temperature = 1, $system_prompt = '') {
        $this->api_key = $api_key;
        $this->model = $model;
        $this->temperature = $temperature;
        $this->system_prompt = $system_prompt;
    }

    public function set_system_prompt($system_prompt) {
        $this->system_prompt = $system_prompt;
    }

    /**
     * Generates content using the OpenAI API
     * 
     * @param string $prompt The prompt to generate content from
     * @return string Generated content or error message
     */
    public function generate_content($prompt) {
        if (empty($this->api_key)) {
            return 'API key not set. Please configure the API key in the AI Plugin Settings.';
        }

        // Reset token counters and cost at the start
        $this->last_prompt_tokens = 0;
        $this->last_completion_tokens = 0;
        $this->last_total_tokens = 0;
        $this->last_total_cost = 0.0;

        $messages = [];
        if (!empty($this->system_prompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->system_prompt,
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $response = wp_remote_post($this->endpoint, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode(array(
                'model'       => $this->model,
                'messages'    => $messages,
                'max_tokens'  => $this->max_tokens,
                'temperature' => $this->temperature,
            )),
        ));

        if (is_wp_error($response)) {
            return 'Error: ' . $response->get_error_message();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Store token usage stats and calculate cost
        if (isset($body['usage'])) {
            $this->last_prompt_tokens = $body['usage']['prompt_tokens'] ?? 0;
            $this->last_completion_tokens = $body['usage']['completion_tokens'] ?? 0;
            $this->last_total_tokens = $body['usage']['total_tokens'] ?? 0;
            
            // Calculate cost based on model pricing
            if (isset($this->model_pricing[$this->model])) {
                $this->last_total_cost = 
                    ($this->last_prompt_tokens / 1000000 * $this->model_pricing[$this->model]['input']) +
                    ($this->last_completion_tokens / 1000000 * $this->model_pricing[$this->model]['output']);
            }
        }

        if (!isset($body['choices']) || !is_array($body['choices']) || empty($body['choices'])) {
            $error_message = isset($body['error']['message'])
                ? $body['error']['message']
                : 'Unexpected API response: ' . print_r($body, true);
            return 'Error: ' . $error_message;
        }

        return isset($body['choices'][0]['message']['content'])
            ? trim($body['choices'][0]['message']['content'])
            : '';
    }

    /**
     * Generates content with continuous completion if the response gets cut off
     * 
     * @param string $prompt Initial prompt
     * @param int $max_output_tokens Optional. Maximum tokens for each response chunk
     * @return string Generated content or error message
     */
    public function generate_content_continuous($prompt, $max_output_tokens = null) {
        if (empty($this->api_key)) {
            return 'API key not set. Please configure the API key in the AI Plugin Settings.';
        }

        // Reset token counters and cost at the start
        $this->last_prompt_tokens = 0;
        $this->last_completion_tokens = 0;
        $this->last_total_tokens = 0;
        $this->last_total_cost = 0.0;

        // Check if the max_output_tokens is set, if not, use the default max_tokens
        $max_output_tokens = $max_output_tokens ?? $this->max_tokens;
        
        $messages = [];
        if (!empty($this->system_prompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->system_prompt,
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];
        
        $full_response = '';
        $is_completed = false;
        $attempt = 0;
        $max_attempts = 5; // Limits the number of attempts to generate the complete response

        while (!$is_completed && $attempt < $max_attempts) {
            $attempt++;
            error_log("Attempt $attempt of continuous generation");
            
            try {
                $response = wp_remote_post($this->endpoint, [
                    'timeout' => 300, // Increase timeout to 5 minutes
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->api_key,
                        'Content-Type'  => 'application/json',
                    ],
                    'body' => json_encode([
                        'model' => $this->model,
                        'messages' => $messages,
                        'max_tokens' => $max_output_tokens,
                        'temperature' => $this->temperature,
                    ])
                ]);

                if (is_wp_error($response)) {
                    error_log('API error: ' . $response->get_error_message());
                    return 'Error: ' . $response->get_error_message();
                }

                $body = json_decode(wp_remote_retrieve_body($response), true);
                error_log('API response: ' . print_r($body, true));

                // Accumulate token usage stats and calculate cost
                if (isset($body['usage'])) {
                    $this->last_prompt_tokens += $body['usage']['prompt_tokens'] ?? 0;
                    $this->last_completion_tokens += $body['usage']['completion_tokens'] ?? 0;
                    $this->last_total_tokens += $body['usage']['total_tokens'] ?? 0;
                    
                    // Calculate and accumulate cost based on model pricing
                    if (isset($this->model_pricing[$this->model])) {
                        $this->last_total_cost += 
                            ($body['usage']['prompt_tokens'] / 1000000 * $this->model_pricing[$this->model]['input']) +
                            ($body['usage']['completion_tokens'] / 1000000 * $this->model_pricing[$this->model]['output']);
                    }
                }

                if (!isset($body['choices']) || !is_array($body['choices']) || empty($body['choices'])) {
                    $error_message = isset($body['error']['message'])
                        ? $body['error']['message']
                        : 'Unexpected API response: ' . print_r($body, true);
                    error_log('API error: ' . $error_message);
                    return 'Error: ' . $error_message;
                }

                $choice = $body['choices'][0];
                $content = isset($choice['message']['content']) ? trim($choice['message']['content']) : '';
                
                if (empty($content)) {
                    error_log('Empty content received');
                    return 'Error: Empty response content';
                }

                // Add the assistant's response to the full response
                $full_response .= $content;
                error_log("Current response length: " . strlen($full_response));

                //$response_tokens = $this->count_tokens([['role' => 'assistant', 'content' => $content]]); // Count the tokens for the assistant's response
                //error_log('Token count for response no.'.$attempt.': '.$response_tokens);

                // Add the assistant's response to the messages
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $content
                ];
                
                // Check if the response is complete
                $appears_complete = 
                    //(strpos($content, 'Zapraszam do komentowania. Czekam na Wasze opinie!') !== false) || // Found the marker
                    (strlen($full_response) >= 40000) ||               // Safety limit
                    ($choice['finish_reason'] === 'length');           // Response was cut off, need to continue

                // If response was cut off or doesn't seem complete, continue
                if (!$appears_complete) {
                    error_log("Response seems incomplete, continuing...");
                    $messages[] = [
                        'role' => 'user',
                        'content' => $this->continuous_prompt
                    ];
                    continue;
                }

                // Token counting and debug
                //error_log('OpenAI continuous generation: Attempt '.$attempt.' of '.$max_attempts.', Current length: '.strlen($full_response).' chars');
                //error_log('This is the last run.');
                //error_log('Messages array: ' . print_r($messages, true));
                //$total_tokens = $this->count_tokens($messages);
                //error_log('Total token count for whole messages array: '.$total_tokens);

                // If we got here, it's complete
                $is_completed = true;

            } catch (Exception $e) {
                error_log('Exception in continuous generation: ' . $e->getMessage());
                return 'Error: ' . $e->getMessage();
            }
        }

        if (!$is_completed && $attempt >= $max_attempts) {
            error_log('Max attempts reached without completion');
            return $full_response . "\n\nWarning: Response may be incomplete due to length limitations.";
        }

        return trim($full_response);
    }

    /**
     * Counts tokens for a given array of messages
     * 
     * What this actually does is sends a completely new prompt to OpenAI, but limits the response 
     * to just 1 token. Then, it simply checks the 'usage' field that's in any API response. 
     * So it basically sends the first response originally gotten from OpenAI back to OpenAI 
     * so that it can see the tokens used. It's a good first effort but this function on its own 
     * is going to create additional costs.
     * 
     * @param array $messages Array of message objects with 'role' and 'content'
     * @return int|false Returns token count or false on error
     */
    public function count_tokens($messages) {
        if (empty($this->api_key)) {
            return false;
        }

        $response = wp_remote_post($this->endpoint, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => 1
            ])
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return isset($body['usage']['prompt_tokens']) 
            ? $body['usage']['prompt_tokens']
            : false;
    }

    // Add new getter functions for token usage and cost
    public function get_last_prompt_tokens() {
        return $this->last_prompt_tokens;
    }

    public function get_last_completion_tokens() {
        return $this->last_completion_tokens;
    }

    public function get_last_total_tokens() {
        return $this->last_total_tokens;
    }

    public function get_last_total_cost() {
        return $this->last_total_cost;
    }
}

class KK_AI_Editor_OpenRouter_Client {
    // MAIN SETTINGS
    private $api_key;
    private $endpoint = 'https://openrouter.ai/api/v1/chat/completions';
    private $model;
    private $temperature = 1;
    private $system_prompt = '';
    //private $max_tokens = 4096;

    // USAGE TRACKING
    private $last_prompt_tokens = 0;
    private $last_completion_tokens = 0;
    private $last_total_tokens = 0;
    private $last_total_cost = 0.0;

    // MODEL PRICING ($ per 1M tokens)
    private $model_pricing = [
        'openai/o3-mini' => [
            'input' => 1.10,
            'output' => 4.40
        ],
        'anthropic/claude-3.5-sonnet' => [
            'input' => 3.00,
            'output' => 15.00
        ],
        'anthropic/claude-3.7-sonnet' => [
            'input' => 3.00,
            'output' => 15.00
        ],
        'google/gemini-2.0-flash-001' => [
            'input' => 0.10,
            'output' => 0.40
        ],
        'google/gemini-2.5-pro-preview-03-25' => [
            'input' => 1.25,
            'output' => 10.00
        ],
        'perplexity/sonar' => [
            'input' => 1.00,
            'output' => 1.00
        ],
        'perplexity/llama-3.1-sonar-large-128k-online' => [
            'input' => 1.00,
            'output' => 1.00
        ]
    ];

    public function __construct($api_key, $model = 'perplexity/sonar', $temperature = 1, $system_prompt = '') {
        $this->api_key = $api_key;
        $this->model = $model;
        $this->temperature = $temperature;
        $this->system_prompt = $system_prompt;
    }

    public function set_system_prompt($system_prompt) {
        $this->system_prompt = $system_prompt;
    }

    /**
     * Fetch usage statistics for a specific generation
     * 
     * @param string $generation_id The ID of the generation to fetch stats for
     * @return bool Whether the stats were successfully fetched and stored
     */
    private function fetch_generation_stats($generation_id) {
        if (empty($generation_id)) {
            error_log('OpenRouter: Empty generation ID');
            return false;
        }

        error_log('OpenRouter: Fetching stats for generation ' . $generation_id);

        $response = wp_remote_get('https://openrouter.ai/api/v1/generation?id=' . $generation_id, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('OpenRouter stats fetch error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        error_log('OpenRouter stats response: ' . print_r($body, true));

        if (isset($body['data'])) {
            $this->last_prompt_tokens = $body['data']['tokens_prompt'] ?? 0;
            $this->last_completion_tokens = $body['data']['tokens_completion'] ?? 0;
            $this->last_total_tokens = ($this->last_prompt_tokens + $this->last_completion_tokens);
            $this->last_total_cost = $body['data']['total_cost'] ?? 0.0;
            
            error_log('OpenRouter stats fetched successfully:');
            error_log('- Prompt tokens: ' . $this->last_prompt_tokens);
            error_log('- Completion tokens: ' . $this->last_completion_tokens);
            error_log('- Total cost: $' . $this->last_total_cost);
            return true;
        }

        error_log('OpenRouter: No stats data in response');
        return false;
    }

    /**
     * Generates content using the OpenRouter API
     * 
     * @param string $prompt The prompt to generate content from
     * @return string Generated content or error message
     */
    public function generate_content($prompt) {
        if (empty($this->api_key)) {
            return 'API key not set. Please configure the API key in the AI Plugin Settings.';
        }

        // Reset token counters and cost at the start
        $this->last_prompt_tokens = 0;
        $this->last_completion_tokens = 0;
        $this->last_total_tokens = 0;
        $this->last_total_cost = 0.0;

        error_log('OpenRouter: Sending request to ' . $this->endpoint);
        error_log('OpenRouter: Using model ' . $this->model);

        $messages = [];
        if (!empty($this->system_prompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->system_prompt,
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $response = wp_remote_post($this->endpoint, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode(array(
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => $this->temperature,
                //'max_tokens'  => $this->max_tokens,
            )),
        ));

        if (is_wp_error($response)) {
            error_log('OpenRouter error: ' . $response->get_error_message());
            return 'Error: ' . $response->get_error_message();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        error_log('OpenRouter response: ' . print_r($body, true));

        // Fetch stats - using the separate call to OpenRouter to fetch the actual costs. This proved not reliable since OpenRouter often can't find the ID of the previous request.
        /*if (isset($body['id'])) {
            usleep(1000); // Add delay so that the stats get time to propagate at OpenRouter
            $this->fetch_generation_stats($body['id']);
        }*/
        // Track usage from response
        if (isset($body['usage'])) {
            $this->last_prompt_tokens = $body['usage']['prompt_tokens'] ?? 0;
            $this->last_completion_tokens = $body['usage']['completion_tokens'] ?? 0;
            $this->last_total_tokens = $body['usage']['total_tokens'] ?? 0;
            
            // Calculate cost if we have pricing for this model
            if (isset($this->model_pricing[$this->model])) {
                $this->last_total_cost = 
                    ($this->last_prompt_tokens / 1000000 * $this->model_pricing[$this->model]['input']) +
                    ($this->last_completion_tokens / 1000000 * $this->model_pricing[$this->model]['output']);
            }
            
            //error_log('OpenRouter usage tracked:');
            //error_log('- Prompt tokens: ' . $this->last_prompt_tokens);
            //error_log('- Completion tokens: ' . $this->last_completion_tokens);
            //error_log('- Total cost: $' . $this->last_total_cost);
        }

        if (!isset($body['choices']) || !is_array($body['choices']) || empty($body['choices'])) {
            $error_message = isset($body['error']['message'])
                ? $body['error']['message']
                : 'Unexpected API response: ' . print_r($body, true);
            return 'Error: ' . $error_message;
        }

        return isset($body['choices'][0]['message']['content'])
            ? trim($body['choices'][0]['message']['content'])
            : '';
    }

    /**
     * Generates content with sources using the OpenRouter API
     * 
     * @param string $prompt The prompt to generate content from
     * @return array Array containing generated content and citations
     */
    public function generate_content_with_sources($prompt) {
        if (empty($this->api_key)) {
            return ['content' => 'API key not set.', 'citations' => []];
        }

        $messages = [];
        if (!empty($this->system_prompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->system_prompt,
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $response = wp_remote_post($this->endpoint, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode(array(
                'model'       => $this->model,
                'messages'    => $messages,
            )),
        ));

        if (is_wp_error($response)) {
            return [
                'content' => 'Error: ' . $response->get_error_message(),
                'citations' => []
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Fetch stats
        /*if (isset($body['id'])) {
            usleep(1000); // Add delay so that the stats get time to propagate at OpenRouter
            $this->fetch_generation_stats($body['id']);
        }*/
        // Track usage from response
        if (isset($body['usage'])) {
            $this->last_prompt_tokens = $body['usage']['prompt_tokens'] ?? 0;
            $this->last_completion_tokens = $body['usage']['completion_tokens'] ?? 0;
            $this->last_total_tokens = $body['usage']['total_tokens'] ?? 0;
            
            // Calculate cost if we have pricing for this model
            if (isset($this->model_pricing[$this->model])) {
                $this->last_total_cost = 
                    ($this->last_prompt_tokens / 1000000 * $this->model_pricing[$this->model]['input']) +
                    ($this->last_completion_tokens / 1000000 * $this->model_pricing[$this->model]['output']);
            }
            
            //error_log('OpenRouter usage tracked:');
            //error_log('- Prompt tokens: ' . $this->last_prompt_tokens);
            //error_log('- Completion tokens: ' . $this->last_completion_tokens);
            //error_log('- Total cost: $' . $this->last_total_cost);
        }

        if (!isset($body['choices']) || !is_array($body['choices']) || empty($body['choices'])) {
            $error_message = isset($body['error']['message'])
                ? $body['error']['message']
                : 'Unexpected API response: ' . print_r($body, true);
            return [
                'content' => 'Error: ' . $error_message,
                'citations' => []
            ];
        }

        return [
            'content' => isset($body['choices'][0]['message']['content']) 
                ? trim($body['choices'][0]['message']['content']) 
                : '',
            'citations' => isset($body['citations']) ? $body['citations'] : []
        ];
    }

    // Add getter methods for the stats
    public function get_last_prompt_tokens() {
        return $this->last_prompt_tokens;
    }

    public function get_last_completion_tokens() {
        return $this->last_completion_tokens;
    }

    public function get_last_total_tokens() {
        return $this->last_total_tokens;
    }

    public function get_last_total_cost() {
        return $this->last_total_cost;
    }
}
