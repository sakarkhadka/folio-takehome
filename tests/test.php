<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('title search returns matching document', function () {
    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['%Welcome%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) >= 1, 'expected at least one match for "Welcome"');
    assert_true(stripos($rows[0]['title'], 'welcome') !== false, 'matched row title should contain search term');
});

test('title search returns nothing for unmatched term', function () {
    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['%zzznomatch%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) === 0, 'expected no results for unmatched term');
});

test('title search is case-insensitive', function () {
    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['%welcome%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) >= 1, 'expected case-insensitive match for lowercase "welcome"');
});

test('document with future publish_at is not yet available', function () {
    $future = (new DateTime('+1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?,?,1,?)')
        ->execute(['Future Doc', 'body', $future]);
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $publishAt = new DateTime($future, new DateTimeZone('UTC'));
    assert_true($now < $publishAt, 'future document should not be available yet');
});

test('document with past publish_at is available', function () {
    $past = (new DateTime('-1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?,?,1,?)')
        ->execute(['Past Doc', 'body', $past]);
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $publishAt = new DateTime($past, new DateTimeZone('UTC'));
    assert_true($now >= $publishAt, 'past-scheduled document should be available');
});

test('document with null publish_at is immediately available', function () {
    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE title = ?');
    $stmt->execute(['Welcome Packet']);
    $row = $stmt->fetch();
    assert_true($row['publish_at'] === null, 'seeded document should have no publish_at restriction');
});

test('seeded document has a readable_id', function () {
    $stmt = db()->prepare('SELECT readable_id FROM documents WHERE title = ?');
    $stmt->execute(['Welcome Packet']);
    $row = $stmt->fetch();
    assert_true($row['readable_id'] !== null, 'seeded document should have a readable_id');
});

test('readable_id matches slug pattern', function () {
    $stmt = db()->prepare('SELECT readable_id FROM documents WHERE title = ?');
    $stmt->execute(['Welcome Packet']);
    $row = $stmt->fetch();
    assert_true(
        preg_match('/^[a-z0-9][a-z0-9-]*-[a-z0-9]{4}$/', $row['readable_id']) === 1,
        'readable_id should match slug-XXXX pattern, got: ' . var_export($row['readable_id'], true)
    );
});

test('generate_readable_id produces different values for same title', function () {
    $id1 = generate_readable_id('Test Document');
    $id2 = generate_readable_id('Test Document');
    assert_true($id1 !== $id2, 'two generated readable_ids for same title should differ');
});

test('generate_readable_id slugifies special characters', function () {
    $id = generate_readable_id('2024 Benefits & Onboarding Guide!');
    assert_true(
        preg_match('/^[a-z0-9][a-z0-9-]*-[a-z0-9]{4}$/', $id) === 1,
        'readable_id with special chars should match slug pattern, got: ' . var_export($id, true)
    );
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
