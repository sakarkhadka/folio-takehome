<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$token   = $_GET['token'] ?? '';
$idParam = $_GET['id']    ?? '';

if ($idParam !== '') {
    $stmt = db()->prepare('SELECT * FROM documents WHERE readable_id = ?');
    $stmt->execute([$idParam]);
    $doc = $stmt->fetch();
    if ($doc) {
        $doc['recipient_email'] = null;
    }
} else {
    $stmt = db()->prepare('
        SELECT d.*, s.recipient_email
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $doc = $stmt->fetch();
}

if (!$doc) {
    http_response_code(404);
    render_header('Not found');
    ?>
    <div class="centered-message">
        <h1><?= $idParam !== '' ? 'Document not found' : 'Share link not found' ?></h1>
        <p><?= $idParam !== '' ? 'No document exists with that ID.' : 'The link you used is invalid or has been removed.' ?></p>
    </div>
    <?php
    render_footer();
    exit;
}

if ($doc['publish_at'] !== null) {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $publishAt = new DateTime($doc['publish_at'], new DateTimeZone('UTC'));
    if ($now < $publishAt) {
        $localTime = $publishAt->setTimezone(new DateTimeZone('America/Chicago'))
                               ->format('M j, Y \a\t g:i A T');
        render_header('Not yet available');
        ?>
        <div class="centered-message">
            <h1>Not yet available</h1>
            <p>This document will be available on <?= h($localTime) ?>.</p>
        </div>
        <?php
        render_footer();
        exit;
    }
}

render_header($doc['title']);
?>

<h1 class="page-title"><?= h($doc['title']) ?></h1>
<?php if ($doc['recipient_email']): ?><p class="meta">Shared with <?= h($doc['recipient_email']) ?></p><?php endif ?>

<pre class="doc-body"><?= h($doc['body']) ?></pre>

<?php render_footer(); ?>
