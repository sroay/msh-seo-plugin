/* MSH SEO settings page: connect and disconnect. Data comes from wp_localize_script (mshSeoAdmin). */
jQuery(function ($) {
    var i18n = (window.mshSeoAdmin && mshSeoAdmin.i18n) || {};

    // Messages are inserted as text, never as HTML: they can come from the server.
    function showMessage($el, text, ok) {
        $el.empty().append($('<span/>').css('color', ok ? '#00a32a' : '#d63638').text(text));
    }

    $('#msh-verify-btn').on('click', function () {
        var btn = $(this);
        var key = $.trim($('#msh-api-key').val() || '');
        var spinner = $('#msh-verify-spinner');
        var msg = $('#msh-verify-message');

        if (!key) {
            showMessage(msg, i18n.enterKey || 'Please enter an API key.', false);
            return;
        }

        btn.prop('disabled', true);
        spinner.addClass('is-active');
        msg.empty();

        $.post(mshSeoAdmin.ajaxUrl, {
            action: 'msh_seo_verify_connection',
            nonce: mshSeoAdmin.nonce,
            api_key: key
        }, function (response) {
            btn.prop('disabled', false);
            spinner.removeClass('is-active');
            var text = response && response.data && response.data.message ? String(response.data.message) : '';

            if (response && response.success) {
                showMessage(msg, text, true);
                setTimeout(function () { location.reload(); }, 1000);
            } else {
                showMessage(msg, text || i18n.failed || 'Request failed. Please try again.', false);
            }
        }).fail(function () {
            btn.prop('disabled', false);
            spinner.removeClass('is-active');
            showMessage(msg, i18n.failed || 'Request failed. Please try again.', false);
        });
    });

    $('#msh-disconnect-btn').on('click', function () {
        if (!window.confirm(i18n.confirmDisconnect || 'Disconnect from Marketing So High?')) {
            return;
        }

        $.post(mshSeoAdmin.ajaxUrl, {
            action: 'msh_seo_disconnect',
            nonce: mshSeoAdmin.nonce
        }, function (response) {
            if (response && response.success) {
                location.reload();
            }
        });
    });
});
