<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$docParam = $_GET['doc'] ?? '';
if (ctype_digit($docParam)) {
    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
    $stmt->execute([(int) $docParam]);
} else {
    $stmt = db()->prepare('SELECT * FROM documents WHERE readable_id = ?');
    $stmt->execute([$docParam]);
}
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header('Not found', $staff);
    ?>
    <div class="banner banner-error">Document not found.</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

$isScheduled = false;
$scheduledFor = null;
if ($doc['publish_at'] !== null) {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $publishAt = new DateTime($doc['publish_at'], new DateTimeZone('UTC'));
    if ($now < $publishAt) {
        $isScheduled = true;
        $scheduledFor = $publishAt->setTimezone(new DateTimeZone('America/Chicago'))
                                  ->format('M j, Y \a\t g:i A T');
    }
}

$error = null;
$created_token = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        $error = 'Recipient email is required.';
    } else {
        $token = random_token();
        $stmt = db()->prepare('
            INSERT INTO shares (document_id, token, recipient_email)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([$doc['id'], $token, $email]);
        $shareId = (int) db()->lastInsertId();
        audit_log('create', 'share', $shareId, [
            'document_id' => $doc['id'],
            'recipient_email' => $email,
        ]);
        $created_token = $token;
    }
}

render_header('Share · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Share "<?= h($doc['title']) ?>"</h1>
<p class="page-subtitle">Generate a one-time link for a recipient.</p>

<?php if ($isScheduled): ?>
    <div class="banner banner-warn">
        This document is scheduled to publish on <?= h($scheduledFor) ?>. Recipients will see a "not yet available" message until then.
    </div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<?php if ($created_token): ?>
    <div class="banner banner-success">
        <p class="share-links-label">Share link ready — choose which to send:</p>
        <div class="link-row">
            <span class="link-label">Token link <span class="label-hint">(private, unguessable — best for sensitive documents)</span></span>
            <div class="link-url-row">
                <code id="token-url">http://<?= h($_SERVER['HTTP_HOST']) ?>/view.php?token=<?= h($created_token) ?></code>
                <button class="btn-copy" onclick="copyLink('token-url', this)">Copy</button>
            </div>
        </div>
        <?php if ($doc['readable_id'] !== null): ?>
        <div class="link-row">
            <span class="link-label">Readable link <span class="label-hint">(easy to say, type, or share verbally)</span></span>
            <div class="link-url-row">
                <code id="readable-url">http://<?= h($_SERVER['HTTP_HOST']) ?>/view.php?id=<?= h($doc['readable_id']) ?></code>
                <button class="btn-copy" onclick="copyLink('readable-url', this)">Copy</button>
            </div>
        </div>
        <?php endif ?>
    </div>
    <script>
    function copyLink(id, btn) {
        navigator.clipboard.writeText(document.getElementById(id).textContent.trim())
            .then(function() {
                btn.textContent = 'Copied!';
                setTimeout(function() { btn.textContent = 'Copy'; }, 1500);
            }).catch(function() {});
    }
    </script>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Create share link</h2>
    <form method="post">
        <div class="form-field">
            <label for="email">Recipient email</label>
            <input type="email" id="email" name="email" required>
        </div>
        <button type="submit" class="btn">Generate link</button>
    </form>
</section>

<?php render_footer(); ?>
