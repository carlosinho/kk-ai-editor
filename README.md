# AI Editor at Your Service

**Your friendly AI editor for WordPress.**

Automatically polishes your post content using OpenAI or OpenRouter models, directly from the post editor.

---

## Features

- One-click AI-powered editing for posts in the Block Editor
- Works with posts 3000 words+
- Choose between strict, loose, or creative editing prompt styles
- Supports OpenAI and OpenRouter models (configurable)
- Usage statistics and cost tracking per post
- Secure AJAX background processing (with progress feedback)
- Settings page for API keys, model, and prompt style

---

## Installation

1. Upload the plugin files to the `/wp-content/plugins/` directory, or upload via the WordPress plugin screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Settings → AI Editor** to configure your API keys and preferences.

---

## Configuration

- **API Keys:**  
  Enter your OpenAI and/or OpenRouter API key in the plugin settings.
- **Model Selection:**  
  Choose your preferred model (e.g., `gpt-4o`, or OpenRouter models).
- **Prompt Style:**  
  Select strict, loose, or creative editing style to control how much the AI changes your text.

---

## Usage

1. Edit or create a post.
2. Click the **"Edit Post"** button in the editor sidebar.
3. The plugin will process your content in the background, showing progress and status.
4. When complete, your post content will be replaced with the AI-edited version.
5. View usage statistics and cost in the post meta box below the editor.

---

## Troubleshooting

- **API Errors:**  
  Ensure your API key is valid and has sufficient quota.
- **No Edit Button:**  
  Make sure you have the `edit_posts` capability and are using the block editor.
- **Content Not Updating:**  
  Check browser console for JavaScript errors and ensure AJAX is not blocked.

Enable `WP_DEBUG` and `WP_DEBUG_LOG` for detailed error messages.

---

## Contributing

Pull requests and issues are welcome!

---

## License

GPL-2.0  
Copyright (c) Karol K of [WP Workshop](https://wpwork.shop/)

---

## Credits

- [OpenAI](https://openai.com/)
- [OpenRouter](https://openrouter.ai/)
- [WordPress](https://wordpress.org/)

---

## Screenshots

- TBA

---

## Disclaimer

This plugin uses third-party AI APIs. Please review their terms and privacy policies before use.
