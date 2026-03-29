<?php
// Control Panel — composable widget dashboard
if (!isset($current_user)) {
    header('Location: ' . BASE_URL . '/?page=home');
    exit;
}

$page_title = __('cp.title');
$userId = (int)$current_user['id'];
$cpDb   = Database::get();

$wStmt = $cpDb->prepare(
    'SELECT * FROM control_panel_widgets WHERE user_id = ? ORDER BY sort_order, id'
);
$wStmt->execute([$userId]);
$widgets = $wStmt->fetchAll();

// Load all webhooks for the URL picker
$whPickerStmt = $cpDb->prepare('
    SELECT w.id, w.name, w.token, p.slug AS project_slug
    FROM webhooks w
    JOIN projects p ON p.id = w.project_id
    WHERE p.user_id = ? AND w.deleted_at IS NULL AND p.deleted_at IS NULL
    ORDER BY p.name, w.name
');
$whPickerStmt->execute([$userId]);
$webhooksForPicker = $whPickerStmt->fetchAll();
$webhookPickerJson = json_encode(array_map(fn($wh) => [
    'name' => $wh['name'],
    'url'  => webhookUrl($wh['project_slug'], $wh['token']),
], $webhooksForPicker));

$WIDGET_TYPES = [
    'button' => ['icon' => '▶', 'label' => __('cp.type_button'),  'desc' => __('cp.type_button_desc')],
    'updown' => ['icon' => '⇅', 'label' => __('cp.type_updown'),  'desc' => __('cp.type_updown_desc')],
    'dpad'   => ['icon' => '✛', 'label' => __('cp.type_dpad'),    'desc' => __('cp.type_dpad_desc')],
    'send'   => ['icon' => '↩', 'label' => __('cp.type_send'),    'desc' => __('cp.type_send_desc')],
];
?>
<div class="page-container">
    <div class="page-header">
        <div class="page-header-left">
            <h1><?= __('cp.title') ?></h1>

        </div>
    </div>

    <div class="cp-grid" id="cpGrid">
        <?php foreach ($widgets as $w):
            $cfg  = json_decode($w['config'] ?? '{}', true) ?: [];
            $wide = ((int)$w['width']) >= 2 ? ' cp-widget--wide' : '';
            $type = $w['type'];
        ?>
        <div class="cp-widget cp-widget-<?= e($type) ?><?= $wide ?>"
             data-id="<?= (int)$w['id'] ?>"
             data-type="<?= e($type) ?>"
             data-config="<?= e(json_encode($cfg)) ?>"
             data-title="<?= e($w['title']) ?>"
             data-width="<?= (int)$w['width'] ?>">
            <div class="cp-widget-header">
                <span class="cp-widget-title"><?= e($w['title']) ?></span>
                <span class="cp-widget-actions">
                    <button class="cp-widget-action-btn" onclick="cpEditWidget(this)" title="<?= __('form.edit') ?>">✎</button>
                    <button class="cp-widget-action-btn cp-widget-action-delete" onclick="cpDeleteWidget(this)" title="<?= __('form.delete') ?>">✕</button>
                </span>
            </div>
            <div class="cp-widget-body">
                <?php if ($type === 'button'): ?>
                    <button class="cp-btn cp-btn--primary cp-btn--full cp-action-btn"
                            data-url="<?= e($cfg['url'] ?? '') ?>"
                            data-method="<?= e($cfg['method'] ?? 'GET') ?>"
                            data-body="<?= e($cfg['body'] ?? '') ?>">
                        <?= e($cfg['label'] ?? __('cp.btn_click')) ?>
                    </button>

                <?php elseif ($type === 'updown'): ?>
                    <button class="cp-btn cp-btn--full cp-action-btn"
                            data-url="<?= e($cfg['up_url'] ?? '') ?>"
                            data-method="<?= e($cfg['up_method'] ?? 'GET') ?>"
                            data-body="<?= e($cfg['up_body'] ?? '') ?>">
                        ▲ <?= e($cfg['up_label'] ?? 'Su') ?>
                    </button>
                    <button class="cp-btn cp-btn--full cp-action-btn"
                            data-url="<?= e($cfg['down_url'] ?? '') ?>"
                            data-method="<?= e($cfg['down_method'] ?? 'GET') ?>"
                            data-body="<?= e($cfg['down_body'] ?? '') ?>">
                        ▼ <?= e($cfg['down_label'] ?? 'Giù') ?>
                    </button>

                <?php elseif ($type === 'dpad'): ?>
                    <div class="cp-dpad-grid">
                        <div></div>
                        <button class="cp-btn cp-btn--icon cp-action-btn"
                                data-url="<?= e($cfg['up_url'] ?? '') ?>"
                                data-method="<?= e($cfg['up_method'] ?? 'GET') ?>"
                                data-body="">▲</button>
                        <div></div>
                        <button class="cp-btn cp-btn--icon cp-action-btn"
                                data-url="<?= e($cfg['left_url'] ?? '') ?>"
                                data-method="<?= e($cfg['left_method'] ?? 'GET') ?>"
                                data-body="">◀</button>
                        <div></div>
                        <button class="cp-btn cp-btn--icon cp-action-btn"
                                data-url="<?= e($cfg['right_url'] ?? '') ?>"
                                data-method="<?= e($cfg['right_method'] ?? 'GET') ?>"
                                data-body="">▶</button>
                        <div></div>
                        <button class="cp-btn cp-btn--icon cp-action-btn"
                                data-url="<?= e($cfg['down_url'] ?? '') ?>"
                                data-method="<?= e($cfg['down_method'] ?? 'GET') ?>"
                                data-body="">▼</button>
                        <div></div>
                    </div>

                <?php elseif ($type === 'send'): ?>
                    <div class="cp-send-row">
                        <input type="text" class="cp-send-input"
                               placeholder="<?= e($cfg['placeholder'] ?? '') ?>"
                               data-param="<?= e($cfg['param_name'] ?? 'value') ?>"
                               data-url="<?= e($cfg['url'] ?? '') ?>"
                               data-method="<?= e($cfg['method'] ?? 'POST') ?>"
                               data-send-as="<?= e($cfg['send_as'] ?? 'body') ?>">
                        <button class="cp-btn cp-btn--primary cp-send-btn">
                            <?= e($cfg['button_label'] ?? __('cp.btn_send')) ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Add widget cell -->
        <div class="cp-add-cell" id="cpAddCell" onclick="cpOpenAddModal()">
            <div class="cp-add-cell-inner">
                <span class="cp-add-cell-icon">+</span>
                <span class="cp-add-cell-label"><?= __('cp.add_widget') ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Webhook URL Picker Dropdown (floats near the trigger button) -->
<div id="cpWebhookPickerDropdown" class="cp-picker-dropdown" style="display:none">
    <ul id="cpWebhookPickerList" class="cp-picker-list"></ul>
</div>

<!-- Add / Edit Widget Modal -->
<div class="modal" id="cpWidgetModal" aria-hidden="true" style="display:none">
    <div class="modal-dialog" style="max-width:520px">
        <div class="modal-header">
            <h2 id="cpModalTitle"><?= __('cp.add_widget') ?></h2>
            <button class="modal-close" onclick="closeModal('cpWidgetModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Step 1: type picker -->
            <div id="cpStepType">
                <p class="form-hint" style="margin-bottom:14px"><?= __('cp.choose_type') ?></p>
                <div class="cp-type-grid">
                    <?php foreach ($WIDGET_TYPES as $typeKey => $typeInfo): ?>
                    <div class="cp-type-card" data-type="<?= e($typeKey) ?>" onclick="cpSelectType('<?= e($typeKey) ?>')">
                        <span class="cp-type-card-icon"><?= $typeInfo['icon'] ?></span>
                        <span class="cp-type-card-name"><?= e($typeInfo['label']) ?></span>
                        <span class="cp-type-card-desc"><?= e($typeInfo['desc']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 2: config form -->
            <div id="cpStepConfig" style="display:none">
                <input type="hidden" id="cpEditId" value="">
                <input type="hidden" id="cpEditType" value="">

                <div class="form-group">
                    <label class="form-label"><?= __('cp.widget_title') ?></label>
                    <input type="text" class="form-control" id="cpFTitle" placeholder="<?= __('cp.widget_title_placeholder') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label"><?= __('cp.widget_width') ?></label>
                    <select class="form-control" id="cpFWidth">
                        <option value="1"><?= __('cp.width_1') ?></option>
                        <option value="2"><?= __('cp.width_2') ?></option>
                    </select>
                </div>

                <!-- Button widget fields -->
                <div id="cpFieldsButton" class="cp-type-fields" style="display:none">
                    <div class="form-group">
                        <label class="form-label"><?= __('cp.field_label') ?></label>
                        <input type="text" class="form-control" id="cpFBtnLabel" placeholder="Clicca">
                    </div>
                    <div class="form-group">
                        <label class="form-label">URL</label>
                        <div class="cp-url-row">
                            <input type="text" class="form-control" id="cpFBtnUrl" placeholder="https://...">
                            <button type="button" class="btn btn-sm btn-outline cp-webhook-pick-btn" onclick="cpOpenWebhookPicker('cpFBtnUrl', this)">🔗 Webhooks</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= __('cp.field_method') ?></label>
                        <select class="form-control" id="cpFBtnMethod">
                            <option>GET</option><option>POST</option><option>PUT</option>
                            <option>DELETE</option><option>PATCH</option>
                        </select>
                    </div>
                    <div class="form-group" id="cpFBtnBodyGroup">
                        <label class="form-label">Body</label>
                        <textarea class="form-control" id="cpFBtnBody" rows="3" placeholder="Opzionale"></textarea>
                    </div>
                </div>

                <!-- UpDown widget fields -->
                <div id="cpFieldsUpdown" class="cp-type-fields" style="display:none">
                    <p class="form-hint">▲ Su</p>
                    <div class="form-group">
                        <label class="form-label"><?= __('cp.field_label') ?></label>
                        <input type="text" class="form-control" id="cpFUpLabel" placeholder="Su">
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex:2">
                            <label class="form-label">URL</label>
                            <div class="cp-url-row">
                                <input type="text" class="form-control" id="cpFUpUrl" placeholder="https://...">
                                <button type="button" class="btn btn-sm btn-outline cp-webhook-pick-btn" onclick="cpOpenWebhookPicker('cpFUpUrl', this)">🔗 Webhooks</button>
                            </div>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label class="form-label"><?= __('cp.field_method') ?></label>
                            <select class="form-control" id="cpFUpMethod">
                                <option>GET</option><option>POST</option><option>PUT</option>
                                <option>DELETE</option><option>PATCH</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" id="cpFUpBodyGroup">
                        <label class="form-label">Body</label>
                        <input type="text" class="form-control" id="cpFUpBody" placeholder="Opzionale">
                    </div>
                    <p class="form-hint" style="margin-top:8px">▼ Giù</p>
                    <div class="form-group">
                        <label class="form-label"><?= __('cp.field_label') ?></label>
                        <input type="text" class="form-control" id="cpFDownLabel" placeholder="Giù">
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex:2">
                            <label class="form-label">URL</label>
                            <div class="cp-url-row">
                                <input type="text" class="form-control" id="cpFDownUrl" placeholder="https://...">
                                <button type="button" class="btn btn-sm btn-outline cp-webhook-pick-btn" onclick="cpOpenWebhookPicker('cpFDownUrl', this)">🔗 Webhooks</button>
                            </div>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label class="form-label"><?= __('cp.field_method') ?></label>
                            <select class="form-control" id="cpFDownMethod">
                                <option>GET</option><option>POST</option><option>PUT</option>
                                <option>DELETE</option><option>PATCH</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" id="cpFDownBodyGroup">
                        <label class="form-label">Body</label>
                        <input type="text" class="form-control" id="cpFDownBody" placeholder="Opzionale">
                    </div>
                </div>

                <!-- DPad widget fields -->
                <div id="cpFieldsDpad" class="cp-type-fields" style="display:none">
                    <?php foreach (['up'=>'▲ Su','down'=>'▼ Giù','left'=>'◀ Sinistra','right'=>'▶ Destra'] as $dir => $dirLabel): ?>
                    <p class="form-hint"><?= $dirLabel ?></p>
                    <div class="form-row">
                        <div class="form-group" style="flex:2">
                            <label class="form-label">URL</label>
                            <div class="cp-url-row">
                                <input type="text" class="form-control" id="cpFDpad<?= ucfirst($dir) ?>Url" placeholder="https://...">
                                <button type="button" class="btn btn-sm btn-outline cp-webhook-pick-btn" onclick="cpOpenWebhookPicker('cpFDpad<?= ucfirst($dir) ?>Url', this)">🔗 Webhooks</button>
                            </div>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label class="form-label"><?= __('cp.field_method') ?></label>
                            <select class="form-control" id="cpFDpad<?= ucfirst($dir) ?>Method">
                                <option>GET</option><option>POST</option><option>PUT</option>
                                <option>DELETE</option><option>PATCH</option>
                            </select>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Send widget fields -->
                <div id="cpFieldsSend" class="cp-type-fields" style="display:none">
                    <div class="form-group">
                        <label class="form-label">URL</label>
                        <div class="cp-url-row">
                            <input type="text" class="form-control" id="cpFSendUrl" placeholder="https://...">
                            <button type="button" class="btn btn-sm btn-outline cp-webhook-pick-btn" onclick="cpOpenWebhookPicker('cpFSendUrl', this)">🔗 Webhooks</button>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label class="form-label"><?= __('cp.field_method') ?></label>
                            <select class="form-control" id="cpFSendMethod">
                                <option>POST</option><option>GET</option><option>PUT</option>
                                <option>PATCH</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label class="form-label"><?= __('cp.field_send_as') ?></label>
                            <select class="form-control" id="cpFSendAs">
                                <option value="body">Body</option>
                                <option value="query">Query string</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= __('cp.field_param_name') ?></label>
                        <input type="text" class="form-control" id="cpFSendParam" placeholder="value">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= __('cp.field_placeholder') ?></label>
                        <input type="text" class="form-control" id="cpFSendPlaceholder" placeholder="Inserisci testo...">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= __('cp.field_btn_label') ?></label>
                        <input type="text" class="form-control" id="cpFSendBtnLabel" placeholder="Invia">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer" id="cpModalFooter" style="display:none">
            <button class="btn btn-outline" onclick="cpBackToTypes()"><?= __('form.back') ?></button>
            <button class="btn btn-primary" onclick="cpSaveWidget()"><?= __('form.save') ?></button>
        </div>
    </div>
</div>

<script>
(function() {
    const BASE = <?= json_encode(BASE_URL) ?>;

    // --- HTTP call from widget button ---
    document.getElementById('cpGrid').addEventListener('click', function(e) {
        const btn = e.target.closest('.cp-action-btn');
        if (btn) { cpFireAction(btn); return; }

        const sendBtn = e.target.closest('.cp-send-btn');
        if (sendBtn) {
            const row  = sendBtn.closest('.cp-send-row');
            const inp  = row.querySelector('.cp-send-input');
            cpFireSend(inp, sendBtn);
        }
    });

    document.getElementById('cpGrid').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const inp = e.target.closest('.cp-send-input');
            if (inp) {
                const sendBtn = inp.closest('.cp-send-row').querySelector('.cp-send-btn');
                cpFireSend(inp, sendBtn);
            }
        }
    });

    function cpFireAction(btn) {
        const url    = btn.dataset.url;
        const method = (btn.dataset.method || 'GET').toUpperCase();
        const body   = btn.dataset.body || '';
        if (!url) return cpToast('URL non configurato', 'error');
        cpDoFetch(url, method, body, btn);
    }

    function cpFireSend(inp, btn) {
        const url      = inp.dataset.url;
        const method   = (inp.dataset.method || 'POST').toUpperCase();
        const param    = inp.dataset.param || 'value';
        const sendAs   = inp.dataset.sendAs || 'body';
        const value    = inp.value.trim();
        if (!url) return cpToast('URL non configurato', 'error');

        let finalUrl = url;
        let finalBody = '';

        if (sendAs === 'query') {
            const sep = url.includes('?') ? '&' : '?';
            finalUrl = url + sep + encodeURIComponent(param) + '=' + encodeURIComponent(value);
        } else {
            finalBody = JSON.stringify({ [param]: value });
        }
        cpDoFetch(finalUrl, method, finalBody, btn, inp);
    }

    function cpDoFetch(url, method, body, btn, extraReset) {
        btn.classList.add('loading');
        const opts = { method };
        if (body && method !== 'GET' && method !== 'HEAD') {
            opts.body = body;
        }
        fetch(url, opts)
            .then(r => {
                btn.classList.remove('loading');
                btn.classList.add(r.ok ? 'success' : 'error');
                if (extraReset) extraReset.value = '';
                setTimeout(() => btn.classList.remove('success', 'error'), 1800);
                cpToast(r.ok ? '✓ OK (' + r.status + ')' : '✗ Errore ' + r.status, r.ok ? 'success' : 'error');
            })
            .catch(err => {
                btn.classList.remove('loading');
                btn.classList.add('error');
                setTimeout(() => btn.classList.remove('error'), 1800);
                cpToast('✗ ' + (err.message || 'Errore'), 'error');
            });
    }

    // --- Toast ---
    function cpToast(msg, type) {
        const el = document.createElement('div');
        el.className = 'cp-toast cp-toast--' + (type || 'info');
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(() => {
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 300);
        }, 2200);
    }
    window.cpToast = cpToast;

    // --- Add modal ---
    window.cpOpenAddModal = function() {
        document.getElementById('cpEditId').value = '';
        document.getElementById('cpEditType').value = '';
        document.getElementById('cpModalTitle').textContent = <?= json_encode(__('cp.add_widget')) ?>;
        cpShowStep('type');
        document.querySelectorAll('.cp-type-card').forEach(c => c.classList.remove('selected'));
        openModal('cpWidgetModal');
    };

    window.cpSelectType = function(type) {
        document.querySelectorAll('.cp-type-card').forEach(c => c.classList.remove('selected'));
        const card = document.querySelector('.cp-type-card[data-type="' + type + '"]');
        if (card) card.classList.add('selected');
        document.getElementById('cpEditType').value = type;
        cpShowTypeFields(type);
        cpSyncMethodBodyVisibility('cpFBtnMethod', 'cpFBtnBodyGroup');
        cpSyncMethodBodyVisibility('cpFUpMethod', 'cpFUpBodyGroup');
        cpSyncMethodBodyVisibility('cpFDownMethod', 'cpFDownBodyGroup');
        cpShowStep('config');
    };

    window.cpBackToTypes = function() {
        cpShowStep('type');
    };

    function cpShowStep(step) {
        document.getElementById('cpStepType').style.display   = step === 'type'   ? '' : 'none';
        document.getElementById('cpStepConfig').style.display = step === 'config' ? '' : 'none';
        document.getElementById('cpModalFooter').style.display = step === 'config' ? '' : 'none';
    }

    function cpShowTypeFields(type) {
        document.querySelectorAll('.cp-type-fields').forEach(el => el.style.display = 'none');
        const fieldsEl = document.getElementById('cpFields' + type.charAt(0).toUpperCase() + type.slice(1));
        if (fieldsEl) fieldsEl.style.display = '';
    }

    function cpMethodSupportsBody(method) {
        return ['POST', 'PUT', 'PATCH'].includes(String(method || '').toUpperCase());
    }

    function cpSyncMethodBodyVisibility(methodId, groupId) {
        const methodEl = document.getElementById(methodId);
        const groupEl = document.getElementById(groupId);
        if (!methodEl || !groupEl) return;
        groupEl.style.display = cpMethodSupportsBody(methodEl.value) ? '' : 'none';
    }

    // --- Edit ---
    window.cpEditWidget = function(btn) {
        const card  = btn.closest('.cp-widget');
        const id    = card.dataset.id;
        const type  = card.dataset.type;
        const title = card.dataset.title;
        const width = card.dataset.width;
        const cfg   = JSON.parse(card.dataset.config || '{}');

        document.getElementById('cpEditId').value = id;
        document.getElementById('cpEditType').value = type;
        document.getElementById('cpModalTitle').textContent = <?= json_encode(__('cp.edit_widget')) ?>;
        document.getElementById('cpFTitle').value = title;
        document.getElementById('cpFWidth').value = width;

        // Prefill type-specific fields
        if (type === 'button') {
            document.getElementById('cpFBtnLabel').value  = cfg.label  || '';
            document.getElementById('cpFBtnUrl').value    = cfg.url    || '';
            document.getElementById('cpFBtnMethod').value = cfg.method || 'GET';
            document.getElementById('cpFBtnBody').value   = cfg.body   || '';
            cpSyncMethodBodyVisibility('cpFBtnMethod', 'cpFBtnBodyGroup');
        } else if (type === 'updown') {
            document.getElementById('cpFUpLabel').value    = cfg.up_label    || '';
            document.getElementById('cpFUpUrl').value      = cfg.up_url      || '';
            document.getElementById('cpFUpMethod').value   = cfg.up_method   || 'GET';
            document.getElementById('cpFUpBody').value     = cfg.up_body     || '';
            document.getElementById('cpFDownLabel').value  = cfg.down_label  || '';
            document.getElementById('cpFDownUrl').value    = cfg.down_url    || '';
            document.getElementById('cpFDownMethod').value = cfg.down_method || 'GET';
            document.getElementById('cpFDownBody').value   = cfg.down_body   || '';
            cpSyncMethodBodyVisibility('cpFUpMethod', 'cpFUpBodyGroup');
            cpSyncMethodBodyVisibility('cpFDownMethod', 'cpFDownBodyGroup');
        } else if (type === 'dpad') {
            ['Up','Down','Left','Right'].forEach(d => {
                const key = d.toLowerCase();
                document.getElementById('cpFDpad' + d + 'Url').value    = cfg[key + '_url']    || '';
                document.getElementById('cpFDpad' + d + 'Method').value = cfg[key + '_method'] || 'GET';
            });
        } else if (type === 'send') {
            document.getElementById('cpFSendUrl').value         = cfg.url          || '';
            document.getElementById('cpFSendMethod').value      = cfg.method       || 'POST';
            document.getElementById('cpFSendAs').value          = cfg.send_as      || 'body';
            document.getElementById('cpFSendParam').value       = cfg.param_name   || 'value';
            document.getElementById('cpFSendPlaceholder').value = cfg.placeholder  || '';
            document.getElementById('cpFSendBtnLabel').value    = cfg.button_label || '';
        }

        cpShowTypeFields(type);
        cpShowStep('config');
        openModal('cpWidgetModal');
    };

    // --- Save ---
    window.cpSaveWidget = function() {
        const id    = document.getElementById('cpEditId').value;
        const type  = document.getElementById('cpEditType').value;
        const title = document.getElementById('cpFTitle').value.trim();
        const width = document.getElementById('cpFWidth').value;

        let config = {};
        if (type === 'button') {
            config = {
                label:  document.getElementById('cpFBtnLabel').value.trim(),
                url:    document.getElementById('cpFBtnUrl').value.trim(),
                method: document.getElementById('cpFBtnMethod').value,
                body:   document.getElementById('cpFBtnBody').value.trim(),
            };
        } else if (type === 'updown') {
            config = {
                up_label:    document.getElementById('cpFUpLabel').value.trim(),
                up_url:      document.getElementById('cpFUpUrl').value.trim(),
                up_method:   document.getElementById('cpFUpMethod').value,
                up_body:     document.getElementById('cpFUpBody').value.trim(),
                down_label:  document.getElementById('cpFDownLabel').value.trim(),
                down_url:    document.getElementById('cpFDownUrl').value.trim(),
                down_method: document.getElementById('cpFDownMethod').value,
                down_body:   document.getElementById('cpFDownBody').value.trim(),
            };
        } else if (type === 'dpad') {
            config = {};
            ['Up','Down','Left','Right'].forEach(d => {
                const key = d.toLowerCase();
                config[key + '_url']    = document.getElementById('cpFDpad' + d + 'Url').value.trim();
                config[key + '_method'] = document.getElementById('cpFDpad' + d + 'Method').value;
            });
        } else if (type === 'send') {
            config = {
                url:          document.getElementById('cpFSendUrl').value.trim(),
                method:       document.getElementById('cpFSendMethod').value,
                send_as:      document.getElementById('cpFSendAs').value,
                param_name:   document.getElementById('cpFSendParam').value.trim() || 'value',
                placeholder:  document.getElementById('cpFSendPlaceholder').value.trim(),
                button_label: document.getElementById('cpFSendBtnLabel').value.trim(),
            };
        }

        const payload = new URLSearchParams({
            _csrf:  csrfToken,
            id:     id,
            type:   type,
            title:  title,
            width:  width,
            config: JSON.stringify(config),
        });

        fetch(BASE + '/?page=api&action=save_cp_widget', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload,
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                closeModal('cpWidgetModal');
                location.reload();
            } else {
                cpToast(data.error || 'Errore', 'error');
            }
        })
        .catch(() => cpToast('Errore di rete', 'error'));
    };

    // --- Webhook URL picker (floating dropdown) ---
    const CP_WEBHOOKS = <?= $webhookPickerJson ?>;
    let _cpPickerTargetId = null;
    const _cpDropdown = document.getElementById('cpWebhookPickerDropdown');

    window.cpOpenWebhookPicker = function(inputId, btn) {
        // If already open for this same field, close it
        if (_cpDropdown.style.display !== 'none' && _cpPickerTargetId === inputId) {
            _cpDropdown.style.display = 'none';
            return;
        }
        _cpPickerTargetId = inputId;

        // Build list
        const list = document.getElementById('cpWebhookPickerList');
        list.innerHTML = '';
        if (CP_WEBHOOKS.length === 0) {
            list.innerHTML = '<li class="cp-picker-empty">Nessun webhook trovato</li>';
        } else {
            CP_WEBHOOKS.forEach(wh => {
                const li = document.createElement('li');
                li.className = 'cp-picker-item';
                li.innerHTML = '<span class="cp-picker-name">' + wh.name.replace(/</g,'&lt;') + '</span>'
                             + '<span class="cp-picker-url">' + wh.url.replace(/</g,'&lt;') + '</span>';
                li.onclick = () => {
                    document.getElementById(_cpPickerTargetId).value = wh.url;
                    _cpDropdown.style.display = 'none';
                };
                list.appendChild(li);
            });
        }

        // Position below the trigger button
        const rect = btn.getBoundingClientRect();
        _cpDropdown.style.display = 'block';
        _cpDropdown.style.position = 'fixed';
        _cpDropdown.style.top  = (rect.bottom + 4) + 'px';
        _cpDropdown.style.left = Math.max(4, rect.right - _cpDropdown.offsetWidth) + 'px';
        // Recompute after display so offsetWidth is accurate
        requestAnimationFrame(() => {
            _cpDropdown.style.left = Math.max(4, rect.right - _cpDropdown.offsetWidth) + 'px';
        });
    };

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!_cpDropdown.contains(e.target) && !e.target.closest('.cp-webhook-pick-btn')) {
            _cpDropdown.style.display = 'none';
        }
    }, true);

    // --- Delete ---
    window.cpDeleteWidget = function(btn) {
        if (!confirm(<?= json_encode(__('cp.confirm_delete')) ?>)) return;
        const card = btn.closest('.cp-widget');
        const id   = card.dataset.id;

        fetch(BASE + '/?page=api&action=delete_cp_widget', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ _csrf: csrfToken, id: id }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) card.remove();
            else cpToast(data.error || 'Errore', 'error');
        })
        .catch(() => cpToast('Errore di rete', 'error'));
    };

    [
        ['cpFBtnMethod', 'cpFBtnBodyGroup'],
        ['cpFUpMethod', 'cpFUpBodyGroup'],
        ['cpFDownMethod', 'cpFDownBodyGroup'],
    ].forEach(([methodId, groupId]) => {
        const methodEl = document.getElementById(methodId);
        if (!methodEl) return;
        methodEl.addEventListener('change', () => cpSyncMethodBodyVisibility(methodId, groupId));
        cpSyncMethodBodyVisibility(methodId, groupId);
    });
})();
</script>
