/* MSH SEO: Autopilot admin page (scan now, save settings). */
jQuery(function($) {
    // Run Scan Now
    $('#msh-autopilot-scan-btn').on('click', function() {
        var btn = $(this);
        var spinner = $('#msh-autopilot-scan-spinner');
        var result = $('#msh-autopilot-scan-result');

        if (!confirm('Run autopilot scan now? This will analyze all published posts and send data to your MSH dashboard.')) return;

        btn.prop('disabled', true);
        spinner.addClass('is-active');
        result.html('<span style="color:#666;">Scanning posts... This may take a minute.</span>');

        $.post(mshSeoAdmin.ajaxUrl, {
            action: 'msh_seo_autopilot_run_scan',
            nonce: mshSeoAdmin.nonce
        }, function(response) {
            btn.prop('disabled', false);
            spinner.removeClass('is-active');

            if (response.success) {
                result.html('<span style="color:#00a32a;font-weight:600;">\u2705 ' + response.data.message + '</span>');
            } else {
                result.html('<span style="color:#d63638;">\u274c ' + (response.data.message || 'Scan failed.') + '</span>');
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false);
            spinner.removeClass('is-active');
            result.html('<span style="color:#d63638;">\u274c Request failed (timeout or server error). Try again.</span>');
        });
    });

    // Save Settings
    $('#msh-autopilot-save-btn').on('click', function() {
        var btn = $(this);
        var spinner = $('#msh-autopilot-save-spinner');
        var result = $('#msh-autopilot-save-result');

        btn.prop('disabled', true);
        spinner.addClass('is-active');
        result.html('');

        $.post(mshSeoAdmin.ajaxUrl, {
            action: 'msh_seo_autopilot_save_settings',
            nonce: mshSeoAdmin.nonce,
            default_mode: $('#msh-ap-mode').val(),
            scan_frequency: $('#msh-ap-frequency').val(),
            min_age_days: $('#msh-ap-min-age').val(),
            min_word_count: $('#msh-ap-min-words').val(),
            auto_ping: $('#msh-ap-ping').is(':checked') ? '1' : ''
        }, function(response) {
            btn.prop('disabled', false);
            spinner.removeClass('is-active');

            if (response.success) {
                result.html('<span style="color:#00a32a;font-weight:600;">\u2705 ' + response.data.message + '</span>');
            } else {
                result.html('<span style="color:#d63638;">' + (response.data.message || 'Save failed.') + '</span>');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            spinner.removeClass('is-active');
            result.html('<span style="color:#d63638;">Request failed.</span>');
        });
    });
});
