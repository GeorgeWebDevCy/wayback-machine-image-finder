(function($) {
    'use strict';

    const WIR = {
        currentScanId: null,
        currentResults: null,
        logsPage: 1,
        logsTotalPages: 1,

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

        startScan: function() {
            const $btn = $('#wir-start-scan');
            const $status = $('#wir-scan-status');
            
            $btn.prop('disabled', true).text(wirData.strings.scanning);
            $status.removeClass('error success').text('Scanning for broken images...');
            $('#wir-scan-results').html('<p>Scanning...</p>');

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
                        this.currentScanId = response.data.scan_id;
                        this.currentResults = response.data;
                        this.displayResults(response.data);
                        $status.addClass('success').text('Scan complete!');
                    } else {
                        $status.addClass('error').text(response.data.message || 'Scan failed');
                    }
                }, this),
                error: $.proxy(function() {
                    $status.addClass('error').text('Request failed');
                }, this),
                complete: function() {
                    $btn.prop('disabled', false).text('Start Scan');
                }
            });
        },

        displayResults: function(data) {
            const stats = data.stats;
            const brokenImages = data.broken_images;
            
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
                                ${img.archive_found ? '<button class="button wir-restore-single" data-url="' + img.url + '" data-archive="' + img.archive_url + '">Restore</button>' : ''}
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

            html += `<p class="description" style="margin-top: 15px;">Duration: ${data.duration_seconds} seconds</p>`;

            $('#wir-scan-results').html(html);

            $('#wir-select-all').on('change', $.proxy(function() {
                $('.wir-image-checkbox').prop('checked', $(this).prop('checked'));
                this.updateSelectedCount();
            }, this));

            $('.wir-image-checkbox').on('change', $.proxy(function() {
                this.updateSelectedCount();
            }, this));

            $('.wir-restore-single').on('click', $.proxy(function(e) {
                const $btn = $(e.currentTarget);
                this.restoreImage($btn.data('url'), $btn.data('archive'));
            }, this));

            $('#wir-restore-selected').on('click', $.proxy(function() {
                const selected = $('.wir-image-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                if (selected.length > 0) {
                    this.confirmRestore(selected);
                }
            }, this));
        },

        formatTimestamp: function(ts) {
            if (!ts || ts.length < 8) return 'Unknown';
            return ts.substring(0, 4) + '-' + ts.substring(4, 6) + '-' + ts.substring(6, 8);
        },

        updateSelectedCount: function() {
            const count = $('.wir-image-checkbox:checked').length;
            $('#wir-selected-count').text(count);
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

        restoreImage: function(imageUrl, archiveUrl) {
            const data = {
                action: 'wir_restore',
                nonce: wirData.nonce,
                image_url: imageUrl,
                archive_url: archiveUrl,
                dry_run: $('#wir_dry_run').is(':checked')
            };

            const $btn = $('.wir-restore-single[data-url="' + imageUrl + '"]');
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
            const $fill = $('.wir-progress-fill');
            const $text = $('#wir-progress-text');
            const $current = $('#wir-progress-current');
            
            $modal.show();
            $fill.css('width', '0%');
            $text.text('0 / ' + imageIds.length);

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
                        alert('Bulk restore failed: ' + (response.data.error || 'Unknown error'));
                        $modal.hide();
                    }
                },
                error: function() {
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
