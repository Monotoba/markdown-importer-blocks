<?php
/**
 * Plugin Name: Markdown Importer Blocks
 * Plugin URI:  https://example.invalid/markdown-importer-blocks
 * Description: Adds a Markdown Importer block that can paste, upload, or fetch Markdown and delegate Mermaid, math, and code rendering to companion content block plugins when available.
 * Version:     1.2.0
 * Author:      Randall Morgan / ChatGPT
 * License:     MIT
 * License URI: https://opensource.org/license/mit/
 * Text Domain: markdown-importer-blocks
 * Requires at least: 7.0
 * Requires PHP: 8.0
 *
 * @package MarkdownImporterBlocks
 */

namespace Markdown_Importer_Blocks;

defined( 'ABSPATH' ) || exit;

define( 'MIB_VERSION', '1.2.0' );
define( 'MIB_PLUGIN_FILE', __FILE__ );
define( 'MIB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MIB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MIB_REST_NAMESPACE', 'markdown-importer-blocks/v1' );
define( 'MIB_MAX_MARKDOWN_BYTES', 1048576 ); // 1 MiB.

require_once MIB_PLUGIN_DIR . 'includes/class-markdown-parser.php';

/**
 * Register the Markdown block.
 */
function register_blocks(): void {
	register_block_type(
		MIB_PLUGIN_DIR . 'blocks/markdown',
		array(
			'render_callback' => __NAMESPACE__ . '\\render_markdown_importer_block',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_blocks' );

/**
 * Register editor REST endpoints.
 */
function register_rest_routes(): void {
	register_rest_route(
		MIB_REST_NAMESPACE,
		'/render',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_render_markdown',
			'permission_callback' => __NAMESPACE__ . '\\can_edit_posts',
			'args'                => array(
				'markdown' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => static fn( $value ): string => mib_sanitize_markdown_input( (string) $value ),
				),
			),
		)
	);

	register_rest_route(
		MIB_REST_NAMESPACE,
		'/fetch-url',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_fetch_markdown_url',
			'permission_callback' => __NAMESPACE__ . '\\can_edit_posts',
			'args'                => array(
				'url' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );

/**
 * Add block/editor settings exposed to JavaScript.
 */
function enqueue_editor_settings(): void {
	$asset_handle = 'markdown-importer-blocks-markdown-editor-script';

	if ( wp_script_is( $asset_handle, 'registered' ) || wp_script_is( $asset_handle, 'enqueued' ) ) {
		wp_add_inline_script(
			$asset_handle,
			'window.MarkdownImporterBlocksSettings = ' . wp_json_encode(
				array(
					'restNamespace'      => MIB_REST_NAMESPACE,
					'maxBytes'           => MIB_MAX_MARKDOWN_BYTES,
					'allowedFiles'       => array( '.md', '.markdown', '.mdown', '.mkd', '.txt' ),
					'mermaidBlockName'   => 'mcb/mermaid',
					'mathBlockName'      => 'mcb/math',
					'codeBlockName'      => 'mcb/code',
					'mermaidCompatible'  => mib_is_mermaid_available(),
					'mathCompatible'     => mib_is_math_available(),
					'codeCompatible'     => mib_is_code_available(),
				)
			) . ';',
			'before'
		);
	}

	// If companion content-block plugins are active, load their frontend renderers
	// in the editor so Markdown preview uses the same code path as the frontend.
	enqueue_companion_content_block_assets();
}
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor_settings', 20 );


/**
 * Determine whether Markdown includes a Mermaid fenced code block.
 *
 * @param string $markdown Markdown source.
 * @return bool
 */
function markdown_contains_mermaid_fence( string $markdown ): bool {
	return (bool) preg_match( '/^\s*(```+|~~~+)\s*mermaid(?:$|[\s,{:=-])/im', $markdown );
}

/**
 * Determine whether Markdown includes math syntax that can be delegated.
 *
 * @param string $markdown Markdown source.
 * @return bool
 */
function markdown_contains_math( string $markdown ): bool {
	return (bool) preg_match( '/(^\s*(```+|~~~+)\s*(math|tex|latex|asciimath|ascii|am|mathml|mml)(?:$|[\s,{:=-]))|(^\s*(\$\$|\\\[|<math\b))|(\\\(.+?\\\))|((?<!\\\\)(?<!\$)\$(?!\$)[^\n$]+?(?<!\\\\)\$(?!\$))/ims', $markdown );
}

/**
 * Determine whether Markdown includes fenced code that can be delegated.
 *
 * @param string $markdown Markdown source.
 * @return bool
 */
function markdown_contains_code_fence( string $markdown ): bool {
	return (bool) preg_match( '/^\s*(```+|~~~+)\s*([^`\s]+)?/im', $markdown );
}

/**
 * Whether Mermaid Content Blocks has registered compatible assets.
 *
 * @return bool
 */
function mib_is_mermaid_available(): bool {
	return wp_script_is( 'mcb-mermaid-loader', 'registered' ) || wp_script_is( 'mcb-mermaid-loader', 'enqueued' );
}

/**
 * Whether Math Content Blocks has registered compatible assets.
 *
 * @return bool
 */
function mib_is_math_available(): bool {
	return wp_script_is( 'mcb-math-renderer', 'registered' ) || wp_script_is( 'mcb-math-renderer', 'enqueued' );
}

/**
 * Whether Code Content Blocks has registered compatible assets.
 *
 * @return bool
 */
function mib_is_code_available(): bool {
	return wp_script_is( 'ccb-code-renderer', 'registered' ) || wp_script_is( 'ccb-code-renderer', 'enqueued' );
}

/**
 * Enqueue companion plugin assets if they are registered.
 *
 * @return array<string,bool> Integration status.
 */
function enqueue_companion_content_block_assets(): array {
	return array(
		'mermaid' => enqueue_mermaid_content_blocks_assets(),
		'math'    => enqueue_math_content_blocks_assets(),
		'code'    => enqueue_code_content_blocks_assets(),
	);
}

/**
 * Enqueue companion plugin assets only for content types found in Markdown.
 *
 * @param string $markdown Markdown source.
 * @return void
 */
function enqueue_needed_companion_assets( string $markdown ): void {
	if ( markdown_contains_mermaid_fence( $markdown ) ) {
		enqueue_mermaid_content_blocks_assets();
	}
	if ( markdown_contains_math( $markdown ) ) {
		enqueue_math_content_blocks_assets();
	}
	if ( markdown_contains_code_fence( $markdown ) ) {
		enqueue_code_content_blocks_assets();
	}
}

/**
 * Enqueue Mermaid Content Blocks assets when that plugin has registered them.
 *
 * The Markdown parser emits the same .mcb-mermaid-block markup used by the
 * Mermaid Content Blocks plugin. This function deliberately does not bundle a
 * second copy of Mermaid; it cooperates with the Mermaid plugin when present.
 *
 * @return bool True when compatible assets were enqueued.
 */
function enqueue_mermaid_content_blocks_assets(): bool {
	$enqueued = false;

	if ( wp_style_is( 'mcb-mermaid-style', 'registered' ) || wp_style_is( 'mcb-mermaid-style', 'enqueued' ) ) {
		wp_enqueue_style( 'mcb-mermaid-style' );
		$enqueued = true;
	}

	if ( wp_script_is( 'mcb-mermaid-lib', 'registered' ) || wp_script_is( 'mcb-mermaid-lib', 'enqueued' ) ) {
		wp_enqueue_script( 'mcb-mermaid-lib' );
		$enqueued = true;
	}

	if ( wp_script_is( 'mcb-mermaid-loader', 'registered' ) || wp_script_is( 'mcb-mermaid-loader', 'enqueued' ) ) {
		wp_enqueue_script( 'mcb-mermaid-loader' );
		$enqueued = true;
	}

	return $enqueued;
}

/**
 * Enqueue Math Content Blocks assets when that plugin has registered them.
 *
 * @return bool True when compatible assets were enqueued.
 */
function enqueue_math_content_blocks_assets(): bool {
	$enqueued = false;

	if ( wp_style_is( 'mcb-math-style', 'registered' ) || wp_style_is( 'mcb-math-style', 'enqueued' ) ) {
		wp_enqueue_style( 'mcb-math-style' );
		$enqueued = true;
	}

	if ( wp_script_is( 'mcb-mathjax', 'registered' ) || wp_script_is( 'mcb-mathjax', 'enqueued' ) ) {
		wp_enqueue_script( 'mcb-mathjax' );
		$enqueued = true;
	}

	if ( wp_script_is( 'mcb-math-renderer', 'registered' ) || wp_script_is( 'mcb-math-renderer', 'enqueued' ) ) {
		wp_enqueue_script( 'mcb-math-renderer' );
		$enqueued = true;
	}

	return $enqueued;
}

/**
 * Enqueue Code Content Blocks assets when that plugin has registered them.
 *
 * @return bool True when compatible assets were enqueued.
 */
function enqueue_code_content_blocks_assets(): bool {
	$enqueued = false;

	if ( wp_style_is( 'ccb-code-style', 'registered' ) || wp_style_is( 'ccb-code-style', 'enqueued' ) ) {
		wp_enqueue_style( 'ccb-code-style' );
		$enqueued = true;
	}

	if ( wp_script_is( 'ccb-highlightjs-lib', 'registered' ) || wp_script_is( 'ccb-highlightjs-lib', 'enqueued' ) ) {
		wp_enqueue_script( 'ccb-highlightjs-lib' );
		$enqueued = true;
	}

	if ( wp_script_is( 'ccb-code-renderer', 'registered' ) || wp_script_is( 'ccb-code-renderer', 'enqueued' ) ) {
		wp_enqueue_script( 'ccb-code-renderer' );
		$enqueued = true;
	}

	return $enqueued;
}

/**
 * Render dynamic block.
 *
 * @param array<string,mixed> $attributes Block attributes.
 * @return string HTML.
 */
function render_markdown_importer_block( array $attributes ): string {
	$markdown = isset( $attributes['markdown'] ) ? mib_sanitize_markdown_input( (string) $attributes['markdown'] ) : '';

	if ( '' === trim( $markdown ) ) {
		return '';
	}

	enqueue_needed_companion_assets( $markdown );

	$options = array(
		'allow_raw_html'              => ! empty( $attributes['allowRawHtml'] ),
		'open_links_new_tab'          => ! empty( $attributes['openLinksNewTab'] ),
		'preserve_line_breaks'        => ! empty( $attributes['preserveLineBreaks'] ),
		'heading_anchors'             => ! empty( $attributes['headingAnchors'] ),
		'code_theme'                 => isset( $attributes['codeTheme'] ) ? (string) $attributes['codeTheme'] : 'system',
		'code_show_line_numbers'     => ! empty( $attributes['codeShowLineNumbers'] ),
		'code_wrap_lines'            => ! empty( $attributes['codeWrapLines'] ),
		'code_show_copy_button'      => array_key_exists( 'codeShowCopyButton', $attributes ) ? ! empty( $attributes['codeShowCopyButton'] ) : true,
		'math_enable_variable_colors' => ! empty( $attributes['mathEnableVariableColors'] ),
		'math_variable_colors'        => isset( $attributes['mathVariableColors'] ) ? (string) $attributes['mathVariableColors'] : '',
	);

	$rendered           = Markdown_Parser::render( $markdown, $options );
	$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'markdown-importer-blocks' ) );
	$caption            = isset( $attributes['caption'] ) ? trim( (string) $attributes['caption'] ) : '';
	$output             = '<div ' . $wrapper_attributes . '>';
	$output .= '<div class="mib-rendered-content">' . $rendered . '</div>';

	if ( '' !== $caption ) {
		$output .= '<figcaption class="mib-caption">' . esc_html( $caption ) . '</figcaption>';
	}

	if ( ! empty( $attributes['showSource'] ) ) {
		$output .= '<details class="mib-source-details"><summary>' . esc_html__( 'Markdown source', 'markdown-importer-blocks' ) . '</summary><pre><code>' . esc_html( $markdown ) . '</code></pre></details>';
	}

	$output .= '</div>';

	return $output;
}

/**
 * REST permission callback.
 *
 * @return bool
 */
function can_edit_posts(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * Render Markdown through REST for editor preview and conversion.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_render_markdown( \WP_REST_Request $request ) {
	$markdown = (string) $request->get_param( 'markdown' );
	$options  = array(
		'allow_raw_html'              => (bool) $request->get_param( 'allowRawHtml' ),
		'open_links_new_tab'          => (bool) $request->get_param( 'openLinksNewTab' ),
		'preserve_line_breaks'        => (bool) $request->get_param( 'preserveLineBreaks' ),
		'heading_anchors'             => (bool) $request->get_param( 'headingAnchors' ),
		'code_theme'                 => (string) ( $request->get_param( 'codeTheme' ) ?: 'system' ),
		'code_show_line_numbers'     => (bool) $request->get_param( 'codeShowLineNumbers' ),
		'code_wrap_lines'            => (bool) $request->get_param( 'codeWrapLines' ),
		'code_show_copy_button'      => null === $request->get_param( 'codeShowCopyButton' ) ? true : (bool) $request->get_param( 'codeShowCopyButton' ),
		'math_enable_variable_colors' => (bool) $request->get_param( 'mathEnableVariableColors' ),
		'math_variable_colors'        => (string) ( $request->get_param( 'mathVariableColors' ) ?: '' ),
	);

	if ( strlen( $markdown ) > MIB_MAX_MARKDOWN_BYTES ) {
		return new \WP_Error(
			'mib_markdown_too_large',
			__( 'Markdown input is too large. The maximum size is 1 MiB.', 'markdown-importer-blocks' ),
			array( 'status' => 413 )
		);
	}

	return rest_ensure_response(
		array(
			'html' => Markdown_Parser::render( $markdown, $options ),
		)
	);
}

/**
 * Fetch a remote Markdown document through an authenticated REST endpoint.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_fetch_markdown_url( \WP_REST_Request $request ) {
	$url = esc_url_raw( (string) $request->get_param( 'url' ) );

	$validation = validate_remote_markdown_url( $url );
	if ( is_wp_error( $validation ) ) {
		return $validation;
	}

	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout'             => 8,
			'redirection'         => 2,
			'limit_response_size' => MIB_MAX_MARKDOWN_BYTES,
			'reject_unsafe_urls'  => true,
			'user-agent'          => 'MarkdownImporterBlocks/' . MIB_VERSION . '; ' . home_url( '/' ),
			'headers'             => array(
				'Accept' => 'text/markdown,text/plain,text/*;q=0.9,*/*;q=0.5',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new \WP_Error(
			'mib_url_fetch_failed',
			sprintf(
				/* translators: %s: Error message. */
				__( 'Unable to fetch the Markdown URL: %s', 'markdown-importer-blocks' ),
				$response->get_error_message()
			),
			array( 'status' => 400 )
		);
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( $status_code < 200 || $status_code >= 300 ) {
		return new \WP_Error(
			'mib_url_bad_status',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The Markdown URL returned HTTP status %d.', 'markdown-importer-blocks' ),
				(int) $status_code
			),
			array( 'status' => 400 )
		);
	}

	$body = (string) wp_remote_retrieve_body( $response );
	if ( strlen( $body ) > MIB_MAX_MARKDOWN_BYTES ) {
		return new \WP_Error(
			'mib_url_too_large',
			__( 'The remote Markdown file is too large. The maximum size is 1 MiB.', 'markdown-importer-blocks' ),
			array( 'status' => 413 )
		);
	}

	$body = mib_sanitize_markdown_input( $body );

	return rest_ensure_response(
		array(
			'markdown' => $body,
			'bytes'    => strlen( $body ),
		)
	);
}

/**
 * Validate a remote URL before asking WordPress HTTP API to fetch it.
 *
 * @param string $url URL.
 * @return true|\WP_Error
 */
function validate_remote_markdown_url( string $url ) {
	if ( '' === $url ) {
		return new \WP_Error( 'mib_empty_url', __( 'Enter a Markdown URL.', 'markdown-importer-blocks' ), array( 'status' => 400 ) );
	}

	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return new \WP_Error( 'mib_invalid_url', __( 'The Markdown URL is not valid.', 'markdown-importer-blocks' ), array( 'status' => 400 ) );
	}

	$scheme = strtolower( (string) $parts['scheme'] );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return new \WP_Error( 'mib_invalid_scheme', __( 'Only http and https URLs are allowed.', 'markdown-importer-blocks' ), array( 'status' => 400 ) );
	}

	$host = trim( strtolower( (string) $parts['host'] ), '[]' );
	if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) || str_ends_with( $host, '.local' ) ) {
		return new \WP_Error( 'mib_local_url_blocked', __( 'Local/private URLs are not allowed.', 'markdown-importer-blocks' ), array( 'status' => 400 ) );
	}

	$ips = resolve_host_ips( $host );
	if ( empty( $ips ) ) {
		return new \WP_Error( 'mib_host_unresolved', __( 'The Markdown URL host could not be resolved.', 'markdown-importer-blocks' ), array( 'status' => 400 ) );
	}

	foreach ( $ips as $ip ) {
		if ( ! is_public_ip( $ip ) ) {
			return new \WP_Error( 'mib_private_url_blocked', __( 'The Markdown URL resolves to a local/private network address and was blocked.', 'markdown-importer-blocks' ), array( 'status' => 400 ) );
		}
	}

	return true;
}

/**
 * Resolve A and AAAA records for a host.
 *
 * @param string $host Hostname or IP.
 * @return array<int,string> IP addresses.
 */
function resolve_host_ips( string $host ): array {
	$ips = array();

	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return array( $host );
	}

	$a_records = gethostbynamel( $host );
	if ( is_array( $a_records ) ) {
		$ips = array_merge( $ips, $a_records );
	}

	if ( function_exists( 'dns_get_record' ) ) {
		$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}
	}

	return array_values( array_unique( array_filter( $ips ) ) );
}

/**
 * Determine whether an IP is globally routable.
 *
 * @param string $ip IP address.
 * @return bool
 */
function is_public_ip( string $ip ): bool {
	$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
	return (bool) filter_var( $ip, FILTER_VALIDATE_IP, $flags );
}

/**
 * Normalize and size-limit Markdown input.
 *
 * @param string $markdown Raw Markdown.
 * @return string Sanitized Markdown.
 */
function mib_sanitize_markdown_input( string $markdown ): string {
	$markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );
	$markdown = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $markdown );

	if ( strlen( $markdown ) > MIB_MAX_MARKDOWN_BYTES ) {
		$markdown = substr( $markdown, 0, MIB_MAX_MARKDOWN_BYTES );
	}

	return $markdown;
}
