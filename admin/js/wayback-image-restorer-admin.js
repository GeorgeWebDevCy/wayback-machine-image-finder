(function($) {
    'use strict';

    const WIR = {
        currentScanId: null,
        currentResults: null,
        logsPage: 1,
        logsTotalPages: 1,
        scanProgressTimer: null,
        scanStartedAtMs: null,
        currentScanStageIndex: -1,

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#wir-start-scan').on('click', $.proxy(this.startScan, this));
            $('#wir-view-logs').on('click', $.proxy(this.viewLogs, this));
            $('#wir-download-logs').on('click', $.proxy(this.downloadLogs, this));
            $('#wir-clear-logs').on('click', $.proxy(this.clearLogs, this));
            $('#wir-rotate-logs').on('click', $.proxy(this.rotateLogs, this));
            
            this.bindModalEvents();
            this.bindLogViewerEvents();
        },

        bindModalEvents: function() {
            $('.wir-modal-close').on('click', $.proxy(function() {
                $(this).closest('.wir-modal').hide();
            }, this));
            
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    $('.wir-modal:visible').hide();
                }
            });
            
            $('#wir-confirm-cancel').on('click', function() {
                $('#wir-confirm-modal').hide();
            });
        },

        bindLogViewerEvents: function() {
            $('#wir-log-level, #wir-log-search').on('change input', $.proxy(function() {
                this.logsPage = 1;
                this.loadLogs();
            }, this));
            
            $('#wir-logs-prev').on('click', $.proxy(function() {
                if (this.logsPage > 1) {
                    this.logsPage--;
                    this.loadLogs();
                }
            }, this));
            
            $('#wir-logs-next').on('click', $.proxy(function() {
                if (this.logsPage < this.logsTotalPages) {
                    this.logsPage++;
                    this.loadLogs();
                }
            }, this));
        },

        startScanProgress: function() {
            this.stopScanProgress();
            this.scanStartedAtMs = Date.now();
            this.currentScanStageIndex = -1;

            $('#wir-scan-results').html(this.getScanProgressMarkup());
            this.updateScanProgress();

            this.scanProgressTimer = window.setInterval($.proxy(function() {
                this.updateScanProgress();
            }, this), 500);
        },

        stopScanProgress: function() {
            if (this.scanProgressTimer) {
                window.clearInterval(this.scanProgressTimer);
                this.scanProgressTimer = null;
            }

            this.scanStartedAtMs = null;
            this.currentScanStageIndex = -1;
        },

        getScanProgressMarkup: function() {
            const title = this.escapeHtml(wirData.strings.scanInProgress || 'Scan in progress');
            const stage = this.escapeHtml(wirData.strings.scanStagePrepare || 'Preparing scan request...');
            const elapsed = this.escapeHtml(wirData.strings.scanElapsed || 'Elapsed');
            const activityTitle = this.escapeHtml(wirData.strings.scanActivityTitle || 'Recent Scan Activity');

            return `
                <div class="wir-scan-progress" aria-live="polite">
                    <div class="wir-scan-progress-header">
                        <div>
                            <div class="wir-scan-progress-title">${title}</div>
                            <p id="wir-scan-stage" class="wir-scan-stage">${stage}</p>
                        </div>
                        <div class="wir-scan-elapsed">${elapsed}: <span id="wir-scan-elapsed-value">0.0s</span></div>
                    </div>
                    <div class="wir-progress-bar is-indeterminate">
                        <div class="wir-progress-fill"></div>
                    </div>
                    <div class="wir-scan-activity-panel is-live">
                        <div class="wir-scan-activity-heading">${activityTitle}</div>
                        <ul id="wir-scan-progress-feed" class="wir-scan-activity-feed">
                            <li class="wir-scan-progress-placeholder">${stage}</li>
                        </ul>
                    </div>
                </div>
            `;
        },

        getScanStages: function() {
            return [
                wirData.strings.scanStagePrepare || 'Preparing scan request...',
                wirData.strings.scanStagePosts || 'Scanning posts and pages for image references...',
                wirData.strings.scanStageImages || 'Checking image URLs and local files...',
                wirData.strings.scanStageWayback || 'Looking for archived copies in the Wayback Machine...',
                wirData.strings.scanStageMedia || 'Checking media library attachments...',
                wirData.strings.scanStageFinalize || 'Finalizing scan results...'
            ];
        },

        updateScanProgress: function() {
            if (!this.scanStartedAtMs) {
                return;
            }

            const elapsedSeconds = (Date.now() - this.scanStartedAtMs) / 1000;
            const stages = this.getScanStages();
            const stageIndex = Math.floor(elapsedSeconds / 4) % stages.length;
            const stageMessage = stages[stageIndex];

            $('#wir-scan-elapsed-value').text(this.formatElapsedTime(elapsedSeconds));
            $('#wir-scan-stage').text(stageMessage);

            if (stageIndex !== this.currentScanStageIndex) {
                this.currentScanStageIndex = stageIndex;
                this.appendScanProgressMessage(stageMessage);
            }
        },

        appendScanProgressMessage: function(message) {
            const $list = $('#wir-scan-progress-feed');
            if ($list.length === 0) {
                return;
            }

            $list.find('.wir-scan-progress-placeholder').remove();
            $list.prepend(`<li>${this.escapeHtml(message)}</li>`);
            $list.children().slice(5).remove();
        },

        postAjax: function(data, options) {
            const settings = options || {};
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: wirData.ajaxUrl,
                    type: 'POST',
                    data: data,
                    timeout: settings.timeout || 30000,
                    success: resolve,
                    error: reject
                });
            });
        },

        createBrowserDetectedBrokenImages: function(items) {
            const timestamp = new Date().toISOString();

            return items.map(function(item) {
                return {
                    url: item.url,
                    type: 'local',
                    referenced_in: [{
                        post_id: Number(item.attachment_id || 0),
                        post_title: item.post_title || '',
                        context: item.context || 'media_library'
                    }],
                    archive_found: false,
                    archive_url: null,
                    archive_timestamp: null,
                    last_checked: timestamp
                };
            });
        },

        runBrowserMediaVerification: async function(scanData, $status) {
            if (!scanData || !scanData.scan_id) {
                return scanData;
            }

            const verifiedData = $.extend(true, {}, scanData);
            verifiedData.broken_images = Array.isArray(verifiedData.broken_images) ? verifiedData.broken_images : [];

            const existingUrls = new Set(verifiedData.broken_images.map(function(image) {
                return image.url;
            }));

            let nextId = verifiedData.broken_images.reduce(function(maxId, image) {
                return Math.max(maxId, Number(image.id) || 0);
            }, 0);

            let offset = 0;
            let hasMore = true;
            let browserVerifiedBroken = 0;

            while (hasMore) {
                if ($status && $status.length) {
                    $status.text(wirData.strings.scanStageBrowser || 'Verifying media library URLs from the browser...');
                }

                let batchResponse;
                try {
                    batchResponse = await this.postAjax({
                        action: 'wir_get_media_candidates',
                        nonce: wirData.nonce,
                        offset: offset,
                        limit: 40,
                        date_from: $('#wir_date_from').val(),
                        date_to: $('#wir_date_to').val()
                    });
                } catch (error) {
                    return verifiedData;
                }

                if (!batchResponse || !batchResponse.success || !batchResponse.data) {
                    return verifiedData;
                }

                const items = Array.isArray(batchResponse.data.items) ? batchResponse.data.items : [];
                if (items.length === 0) {
                    break;
                }

                const brokenCandidates = await this.probeBrokenMediaCandidates(items, existingUrls);

                if (brokenCandidates.length > 0) {
                    let browserRecords = this.createBrowserDetectedBrokenImages(brokenCandidates);

                    try {
                        const enrichResponse = await this.postAjax({
                            action: 'wir_enrich_media_failures',
                            nonce: wirData.nonce,
                            items: JSON.stringify(brokenCandidates)
                        }, {
                            timeout: 20000
                        });

                        if (enrichResponse && enrichResponse.success && enrichResponse.data && Array.isArray(enrichResponse.data.broken_images) && enrichResponse.data.broken_images.length > 0) {
                            browserRecords = enrichResponse.data.broken_images;
                        }
                    } catch (error) {
                        browserRecords = this.createBrowserDetectedBrokenImages(brokenCandidates);
                    }

                    browserRecords.forEach(function(image) {
                        if (existingUrls.has(image.url)) {
                            return;
                        }

                        nextId += 1;
                        image.id = nextId;
                        verifiedData.broken_images.push(image);
                        existingUrls.add(image.url);
                        browserVerifiedBroken += 1;
                    });
                }

                hasMore = Boolean(batchResponse.data.has_more);
                offset = Number(batchResponse.data.next_offset || (offset + items.length));
            }

            verifiedData.stats = verifiedData.stats || {};
            verifiedData.stats.images_broken = verifiedData.broken_images.length;
            verifiedData.stats.browser_verified_broken = browserVerifiedBroken;

            return verifiedData;
        },

        probeBrokenMediaCandidates: async function(items, existingUrls) {
            const broken = [];
            const concurrency = 5;

            for (let index = 0; index < items.length; index += concurrency) {
                const chunk = items.slice(index, index + concurrency);
                const results = await Promise.all(chunk.map(async function(item) {
                    if (!item || !item.url || existingUrls.has(item.url)) {
                        return null;
                    }

                    const isBroken = await this.isPublicMediaUrlBroken(item.url);
                    return isBroken ? item : null;
                }, this));

                results.forEach(function(item) {
                    if (item) {
                        broken.push(item);
                    }
                });
            }

            return broken;
        },

        isPublicMediaUrlBroken: async function(url) {
            try {
                const response = await fetch(url, {
                    method: 'GET',
                    credentials: 'omit',
                    cache: 'no-store',
                    headers: {
                        'Range': 'bytes=0-255'
                    }
                });

                const contentType = String(response.headers.get('content-type') || '').split(';')[0].toLowerCase();
                return !response.ok || !(contentType.startsWith('image/') || contentType === 'application/octet-stream');
            } catch (error) {
                return true;
            }
        },

        startScan: function() {
            const $btn = $('#wir-start-scan');
            const $status = $('#wir-scan-status');

            this.currentScanId = null;
            this.currentResults = null;
            $btn.prop('disabled', true).text(wirData.strings.scanning);
            $status.removeClass('error success').text(wirData.strings.scanInProgress || 'Scanning for broken images...');
            this.startScanProgress();

            const data = {
                action: 'wir_scan',
                nonce: wirData.nonce,
                dry_run: $('#wir_dry_run').is(':checked'),
                post_types: $('input[name="wir_settings[post_types][]"]:checked').map(function() {
                    return $(this).val();
                }).get()
            };

            if ($('#wir_date_from').val()) {
                data.date_from = $('#wir_date_from').val();
            }
            if ($('#wir_date_to').val()) {
                data.date_to = $('#wir_date_to').val();
            }

            $.ajax({
                url: wirData.ajaxUrl,
                type: 'POST',
                data: data,
                success: $.proxy(function(response) {
                    if (response.success) {
                        this.stopScanProgress();
                        this.currentScanId = response.data.scan_id;
                        this.runBrowserMediaVerification(response.data, $status)
                            .then($.proxy(function(verifiedData) {
                                this.currentResults = verifiedData;
                                this.displayResults(verifiedData);
                                $status.addClass('success').text(wirData.strings.scanComplete || 'Scan complete!');
                            }, this))
                            .catch($.proxy(function() {
                                this.currentResults = response.data;
                                this.displayResults(response.data);
                                $status.addClass('success').text(wirData.strings.scanComplete || 'Scan complete!');
                            }, this));
                    } else {
                        this.stopScanProgress();
                        $('#wir-scan-results').html('<p class="description">Scan failed. Check the plugin logs for more details.</p>');
                        $status.addClass('error').text(response.data.message || 'Scan failed');
                    }
                }, this),
                error: $.proxy(function() {
                    this.stopScanProgress();
                    $('#wir-scan-results').html('<p class="description">Scan request failed. Please try again.</p>');
                    $status.addClass('error').text('Request failed');
                }, this),
                complete: $.proxy(function() {
                    this.stopScanProgress();
                    $btn.prop('disabled', false).text('Start Scan');
                }, this)
            });
        },

        displayResults: function(data) {
            const stats = data.stats;
            const brokenImages = data.broken_images;
            const durationSeconds = this.getDurationSeconds(data);
            
            let html = `
                <div class="wir-stats-summary">
                    <div class="wir-stat">
                        <div class="wir-stat-value">${stats.posts_scanned}</div>
                        <div class="wir-stat-label">Posts Scanned</div>
                    </div>
                    <div class="wir-stat">
                        <div class="wir-stat-value">${stats.images_found}</div>
                        <div class="wir-stat-label">Images Found</div>
                    </div>
                    <div class="wir-stat">
                        <div class="wir-stat-value">${stats.images_broken}</div>
                        <div class="wir-stat-label">Broken Images</div>
                    </div>
                    <div class="wir-stat">
                        <div class="wir-stat-value">${stats.images_ok}</div>
                        <div class="wir-stat-label">OK Images</div>
                    </div>
                </div>
            `;

            if (brokenImages.length === 0) {
                html += '<div class="wir-empty-state"><p>No broken images found!</p></div>';
            } else {
                html += `
                    <table>
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="wir-select-all"></th>
                                <th>Image</th>
                                <th>URL</th>
                                <th>Found In</th>
                                <th>Archive</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                brokenImages.forEach(function(img) {
                    const archiveClass = img.archive_found ? 'found' : 'not-found';
                    const archiveIcon = img.archive_found ? '&#10004;' : '&#10008;';
                    const archiveText = img.archive_found 
                        ? 'Found (' + this.formatTimestamp(img.archive_timestamp) + ')' 
                        : 'Not Found';
                    
                    const postsList = img.referenced_in.map(function(ref) {
                        return ref.post_title + ' (' + ref.context + ')';
                    }).join(', ');

                    html += `
                        <tr data-id="${img.id}">
                            <td><input type="checkbox" class="wir-image-checkbox" value="${img.id}"></td>
                            <td>
                                <div class="wir-broken-icon">&#9888;</div>
                            </td>
                            <td class="wir-url-cell" title="${img.url}">${img.url}</td>
                            <td class="wir-posts-cell" title="${postsList}">${postsList}</td>
                            <td>
                                <span class="wir-archive-status ${archiveClass}">
                                    ${archiveIcon} ${archiveText}
                                </span>
                            </td>
                            <td class="wir-actions-cell">
                                ${img.archive_found ? '<button class="button wir-restore-single" data-id="' + img.id + '">Restore</button>' : ''}
                                <button class="button wir-ignore" data-id="${img.id}">Ignore</button>
                            </td>
                        </tr>
                    `;
                }, this);

                html += `
                        </tbody>
                    </table>
                    <div class="wir-bulk-actions">
                        <button class="button button-primary" id="wir-restore-selected">Restore Selected (<span id="wir-selected-count">0</span>)</button>
                    </div>
                `;
            }

            if (stats.scan_stopped_early) {
                html += '<p class="description" style="color: #dba617; margin-top: 15px;">Note: Scan was stopped early due to resource limits. Consider scanning fewer posts.</p>';
            }

            if (Number(stats.browser_verified_broken || 0) > 0) {
                html += `<p class="description" style="margin-top: 15px;">Browser-verified media failures: ${Number(stats.browser_verified_broken)}.</p>`;
            }

            if (durationSeconds !== null) {
                html += `<p class="description" style="margin-top: 15px;">Duration: ${durationSeconds} seconds</p>`;
            }

            html += `
                <div class="wir-scan-activity-panel">
                    <div class="wir-scan-activity-heading">${this.escapeHtml(wirData.strings.scanActivityTitle || 'Recent Scan Activity')}</div>
                    <div id="wir-scan-activity-list">
                        <p class="description">${this.escapeHtml(wirData.strings.scanActivityLoading || 'Loading scan activity...')}</p>
                    </div>
                </div>
            `;

            $('#wir-scan-results').html(html);

            $('#wir-select-all').on('change', $.proxy(function(e) {
                const isChecked = $(e.currentTarget).prop('checked');
                $('.wir-image-checkbox').prop('checked', isChecked);
                this.updateSelectedCount();
            }, this));

            $('.wir-image-checkbox').on('change', $.proxy(function() {
                this.updateSelectedCount();
            }, this));

            $('.wir-restore-single').on('click', $.proxy(function(e) {
                const $btn = $(e.currentTarget);
                this.restoreImage(Number($btn.data('id')), $btn);
            }, this));

            $('#wir-restore-selected').on('click', $.proxy(function() {
                const selected = $('.wir-image-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                if (selected.length > 0) {
                    this.confirmRestore(selected);
                }
            }, this));

            this.updateSelectedCount();

            if (data.scan_id) {
                this.loadScanActivity(data.scan_id);
            }
        },

        formatTimestamp: function(ts) {
            if (!ts || ts.length < 8) return 'Unknown';
            return ts.substring(0, 4) + '-' + ts.substring(4, 6) + '-' + ts.substring(6, 8);
        },

        getDurationSeconds: function(data) {
            const value = Number(data.duration_seconds);
            return Number.isFinite(value) ? value : null;
        },

        formatElapsedTime: function(seconds) {
            return `${seconds < 10 ? seconds.toFixed(1) : seconds.toFixed(0)}s`;
        },

        getImageById: function(imageId) {
            if (!this.currentResults || !Array.isArray(this.currentResults.broken_images)) {
                return null;
            }

            return this.currentResults.broken_images.find(function(img) {
                return Number(img.id) === Number(imageId);
            }) || null;
        },

        updateSelectedCount: function() {
            const count = $('.wir-image-checkbox:checked').length;
            $('#wir-selected-count').text(count);
            this.updateSelectAllState();
        },

        updateSelectAllState: function() {
            const $selectAll = $('#wir-select-all');
            if ($selectAll.length === 0) {
                return;
            }

            const total = $('.wir-image-checkbox').length;
            const checked = $('.wir-image-checkbox:checked').length;

            $selectAll.prop('checked', total > 0 && checked === total);
            $selectAll.prop('indeterminate', checked > 0 && checked < total);
        },

        loadScanActivity: function(scanId) {
            const $container = $('#wir-scan-activity-list');
            if ($container.length === 0) {
                return;
            }

            $.ajax({
                url: wirData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wir_get_logs',
                    nonce: wirData.nonce,
                    page: 1,
                    per_page: 10,
                    search: scanId
                },
                success: $.proxy(function(response) {
                    if (!response.success) {
                        $container.html(`<p class="description">${this.escapeHtml(wirData.strings.scanActivityError || 'Unable to load scan activity.')}</p>`);
                        return;
                    }

                    this.renderScanActivity(response.data.logs || [], scanId);
                }, this),
                error: $.proxy(function() {
                    $container.html(`<p class="description">${this.escapeHtml(wirData.strings.scanActivityError || 'Unable to load scan activity.')}</p>`);
                }, this)
            });
        },

        renderScanActivity: function(logs, scanId) {
            const $container = $('#wir-scan-activity-list');
            if ($container.length === 0) {
                return;
            }

            const relevantLogs = Array.isArray(logs)
                ? logs.filter(function(log) {
                    return String(log.scan_id || '') === String(scanId);
                })
                : [];

            if (relevantLogs.length === 0) {
                $container.html(`<p class="description">${this.escapeHtml(wirData.strings.scanActivityEmpty || 'No scan activity was recorded for this run.')}</p>`);
                return;
            }

            let html = '<ul class="wir-scan-activity-feed">';

            relevantLogs.slice().reverse().forEach(function(log) {
                html += `
                    <li>
                        <span class="wir-scan-activity-time">${this.escapeHtml(this.formatLogTime(log.timestamp))}</span>
                        <span class="wir-scan-activity-text">${this.escapeHtml(this.formatScanLogMessage(log))}</span>
                    </li>
                `;
            }, this);

            html += '</ul>';
            $container.html(html);
        },

        formatLogTime: function(timestamp) {
            if (!timestamp) {
                return '';
            }

            const date = new Date(timestamp);
            if (Number.isNaN(date.getTime())) {
                return '';
            }

            return date.toLocaleTimeString();
        },

        formatScanLogMessage: function(log) {
            switch (log.action) {
                case 'scan_start':
                    return 'Scan started with the current filters.';
                case 'scan_posts_started':
                    return `Scanning ${Number(log.total_posts || 0)} post(s).`;
                case 'scan_posts_complete':
                    return `Finished posts scan after ${Number(log.posts_scanned || 0)} post(s) and ${Number(log.images_found || 0)} image reference(s).`;
                case 'scan_media_started':
                    return 'Checking media library attachments for missing files.';
                case 'scan_media_complete':
                    return `Media library scan finished. Broken images currently found: ${Number(log.broken_images || 0)}.`;
                case 'scan_stopped_early':
                    return `Scan stopped early after ${Number(log.processed_posts || 0)} of ${Number(log.total_posts || 0)} post(s) because of resource limits.`;
                case 'scan_complete':
                    return `Scan finished in ${log.duration_seconds || 0} second(s) with ${Number(log.found_broken || 0)} broken image(s).`;
                default:
                    return this.humanizeLogAction(log.action || 'scan_update');
            }
        },

        humanizeLogAction: function(action) {
            return String(action)
                .replace(/_/g, ' ')
                .replace(/\b\w/g, function(char) {
                    return char.toUpperCase();
                });
        },

        escapeHtml: function(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        confirmRestore: function(imageIds) {
            const count = imageIds.length;
            const dryRun = $('#wir_dry_run').is(':checked');
            
            $('#wir-confirm-title').text('Confirm Restore');
            $('#wir-confirm-message').html(
                `You are about to ${dryRun ? 'test restoring' : 'restore'} <strong>${count}</strong> image${count > 1 ? 's' : ''}.<br><br>` +
                (dryRun ? '<em>This is a dry run - no changes will be made.</em>' : 'This will download images and update your posts.')
            );
            
            $('#wir-confirm-ok').off('click').on('click', $.proxy(function() {
                $('#wir-confirm-modal').hide();
                this.bulkRestore(imageIds, dryRun);
            }, this));
            
            $('#wir-confirm-modal').show();
        },

        restoreImage: function(imageId, $btn) {
            const image = this.getImageById(imageId);
            if (!image) {
                alert('Image data is no longer available. Please run the scan again.');
                return;
            }

            const data = {
                action: 'wir_restore',
                nonce: wirData.nonce,
                image_url: image.url,
                archive_url: image.archive_url,
                dry_run: $('#wir_dry_run').is(':checked'),
                referenced_in: JSON.stringify(image.referenced_in || [])
            };

            $btn.prop('disabled', true).text('Restoring...');

            $.ajax({
                url: wirData.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        $btn.replaceWith('<span style="color: #00a32a;">&#10004; Done</span>');
                    } else {
                        $btn.prop('disabled', false).text('Retry');
                        alert('Restore failed: ' + (response.data.error || 'Unknown error'));
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Retry');
                    alert('Request failed');
                }
            });
        },

        bulkRestore: function(imageIds, dryRun) {
            const $modal = $('#wir-progress-modal');
            const $bar = $modal.find('.wir-progress-bar');
            const $fill = $('.wir-progress-fill');
            const $text = $('#wir-progress-text');
            const $current = $('#wir-progress-current');
            
            $modal.show();
            $bar.addClass('is-indeterminate');
            $fill.css('width', '');
            $text.text(dryRun ? 'Checking selected images...' : 'Restoring selected images...');
            $current.text('Submitting restore request...');

            const data = {
                action: 'wir_bulk_restore',
                nonce: wirData.nonce,
                image_ids: imageIds,
                dry_run: dryRun
            };

            $.ajax({
                url: wirData.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        const result = response.data;
                        $bar.removeClass('is-indeterminate');
                        $fill.css('width', '100%');
                        $text.text('Complete!');
                        $current.html(
                            `Succeeded: ${result.succeeded}<br>` +
                            `Failed: ${result.failed}<br>` +
                            (result.stopped_early ? '<em>Stopped early due to resource limits</em>' : '')
                        );
                        
                        setTimeout(function() {
                            $modal.hide();
                        }, 2000);
                    } else {
                        $bar.removeClass('is-indeterminate');
                        $fill.css('width', '0%');
                        alert('Bulk restore failed: ' + (response.data.error || 'Unknown error'));
                        $modal.hide();
                    }
                },
                error: function() {
                    $bar.removeClass('is-indeterminate');
                    $fill.css('width', '0%');
                    alert('Request failed');
                    $modal.hide();
                }
            });
        },

        viewLogs: function() {
            this.logsPage = 1;
            this.loadLogs();
            $('#wir-logs-modal').show();
        },

        loadLogs: function() {
            const data = {
                action: 'wir_get_logs',
                nonce: wirData.nonce,
                page: this.logsPage,
                level: $('#wir-log-level').val(),
                search: $('#wir-log-search').val()
            };

            $.ajax({
                url: wirData.ajaxUrl,
                type: 'POST',
                data: data,
                success: $.proxy(function(response) {
                    if (response.success) {
                        this.displayLogs(response.data.logs);
                        this.logsTotalPages = response.data.total_pages;
                        this.updateLogPagination();
                    }
                }, this)
            });
        },

        displayLogs: function(logs) {
            const $list = $('#wir-logs-list');
            
            if (logs.length === 0) {
                $list.html('<p style="padding: 20px; text-align: center;">No log entries found.</p>');
                return;
            }

            let html = '';
            logs.forEach(function(log) {
                const timestamp = log.timestamp ? new Date(log.timestamp).toLocaleString() : '';
                const level = log.level || 'info';
                const action = log.action || '';
                const details = JSON.stringify(log, null, 0).replace(/[{}"]/g, '').substring(0, 200);
                
                html += `
                    <div class="wir-log-entry">
                        <span class="timestamp">${timestamp}</span>
                        <span class="level ${level}">[${level.toUpperCase()}]</span>
                        <span class="action">${action}</span>
                        <span class="details">${details}</span>
                    </div>
                `;
            });

            $list.html(html);
        },

        updateLogPagination: function() {
            $('#wir-logs-prev').prop('disabled', this.logsPage <= 1);
            $('#wir-logs-next').prop('disabled', this.logsPage >= this.logsTotalPages);
            $('#wir-logs-page-info').text(`Page ${this.logsPage} of ${this.logsTotalPages}`);
        },

        downloadLogs: function() {
            const $btn = $('#wir-download-logs');
            $btn.prop('disabled', true).text('Downloading...');

            window.location.href = wirData.ajaxUrl + '?action=wir_download_logs&nonce=' + wirData.nonce;

            setTimeout(function() {
                $btn.prop('disabled', false).text('Download CSV');
            }, 1000);
        },

        clearLogs: function() {
            if (!confirm(wirData.strings.confirmClear)) {
                return;
            }

            $.ajax({
                url: wirData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wir_clear_logs',
                    nonce: wirData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('Failed to clear logs');
                    }
                }
            });
        },

        rotateLogs: function() {
            $.ajax({
                url: wirData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wir_rotate_logs',
                    nonce: wirData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('Failed to rotate logs');
                    }
                }
            });
        }
    };

    $(document).ready(function() {
        WIR.init();
    });

})(jQuery);
