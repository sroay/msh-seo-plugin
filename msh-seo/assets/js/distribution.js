/* MSH SEO: Distribution meta box on the post editor. */
(function() {
    var btn = document.getElementById('msh-distribute-btn');
    if (!btn) return;

    btn.addEventListener('click', function() {
        var checks = document.querySelectorAll('input[name="msh_seo_channels[]"]:checked');
        if (!checks.length) {
            alert('Select at least one channel.');
            return;
        }

        var channels = [];
        checks.forEach(function(c) { channels.push(c.value); });

        btn.disabled = true;
        btn.textContent = 'Distributing...';

        var data = new FormData();
        data.append('action', 'msh_seo_distribute_post');
        data.append('post_id', mshSeoDistribution.postId);
        data.append('channels', JSON.stringify(channels));
        data.append('nonce', mshSeoDistribution.nonce);

        fetch(ajaxurl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                var el = document.getElementById('msh-distribute-result');
                if (res.success) {
                    el.textContent = res.data.message;
                    el.style.color = '#46b450';
                } else {
                    el.textContent = res.data || 'Distribution failed.';
                    el.style.color = '#d63638';
                }
                btn.disabled = false;
                btn.textContent = 'Distribute Now';
            })
            .catch(function() {
                btn.disabled = false;
                btn.textContent = 'Distribute Now';
            });
    });
})();
