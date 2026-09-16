/* MSH SEO: SEO Command Center (Analytics) admin page actions. */
(function() {
    var ajaxUrl = mshSeoAnalytics.ajaxUrl;
    var nonce   = mshSeoAnalytics.nonce;

    // Refresh button.
    var refreshBtn = document.getElementById('msh-refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            refreshBtn.disabled = true;
            refreshBtn.textContent = 'Refreshing...';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() { location.reload(); };
            xhr.onerror = function() { location.reload(); };
            xhr.send('action=msh_seo_refresh_analytics&nonce=' + nonce);
        });
    }

    // Auto-fix action buttons.
    document.querySelectorAll('.msh-action-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var action = btn.getAttribute('data-ajax');
            var btnNonce = btn.getAttribute('data-nonce') || nonce;
            var origText = btn.textContent;

            btn.disabled = true;
            btn.textContent = 'Working...';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        btn.textContent = resp.data.message || 'Done!';
                        btn.classList.add('msh-done');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        btn.textContent = resp.data || 'Error';
                        btn.disabled = false;
                        setTimeout(function() { btn.textContent = origText; }, 3000);
                    }
                } catch (e) {
                    btn.textContent = 'Error';
                    btn.disabled = false;
                }
            };
            xhr.onerror = function() {
                btn.textContent = 'Network error';
                btn.disabled = false;
            };
            xhr.send('action=' + action + '&nonce=' + btnNonce);
        });
    });

    // 404 redirect create buttons.
    document.querySelectorAll('.msh-create-redirect').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var row = btn.closest('tr');
            var source = btn.getAttribute('data-source');
            var target = row.querySelector('.msh-redirect-target').value;
            var btnNonce = btn.getAttribute('data-nonce') || nonce;

            if (!target) { alert('Please enter a target URL.'); return; }

            btn.disabled = true;
            btn.textContent = 'Creating...';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        btn.textContent = 'Created!';
                        btn.classList.add('msh-done');
                        row.style.opacity = '0.5';
                    } else {
                        btn.textContent = resp.data || 'Error';
                        btn.disabled = false;
                    }
                } catch (e) {
                    btn.textContent = 'Error';
                    btn.disabled = false;
                }
            };
            xhr.send('action=msh_seo_create_redirect&nonce=' + btnNonce + '&source=' + encodeURIComponent(source) + '&target=' + encodeURIComponent(target));
        });
    });
})();
