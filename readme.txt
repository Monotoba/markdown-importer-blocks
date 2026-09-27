=== Markdown Importer Blocks ===
Contributors: rmorgan62
Tags: markdown, importer, block, gutenberg, html, mermaid, math, syntax-highlighting
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.0
License: BSD-2-Clause
License URI: https://opensource.org/license/bsd-2-clause

Adds a Markdown Importer block that can paste Markdown, read a Markdown file, import a Markdown URL, render it as WordPress HTML, and cooperate with Mermaid, Math, and Code Content Blocks.

== Description ==

Markdown Importer Blocks adds a block-editor block named "Markdown Importer".

Editors can:

* paste Markdown directly into the block;
* read a local .md/.markdown/.mdown/.mkd/.txt file in the browser;
* fetch Markdown from a public HTTP/HTTPS URL through an authenticated REST endpoint;
* render the Markdown dynamically on the frontend;
* copy the converted HTML; or
* replace the Markdown block with WordPress blocks.

Version 1.2 delegates specialist content to companion plugins when they are active:

* Mermaid fences become Mermaid Content Blocks-compatible markup or real mcb/mermaid blocks during conversion.
* TeX/LaTeX, AsciiMath, and MathML become Math Content Blocks-compatible markup or real mcb/math blocks during conversion.
* Fenced code becomes Code Content Blocks-compatible markup or real mcb/code blocks during conversion.

The plugin is intentionally dependency-free and uses a conservative built-in Markdown parser.

== Security ==

* Rendered output is filtered through a WordPress KSES allowlist.
* Raw HTML is disabled by default.
* URL imports require edit_posts capability.
* URL imports block local/private network addresses.
* URL fetches use wp_safe_remote_get(), short timeouts, limited redirects, and a 1 MiB size limit.
* Mermaid, math, and code rendering are delegated to companion plugins when active.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Insert the Markdown Importer block in the block editor.
4. Install and activate the optional companion plugins for Mermaid, math, and syntax highlighting.

== Changelog ==

= 1.2.0 =
Refactors rendering so Mermaid, math, and code are delegated to companion content-block plugins when available. Adds TeX/LaTeX, AsciiMath, MathML, and Code Content Blocks-compatible fenced code handling.

= 1.1.0 =
Adds Mermaid fence compatibility with Mermaid Content Blocks and conversion to Mermaid Diagram blocks when available.

= 1.0.0 =
Initial release.
