<?php
/**
 * Lightweight Markdown-to-HTML renderer for Markdown Importer Blocks.
 *
 * This parser intentionally supports a practical, conservative Markdown subset
 * and delegates specialist content to companion block plugins when they are
 * available. It emits shared-compatible markup for Mermaid Content Blocks,
 * Math Content Blocks, and Code Content Blocks.
 *
 * @package MarkdownImporterBlocks
 */

namespace Markdown_Importer_Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Conservative Markdown renderer.
 */
final class Markdown_Parser {
	/** Maximum heading level. */
	private const MAX_HEADING_LEVEL = 6;

	/**
	 * Convert Markdown to sanitized HTML.
	 *
	 * @param string $markdown Markdown source.
	 * @param array  $options  Rendering options.
	 * @return string Sanitized HTML.
	 */
	public static function render( string $markdown, array $options = array() ): string {
		$defaults = array(
			'allow_raw_html'              => false,
			'open_links_new_tab'          => false,
			'preserve_line_breaks'        => false,
			'heading_anchors'             => false,
			'code_theme'                 => 'system',
			'code_show_line_numbers'     => false,
			'code_wrap_lines'            => false,
			'code_show_copy_button'      => true,
			'math_enable_variable_colors' => false,
			'math_variable_colors'        => '',
		);
		$options  = array_merge( $defaults, $options );

		$markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );
		$markdown = preg_replace( "/\xEF\xBB\xBF/", '', $markdown );
		$lines    = explode( "\n", $markdown );
		$count    = count( $lines );
		$index    = 0;
		$html     = array();

		while ( $index < $count ) {
			$line = rtrim( $lines[ $index ], "\n" );

			if ( '' === trim( $line ) ) {
				++$index;
				continue;
			}

			// Fenced blocks: Mermaid, Math, or Code Content Blocks-compatible code.
			if ( preg_match( '/^\s*(```+|~~~+)\s*([^`]*)\s*$/', $line, $match ) ) {
				$fence_info   = (string) ( $match[2] ?? '' );
				$fence_marker = (string) $match[1];
				$fence_char   = substr( $fence_marker, 0, 1 );
				$fence        = str_repeat( $fence_char, strlen( $fence_marker ) );
				$code         = array();
				++$index;

				while ( $index < $count ) {
					$current = rtrim( $lines[ $index ], "\n" );
					if ( preg_match( '/^\s*' . preg_quote( $fence, '/' ) . '+\s*$/', $current ) ) {
						++$index;
						break;
					}
					$code[] = $lines[ $index ];
					++$index;
				}

				$source = implode( "\n", $code );
				if ( self::is_mermaid_fence( $fence_info ) ) {
					$html[] = self::render_mermaid_compatible_block( $source, self::extract_mermaid_theme( $fence_info ) );
					continue;
				}

				if ( self::is_math_fence( $fence_info ) ) {
					$html[] = self::render_math_compatible_block(
						$source,
						self::extract_math_format( $fence_info ),
						self::extract_math_display_mode( $fence_info, true ),
						$options
					);
					continue;
				}

				$html[] = self::render_code_compatible_block(
					$source,
					self::extract_code_language( $fence_info ),
					$options,
					self::extract_code_highlight_lines( $fence_info )
				);
				continue;
			}

			// Display TeX math blocks: $$...$$ or \[...\].
			$math_block = self::consume_display_math_block( $lines, $index, $count, $options );
			if ( null !== $math_block ) {
				$html[] = $math_block['html'];
				$index  = $math_block['next_index'];
				continue;
			}

			// Raw MathML blocks are handled by Math Content Blocks, even when raw HTML is disabled.
			$mathml_block = self::consume_mathml_block( $lines, $index, $count, $options );
			if ( null !== $mathml_block ) {
				$html[] = $mathml_block['html'];
				$index  = $mathml_block['next_index'];
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^\s{0,3}((\*\s*){3,}|(-\s*){3,}|(_\s*){3,})$/', $line ) ) {
				$html[] = '<hr />';
				++$index;
				continue;
			}

			// ATX headings: # Heading.
			if ( preg_match( '/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $match ) ) {
				$level   = min( strlen( $match[1] ), self::MAX_HEADING_LEVEL );
				$content = trim( $match[2] );
				$id_attr = '';

				if ( ! empty( $options['heading_anchors'] ) ) {
					$plain = wp_strip_all_tags( html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					$id    = sanitize_title( $plain );
					if ( '' !== $id ) {
						$id_attr = ' id="' . esc_attr( $id ) . '"';
					}
				}

				$html[] = '<h' . $level . $id_attr . '>' . self::render_inline( $content, $options ) . '</h' . $level . '>';
				++$index;
				continue;
			}

			// Tables: header | header followed by separator | --- |.
			if ( self::looks_like_table( $lines, $index, $count ) ) {
				$table = self::parse_table( $lines, $index, $count, $options );
				$html[] = $table['html'];
				$index  = $table['next_index'];
				continue;
			}

			// Blockquote.
			if ( preg_match( '/^\s{0,3}>\s?(.*)$/', $line ) ) {
				$quoted = array();
				while ( $index < $count && preg_match( '/^\s{0,3}>\s?(.*)$/', $lines[ $index ], $match ) ) {
					$quoted[] = $match[1];
					++$index;
				}
				$html[] = '<blockquote>' . self::render( implode( "\n", $quoted ), $options ) . '</blockquote>';
				continue;
			}

			// Ordered and unordered lists. Handles one nesting level by indentation.
			if ( self::is_list_line( $line ) ) {
				$list = self::parse_list( $lines, $index, $count, $options );
				$html[] = $list['html'];
				$index  = $list['next_index'];
				continue;
			}

			// Raw HTML blocks can be used by trusted editors, but WordPress KSES still sanitizes final output.
			if ( ! empty( $options['allow_raw_html'] ) && self::looks_like_raw_html_block( $line ) ) {
				$raw = array( $line );
				++$index;
				while ( $index < $count && '' !== trim( $lines[ $index ] ) ) {
					$raw[] = $lines[ $index ];
					++$index;
				}
				$html[] = wp_kses_post( implode( "\n", $raw ) );
				continue;
			}

			// Paragraph.
			$paragraph = array( $line );
			++$index;
			while ( $index < $count ) {
				$next = rtrim( $lines[ $index ], "\n" );
				if (
					'' === trim( $next ) ||
					preg_match( '/^\s*(```+|~~~+)/', $next ) ||
					self::line_starts_display_math( $next ) ||
					self::line_starts_mathml( $next ) ||
					preg_match( '/^\s{0,3}(#{1,6})\s+/', $next ) ||
					preg_match( '/^\s{0,3}>\s?/', $next ) ||
					self::is_list_line( $next ) ||
					preg_match( '/^\s{0,3}((\*\s*){3,}|(-\s*){3,}|(_\s*){3,})$/', $next ) ||
					self::looks_like_table( $lines, $index, $count )
				) {
					break;
				}
				$paragraph[] = $next;
				++$index;
			}

			$separator = ! empty( $options['preserve_line_breaks'] ) ? '<br />' : ' ';
			$html[]    = '<p>' . self::render_inline( implode( $separator, array_map( 'trim', $paragraph ) ), $options ) . '</p>';
		}

		return self::sanitize_rendered_html( implode( "\n", $html ) );
	}

	/**
	 * Render inline Markdown tokens.
	 *
	 * @param string $text    Text.
	 * @param array  $options Options.
	 * @return string HTML.
	 */
	private static function render_inline( string $text, array $options ): string {
		$placeholders = array();

		// Code spans must be protected before math and emphasis parsing.
		$text = preg_replace_callback(
			'/`([^`]+)`/',
			static function ( array $match ) use ( &$placeholders ): string {
				$key                  = '%%MIBCODE' . count( $placeholders ) . '%%';
				$placeholders[ $key ] = '<code>' . esc_html( $match[1] ) . '</code>';
				return $key;
			},
			$text
		);

		// TeX inline math: \(...\).
		$text = preg_replace_callback(
			'/\\\((.+?)\\\)/s',
			static function ( array $match ) use ( &$placeholders, $options ): string {
				$key                  = '%%MIBMATH' . count( $placeholders ) . '%%';
				$placeholders[ $key ] = self::render_math_compatible_block( $match[1], 'tex', false, $options );
				return $key;
			},
			$text
		);

		// TeX inline math: $...$. Conservative enough for documentation; use code spans for literal dollars.
		$text = preg_replace_callback(
			'/(?<!\\\\)(?<!\$)\$(?!\$)([^\n$]+?)(?<!\\\\)\$(?!\$)/',
			static function ( array $match ) use ( &$placeholders, $options ): string {
				$source = trim( (string) $match[1] );
				if ( '' === $source ) {
					return $match[0];
				}
				$key                  = '%%MIBMATH' . count( $placeholders ) . '%%';
				$placeholders[ $key ] = self::render_math_compatible_block( $source, 'tex', false, $options );
				return $key;
			},
			$text
		);

		$text = esc_html( $text );

		// Images: ![alt](url "title").
		$text = preg_replace_callback(
			'/!\[([^\]]*)\]\(([^\s)]+)(?:\s+&quot;([^&]*)&quot;)?\)/',
			static function ( array $match ): string {
				$alt   = esc_attr( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$src   = esc_url( html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$title = isset( $match[3] ) ? esc_attr( html_entity_decode( $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';

				if ( '' === $src ) {
					return '';
				}

				$title_attr = '' !== $title ? ' title="' . $title . '"' : '';
				return '<img src="' . $src . '" alt="' . $alt . '"' . $title_attr . ' />';
			},
			$text
		);

		// Links: [text](url "title").
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^\s)]+)(?:\s+&quot;([^&]*)&quot;)?\)/',
			static function ( array $match ) use ( $options ): string {
				$label = $match[1];
				$url   = esc_url( html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$title = isset( $match[3] ) ? esc_attr( html_entity_decode( $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';

				if ( '' === $url ) {
					return $label;
				}

				$title_attr = '' !== $title ? ' title="' . $title . '"' : '';
				$target     = ! empty( $options['open_links_new_tab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';
				return '<a href="' . $url . '"' . $title_attr . $target . '>' . $label . '</a>';
			},
			$text
		);

		// Strong/emphasis/strikethrough. These are intentionally conservative.
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/__([^_]+)__/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text );
		$text = preg_replace( '/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $text );
		$text = preg_replace( '/~~([^~]+)~~/', '<del>$1</del>', $text );

		foreach ( $placeholders as $key => $replacement ) {
			$text = str_replace( esc_html( $key ), $replacement, $text );
		}

		return $text;
	}

	/**
	 * Determine whether a fenced block should be treated as Mermaid.
	 *
	 * @param string $info Raw fence info string.
	 * @return bool
	 */
	private static function is_mermaid_fence( string $info ): bool {
		$info = trim( strtolower( $info ) );
		return (bool) preg_match( '/^mermaid(?:$|[\s,{:=-])/', $info );
	}

	/**
	 * Determine whether a fenced block should be treated as Math.
	 *
	 * @param string $info Raw fence info string.
	 * @return bool
	 */
	private static function is_math_fence( string $info ): bool {
		$token = self::first_fence_token( $info );
		return in_array( $token, array( 'math', 'tex', 'latex', 'asciimath', 'ascii', 'am', 'mathml', 'mml' ), true );
	}

	/**
	 * Extract the first normalized fence token.
	 *
	 * @param string $info Raw fence info string.
	 * @return string
	 */
	private static function first_fence_token( string $info ): string {
		$info = trim( strtolower( $info ) );
		if ( '' === $info ) {
			return '';
		}
		$parts = preg_split( '/\s+/', $info ) ?: array();
		return preg_replace( '/[^a-z0-9_+.#\/-]/', '', (string) ( $parts[0] ?? '' ) );
	}

	/**
	 * Extract an optional Mermaid theme from the fence info string.
	 *
	 * @param string $info Raw fence info string.
	 * @return string
	 */
	private static function extract_mermaid_theme( string $info ): string {
		$theme = 'default';
		if ( preg_match( '/\btheme\s*[:=]\s*([a-zA-Z0-9_-]+)/', $info, $match ) ) {
			$theme = strtolower( (string) $match[1] );
		}

		$allowed = array( 'default', 'neutral', 'dark', 'forest', 'base' );
		return in_array( $theme, $allowed, true ) ? $theme : 'default';
	}

	/**
	 * Extract a Math Content Blocks input format from fence info.
	 *
	 * @param string $info Raw fence info string.
	 * @return string tex, asciimath, or mathml.
	 */
	private static function extract_math_format( string $info ): string {
		$token = self::first_fence_token( $info );
		if ( preg_match( '/\b(?:format|input|type)\s*[:=]\s*([a-zA-Z0-9_-]+)/', $info, $match ) ) {
			$token = strtolower( (string) $match[1] );
		}

		if ( in_array( $token, array( 'asciimath', 'ascii', 'am' ), true ) ) {
			return 'asciimath';
		}
		if ( in_array( $token, array( 'mathml', 'mml' ), true ) ) {
			return 'mathml';
		}
		return 'tex';
	}

	/**
	 * Extract display mode from math fence info.
	 *
	 * @param string $info    Raw fence info string.
	 * @param bool   $default Default display mode.
	 * @return bool
	 */
	private static function extract_math_display_mode( string $info, bool $default ): bool {
		if ( preg_match( '/\bdisplay\s*[:=]\s*(inline|false|0|no)\b/i', $info ) ) {
			return false;
		}
		if ( preg_match( '/\bdisplay\s*[:=]\s*(block|true|1|yes)\b/i', $info ) ) {
			return true;
		}
		return $default;
	}

	/**
	 * Extract a code language from a fence info string.
	 *
	 * @param string $info Raw fence info string.
	 * @return string
	 */
	private static function extract_code_language( string $info ): string {
		$token = self::first_fence_token( $info );
		if ( '' === $token || str_contains( $token, '=' ) ) {
			$token = 'plaintext';
		}

		return self::normalize_code_language( $token );
	}

	/**
	 * Extract Code Content Blocks-compatible highlighted lines.
	 *
	 * @param string $info Raw fence info string.
	 * @return string
	 */
	private static function extract_code_highlight_lines( string $info ): string {
		$lines = '';
		if ( preg_match( '/\{\s*([0-9,\-\s]+)\s*\}/', $info, $match ) ) {
			$lines = (string) $match[1];
		} elseif ( preg_match( '/\b(?:lines|highlight|highlight-lines)\s*[:=]\s*([0-9,\-\s]+)/i', $info, $match ) ) {
			$lines = (string) $match[1];
		}
		$lines = preg_replace( '/[^0-9,\-\s]/', '', $lines );
		$lines = preg_replace( '/\s+/', '', (string) $lines );
		return trim( (string) $lines, ',' );
	}

	/**
	 * Render markup intentionally compatible with Mermaid Content Blocks.
	 *
	 * @param string $source Mermaid source.
	 * @param string $theme  Mermaid theme.
	 * @return string HTML.
	 */
	private static function render_mermaid_compatible_block( string $source, string $theme = 'default' ): string {
		$source = trim( $source );
		if ( '' === $source ) {
			return '';
		}

		return '<figure class="wp-block-mcb-mermaid mcb-mermaid-block" data-mcb-theme="' . esc_attr( $theme ) . '" data-mcb-show-source="false">'
			. '<div class="mcb-mermaid-output" role="img" aria-label="' . esc_attr__( 'Mermaid diagram', 'markdown-importer-blocks' ) . '" hidden></div>'
			. '<pre class="mcb-mermaid-source"><code>' . esc_html( $source ) . '</code></pre>'
			. '<div class="mcb-mermaid-error" role="alert" hidden></div>'
			. '</figure>';
	}

	/**
	 * Render markup intentionally compatible with Math Content Blocks.
	 *
	 * @param string $source  Math source.
	 * @param string $format  tex, asciimath, or mathml.
	 * @param bool   $display True for display math.
	 * @param array  $options Parser options.
	 * @return string HTML.
	 */
	private static function render_math_compatible_block( string $source, string $format, bool $display, array $options ): string {
		$source = trim( $source );
		if ( '' === $source ) {
			return '';
		}

		$format = self::normalize_math_format( $format );
		$data   = array(
			'source'               => $source,
			'inputFormat'          => $format,
			'displayMode'          => $display,
			'enableVariableColors' => ! empty( $options['math_enable_variable_colors'] ),
			'variableColors'       => self::parse_variable_color_rules( (string) ( $options['math_variable_colors'] ?? '' ) ),
		);
		$json   = self::json_encode_for_html( $data );

		if ( $display ) {
			return '<figure class="mcb-math-block mcb-math-format-' . esc_attr( $format ) . ' mcb-math-display-block mib-math-compatible-block" data-mcb-math-block="1">'
				. '<script type="application/json" class="mcb-math-data">' . $json . '</script>'
				. '<div class="mcb-math-render" role="img" aria-label="' . esc_attr__( 'Rendered mathematics', 'markdown-importer-blocks' ) . '"></div>'
				. '<pre class="mib-math-fallback mcb-math-visible-source"><code>' . esc_html( $source ) . '</code></pre>'
				. '</figure>';
		}

		return '<span class="mcb-math-block mcb-math-format-' . esc_attr( $format ) . ' mcb-math-display-inline mib-math-compatible-inline" data-mcb-math-block="1">'
			. '<script type="application/json" class="mcb-math-data">' . $json . '</script>'
			. '<span class="mcb-math-render" role="img" aria-label="' . esc_attr__( 'Rendered mathematics', 'markdown-importer-blocks' ) . '"></span>'
			. '<code class="mib-math-fallback">' . esc_html( $source ) . '</code>'
			. '</span>';
	}

	/**
	 * Render markup intentionally compatible with Code Content Blocks.
	 *
	 * @param string $source          Code source.
	 * @param string $language        Code language.
	 * @param array  $options         Parser options.
	 * @param string $highlight_lines Line ranges.
	 * @return string HTML.
	 */
	private static function render_code_compatible_block( string $source, string $language, array $options, string $highlight_lines = '' ): string {
		$language = self::normalize_code_language( $language );
		$theme    = self::normalize_code_theme( (string) ( $options['code_theme'] ?? 'system' ) );
		$label    = self::code_language_label( $language );
		$lines    = ! empty( $options['code_show_line_numbers'] );
		$wrap     = ! empty( $options['code_wrap_lines'] );
		$copy     = array_key_exists( 'code_show_copy_button', $options ) ? ! empty( $options['code_show_copy_button'] ) : true;

		$output  = '<figure class="mcb-code-block ccb-code-block ccb-theme-' . esc_attr( $theme ) . ' ccb-language-' . esc_attr( $language ) . '" data-mcb-code-block="1" data-ccb-code-block="1" data-mcb-language="' . esc_attr( $language ) . '" data-mcb-theme="' . esc_attr( $theme ) . '" data-mcb-line-numbers="' . ( $lines ? 'true' : 'false' ) . '" data-mcb-wrap-lines="' . ( $wrap ? 'true' : 'false' ) . '" data-mcb-highlight-lines="' . esc_attr( $highlight_lines ) . '">';
		$output .= '<div class="ccb-code-toolbar"><span class="ccb-code-language-label">' . esc_html( $label ) . '</span>';
		if ( $copy ) {
			$output .= '<button type="button" class="ccb-code-copy-button" data-ccb-copy="1">' . esc_html__( 'Copy', 'markdown-importer-blocks' ) . '</button>';
		}
		$output .= '</div>';
		$output .= '<pre class="ccb-code-pre"><code class="mcb-code-source ccb-code-source language-' . esc_attr( $language ) . '">' . esc_html( $source ) . '</code></pre>';
		$output .= '<div class="ccb-code-error" role="alert" hidden></div>';
		$output .= '</figure>';
		return $output;
	}

	/**
	 * Consume a display TeX math block if the current line starts one.
	 *
	 * @param array $lines   Lines.
	 * @param int   $index   Current index.
	 * @param int   $count   Count.
	 * @param array $options Parser options.
	 * @return array{html:string,next_index:int}|null
	 */
	private static function consume_display_math_block( array $lines, int $index, int $count, array $options ): ?array {
		$line = trim( (string) $lines[ $index ] );
		if ( preg_match( '/^\$\$\s*(.+?)\s*\$\$$/s', $line, $match ) ) {
			return array(
				'html'       => self::render_math_compatible_block( $match[1], 'tex', true, $options ),
				'next_index' => $index + 1,
			);
		}
		if ( preg_match( '/^\\\[\s*(.+?)\s*\\\]$/s', $line, $match ) ) {
			return array(
				'html'       => self::render_math_compatible_block( $match[1], 'tex', true, $options ),
				'next_index' => $index + 1,
			);
		}

		$delimiter = '';
		$start     = '';
		if ( str_starts_with( $line, '$$' ) ) {
			$delimiter = '$$';
			$start     = trim( substr( $line, 2 ) );
		} elseif ( str_starts_with( $line, '\\[' ) ) {
			$delimiter = '\\]';
			$start     = trim( substr( $line, 2 ) );
		} else {
			return null;
		}

		$source = array();
		if ( '' !== $start ) {
			$source[] = $start;
		}
		++$index;
		while ( $index < $count ) {
			$current = rtrim( (string) $lines[ $index ], "\n" );
			$trimmed = trim( $current );
			if ( str_ends_with( $trimmed, $delimiter ) ) {
				$before = trim( substr( $trimmed, 0, -strlen( $delimiter ) ) );
				if ( '' !== $before ) {
					$source[] = $before;
				}
				++$index;
				break;
			}
			$source[] = $current;
			++$index;
		}

		return array(
			'html'       => self::render_math_compatible_block( implode( "\n", $source ), 'tex', true, $options ),
			'next_index' => $index,
		);
	}

	/**
	 * Consume a raw MathML block if the current line starts one.
	 *
	 * @param array $lines   Lines.
	 * @param int   $index   Current index.
	 * @param int   $count   Count.
	 * @param array $options Parser options.
	 * @return array{html:string,next_index:int}|null
	 */
	private static function consume_mathml_block( array $lines, int $index, int $count, array $options ): ?array {
		if ( ! self::line_starts_mathml( (string) $lines[ $index ] ) ) {
			return null;
		}

		$source = array();
		while ( $index < $count ) {
			$current = (string) $lines[ $index ];
			$source[] = $current;
			++$index;
			if ( preg_match( '/<\/math>\s*$/i', trim( $current ) ) ) {
				break;
			}
		}

		return array(
			'html'       => self::render_math_compatible_block( implode( "\n", $source ), 'mathml', true, $options ),
			'next_index' => $index,
		);
	}

	/**
	 * Check whether a line starts a display math block.
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	private static function line_starts_display_math( string $line ): bool {
		$line = trim( $line );
		return str_starts_with( $line, '$$' ) || str_starts_with( $line, '\\[' );
	}

	/**
	 * Check whether a line starts a MathML block.
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	private static function line_starts_mathml( string $line ): bool {
		return (bool) preg_match( '/^\s*<math\b/i', $line );
	}

	/**
	 * Normalize Math Content Blocks format.
	 *
	 * @param string $format Raw format.
	 * @return string
	 */
	private static function normalize_math_format( string $format ): string {
		$format = strtolower( trim( $format ) );
		if ( in_array( $format, array( 'ascii', 'am' ), true ) ) {
			return 'asciimath';
		}
		if ( 'mml' === $format ) {
			return 'mathml';
		}
		return in_array( $format, array( 'tex', 'asciimath', 'mathml' ), true ) ? $format : 'tex';
	}

	/**
	 * Normalize Code Content Blocks theme.
	 *
	 * @param string $theme Raw theme.
	 * @return string
	 */
	private static function normalize_code_theme( string $theme ): string {
		$theme = strtolower( trim( $theme ) );
		return in_array( $theme, array( 'system', 'light', 'dark' ), true ) ? $theme : 'system';
	}

	/**
	 * Normalize a code language through Code Content Blocks when available.
	 *
	 * @param string $language Raw language.
	 * @return string
	 */
	private static function normalize_code_language( string $language ): string {
		if ( class_exists( '\CCB_Code_Languages' ) ) {
			return \CCB_Code_Languages::normalize_language( $language );
		}

		$key = self::slugify_language( $language );
		$aliases = array(
			'' => 'plaintext', 'text' => 'plaintext', 'txt' => 'plaintext', 'none' => 'plaintext',
			'cplus' => 'cpp', 'cplusplus' => 'cpp', 'cpp' => 'cpp', 'cc' => 'cpp', 'cxx' => 'cpp',
			'csharp' => 'csharp', 'c-sharp' => 'csharp', 'cs' => 'csharp',
			'js' => 'javascript', 'node' => 'javascript', 'nodejs' => 'javascript',
			'ts' => 'typescript', 'py' => 'python', 'python3' => 'python',
			'kt' => 'kotlin', 'kts' => 'kotlin', 'rs' => 'rust', 'golang' => 'go',
			'qbasic' => 'quickbasic', 'qb' => 'quickbasic', 'quick-basic' => 'quickbasic',
			'colorbasic' => 'color-basic', 'coco-basic' => 'color-basic', 'coco' => 'color-basic', 'extended-color-basic' => 'color-basic', 'ecb' => 'color-basic',
			'cbm-basic' => 'commodore-basic', 'c64-basic' => 'commodore-basic', 'vic20-basic' => 'commodore-basic', 'pet-basic' => 'commodore-basic',
			'pli' => 'pl1', 'pl-i' => 'pl1', 'pl-1' => 'pl1',
			'turbo-pascal' => 'pascal', 'tp' => 'pascal', 'objectpascal' => 'delphi', 'object-pascal' => 'delphi',
			'sh' => 'bash', 'shell' => 'bash', 'zsh' => 'bash', 'ksh' => 'bash',
			'bat' => 'dos', 'batch' => 'dos', 'cmd' => 'dos', 'msdos' => 'dos',
			'pwsh' => 'powershell', 'ps1' => 'powershell', 'tcsh' => 'csh',
			'jsonc' => 'json', 'yml' => 'yaml', 'md' => 'markdown', 'patch' => 'diff',
			'x86' => 'x86asm', '8086' => 'x86asm', 'i8086' => 'x86asm', 'i386' => 'x86asm', '80386' => 'x86asm',
			'6502' => '6502asm', 'z80' => 'z80asm', '68k' => 'm68kasm', '68000' => 'm68kasm', 'arm' => 'armasm', 'avr' => 'avrasm', 'mips' => 'mipsasm',
		);
		return $aliases[ $key ] ?? $key ?: 'plaintext';
	}

	/**
	 * Return a display label for a code language.
	 *
	 * @param string $language Normalized language.
	 * @return string
	 */
	private static function code_language_label( string $language ): string {
		if ( class_exists( '\CCB_Code_Languages' ) ) {
			return \CCB_Code_Languages::label_for( $language );
		}
		$labels = array(
			'plaintext' => 'Plain text', 'cpp' => 'C++', 'csharp' => 'C#', 'javascript' => 'JavaScript',
			'typescript' => 'TypeScript', 'quickbasic' => 'QuickBASIC / QBasic', 'color-basic' => 'TRS-80 Color BASIC',
			'commodore-basic' => 'Commodore BASIC', 'pl1' => 'PL/I', 'x86asm' => 'Assembly - x86',
			'6502asm' => 'Assembly - 6502', 'z80asm' => 'Assembly - Z80', 'm68kasm' => 'Assembly - Motorola 68000',
			'armasm' => 'Assembly - ARM', 'avrasm' => 'Assembly - AVR', 'mipsasm' => 'Assembly - MIPS',
		);
		return $labels[ $language ] ?? strtoupper( str_replace( '-', ' ', $language ) );
	}

	/**
	 * Slugify language identifiers the same way Code Content Blocks does.
	 *
	 * @param string $language Raw language.
	 * @return string
	 */
	private static function slugify_language( string $language ): string {
		$language = strtolower( trim( $language ) );
		$language = str_replace( array( '+', '#', '/', '_' ), array( 'plus', 'sharp', '-', '-' ), $language );
		$language = preg_replace( '/[^a-z0-9-]+/', '-', $language );
		$language = preg_replace( '/-+/', '-', (string) $language );
		return trim( (string) $language, '-' );
	}

	/**
	 * Parse math variable color rules in the same simple form the math plugin accepts.
	 *
	 * @param string $rules_text Text rules.
	 * @return array<int,array{name:string,color:string}>
	 */
	private static function parse_variable_color_rules( string $rules_text ): array {
		$rules = array();
		foreach ( preg_split( '/\r?\n/', $rules_text ) ?: array() as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || str_starts_with( $line, '#' ) || str_starts_with( $line, '//' ) ) {
				continue;
			}
			$parts = preg_split( '/\s*(?:=|:)\s*/', $line, 2 );
			if ( ! is_array( $parts ) || 2 !== count( $parts ) ) {
				continue;
			}
			$name  = trim( (string) $parts[0] );
			$color = self::sanitize_css_color( trim( (string) $parts[1] ) );
			if ( preg_match( '/^\\?[A-Za-z][A-Za-z0-9_]*$|^[A-Za-z]$/', $name ) && '' !== $color ) {
				$rules[] = array( 'name' => $name, 'color' => $color );
			}
		}
		usort(
			$rules,
			static fn( array $a, array $b ): int => strlen( $b['name'] ) <=> strlen( $a['name'] )
		);
		return $rules;
	}

	/**
	 * Sanitizes variable color values.
	 *
	 * @param string $color Raw color.
	 * @return string
	 */
	private static function sanitize_css_color( string $color ): string {
		$color = trim( $color );
		if ( '' === $color || strlen( $color ) > 64 ) {
			return '';
		}
		$patterns = array(
			'/^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{1})?([0-9A-Fa-f]{2})?([0-9A-Fa-f]{2})?$/',
			'/^[A-Za-z][A-Za-z0-9_-]{0,30}$/',
			'/^rgba?\(\s*(?:\d{1,3}\s*,\s*){2}\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/',
			'/^hsla?\(\s*\d{1,3}(?:deg)?\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $color ) ) {
				return $color;
			}
		}
		return '';
	}

	/**
	 * JSON encode for HTML data islands.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	private static function json_encode_for_html( $data ): string {
		$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $data, $flags );
		}
		return (string) json_encode( $data, $flags );
	}

	/**
	 * Sanitize generated HTML while preserving companion block markup and attributes.
	 *
	 * @param string $html Generated HTML.
	 * @return string Sanitized HTML.
	 */
	private static function sanitize_rendered_html( string $html ): string {
		if ( function_exists( 'wp_kses' ) && function_exists( 'wp_kses_allowed_html' ) ) {
			$allowed = wp_kses_allowed_html( 'post' );
			$common  = array(
				'class'                       => true,
				'id'                          => true,
				'style'                       => true,
				'title'                       => true,
				'role'                        => true,
				'aria-label'                  => true,
				'hidden'                      => true,
				'data-mcb-theme'              => true,
				'data-mcb-show-source'        => true,
				'data-mcb-math-block'         => true,
				'data-mcb-code-block'         => true,
				'data-ccb-code-block'         => true,
				'data-mcb-language'           => true,
				'data-mcb-line-numbers'       => true,
				'data-mcb-wrap-lines'         => true,
				'data-mcb-highlight-lines'    => true,
				'data-ccb-copy'               => true,
				'data-mcb-math-status'        => true,
				'data-mcb-rendered'           => true,
				'data-mcb-normalized-language' => true,
				'data-line'                   => true,
			);

			foreach ( array( 'figure', 'div', 'pre', 'code', 'span', 'button', 'figcaption', 'noscript' ) as $tag ) {
				$allowed[ $tag ] = array_merge( $allowed[ $tag ] ?? array(), $common );
			}
			$allowed['button'] = array_merge( $allowed['button'] ?? array(), array( 'type' => true, 'disabled' => true ) );
			$allowed['script'] = array(
				'type'  => true,
				'class' => true,
			);

			return wp_kses( $html, $allowed );
		}

		return wp_kses_post( $html );
	}

	/**
	 * Determine if line starts a list.
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	private static function is_list_line( string $line ): bool {
		return (bool) preg_match( '/^\s{0,6}(([-+*])|(\d+\.))\s+.+$/', $line );
	}

	/**
	 * Parse a flat or lightly nested Markdown list.
	 *
	 * @param array $lines   Lines.
	 * @param int   $index   Current index.
	 * @param int   $count   Line count.
	 * @param array $options Options.
	 * @return array{html:string,next_index:int}
	 */
	private static function parse_list( array $lines, int $index, int $count, array $options ): array {
		$first = $lines[ $index ];
		$type  = preg_match( '/^\s{0,6}\d+\.\s+/', $first ) ? 'ol' : 'ul';
		$items = array();

		while ( $index < $count && self::is_list_line( $lines[ $index ] ) ) {
			$line = $lines[ $index ];
			preg_match( '/^(\s*)(([-+*])|(\d+\.))\s+(.+)$/', $line, $match );
			$indent = strlen( $match[1] ?? '' );
			$text   = $match[5] ?? '';

			++$index;
			$continuation = array();
			while ( $index < $count ) {
				$next = $lines[ $index ];
				if ( '' === trim( $next ) ) {
					++$index;
					break;
				}
				if ( self::is_list_line( $next ) ) {
					preg_match( '/^(\s*)/', $next, $indent_match );
					$next_indent = strlen( $indent_match[1] ?? '' );
					if ( $next_indent <= $indent ) {
						break;
					}
				}
				if ( preg_match( '/^\s{2,}(.+)$/', $next, $continuation_match ) ) {
					$continuation[] = $continuation_match[1];
					++$index;
					continue;
				}
				break;
			}

			$item_text = trim( $text . ( empty( $continuation ) ? '' : "\n" . implode( "\n", $continuation ) ) );
			if ( ! empty( $continuation ) && self::is_list_line( $continuation[0] ?? '' ) ) {
				$items[] = self::render( $item_text, $options );
			} else {
				$items[] = self::render_inline( str_replace( "\n", ' ', $item_text ), $options );
			}
		}

		$html = '<' . $type . '>';
		foreach ( $items as $item ) {
			$html .= '<li>' . $item . '</li>';
		}
		$html .= '</' . $type . '>';

		return array(
			'html'       => $html,
			'next_index' => $index,
		);
	}

	/**
	 * Check whether current line begins a table.
	 *
	 * @param array $lines Lines.
	 * @param int   $index Index.
	 * @param int   $count Count.
	 * @return bool
	 */
	private static function looks_like_table( array $lines, int $index, int $count ): bool {
		if ( $index + 1 >= $count ) {
			return false;
		}

		$header    = trim( $lines[ $index ] );
		$separator = trim( $lines[ $index + 1 ] );

		return false !== strpos( $header, '|' ) && (bool) preg_match( '/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?$/', $separator );
	}

	/**
	 * Parse a GFM-style table.
	 *
	 * @param array $lines   Lines.
	 * @param int   $index   Current index.
	 * @param int   $count   Count.
	 * @param array $options Options.
	 * @return array{html:string,next_index:int}
	 */
	private static function parse_table( array $lines, int $index, int $count, array $options ): array {
		$headers    = self::split_table_row( $lines[ $index ] );
		$separators = self::split_table_row( $lines[ $index + 1 ] );
		$alignments = array();

		foreach ( $separators as $separator ) {
			$separator = trim( $separator );
			if ( str_starts_with( $separator, ':' ) && str_ends_with( $separator, ':' ) ) {
				$alignments[] = 'center';
			} elseif ( str_ends_with( $separator, ':' ) ) {
				$alignments[] = 'right';
			} elseif ( str_starts_with( $separator, ':' ) ) {
				$alignments[] = 'left';
			} else {
				$alignments[] = '';
			}
		}

		$index += 2;
		$rows   = array();
		while ( $index < $count && false !== strpos( $lines[ $index ], '|' ) && '' !== trim( $lines[ $index ] ) ) {
			$rows[] = self::split_table_row( $lines[ $index ] );
			++$index;
		}

		$html = '<table class="mib-markdown-table"><thead><tr>';
		foreach ( $headers as $cell_index => $header ) {
			$align_attr = self::alignment_attr( $alignments[ $cell_index ] ?? '' );
			$html      .= '<th' . $align_attr . '>' . self::render_inline( trim( $header ), $options ) . '</th>';
		}
		$html .= '</tr></thead>';

		if ( ! empty( $rows ) ) {
			$html .= '<tbody>';
			foreach ( $rows as $row ) {
				$html .= '<tr>';
				foreach ( $headers as $cell_index => $_header ) {
					$cell       = $row[ $cell_index ] ?? '';
					$align_attr = self::alignment_attr( $alignments[ $cell_index ] ?? '' );
					$html      .= '<td' . $align_attr . '>' . self::render_inline( trim( $cell ), $options ) . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody>';
		}

		$html .= '</table>';

		return array(
			'html'       => $html,
			'next_index' => $index,
		);
	}

	/**
	 * Split a table row into cells.
	 *
	 * @param string $row Row.
	 * @return array<int,string>
	 */
	private static function split_table_row( string $row ): array {
		$row = trim( $row );
		$row = trim( $row, '|' );
		return array_map( 'trim', preg_split( '/(?<!\\\\)\|/', $row ) ?: array() );
	}

	/**
	 * Alignment attribute for table cells.
	 *
	 * @param string $alignment Alignment.
	 * @return string Attribute or empty string.
	 */
	private static function alignment_attr( string $alignment ): string {
		if ( ! in_array( $alignment, array( 'left', 'center', 'right' ), true ) ) {
			return '';
		}

		return ' style="text-align:' . esc_attr( $alignment ) . '"';
	}

	/**
	 * Detect an HTML-ish block.
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	private static function looks_like_raw_html_block( string $line ): bool {
		return (bool) preg_match( '/^\s*<\/?[a-zA-Z][^>]*>\s*$/', trim( $line ) );
	}
}
