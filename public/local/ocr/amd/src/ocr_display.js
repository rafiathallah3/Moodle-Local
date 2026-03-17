// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AMD module: local_ocr/ocr_display
 *
 * Scans the current page for Moodle pluginfile.php links that point to
 * image files and, for each one found inside an assignment submission or
 * quiz response area, appends a styled "OCR Extracted Text" panel
 * directly beneath the file link.
 *
 * The panel is populated by calling local/ocr_ajax.php with the file's
 * storage parameters (contextid, component, filearea, itemid, filepath,
 * filename) extracted from the Moodle file URL.
 *
 * Supported contexts
 * ------------------
 *   Assignment grading view  – links inside .fileuploadsubmission,
 *                              .submissionplugin, .submission
 *   Assignment student view  – same containers
 *   Quiz review page         – links inside .qtype_essay_response,
 *                              .attachments, .answer, .que
 *
 * @module     local_ocr/ocr_display
 * @package    local_ocr
 * @copyright  2026 Moodle OCR Plugin
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, CoreAjax, Notification) {

    'use strict';

    // ── Constants ─────────────────────────────────────────────────────────────

    /** File extensions we will attempt to OCR. Lower-case. */
    var IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif',
        'webp', 'bmp', 'tiff', 'tif'
    ];

    /**
     * CSS selectors for submission / response containers we care about.
     * We restrict OCR panels to these areas to avoid injecting into
     * unrelated file links (e.g. course resource lists, user avatars).
     */
    var SUBMISSION_SELECTORS = [
        // assign – file submission plugin wrapper
        '.fileuploadsubmission',
        // assign – generic submission plugin container
        '.submissionplugin',
        // assign – full submission box
        '.submission',
        // assign – the boxaligncenter summary box rendered by the plugin
        '.boxaligncenter',
        // quiz – essay response body
        '.qtype_essay_response',
        // quiz – attachment list rendered by essay/file upload questions
        '.attachments',
        // quiz – generic answer area (covers most question types)
        '.answer',
        // quiz – outer question wrapper
        '.que'
    ].join(', ');

    // ── URL parser ────────────────────────────────────────────────────────────

    /**
     * Attempt to parse a Moodle pluginfile.php URL into its constituent
     * file-storage parameters.
     *
     * Moodle URL format (two variants):
     *   /pluginfile.php/{contextid}/{component}/{filearea}/{itemid}/{filename}
     *   /pluginfile.php/{contextid}/{component}/{filearea}/{itemid}/{path}/{filename}
     *
     * @param  {string}      href  The href attribute of an anchor element.
     * @return {Object|null}       Parsed params, or null if not a match.
     */
    function parsePluginfileUrl(href) {
        if (!href || href.indexOf('/pluginfile.php/') === -1) {
            return null;
        }

        // Strip query-string / fragment before parsing the path.
        var cleanHref = href.split('?')[0].split('#')[0];

        // Locate the pluginfile segment and take everything after it.
        var marker = '/pluginfile.php/';
        var markerIdx = cleanHref.indexOf(marker);
        if (markerIdx === -1) {
            return null;
        }

        var pathAfterMarker = cleanHref.slice(markerIdx + marker.length);
        // pathAfterMarker: {contextid}/{component}/{filearea}/{itemid}/{rest...}
        var parts = pathAfterMarker.split('/');

        if (parts.length < 5) {
            // Need at least contextid / component / filearea / itemid / filename.
            return null;
        }

        var contextid = parts[0];
        var component = parts[1];
        var filearea  = parts[2];
        var itemid    = parts[3];

        // Everything after itemid is filepath + filename.
        // Moodle's convention: last segment is filename, preceding segments
        // (if any) form the filepath (always starts and ends with '/').
        var rest      = parts.slice(4); // e.g. ['filename.png'] or ['subdir','file.png']
        var filename  = rest[rest.length - 1];
        var filepath  = '/' + (rest.length > 1 ? rest.slice(0, -1).join('/') + '/' : '');

        // Validate that the filename looks like an image.
        var dotIdx = filename.lastIndexOf('.');
        if (dotIdx === -1) {
            return null;
        }
        var ext = filename.slice(dotIdx + 1).toLowerCase();
        if (IMAGE_EXTENSIONS.indexOf(ext) === -1) {
            return null;
        }

        return {
            contextid: contextid,
            component: component,
            filearea:  filearea,
            itemid:    itemid,
            filepath:  filepath,
            filename:  filename
        };
    }

    // ── DOM helpers ───────────────────────────────────────────────────────────

    /**
     * Build a stable, unique DOM id for an OCR panel so we never duplicate it.
     *
     * @param  {Object} p  Parsed file params.
     * @return {string}
     */
    function panelId(p) {
        var safe = p.filename.replace(/[^a-zA-Z0-9]/g, '_');
        return 'local-ocr-panel-' + p.contextid + '-' + p.itemid + '-' + safe;
    }

    /**
     * Render the OCR panel skeleton with a loading indicator and append it
     * after the given anchor element.
     *
     * @param  {jQuery} $anchor  The file-link element.
     * @param  {string} id       The DOM id for the new panel.
     * @return {jQuery}          The created panel element.
     */
    function createPanel($anchor, id) {
        var $panel = $('<div>', {
            id:    id,
            class: 'local-ocr-panel',
            css: {
                margin:       '8px 0 12px 0',
                padding:      '10px 14px',
                background:   '#f0f7ff',
                border:       '1px solid #b8d4f5',
                borderRadius: '6px',
                fontSize:     '0.92em',
                lineHeight:   '1.55',
                wordBreak:    'break-word'
            }
        });

        var $header = $('<div>', {
            class: 'local-ocr-panel-header',
            css: {
                display:       'flex',
                alignItems:    'center',
                marginBottom:  '6px',
                gap:           '8px'
            }
        });

        var $icon = $('<span>', {
            'aria-hidden': 'true',
            css: { fontSize: '1.1em' },
            text: '\uD83D\uDD0D' // 🔍 magnifying glass
        });

        var $title = $('<strong>', {
            css:  { color: '#1a5276', flexGrow: '1' },
            text: 'OCR Extracted Text'
        });

        var $badge = $('<span>', {
            class: 'local-ocr-badge',
            css: {
                fontSize:     '0.78em',
                padding:      '1px 6px',
                borderRadius: '10px',
                background:   '#d6eaf8',
                color:        '#1a5276',
                border:       '1px solid #aed6f1'
            },
            text: 'loading\u2026'
        });

        $header.append($icon, $title, $badge);

        var $body = $('<div>', {
            class: 'local-ocr-panel-body',
            css:   { color: '#1a1a1a', whiteSpace: 'pre-wrap' },
            text:  'Extracting text from image\u2026'
        });

        $panel.append($header, $body);

        // Insert the panel directly after the anchor element.
        $anchor.after($panel);

        return $panel;
    }

    /**
     * Update an existing panel with the OCR result.
     *
     * @param {jQuery}  $panel   The panel returned by createPanel().
     * @param {boolean} success  Whether the OCR call succeeded.
     * @param {string}  text     The extracted text, or an error message.
     * @param {boolean} cached   Whether the result came from the DB cache.
     */
    function updatePanel($panel, success, text, cached) {
        var $body  = $panel.find('.local-ocr-panel-body');
        var $badge = $panel.find('.local-ocr-badge');

        if (success) {
            var displayText = (text && text.trim() !== '')
                ? text
                : 'Text not found inside the image.';

            $body.text(displayText);

            if (displayText === 'Text not found inside the image.') {
                // Neutral/muted styling for the "no text" case.
                $panel.css({
                    background:   '#fafafa',
                    border:       '1px solid #d5d8dc',
                    color:        '#777'
                });
                $body.css('fontStyle', 'italic');
                $badge.text('no text').css({
                    background: '#f0f0f0',
                    color:      '#888',
                    border:     '1px solid #ccc'
                });
            } else {
                // Positive styling for a successful extraction.
                $panel.css({
                    background: '#eafaf1',
                    border:     '1px solid #a9dfbf'
                });
                $badge.text(cached ? 'cached' : 'extracted').css({
                    background: cached ? '#d5f5e3' : '#d6eaf8',
                    color:      cached ? '#1e8449' : '#1a5276',
                    border:     cached ? '1px solid #a9dfbf' : '1px solid #aed6f1'
                });
            }
        } else {
            // Error styling.
            $panel.css({
                background: '#fff8f0',
                border:     '1px solid #f5cba7'
            });
            $body.text('Text not found inside the image.').css({
                color:      '#884400',
                fontStyle:  'italic'
            });
            $badge.text('error').css({
                background: '#fdebd0',
                color:      '#884400',
                border:     '1px solid #f5cba7'
            });
        }
    }

    // ── AJAX call ─────────────────────────────────────────────────────────────

    /**
     * Request the OCR text for a single image file from the server.
     *
     * @param {string}   ajaxUrl  Full URL to ocr_ajax.php.
     * @param {string}   sesskey  Moodle session key.
     * @param {Object}   params   Parsed file params from parsePluginfileUrl().
     * @param {jQuery}   $panel   Panel element to update on completion.
     */
    function requestOcr(ajaxUrl, sesskey, params, $panel) {
        $.ajax({
            url:      ajaxUrl,
            method:   'POST',
            dataType: 'json',
            data: {
                sesskey:   sesskey,
                contextid: params.contextid,
                component: params.component,
                filearea:  params.filearea,
                itemid:    params.itemid,
                filepath:  params.filepath,
                filename:  params.filename
            },
            success: function(response) {
                if (response && response.success) {
                    updatePanel($panel, true, response.text, response.cached === true);
                } else {
                    var errMsg = (response && response.message)
                        ? response.message
                        : 'OCR request failed.';
                    updatePanel($panel, false, errMsg, false);
                }
            },
            error: function(jqXHR, textStatus) {
                updatePanel($panel, false, 'Network error: ' + textStatus, false);
            }
        });
    }

    // ── Main scan ─────────────────────────────────────────────────────────────

    /**
     * Walk every anchor element on the page, filter to those inside submission
     * containers that link to image files via pluginfile.php, and attach OCR
     * panels to them.
     *
     * If autostart is false a "Show OCR text" button is rendered instead of
     * immediately firing the AJAX request, letting graders fetch OCR on demand.
     *
     * @param {string}  ajaxUrl   Full URL to ocr_ajax.php.
     * @param {string}  sesskey   Moodle session key.
     * @param {boolean} autostart Whether to start OCR automatically.
     */
    function scanPage(ajaxUrl, sesskey, autostart) {
        // Find every anchor that lives inside a relevant submission container
        // AND points through pluginfile.php.
        var $candidates = $(SUBMISSION_SELECTORS).find('a[href*="/pluginfile.php/"]');

        // Also catch anchors that ARE themselves inside pluginfile.php paths
        // but were not nested in the submission selectors above (edge-case
        // layouts in some themes).
        var $direct = $('a[href*="/pluginfile.php/"]').filter(function() {
            return $(this).closest(SUBMISSION_SELECTORS).length > 0;
        });

        // Merge, de-duplicate via a Map keyed on the DOM node.
        var seen  = new Map();
        var nodes = [];

        $candidates.add($direct).each(function() {
            if (!seen.has(this)) {
                seen.set(this, true);
                nodes.push(this);
            }
        });

        nodes.forEach(function(anchor) {
            var href   = $(anchor).attr('href') || '';
            var params = parsePluginfileUrl(href);

            if (!params) {
                return; // not a supported image link
            }

            var id = panelId(params);

            // Never create a duplicate panel for the same file.
            if ($('#' + id).length > 0) {
                return;
            }

            var $anchor = $(anchor);

            if (autostart) {
                // ── Auto mode: create panel immediately and fire AJAX ─────────
                var $panel = createPanel($anchor, id);
                requestOcr(ajaxUrl, sesskey, params, $panel);

            } else {
                // ── Manual mode: show a small "Extract OCR Text" button ───────
                var $btn = $('<button>', {
                    type:  'button',
                    class: 'btn btn-sm btn-outline-secondary local-ocr-trigger',
                    css:   { marginLeft: '8px', fontSize: '0.82em' },
                    text:  '\uD83D\uDD0D Extract OCR Text'
                });

                $anchor.after($btn);

                $btn.on('click', function() {
                    $btn.remove();
                    var $panel = createPanel($anchor, id);
                    requestOcr(ajaxUrl, sesskey, params, $panel);
                });
            }
        });
    }

    // ── Public API ────────────────────────────────────────────────────────────

    return {

        /**
         * Entry point called by Moodle's AMD loader via
         * $PAGE->requires->js_call_amd('local_ocr/ocr_display', 'init', [$params]).
         *
         * @param {Object}  params
         * @param {string}  params.sesskey   Moodle session key.
         * @param {string}  params.wwwroot   Site root URL (unused directly, kept for flexibility).
         * @param {string}  params.ajaxurl   Absolute URL to local/ocr_ajax.php.
         * @param {boolean} params.autostart Whether to begin OCR immediately on load.
         */
        init: function(params) {
            var sesskey   = params.sesskey   || '';
            var ajaxUrl   = params.ajaxurl   || (params.wwwroot + '/local/ocr_ajax.php');
            var autostart = (params.autostart !== undefined) ? !!params.autostart : true;

            if (!sesskey || !ajaxUrl) {
                return;
            }

            // Run after the DOM is fully ready.
            $(document).ready(function() {
                scanPage(ajaxUrl, sesskey, autostart);

                // Some Moodle grading views (assign grading panel) load
                // submission content dynamically via their own AJAX calls.
                // We observe DOM mutations and re-scan whenever new nodes
                // containing pluginfile.php links are inserted.
                if (typeof MutationObserver !== 'undefined') {
                    var observer = new MutationObserver(function(mutations) {
                        var shouldScan = false;
                        mutations.forEach(function(mutation) {
                            mutation.addedNodes.forEach(function(node) {
                                if (node.nodeType === 1) { // Element node
                                    var html = node.innerHTML || '';
                                    if (html.indexOf('/pluginfile.php/') !== -1) {
                                        shouldScan = true;
                                    }
                                }
                            });
                        });
                        if (shouldScan) {
                            // Small debounce so rapid DOM changes only
                            // trigger one additional scan.
                            clearTimeout(observer._debounceTimer);
                            observer._debounceTimer = setTimeout(function() {
                                scanPage(ajaxUrl, sesskey, autostart);
                            }, 400);
                        }
                    });

                    observer.observe(document.body, {
                        childList: true,
                        subtree:   true
                    });
                }
            });
        }
    };
});
