<?php
$current_user = requireAuth($db);
$userId       = (int)$current_user['id'];
$page_title   = __('known_ips.title');
$action       = $_GET['action'] ?? '';

// --- ADD ---
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=known_ips');
        exit;
    }
    $ip    = trim($_POST['ip'] ?? '');
    $label = trim($_POST['label'] ?? '');
    if ($ip !== '' && $label !== '') {
        $db->prepare('INSERT INTO known_ips (user_id, ip, label) VALUES (?, ?, ?)')->execute([$userId, $ip, $label]);
        setFlash('success', __('msg.saved'));
    } else {
        setFlash('error', __('msg.required'));
    }
    header('Location: ' . BASE_URL . '/?page=known_ips');
    exit;
}

// --- DELETE ---
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=known_ips');
        exit;
    }
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $db->prepare('DELETE FROM known_ips WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
        setFlash('success', __('msg.deleted'));
    }
    header('Location: ' . BASE_URL . '/?page=known_ips');
    exit;
}

// --- EDIT ---
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=known_ips');
        exit;
    }
    $id    = (int)($_GET['id'] ?? 0);
    $ip    = trim($_POST['ip'] ?? '');
    $label = trim($_POST['label'] ?? '');
    if ($id > 0 && $ip !== '' && $label !== '') {
        $db->prepare('UPDATE known_ips SET ip = ?, label = ? WHERE id = ? AND user_id = ?')
           ->execute([$ip, $label, $id, $userId]);
        setFlash('success', __('msg.saved'));
    }
    header('Location: ' . BASE_URL . '/?page=known_ips');
    exit;
}

$rows = $db->prepare('SELECT * FROM known_ips WHERE user_id = ? ORDER BY label');
$rows->execute([$userId]);
$knownIps = $rows->fetchAll();
?>
<div class="page-container">
    <div class="page-header">
        <div class="header-title-group">
            <div class="breadcrumb">
                <a href="<?= BASE_URL ?>/?page=settings"><?= __('nav.settings') ?></a>
                <span class="breadcrumb-sep">›</span>
                <span><?= __('known_ips.title') ?></span>
            </div>
            <h1><?= __('known_ips.title') ?></h1>
        </div>
        <a href="<?= BASE_URL ?>/?page=settings" class="btn btn-outline"><?= __('form.back') ?></a>
    </div>

    <div class="card">
        <p class="text-muted" style="margin-top:0"><?= __('known_ips.desc') ?></p>

        <!-- Add form -->
        <form method="post" action="<?= BASE_URL ?>/?page=known_ips&action=add" class="form" style="margin-bottom:1.5rem">
            <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
            <div class="form-row" style="align-items:flex-end;gap:0.75rem">
                <div class="form-group flex-1" style="margin-bottom:0">
                    <label><?= __('known_ips.ip') ?></label>
                    <input type="text" name="ip" placeholder="192.168.1.1" maxlength="45" required style="font-family:var(--font-mono)">
                </div>
                <div class="form-group flex-1" style="margin-bottom:0">
                    <label><?= __('known_ips.label') ?></label>
                    <input type="text" name="label" placeholder="<?= __('known_ips.label_placeholder') ?>" maxlength="50" required>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-bottom:0">+ <?= __('known_ips.add') ?></button>
            </div>
        </form>

        <!-- List -->
        <?php if (empty($knownIps)): ?>
        <p class="text-muted"><?= __('known_ips.empty') ?></p>
        <?php else: ?>
        <table class="diag-table known-ips-table">
            <thead>
                <tr>
                    <th><?= __('known_ips.ip') ?></th>
                    <th><?= __('known_ips.label') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($knownIps as $row): ?>
            <tr id="row-<?= $row['id'] ?>">
                <td class="mono" id="ip-disp-<?= $row['id'] ?>"><?= e($row['ip']) ?></td>
                <td id="label-disp-<?= $row['id'] ?>"><?= e($row['label']) ?></td>
                <td style="text-align:right;white-space:nowrap">
                    <button class="btn btn-xs btn-outline" onclick="openEditKip(<?= $row['id'] ?>, <?= htmlspecialchars(json_encode($row['ip'])) ?>, <?= htmlspecialchars(json_encode($row['label'])) ?>)">
                        <?= __('form.edit') ?>
                    </button>
                    <form method="post" action="<?= BASE_URL ?>/?page=known_ips&action=delete&id=<?= $row['id'] ?>" class="inline" onsubmit="return confirm('<?= __('known_ips.confirm_delete') ?>')">
                        <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                        <button type="submit" class="btn btn-xs btn-danger"><?= __('form.delete') ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Edit modal -->
<div id="editKipModal" class="modal" style="display:none" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><?= __('known_ips.edit') ?></h3>
            <button onclick="closeModal('editKipModal')" class="modal-close">&times;</button>
        </div>
        <form method="post" id="editKipForm" class="form">
            <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label><?= __('known_ips.ip') ?></label>
                    <input type="text" name="ip" id="editKipIp" maxlength="45" required style="font-family:var(--font-mono)">
                </div>
                <div class="form-group">
                    <label><?= __('known_ips.label') ?></label>
                    <input type="text" name="label" id="editKipLabel" maxlength="50" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= __('form.save') ?></button>
                <button type="button" onclick="closeModal('editKipModal')" class="btn btn-outline"><?= __('form.cancel') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
function openEditKip(id, ip, label) {
    document.getElementById('editKipIp').value = ip;
    document.getElementById('editKipLabel').value = label;
    document.getElementById('editKipForm').action = '<?= BASE_URL ?>/?page=known_ips&action=edit&id=' + id;
    openModal('editKipModal');
}
</script>
