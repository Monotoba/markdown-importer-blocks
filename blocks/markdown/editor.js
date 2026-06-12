(function (blocks, blockEditor, components, element, i18n, apiFetch, data) {
	'use strict';

	var registerBlockType = blocks.registerBlockType;
	var createBlock = blocks.createBlock;
	var getBlockType = blocks.getBlockType;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextareaControl = components.TextareaControl;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var Button = components.Button;
	var Notice = components.Notice;
	var Spinner = components.Spinner;
	var ToolbarGroup = components.ToolbarGroup;
	var ToolbarButton = components.ToolbarButton;
	var BlockControls = blockEditor.BlockControls;
	var createElement = element.createElement;
	var Fragment = element.Fragment;
	var useEffect = element.useEffect;
	var useRef = element.useRef;
	var useState = element.useState;
	var __ = i18n.__;
	var dispatch = data.dispatch;

	var settings = window.MarkdownImporterBlocksSettings || {
		restNamespace: 'markdown-importer-blocks/v1',
		maxBytes: 1048576,
		allowedFiles: ['.md', '.markdown', '.mdown', '.mkd', '.txt'],
		mermaidBlockName: 'mcb/mermaid',
		mathBlockName: 'mcb/math',
		codeBlockName: 'mcb/code',
		mermaidCompatible: false,
		mathCompatible: false,
		codeCompatible: false
	};

	function restPath(endpoint) {
		return '/' + settings.restNamespace.replace(/^\/+|\/+$/g, '') + '/' + endpoint.replace(/^\/+/, '');
	}

	function bytesToHuman(bytes) {
		if (bytes < 1024) {
			return bytes + ' B';
		}
		if (bytes < 1024 * 1024) {
			return (bytes / 1024).toFixed(1) + ' KiB';
		}
		return (bytes / (1024 * 1024)).toFixed(2) + ' MiB';
	}

	function escapeRegex(value) {
		return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	function firstFenceToken(info) {
		var parts = String(info || '').trim().toLowerCase().split(/\s+/);
		return (parts[0] || '').replace(/[^a-z0-9_+.#\/-]/g, '');
	}

	function isMermaidFenceInfo(info) {
		return /^mermaid(?:$|[\s,{:=-])/i.test(String(info || '').trim());
	}

	function isMathFenceInfo(info) {
		return ['math', 'tex', 'latex', 'asciimath', 'ascii', 'am', 'mathml', 'mml'].indexOf(firstFenceToken(info)) !== -1;
	}

	function mermaidFenceTheme(info) {
		var match = String(info || '').match(/\btheme\s*[:=]\s*([a-zA-Z0-9_-]+)/);
		var theme = match ? match[1].toLowerCase() : 'default';
		return ['default', 'neutral', 'dark', 'forest', 'base'].indexOf(theme) !== -1 ? theme : 'default';
	}

	function mathFenceFormat(info) {
		var token = firstFenceToken(info);
		var option = String(info || '').match(/\b(?:format|input|type)\s*[:=]\s*([a-zA-Z0-9_-]+)/);
		if (option) {
			token = option[1].toLowerCase();
		}
		if (['asciimath', 'ascii', 'am'].indexOf(token) !== -1) {
			return 'asciimath';
		}
		if (['mathml', 'mml'].indexOf(token) !== -1) {
			return 'mathml';
		}
		return 'tex';
	}

	function mathFenceDisplayMode(info) {
		if (/\bdisplay\s*[:=]\s*(inline|false|0|no)\b/i.test(String(info || ''))) {
			return false;
		}
		return true;
	}

	function slugifyLanguage(language) {
		return String(language || '')
			.toLowerCase()
			.trim()
			.replace(/[+#/_]/g, function (ch) {
				return { '+': 'plus', '#': 'sharp', '/': '-', '_': '-' }[ch] || '-';
			})
			.replace(/[^a-z0-9-]+/g, '-')
			.replace(/-+/g, '-')
			.replace(/^-+|-+$/g, '');
	}

	function normalizeCodeLanguage(language) {
		var key = slugifyLanguage(language || 'plaintext') || 'plaintext';
		var registry = window.CCBCodeLanguages || {};
		if (registry.aliases && registry.aliases[key]) {
			return registry.aliases[key];
		}
		var localAliases = {
			text: 'plaintext', txt: 'plaintext', none: 'plaintext',
			cplus: 'cpp', cplusplus: 'cpp', cc: 'cpp', cxx: 'cpp',
			csharp: 'csharp', 'c-sharp': 'csharp', cs: 'csharp',
			js: 'javascript', node: 'javascript', nodejs: 'javascript', ts: 'typescript',
			py: 'python', python3: 'python', kt: 'kotlin', kts: 'kotlin', rs: 'rust', golang: 'go',
			qbasic: 'quickbasic', qb: 'quickbasic', 'quick-basic': 'quickbasic',
			colorbasic: 'color-basic', 'coco-basic': 'color-basic', coco: 'color-basic', ecb: 'color-basic',
			'cbm-basic': 'commodore-basic', 'c64-basic': 'commodore-basic',
			pli: 'pl1', 'pl-i': 'pl1', 'pl-1': 'pl1',
			'turbo-pascal': 'pascal', tp: 'pascal', objectpascal: 'delphi', 'object-pascal': 'delphi',
			sh: 'bash', shell: 'bash', zsh: 'bash', ksh: 'bash', bat: 'dos', batch: 'dos', cmd: 'dos', msdos: 'dos',
			pwsh: 'powershell', ps1: 'powershell', tcsh: 'csh', jsonc: 'json', yml: 'yaml', md: 'markdown', patch: 'diff',
			x86: 'x86asm', '8086': 'x86asm', i8086: 'x86asm', i386: 'x86asm', '80386': 'x86asm',
			'6502': '6502asm', z80: 'z80asm', '68k': 'm68kasm', '68000': 'm68kasm', arm: 'armasm', avr: 'avrasm', mips: 'mipsasm'
		};
		return localAliases[key] || key || 'plaintext';
	}

	function codeFenceLanguage(info) {
		var token = firstFenceToken(info);
		return normalizeCodeLanguage(token || 'plaintext');
	}

	function codeFenceHighlightLines(info) {
		var text = String(info || '');
		var match = text.match(/\{\s*([0-9,\-\s]+)\s*\}/) || text.match(/\b(?:lines|highlight|highlight-lines)\s*[:=]\s*([0-9,\-\s]+)/i);
		return match ? match[1].replace(/[^0-9,\-\s]/g, '').replace(/\s+/g, '').replace(/^,+|,+$/g, '') : '';
	}

	function detectFenceType(info) {
		if (isMermaidFenceInfo(info)) {
			return 'mermaid';
		}
		if (isMathFenceInfo(info)) {
			return 'math';
		}
		return 'code';
	}

	function splitMarkdownBySpecialFences(markdown) {
		var lines = String(markdown || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
		var segments = [];
		var buffer = [];
		var index = 0;

		function pushText() {
			var text = buffer.join('\n');
			if (text.trim()) {
				segments.push({ type: 'markdown', markdown: text });
			}
			buffer = [];
		}

		while (index < lines.length) {
			var line = lines[index];
			var open = line.match(/^\s*(```+|~~~+)\s*([^`]*)\s*$/);
			if (open) {
				var marker = open[1];
				var info = open[2] || '';
				var fenceChar = marker.charAt(0);
				var closePattern = new RegExp('^\\s*' + escapeRegex(new Array(marker.length + 1).join(fenceChar)) + '+\\s*$');
				var source = [];
				var raw = [line];
				var closed = false;
				pushText();
				index += 1;
				while (index < lines.length) {
					if (closePattern.test(lines[index])) {
						raw.push(lines[index]);
						closed = true;
						index += 1;
						break;
					}
					source.push(lines[index]);
					raw.push(lines[index]);
					index += 1;
				}
				if (!closed) {
					raw.push(marker);
				}
				var sourceText = source.join('\n');
				var type = detectFenceType(info);
				if (sourceText.trim()) {
					if (type === 'mermaid') {
						segments.push({ type: 'mermaid', source: sourceText, theme: mermaidFenceTheme(info), raw: raw.join('\n') });
					} else if (type === 'math') {
						segments.push({ type: 'math', source: sourceText, inputFormat: mathFenceFormat(info), displayMode: mathFenceDisplayMode(info), raw: raw.join('\n') });
					} else {
						segments.push({ type: 'code', source: sourceText, language: codeFenceLanguage(info), highlightLines: codeFenceHighlightLines(info), raw: raw.join('\n') });
					}
				}
				continue;
			}

			buffer.push(line);
			index += 1;
		}

		pushText();
		return segments;
	}

	function hasBlockType(blockName) {
		return typeof getBlockType === 'function' && !!getBlockType(blockName);
	}

	function buildRenderRequestData(markdown, attributes) {
		return {
			markdown: markdown,
			allowRawHtml: !!attributes.allowRawHtml,
			openLinksNewTab: !!attributes.openLinksNewTab,
			preserveLineBreaks: !!attributes.preserveLineBreaks,
			headingAnchors: !!attributes.headingAnchors,
			codeTheme: attributes.codeTheme || 'system',
			codeShowLineNumbers: !!attributes.codeShowLineNumbers,
			codeWrapLines: !!attributes.codeWrapLines,
			codeShowCopyButton: attributes.codeShowCopyButton !== false,
			mathEnableVariableColors: !!attributes.mathEnableVariableColors,
			mathVariableColors: attributes.mathVariableColors || ''
		};
	}

	function renderCompanionBlocks(root) {
		if (!root) {
			return;
		}
		window.setTimeout(function () {
			if (window.MermaidContentBlocks && typeof window.MermaidContentBlocks.renderAll === 'function') {
				window.MermaidContentBlocks.renderAll(root);
			}
			if (window.MCBMathBlocks && typeof window.MCBMathBlocks.renderAll === 'function') {
				window.MCBMathBlocks.renderAll(root);
			}
			if (window.MCBCodeBlocks && typeof window.MCBCodeBlocks.renderAll === 'function') {
				window.MCBCodeBlocks.renderAll(root);
			}
		}, 0);
	}

	function MarkdownImporterEdit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var clientId = props.clientId;
		var blockProps = useBlockProps({ className: 'mib-editor-block' });
		var fileInputRef = useRef(null);
		var previewRef = useRef(null);
		var requestCounter = useRef(0);
		var markdown = attributes.markdown || '';
		var sourceType = attributes.sourceType || 'paste';
		var sourceUrl = attributes.sourceUrl || '';
		var caption = attributes.caption || '';
		var showSource = !!attributes.showSource;
		var allowRawHtml = !!attributes.allowRawHtml;
		var openLinksNewTab = !!attributes.openLinksNewTab;
		var preserveLineBreaks = !!attributes.preserveLineBreaks;
		var headingAnchors = !!attributes.headingAnchors;
		var codeTheme = attributes.codeTheme || 'system';
		var codeShowLineNumbers = !!attributes.codeShowLineNumbers;
		var codeWrapLines = !!attributes.codeWrapLines;
		var codeShowCopyButton = attributes.codeShowCopyButton !== false;
		var mathEnableVariableColors = !!attributes.mathEnableVariableColors;
		var mathVariableColors = attributes.mathVariableColors || '';
		var [previewHtml, setPreviewHtml] = useState('');
		var [isRendering, setIsRendering] = useState(false);
		var [isFetching, setIsFetching] = useState(false);
		var [message, setMessage] = useState(null);
		var [showPreview, setShowPreview] = useState(true);

		function renderMarkdown() {
			var currentRequest = ++requestCounter.current;
			setIsRendering(true);
			return apiFetch({
				path: restPath('render'),
				method: 'POST',
				data: buildRenderRequestData(markdown, attributes)
			}).then(function (response) {
				if (currentRequest === requestCounter.current) {
					setPreviewHtml(response.html || '');
				}
				return response.html || '';
			}).catch(function (error) {
				setMessage({ status: 'error', text: error.message || __('Unable to render Markdown.', 'markdown-importer-blocks') });
				return '';
			}).finally(function () {
				if (currentRequest === requestCounter.current) {
					setIsRendering(false);
				}
			});
		}

		useEffect(function () {
			var timeout = window.setTimeout(function () {
				renderMarkdown();
			}, 450);

			return function () {
				window.clearTimeout(timeout);
			};
		}, [markdown, allowRawHtml, openLinksNewTab, preserveLineBreaks, headingAnchors, codeTheme, codeShowLineNumbers, codeWrapLines, codeShowCopyButton, mathEnableVariableColors, mathVariableColors]);

		useEffect(function () {
			if (!showPreview || !previewRef.current) {
				return;
			}
			renderCompanionBlocks(previewRef.current);
		}, [previewHtml, showPreview]);

		function onFileSelected(event) {
			var file = event.target.files && event.target.files[0];
			if (!file) {
				return;
			}

			if (file.size > settings.maxBytes) {
				setMessage({ status: 'error', text: __('The selected file is too large. Maximum size is ', 'markdown-importer-blocks') + bytesToHuman(settings.maxBytes) + '.' });
				return;
			}

			var name = file.name || '';
			var allowed = settings.allowedFiles.some(function (suffix) {
				return name.toLowerCase().endsWith(suffix);
			});

			if (!allowed && file.type && !/^text\//.test(file.type)) {
				setMessage({ status: 'warning', text: __('This does not look like a Markdown/text file, but it will still be read as text.', 'markdown-importer-blocks') });
			}

			var reader = new FileReader();
			reader.onload = function () {
				setAttributes({
					markdown: String(reader.result || ''),
					sourceType: 'upload',
					sourceUrl: ''
				});
				setMessage({ status: 'success', text: __('Markdown file loaded into the block.', 'markdown-importer-blocks') });
			};
			reader.onerror = function () {
				setMessage({ status: 'error', text: __('Unable to read the selected file.', 'markdown-importer-blocks') });
			};
			reader.readAsText(file);
		}

		function fetchMarkdownUrl() {
			if (!sourceUrl.trim()) {
				setMessage({ status: 'warning', text: __('Enter a Markdown URL first.', 'markdown-importer-blocks') });
				return;
			}

			setIsFetching(true);
			setMessage(null);

			apiFetch({
				path: restPath('fetch-url'),
				method: 'POST',
				data: { url: sourceUrl.trim() }
			}).then(function (response) {
				setAttributes({
					markdown: response.markdown || '',
					sourceType: 'url'
				});
				setMessage({ status: 'success', text: __('Markdown URL imported into the block.', 'markdown-importer-blocks') });
			}).catch(function (error) {
				setMessage({ status: 'error', text: error.message || __('Unable to fetch the Markdown URL.', 'markdown-importer-blocks') });
			}).finally(function () {
				setIsFetching(false);
			});
		}

		function copyHtml() {
			renderMarkdown().then(function (html) {
				if (!html) {
					setMessage({ status: 'warning', text: __('There is no rendered HTML to copy yet.', 'markdown-importer-blocks') });
					return;
				}

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(html).then(function () {
						setMessage({ status: 'success', text: __('Converted HTML copied to the clipboard.', 'markdown-importer-blocks') });
					}).catch(function () {
						setMessage({ status: 'error', text: __('Unable to copy HTML to the clipboard.', 'markdown-importer-blocks') });
					});
				} else {
					setMessage({ status: 'warning', text: __('Clipboard access is not available in this browser. Use “Replace with WordPress blocks” instead.', 'markdown-importer-blocks') });
				}
			});
		}

		function renderSegmentAsHtmlBlock(segment) {
			var sourceMarkdown = segment.type === 'markdown' ? segment.markdown : segment.raw;
			return apiFetch({
				path: restPath('render'),
				method: 'POST',
				data: buildRenderRequestData(sourceMarkdown || '', attributes)
			}).then(function (response) {
				var html = response.html || '';
				return html.trim() ? createBlock('core/html', { content: html }) : null;
			});
		}

		function convertSegmentToBlock(segment) {
			if (segment.type === 'mermaid' && hasBlockType(settings.mermaidBlockName || 'mcb/mermaid')) {
				return Promise.resolve(createBlock(settings.mermaidBlockName || 'mcb/mermaid', {
					source: segment.source,
					theme: segment.theme || 'default',
					showSource: false
				}));
			}

			if (segment.type === 'math' && hasBlockType(settings.mathBlockName || 'mcb/math')) {
				return Promise.resolve(createBlock(settings.mathBlockName || 'mcb/math', {
					source: segment.source,
					inputFormat: segment.inputFormat || 'tex',
					displayMode: segment.displayMode !== false,
					enableVariableColors: mathEnableVariableColors,
					variableColors: mathVariableColors,
					showSource: false
				}));
			}

			if (segment.type === 'code' && hasBlockType(settings.codeBlockName || 'mcb/code')) {
				return Promise.resolve(createBlock(settings.codeBlockName || 'mcb/code', {
					source: segment.source,
					language: segment.language || 'plaintext',
					theme: codeTheme || 'system',
					showLanguage: true,
					showLineNumbers: codeShowLineNumbers,
					showCopyButton: codeShowCopyButton,
					wrapLines: codeWrapLines,
					highlightLines: segment.highlightLines || ''
				}));
			}

			return renderSegmentAsHtmlBlock(segment);
		}

		function replaceWithWordPressBlocks() {
			var segments = splitMarkdownBySpecialFences(markdown);
			if (!segments.length) {
				renderMarkdown().then(function (html) {
					if (!html) {
						setMessage({ status: 'warning', text: __('There is no rendered HTML to insert yet.', 'markdown-importer-blocks') });
						return;
					}
					dispatch('core/block-editor').replaceBlocks(clientId, createBlock('core/html', { content: html }));
				});
				return;
			}

			setIsRendering(true);
			segments.reduce(function (promise, segment) {
				return promise.then(function (newBlocks) {
					return convertSegmentToBlock(segment).then(function (block) {
						if (block) {
							newBlocks.push(block);
						}
						return newBlocks;
					});
				});
			}, Promise.resolve([])).then(function (newBlocks) {
				if (!newBlocks.length) {
					setMessage({ status: 'warning', text: __('There is no rendered content to insert yet.', 'markdown-importer-blocks') });
					return;
				}

				dispatch('core/block-editor').replaceBlocks(clientId, newBlocks);
			}).catch(function (error) {
				setMessage({ status: 'error', text: error.message || __('Unable to convert Markdown to WordPress blocks.', 'markdown-importer-blocks') });
			}).finally(function () {
				setIsRendering(false);
			});
		}

		function renderNotice() {
			if (!message) {
				return null;
			}

			return createElement(Notice, {
				status: message.status,
				isDismissible: true,
				onRemove: function () { setMessage(null); }
			}, message.text);
		}

		function renderSourceControls() {
			var uploadHelp = __('Upload reads the Markdown file into this block; it does not create a Media Library attachment.', 'markdown-importer-blocks');

			return createElement(Fragment, null,
				createElement(SelectControl, {
					label: __('Markdown source', 'markdown-importer-blocks'),
					value: sourceType,
					options: [
						{ label: __('Paste text', 'markdown-importer-blocks'), value: 'paste' },
						{ label: __('Upload file', 'markdown-importer-blocks'), value: 'upload' },
						{ label: __('Import from URL', 'markdown-importer-blocks'), value: 'url' }
					],
					onChange: function (value) { setAttributes({ sourceType: value }); }
				}),
				sourceType === 'upload' && createElement('div', { className: 'mib-editor-source-panel' },
					createElement('p', { className: 'mib-help' }, uploadHelp),
					createElement('input', {
						type: 'file',
						accept: '.md,.markdown,.mdown,.mkd,.txt,text/markdown,text/plain',
						ref: fileInputRef,
						onChange: onFileSelected
					}),
					createElement('p', { className: 'mib-help' }, __('Maximum file size: ', 'markdown-importer-blocks') + bytesToHuman(settings.maxBytes))
				),
				sourceType === 'url' && createElement('div', { className: 'mib-editor-source-panel' },
					createElement(TextControl, {
						label: __('Markdown URL', 'markdown-importer-blocks'),
						value: sourceUrl,
						placeholder: 'https://example.com/README.md',
						onChange: function (value) { setAttributes({ sourceUrl: value }); }
					}),
					createElement(Button, {
						variant: 'primary',
						onClick: fetchMarkdownUrl,
						isBusy: isFetching,
						disabled: isFetching
					}, isFetching ? __('Fetching…', 'markdown-importer-blocks') : __('Import URL into block', 'markdown-importer-blocks')),
					createElement('p', { className: 'mib-help' }, __('Remote URLs are fetched by WordPress only for logged-in editors. Local/private network URLs are blocked.', 'markdown-importer-blocks'))
				)
			);
		}

		return createElement(Fragment, null,
			createElement(BlockControls, null,
				createElement(ToolbarGroup, null,
					createElement(ToolbarButton, {
						icon: 'visibility',
						label: showPreview ? __('Hide preview', 'markdown-importer-blocks') : __('Show preview', 'markdown-importer-blocks'),
						onClick: function () { setShowPreview(!showPreview); },
						isPressed: showPreview
					}),
					createElement(ToolbarButton, {
						icon: 'editor-code',
						label: __('Copy converted HTML', 'markdown-importer-blocks'),
						onClick: copyHtml
					})
				)
			),
			createElement(InspectorControls, null,
				createElement(PanelBody, { title: __('Markdown import', 'markdown-importer-blocks'), initialOpen: true },
					renderSourceControls(),
					createElement(TextControl, {
						label: __('Caption', 'markdown-importer-blocks'),
						value: caption,
						onChange: function (value) { setAttributes({ caption: value }); }
					})
				),
				createElement(PanelBody, { title: __('Rendering options', 'markdown-importer-blocks'), initialOpen: false },
					createElement(ToggleControl, {
						label: __('Show Markdown source on the frontend', 'markdown-importer-blocks'),
						checked: showSource,
						onChange: function (value) { setAttributes({ showSource: value }); }
					}),
					createElement(ToggleControl, {
						label: __('Allow raw HTML from Markdown', 'markdown-importer-blocks'),
						help: __('Raw HTML is still filtered through WordPress post-content sanitization.', 'markdown-importer-blocks'),
						checked: allowRawHtml,
						onChange: function (value) { setAttributes({ allowRawHtml: value }); }
					}),
					createElement(ToggleControl, {
						label: __('Open links in a new tab', 'markdown-importer-blocks'),
						checked: openLinksNewTab,
						onChange: function (value) { setAttributes({ openLinksNewTab: value }); }
					}),
					createElement(ToggleControl, {
						label: __('Preserve single line breaks', 'markdown-importer-blocks'),
						checked: preserveLineBreaks,
						onChange: function (value) { setAttributes({ preserveLineBreaks: value }); }
					}),
					createElement(ToggleControl, {
						label: __('Add heading anchors', 'markdown-importer-blocks'),
						checked: headingAnchors,
						onChange: function (value) { setAttributes({ headingAnchors: value }); }
					})
				),
				createElement(PanelBody, { title: __('Code rendering', 'markdown-importer-blocks'), initialOpen: false },
					createElement(SelectControl, {
						label: __('Code theme', 'markdown-importer-blocks'),
						value: codeTheme,
						options: [
							{ label: __('System', 'markdown-importer-blocks'), value: 'system' },
							{ label: __('Light', 'markdown-importer-blocks'), value: 'light' },
							{ label: __('Dark', 'markdown-importer-blocks'), value: 'dark' }
						],
						onChange: function (value) { setAttributes({ codeTheme: value }); }
					}),
					createElement(ToggleControl, {
						label: __('Show line numbers for fenced code', 'markdown-importer-blocks'),
						checked: codeShowLineNumbers,
						onChange: function (value) { setAttributes({ codeShowLineNumbers: value }); }
					}),
					createElement(ToggleControl, {
						label: __('Wrap long code lines', 'markdown-importer-blocks'),
						checked: codeWrapLines,
						onChange: function (value) { setAttributes({ codeWrapLines: value }); }
					}),
					createElement(ToggleControl, {
						label: __('Show code copy button', 'markdown-importer-blocks'),
						checked: codeShowCopyButton,
						onChange: function (value) { setAttributes({ codeShowCopyButton: value }); }
					})
				),
				createElement(PanelBody, { title: __('Math rendering', 'markdown-importer-blocks'), initialOpen: false },
					createElement(ToggleControl, {
						label: __('Enable math variable colors', 'markdown-importer-blocks'),
						checked: mathEnableVariableColors,
						onChange: function (value) { setAttributes({ mathEnableVariableColors: value }); }
					}),
					createElement(TextareaControl, {
						label: __('Variable color rules', 'markdown-importer-blocks'),
						help: __('One rule per line, such as x = #d32f2f or \\alpha = purple. Used by Math Content Blocks.', 'markdown-importer-blocks'),
						value: mathVariableColors,
						rows: 5,
						onChange: function (value) { setAttributes({ mathVariableColors: value }); }
					})
				),
				createElement(PanelBody, { title: __('Conversion', 'markdown-importer-blocks'), initialOpen: false },
					createElement('p', null, __('Display mode keeps Markdown editable and renders HTML dynamically. Convert mode replaces ordinary Markdown with Custom HTML blocks and turns fenced Mermaid, math, and code sections into their companion blocks when those plugins are active.', 'markdown-importer-blocks')),
					createElement(Button, { variant: 'secondary', onClick: copyHtml }, __('Copy converted HTML', 'markdown-importer-blocks')),
					createElement(Button, { variant: 'primary', className: 'mib-convert-button', onClick: replaceWithWordPressBlocks }, __('Replace with WordPress blocks', 'markdown-importer-blocks'))
				)
			),
			createElement('div', blockProps,
				createElement('div', { className: 'mib-editor-header' },
					createElement('strong', null, __('Markdown Importer', 'markdown-importer-blocks')),
					isRendering && createElement('span', { className: 'mib-rendering' }, createElement(Spinner, null), __('Rendering preview…', 'markdown-importer-blocks'))
				),
				renderNotice(),
				createElement('div', { className: 'mib-editor-main' },
					createElement('div', { className: showPreview ? 'mib-editor-column' : 'mib-editor-column mib-editor-column-full' },
						createElement(TextareaControl, {
							label: __('Markdown', 'markdown-importer-blocks'),
							value: markdown,
							rows: 18,
							onChange: function (value) { setAttributes({ markdown: value, sourceType: sourceType || 'paste' }); }
						})
					),
					showPreview && createElement('div', { className: 'mib-editor-column mib-preview-column' },
						createElement('div', { className: 'mib-preview-title' }, __('Preview', 'markdown-importer-blocks')),
						createElement('div', { className: 'mib-preview', ref: previewRef, dangerouslySetInnerHTML: { __html: previewHtml || '<p><em>' + __('Nothing to preview yet.', 'markdown-importer-blocks') + '</em></p>' } })
					)
				),
				createElement('div', { className: 'mib-editor-actions' },
					createElement(Button, { variant: 'secondary', onClick: renderMarkdown, disabled: isRendering }, __('Refresh preview', 'markdown-importer-blocks')),
					createElement(Button, { variant: 'secondary', onClick: copyHtml }, __('Copy HTML', 'markdown-importer-blocks')),
					createElement(Button, { variant: 'primary', onClick: replaceWithWordPressBlocks }, __('Replace with WordPress blocks', 'markdown-importer-blocks'))
				)
			)
		);
	}

	registerBlockType('markdown-importer-blocks/markdown', {
		edit: MarkdownImporterEdit,
		save: function () {
			return null;
		}
	});
})(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n,
	window.wp.apiFetch,
	window.wp.data
);
