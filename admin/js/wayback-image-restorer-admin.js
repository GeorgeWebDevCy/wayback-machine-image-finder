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
            this.updateDryRunIndicator();
            this.loadPersistedResults();
        },

        bindEvents: function() {
            $('#wir-start-scan').on('click', $.proxy(this.startScan, this));
            $('#wir-view-logs').on('click', $.proxy(this.viewLogs, this));
            $('#wir-download-logs').on('click', $.proxy(this.downloadLogs, this));
            $('#wir-clear-logs').on('click', $.proxy(this.clearLogs, this));
            $('#wir-rotate-logs').on('click', $.proxy(this.rotateLogs, this));
            $('#wir_dry_run').on('change', $.proxy(this.updateDryRunIndicator, this));
            
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
                    target_date: item.target_date || null,
                    archive_found: false,
                    archive_url: null,
                    archive_timestamp: null,
                    last_checked: timestamp
                };
            });
        },

        loadPersistedResults: function() {
            const $status = $('#wir-scan-status');
            $status.removeClass('error success').text(wirData.strings.loadingSavedResults || 'Loading the most recent saved scan results...');

            $.ajax({
                url: wirData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wir_get_results',
                    nonce: wirData.nonce
                },
                success: $.proxy(function(response) {
                    if (!response.success || !response.data || !response.data.scan_id) {
                        $status.text('');
                        return;
                    }

                    this.currentScanId = response.data.scan_id;
                    this.currentResults = response.data;
                    this.displayResults(response.data);
                    $status.addClass('success').text(wirData.strings.loadedSavedResults || 'Loaded the most recent saved scan results.');
                }, this),
                error: function() {
                    $status.text('');
                }
            });
        },

        updateDryRunIndicator: function() {
            const isDryRun = $('#wir_dry_run').is(':checked');
            const $indicator = $('#wir-dry-run-indicator');
            const $button = $('#wir-start-scan');

            if (isDryRun) {
                $indicator.text(wirData.strings.dryRunActive || 'Dry-run mode is active. Restores will be simulated only.').show();
                $button.text(wirData.strings.startDryRunScan || 'Start Dry-Run Scan');
            } else {
                $indicator.hide().text('');
                $button.text(wirData.strings.startScan || 'Start Scan');
            }
        },

        syncBrowserResults: function(scanId, brokenImages) {
            if (!scanId || !Array.isArray(brokenImages) || brokenImages.length === 0) {
                return Promise.resolve(null);
            }

            return this.postAjax({
                action: 'wir_merge_browser_results',
                nonce: wirData.nonce,
                scan_id: scanId,
                broken_images: JSON.stringify(brokenImages)
            }, {
                timeout: 30000
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
            const addedBrowserRecords = [];

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
                        addedBrowserRecords.push($.extend(true, {}, image));
                    });
                }

                hasMore = Boolean(batchResponse.data.has_more);
                offset = Number(batchResponse.data.next_offset || (offset + items.length));
            }

            verifiedData.stats = verifiedData.stats || {};
            verifiedData.stats.images_broken = verifiedData.broken_images.length;
            verifiedData.stats.browser_verified_broken = browserVerifiedBroken;

            if (addedBrowserRecords.length > 0) {
                try {
                    const syncResponse = await this.syncBrowserResults(verifiedData.scan_id, addedBrowserRecords);
                    if (syncResponse && syncResponse.success && syncResponse.data) {
                        return syncResponse.data;
                    }
                } catch (error) {
                    return verifiedData;
                }
            }

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
                    $btn.prop('disabled', false);
                    this.updateDryRunIndicator();
                }, this)
            });
        },

        displayResults: function(data) {
            const stats = data.stats || {};
            const brokenImages = Array.isArray(data.broken_images) ? data.broken_images : [];
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
                    const postsList = (Array.isArray(img.referenced_in) ? img.referenced_in : []).map(function(ref) {
                        return ref.post_title + ' (' + ref.context + ')';
                    }).join(', ');
                    const escapedUrl = this.escapeHtml(img.url || '');
                    const escapedPostsList = this.escapeHtml(postsList);
                    const rowClass = this.isImageRestored(img) ? ' class="wir-row-restored"' : '';

                    html += `
                        <tr data-id="${img.id}"${rowClass}>
                            <td>${this.renderSelectionCell(img)}</td>
                            <td>
                                <div class="wir-broken-icon">&#9888;</div>
                            </td>
                            <td class="wir-url-cell" title="${escapedUrl}">${escapedUrl}</td>
                            <td class="wir-posts-cell" title="${escapedPostsList}">${escapedPostsList}</td>
                            <td>${this.renderArchiveCell(img, archiveClass, archiveIcon, archiveText)}</td>
                            <td class="wir-actions-cell">
                                ${this.renderImageActions(img)}
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

            $('.wir-undo-restore').on('click', $.proxy(function(e) {
                const $btn = $(e.currentTarget);
                this.undoRestore(Number($btn.data('id')), Number($btn.data('attachment-id')), $btn);
            }, this));

            $('.wir-ignore').on('click', $.proxy(function(e) {
                this.ignoreImage(Number($(e.currentTarget).data('id')));
            }, this));

            $('.wir-target-date').on('change', $.proxy(function(e) {
                const $input = $(e.currentTarget);
                this.updateImageTargetDate(Number($input.data('id')), String($input.val() || ''));
            }, this));

            $('.wir-refresh-archive').on('click', $.proxy(function(e) {
                const $btn = $(e.currentTarget);
                this.lookupArchive(Number($btn.data('id')), $btn);
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

        isImageRestored: function(image) {
            return Boolean(image && Number(image.restored_attachment_id || 0) > 0);
        },

        renderSelectionCell: function(image) {
            if (this.isImageRestored(image)) {
                return '';
            }

            return `<input type="checkbox" class="wir-image-checkbox" value="${Number(image.id)}">`;
        },

        renderImageActions: function(image) {
            if (this.isImageRestored(image)) {
                let html = `<span class="wir-restore-complete">&#10004; ${this.escapeHtml(wirData.strings.restored || 'Restored')}</span>`;

                if (image.undo_available && Number(image.restored_attachment_id || 0) > 0) {
                    html += ` <button class="button wir-undo-restore" data-id="${Number(image.id)}" data-attachment-id="${Number(image.restored_attachment_id)}">${this.escapeHtml(wirData.strings.undoRestore || 'Undo Restore')}</button>`;
                }

                return html;
            }

            let html = '';
            if (image.archive_found) {
                html += `<button class="button wir-restore-single" data-id="${Number(image.id)}">Restore</button> `;
            }

            html += `<button class="button wir-ignore" data-id="${Number(image.id)}">Ignore</button>`;

            if (image.restore_status === 'failed' && image.restore_error) {
                html += ` <span class="wir-restore-error">${this.escapeHtml(image.restore_error)}</span>`;
            }

            return html;
        },

        renderArchiveCell: function(image, archiveClass, archiveIcon, archiveText) {
            const targetDateValue = this.formatTargetDateInputValue(image.target_date || '');

            return `
                <div class="wir-archive-cell">
                    <span class="wir-archive-status ${archiveClass}">
                        ${archiveIcon} ${this.escapeHtml(archiveText)}
                    </span>
                    <label class="wir-archive-date-label">
                        <span>${this.escapeHtml(wirData.strings.archiveDate || 'Archive Date')}</span>
                        <input type="date" class="wir-target-date" data-id="${Number(image.id)}" value="${this.escapeHtml(targetDateValue)}">
                    </label>
                    <button type="button" class="button button-small wir-refresh-archive" data-id="${Number(image.id)}">
                        ${this.escapeHtml(wirData.strings.recheckArchive || 'Recheck Archive')}
                    </button>
                </div>
            `;
        },

        formatTargetDateInputValue: function(value) {
            if (!value) {
                return '';
            }

            const normalized = String(value).trim();
            const isoMatch = normalized.match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (isoMatch) {
                return `${isoMatch[1]}-${isoMatch[2]}-${isoMatch[3]}`;
            }

            const compactMatch = normalized.match(/^(\d{4})(\d{2})(\d{2})/);
            if (compactMatch) {
                return `${compactMatch[1]}-${compactMatch[2]}-${compactMatch[3]}`;
            }

            const parsed = new Date(normalized);
            if (Number.isNaN(parsed.getTime())) {
                return '';
            }

            const year = parsed.getFullYear();
            const month = String(parsed.getMonth() + 1).padStart(2, '0');
            const day = String(parsed.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        },

        updateImageTargetDate: function(imageId, value) {
            const image = this.getImageById(imageId);
            if (!image) {
                return;
            }

            image.target_date = value || null;
        },

        lookupArchive: function(imageId, $btn) {
            const image = this.getImageById(imageId);
            if (!image) {
                alert('Image data is no longer available. Please run the scan again.');
                return;
            }

            $btn.prop('disabled', true).text(wirData.strings.lookingUpArchive || 'Looking up archive...');

            $.ajax({
                url: wirData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wir_lookup_archive',
                    nonce: wirData.nonce,
                    scan_id: this.currentScanId || '',
                    image_id: Number(image.id),
                    image_url: image.url,
                    target_date: image.target_date || ''
                },
                success: $.proxy(function(response) {
                    if (response.success && response.data) {
                        image.archive_found = Boolean(response.data.archive_found);
                        image.archive_url = response.data.archive_url || null;
                        image.archive_timestamp = response.data.archive_timestamp || null;
                        image.target_date = response.data.target_date || image.target_date || null;
                        image.last_checked = new Date().toISOString();
                        this.displayResults(this.currentResults);
                    } else {
                        $btn.prop('disabled', false).text(wirData.strings.recheckArchive || 'Recheck Archive');
                        alert('Archive lookup failed: ' + ((response.data && response.data.message) || 'Unknown error'));
                    }
                }, this),
                error: function() {
                    $btn.prop('disabled', false).text(wirData.strings.recheckArchive || 'Recheck Archive');
                    alert('Request failed');
                }
            });
        },

        applyRestoreResult: function(imageId, result) {
            const image = this.getImageById(imageId);
            if (!image || !result || result.dry_run) {
                return;
            }

            image.restored = true;
            image.restore_status = 'restored';
            image.restored_attachment_id = Number(result.undo_attachment_id || result.new_attachment_id || 0);
            image.restored_url = String(result.new_url || '');
            image.undo_available = Boolean(result.undo_available && image.restored_attachment_id > 0);
            delete image.restore_error;
        },

        clearRestoreResult: function(imageId) {
            const image = this.getImageById(imageId);
            if (!image) {
                return;
            }

            delete image.restored;
            delete image.restore_status;
            delete image.restored_attachment_id;
            delete image.restored_url;
            delete image.undo_available;
            delete image.restore_error;
        },

        applyBulkRestoreResults: function(items) {
            if (!Array.isArray(items)) {
                return;
            }

            items.forEach(function(item) {
                if (!item || !item.success) {
                    return;
                }

                this.applyRestoreResult(item.id, item);
            }, this);
        },

        getBulkImagePayload: function(imageIds) {
            return imageIds.map(function(imageId) {
                const image = this.getImageById(imageId);
                if (!image) {
                    return null;
                }

                return {
                    id: Number(image.id),
                    url: image.url,
                    archive_url: image.archive_url || null,
                    target_date: image.target_date || null,
                    referenced_in: Array.isArray(image.referenced_in) ? image.referenced_in : []
                };
            }, this).filter(Boolean);
        },

        ignoreImage: function(imageId) {
            if (!this.currentResults || !Array.isArray(this.currentResults.broken_images)) {
                return;
            }

            this.currentResults.broken_images = this.currentResults.broken_images.filter(function(image) {
                return Number(image.id) !== Number(imageId);
            });

            if (this.currentResults.stats) {
                this.currentResults.stats.images_broken = this.currentResults.broken_images.length;
            }

            this.displayResults(this.currentResults);
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
            const selectedImages = imageIds.map($.proxy(function(imageId) {
                return this.getImageById(imageId);
            }, this)).filter(function(image) {
                return image && !image.restored_attachment_id;
            });
            const count = selectedImages.length;
            const dryRun = $('#wir_dry_run').is(':checked');

            if (count === 0) {
                return;
            }
            
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
            const dryRun = $('#wir_dry_run').is(':checked');

            $btn.prop('disabled', true).text('Restoring...');

            this.requestRestore(image, dryRun).then($.proxy(function(response) {
                if (response.success) {
                    if (response.data && response.data.dry_run) {
                        $btn.prop('disabled', false).text('Restore');
                        alert(wirData.strings.dryRunComplete || 'Dry run complete. No changes were made.');
                        return;
                    }

                    this.applyRestoreResult(imageId, response.data || {});
                    this.displayResults(this.currentResults);
                } else {
                    $btn.prop('disabled', false).text('Retry');
                    this.markLocalRestoreFailure(imageId, response.data);
                    alert('Restore failed: ' + (response.data.error || response.data.message || 'Unknown error'));
                }
            }, this)).catch($.proxy(function() {
                $btn.prop('disabled', false).text('Retry');
                this.markLocalRestoreFailure(imageId, { error: 'Request failed' });
                alert('Request failed');
            }, this));
        },

        requestRestore: function(image, dryRun) {
            return this.postAjax({
                action: 'wir_restore',
                nonce: wirData.nonce,
                scan_id: this.currentScanId || '',
                image_id: Number(image.id),
                image_url: image.url,
                archive_url: image.archive_url,
                target_date: image.target_date || '',
                dry_run: dryRun,
                referenced_in: JSON.stringify(image.referenced_in || [])
            }, {
                timeout: 120000
            });
        },

        markLocalRestoreFailure: function(imageId, payload) {
            const image = this.getImageById(imageId);
            if (!image) {
                return;
            }

            image.restore_status = 'failed';
            image.restore_error = (payload && (payload.error || payload.message)) || (wirData.strings.restoreFailed || 'Restore failed');
        },

        undoRestore: function(imageId, attachmentId, $btn) {
            if (!attachmentId) {
                alert('Undo is not available for this restore.');
                return;
            }

            if (!confirm(wirData.strings.confirmUndo || 'Undo this restore?')) {
                return;
            }

            $btn.prop('disabled', true).text(wirData.strings.undoing || 'Undoing...');

            $.ajax({
                url: wirData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wir_undo_restore',
                    nonce: wirData.nonce,
                    scan_id: this.currentScanId || '',
                    image_id: Number(imageId),
                    attachment_id: attachmentId
                },
                success: $.proxy(function(response) {
                    if (response.success) {
                        this.clearRestoreResult(imageId);
                        this.displayResults(this.currentResults);
                    } else {
                        $btn.prop('disabled', false).text(wirData.strings.undoRestore || 'Undo Restore');
                        alert('Undo failed: ' + ((response.data && (response.data.error || response.data.message)) || 'Unknown error'));
                    }
                }, this),
                error: function() {
                    $btn.prop('disabled', false).text(wirData.strings.undoRestore || 'Undo Restore');
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
            const images = this.getBulkImagePayload(imageIds).filter($.proxy(function(image) {
                return !this.isImageRestored(image);
            }, this));
            
            $modal.show();
            $bar.removeClass('is-indeterminate');
            $fill.css('width', '0%');
            $text.text(dryRun ? 'Checking selected images...' : 'Restoring selected images...');
            $current.text('Preparing restore queue...');

            if (images.length === 0) {
                $text.text('Nothing to do');
                $current.text('All selected images are already restored.');
                setTimeout(function() {
                    $modal.hide();
                }, 1200);
                return;
            }

            let succeeded = 0;
            let failed = 0;
            const total = images.length;

            const runQueue = async () => {
                for (let index = 0; index < images.length; index += 1) {
                    const image = images[index];
                    const progressPercent = Math.round((index / total) * 100);
                    $fill.css('width', `${progressPercent}%`);
                    $current.text(`Processing ${index + 1} of ${total}: ${image.url}`);

                    try {
                        const response = await this.requestRestore(image, dryRun);
                        if (response.success) {
                            succeeded += 1;
                            if (!dryRun) {
                                this.applyRestoreResult(image.id, response.data || {});
                            }
                        } else {
                            failed += 1;
                            this.markLocalRestoreFailure(image.id, response.data || {});
                        }
                    } catch (error) {
                        failed += 1;
                        this.markLocalRestoreFailure(image.id, { error: 'Request failed' });
                    }
                }

                $fill.css('width', '100%');
                $text.text('Complete!');
                $current.html(`Succeeded: ${succeeded}<br>Failed: ${failed}`);

                if (!dryRun) {
                    this.displayResults(this.currentResults);
                }

                setTimeout(function() {
                    $modal.hide();
                }, 2000);
            };

            runQueue().catch(function() {
                alert('Bulk restore failed unexpectedly.');
                $modal.hide();
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
