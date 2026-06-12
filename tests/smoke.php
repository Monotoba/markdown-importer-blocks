<?php
// Minimal smoke test harness for the standalone parser.
define( 'ABSPATH', __DIR__ );

function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr__( $value, $domain = null ) { return esc_attr( $value ); }
function esc_html__( $value, $domain = null ) { return esc_html( $value ); }
function esc_url( $value ) { return filter_var( (string) $value, FILTER_VALIDATE_URL ) ? htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) : ''; }
function sanitize_html_class( $value ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ); }
function sanitize_title( $value ) { $value = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $value ) ); return trim( $value, '-' ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_kses_post( $value ) { return (string) $value; }

require __DIR__ . '/../includes/class-markdown-parser.php';

$markdown = <<<'MD'
# Smoke Test

This is **bold**, _italic_, `code`, inline math $a^2 + b^2 = c^2$, and [a link](https://example.com).

- one
- two

| Name | Value |
| --- | ---: |
| A | 1 |

```php {1}
echo "ok";
```

```mermaid theme=dark
flowchart TD
    A[Markdown] --> B[Mermaid Content Blocks]
```

```asciimath
sum_(i=1)^n i = (n(n+1))/2
```

$$
\frac{-b \pm \sqrt{b^2 - 4ac}}{2a}
$$
MD;

$html = Markdown_Importer_Blocks\Markdown_Parser::render(
	$markdown,
	array(
		'heading_anchors'             => true,
		'code_theme'                 => 'dark',
		'code_show_line_numbers'     => true,
		'math_enable_variable_colors' => true,
		'math_variable_colors'        => "a = #d32f2f\nb = #1976d2",
	)
);

$required = array(
	'<h1 id="smoke-test">Smoke Test</h1>',
	'<strong>bold</strong>',
	'<em>italic</em>',
	'<code>code</code>',
	'<ul>',
	'<table',
	'mcb-code-block',
	'data-mcb-language="php"',
	'data-mcb-highlight-lines="1"',
	'mcb-mermaid-block',
	'data-mcb-theme="dark"',
	'data-mcb-math-block="1"',
	'"inputFormat":"asciimath"',
	'"inputFormat":"tex"',
	'"enableVariableColors":true',
);
foreach ( $required as $needle ) {
	if ( false === strpos( $html, $needle ) ) {
		fwrite( STDERR, "Missing expected output: {$needle}\n$html\n" );
		exit( 1 );
	}
}

echo "Parser smoke test passed.\n";
