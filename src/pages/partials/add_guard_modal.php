<!-- Add Guard Modal — included in webhook.php and project.php -->
<div id="addGuardModal" class="modal" style="display:none" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <h3><?= __('guard.create') ?></h3>
            <button onclick="closeModal('addGuardModal')" class="modal-close">&times;</button>
        </div>
        <form id="addGuardForm" method="post" action="<?= BASE_URL ?>/?page=webhook&action=add_guard">
            <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="project_id" id="addGuardProjectId" value="">
            <input type="hidden" name="webhook_id" id="addGuardWebhookId" value="">
            <input type="hidden" name="redirect" id="addGuardRedirect" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label for="guardType"><?= __('guard.type') ?></label>
                    <select id="guardType" name="type" onchange="updateGuardFields(this.value)" required>
                        <option value="">— Select type —</option>
                        <option value="required_header"><?= __('guard.required_header') ?></option>
                        <option value="static_token"><?= __('guard.static_token') ?></option>
                        <option value="query_secret"><?= __('guard.query_secret') ?></option>
                        <option value="ip_whitelist"><?= __('guard.ip_whitelist') ?></option>
                    </select>
                </div>

                <!-- required_header -->
                <div id="guard_required_header" class="guard-fields" style="display:none">
                    <div class="form-group">
                        <label><?= __('guard.header_name') ?></label>
                        <input type="text" name="rh_header" placeholder="X-My-Header" maxlength="100">
                        <p class="form-hint">Request will be rejected if this header is absent or empty.</p>
                    </div>
                </div>

                <!-- static_token -->
                <div id="guard_static_token" class="guard-fields" style="display:none">
                    <div class="form-group">
                        <label><?= __('guard.header_name') ?></label>
                        <input type="text" name="st_header" placeholder="Authorization" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label><?= __('guard.token_value') ?></label>
                        <input type="text" name="st_value" placeholder="Bearer my-secret-token" maxlength="500">
                    </div>
                </div>

                <!-- query_secret -->
                <div id="guard_query_secret" class="guard-fields" style="display:none">
                    <div class="form-group">
                        <label><?= __('guard.param_name') ?></label>
                        <input type="text" name="qs_param" placeholder="secret" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label><?= __('guard.secret_value') ?></label>
                        <input type="text" name="qs_value" placeholder="my-secret-value" maxlength="500">
                    </div>
                </div>

                <!-- ip_whitelist -->
                <div id="guard_ip_whitelist" class="guard-fields" style="display:none">
                    <div class="form-group">
                        <label><?= __('guard.ips') ?></label>
                        <input type="text" name="ip_ips" placeholder="192.168.1.1, 10.0.0.0/8" maxlength="1000">
                        <p class="form-hint">Comma-separated list of IPs. CIDR ranges are matched by prefix.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= __('guard.create') ?></button>
                <button type="button" onclick="closeModal('addGuardModal')" class="btn btn-outline"><?= __('form.cancel') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
function updateGuardFields(type) {
    document.querySelectorAll('.guard-fields').forEach(el => el.style.display = 'none');
    if (type) {
        const el = document.getElementById('guard_' + type);
        if (el) el.style.display = 'block';
    }
}
</script>
