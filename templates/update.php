<?php
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../includes/SynapseClient.php';
?>

<div class="synapse-card">
    <h3>Addon Update</h3>
    <p>Check the Synapse backend for a newer addon package and install it on this WHMCS server.</p>
    <p style="font-size: 12px; color: #666;">
        Installed: <strong id="synapse-current-version"><?php echo defined('SYNAPSE_ADDON_VERSION') ? htmlspecialchars(SYNAPSE_ADDON_VERSION) : ''; ?></strong>
    </p>
    <p id="synapse-update-status" style="font-size: 12px;">Checking for updates…</p>
    <p>
        <button type="button" id="synapse-check-update-btn" class="btn btn-default btn-sm">Check for updates</button>
        <button type="button" id="synapse-update-btn" class="btn btn-success btn-sm" style="display:none;">
            Install update
        </button>
    </p>
</div>

<script>
(function() {
    const statusEl = document.getElementById('synapse-update-status');
    const btn = document.getElementById('synapse-update-btn');
    const checkBtn = document.getElementById('synapse-check-update-btn');
    if (!statusEl || !btn) return;
    const checkUrl = <?php echo json_encode(synapseAdminAjaxUrl($modulelink, 'check_update')); ?>;
    const applyUrl = <?php echo json_encode(synapseAdminAjaxUrl($modulelink, 'apply_update')); ?>;
    const token = window.synapseCsrfToken || '';
    function headers() {
        return { 'X-Synapse-Token': token, 'Accept': 'application/json' };
    }
    function withToken(url) {
        return url;
    }
    function parseJsonResponse(r) {
        return r.text().then(function(text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error(text.indexOf('Critical Error') >= 0
                    ? 'WHMCS admin routing error — reload the Synapse addon page and try again.'
                    : 'Invalid server response');
            }
        });
    }
    function checkUpdate() {
        statusEl.textContent = 'Checking for updates…';
        btn.style.display = 'none';
        fetch(withToken(checkUrl), { credentials: 'same-origin', headers: headers() })
            .then(parseJsonResponse)
            .then(function(data) {
                if (!data.success) {
                    statusEl.textContent = data.error || 'Unable to check for updates.';
                    return;
                }
                if (data.updateAvailable) {
                    statusEl.textContent = 'Update available: v' + data.latestVersion;
                    btn.style.display = 'inline-block';
                } else {
                    statusEl.textContent = 'You are on the latest version.';
                }
            })
            .catch(function(err) {
                statusEl.textContent = err.message || 'Unable to check for updates.';
            });
    }
    checkUpdate();
    if (checkBtn) {
        checkBtn.addEventListener('click', function() { checkUpdate(); });
    }
    btn.addEventListener('click', function() {
        btn.disabled = true;
        statusEl.textContent = 'Downloading and installing update…';
        fetch(withToken(applyUrl), { credentials: 'same-origin', headers: headers() })
            .then(parseJsonResponse)
            .then(function(data) {
                if (!data.success) {
                    statusEl.textContent = data.error || 'Update failed.';
                    btn.disabled = false;
                    return;
                }
                statusEl.textContent = data.updated ? ('Updated to v' + data.version + '. Reloading…') : (data.message || 'Already up to date.');
                if (data.updated) {
                    window.location.reload();
                } else {
                    btn.disabled = false;
                    btn.style.display = 'none';
                }
            })
            .catch(function(err) {
                statusEl.textContent = err.message || 'Update failed.';
                btn.disabled = false;
            });
    });
})();
</script>
