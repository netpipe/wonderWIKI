<?php
session_start();

$db_file = __DIR__ . '/miniwiki.sqlite';
$pdo = new PDO('sqlite:' . $db_file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

function table_exists(PDO $pdo, string $name): bool {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$name]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function init_db(PDO $pdo): void {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            display_name TEXT NOT NULL,
            credits INTEGER NOT NULL DEFAULT 0,
            is_admin INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            content TEXT NOT NULL,
            author_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE SET NULL
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS edits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id INTEGER NOT NULL,
            user_id INTEGER,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            summary TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
        )
    ');

    $pw = password_hash('admin', PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users (username, password_hash, display_name, credits, is_admin) VALUES (?,?,?,?,?)')
        ->execute(['admin', $pw, 'Administrator', 1000, 1]);
    $admin_id = (int)$pdo->lastInsertId();

    $main = "# Welcome\n\nThis is a functional mini wiki.\n\nLinks: [[Help]] [[Sandbox]]\n\nEdit this page to try it.\n\nExternal: [https://www.php.net PHP docs]";
    $pdo->prepare('INSERT INTO pages (title, slug, content, author_id) VALUES (?,?,?,?)')
        ->execute(['Main Page', 'main-page', $main, $admin_id]);
    $main_id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO edits (page_id, user_id, title, content, summary) VALUES (?,?,?,?,?)')
        ->execute([$main_id, $admin_id, 'Main Page', $main, 'Created main page']);

    $help = "# Help\n\nMarkup:\n\n- `# Heading`\n- `## Smaller heading`\n- `**bold**`\n- `_italic_`\n- ``code``\n- `[[Page Name]]`\n- `[[Page Name|link text]]`\n- `[https://example.com Label]`\n\nRed links create new pages.";
    $pdo->prepare('INSERT INTO pages (title, slug, content, author_id) VALUES (?,?,?,?)')
        ->execute(['Help', 'help', $help, $admin_id]);
    $help_id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO edits (page_id, user_id, title, content, summary) VALUES (?,?,?,?,?)')
        ->execute([$help_id, $admin_id, 'Help', $help, 'Created help page']);
}

if (!table_exists($pdo, 'users')) {
    init_db($pdo);
}

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function consume_flash(): ?array {
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void {
    if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Invalid CSRF token. Go back, refresh, and try again.');
    }
}

function current_user(PDO $pdo): ?array {
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }
    return null;
}

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        redirect('?action=login');
    }
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s === '' ? 'page' : $s;
}

function unique_slug(PDO $pdo, string $slug, int $ignore_id = 0): string {
    $candidate = $slug;
    $i = 2;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug=?');
        $stmt->execute([$candidate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['id'] === $ignore_id) {
            return $candidate;
        }
        $candidate = $slug . '-' . $i;
        $i++;
    }
}

function get_page_by_slug(PDO $pdo, string $slug): ?array {
    $stmt = $pdo->prepare('SELECT p.*, u.display_name AS author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id WHERE p.slug=?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_page_by_title(PDO $pdo, string $title): ?array {
    $slug = slugify($title);
    $stmt = $pdo->prepare('SELECT * FROM pages WHERE slug=? OR lower(title)=lower(?) LIMIT 1');
    $stmt->execute([$slug, $title]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_page_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM pages WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function render_wiki(PDO $pdo, string $text): string {
    $html = h($text);

    $html = preg_replace_callback('/^(#{1,6})\s+(.+)$/m', function (array $m): string {
        $level = strlen($m[1]);
        $title = trim($m[2]);
        return "<h{$level}>{$title}</h{$level}>";
    }, $html);

    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/_(.+?)_/s', '<em>$1</em>', $html);
    $html = preg_replace('/`([^`]+)`/s', '<code>$1</code>', $html);

    $html = preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u', function (array $m) use ($pdo): string {
        $title = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        $label = isset($m[2]) && trim($m[2]) !== '' ? trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')) : $title;
        $page = get_page_by_title($pdo, $title);
        if ($page) {
            return '<a href="?action=view&page=' . h(rawurlencode($page['slug'])) . '">' . h($label) . '</a>';
        }
        return '<a class="redlink" href="?action=edit&title=' . h(rawurlencode($title)) . '">' . h($label) . '</a>';
    }, $html);

    $html = preg_replace_callback('/\[(https?:\/\/[^\s\]]+)(?:\s+([^\]]+))?\]/u', function (array $m): string {
        $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        $label = isset($m[2]) && trim($m[2]) !== '' ? trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')) : $url;
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return h($m[0]);
        }
        return '<a href="' . h($url) . '" rel="noopener noreferrer" target="_blank">' . h($label) . '</a>';
    }, $html);

    return nl2br($html, false);
}

$action = $_GET['action'] ?? 'home';
$me = current_user($pdo);
$flash_data = consume_flash();
$error = ($flash_data && $flash_data['type'] === 'error') ? $flash_data['message'] : '';
$success = ($flash_data && $flash_data['type'] === 'success') ? $flash_data['message'] : '';

if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        flash('error', 'Username and password are required.');
        redirect('?action=register');
    }

    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (username, password_hash, display_name, credits) VALUES (?,?,?,?)')
            ->execute([$username, $hash, $display_name !== '' ? $display_name : $username, 10]);
        $_SESSION['user_id'] = (int)$pdo->lastInsertId();
        flash('success', 'Registration successful.');
        redirect('?');
    } catch (PDOException $ex) {
        flash('error', 'Registration failed. Username may already be taken.');
        redirect('?action=register');
    }
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username=?');
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['user_id'] = (int)$row['id'];
        flash('success', 'Logged in.');
        redirect('?');
    }

    flash('error', 'Invalid username or password.');
    redirect('?action=login');
}

if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    session_destroy();
    redirect('?');
}

if ($action === 'save_page' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_login();
    $me = current_user($pdo);

    $page_id = (int)($_POST['page_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    $summary = substr(trim($_POST['summary'] ?? ''), 0, 255);
    $slug_in = trim($_POST['slug'] ?? '');

    if ($title === '') {
        flash('error', 'Title is required.');
        redirect($page_id > 0 ? '?action=edit&page=' . rawurlencode($slug_in) : '?action=edit');
    }

    if ($page_id > 0) {
        $page = get_page_by_id($pdo, $page_id);
        if (!$page) {
            flash('error', 'Page not found.');
            redirect('?');
        }

        $pdo->prepare('UPDATE pages SET title=?, content=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$title, $content, $page_id]);
        $pdo->prepare('INSERT INTO edits (page_id, user_id, title, content, summary) VALUES (?,?,?,?,?)')
            ->execute([$page_id, $me['id'], $title, $content, $summary !== '' ? $summary : 'Edited page']);
        $pdo->prepare('UPDATE users SET credits = credits + 1 WHERE id=?')->execute([$me['id']]);

        flash('success', 'Page saved.');
        redirect('?action=view&page=' . rawurlencode($page['slug']));
    } else {
        $existing = get_page_by_title($pdo, $title);
        if ($existing) {
            redirect('?action=view&page=' . rawurlencode($existing['slug']));
        }

        $slug = unique_slug($pdo, slugify($title));
        $pdo->prepare('INSERT INTO pages (title, slug, content, author_id) VALUES (?,?,?,?)')
            ->execute([$title, $slug, $content, $me['id']]);
        $new_id = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO edits (page_id, user_id, title, content, summary) VALUES (?,?,?,?,?)')
            ->execute([$new_id, $me['id'], $title, $content, $summary !== '' ? $summary : 'Created page']);
        $pdo->prepare('UPDATE users SET credits = credits + 5 WHERE id=?')->execute([$me['id']]);

        flash('success', 'Page created.');
        redirect('?action=view&page=' . rawurlencode($slug));
    }
}

if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_login();
    $me = current_user($pdo);

    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $slug = trim($_POST['slug'] ?? '');

    $stmt = $pdo->prepare('SELECT e.*, p.slug AS page_slug FROM edits e JOIN pages p ON e.page_id = p.id WHERE e.id=?');
    $stmt->execute([$edit_id]);
    $rev = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rev) {
        flash('error', 'Revision not found.');
        redirect('?action=view&page=' . rawurlencode($slug));
    }

    $pdo->prepare('UPDATE pages SET title=?, content=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
        ->execute([$rev['title'], $rev['content'], $rev['page_id']]);
    $pdo->prepare('INSERT INTO edits (page_id, user_id, title, content, summary) VALUES (?,?,?,?,?)')
        ->execute([$rev['page_id'], $me['id'], $rev['title'], $rev['content'], 'Restored revision #' . $edit_id]);

    flash('success', 'Revision restored.');
    redirect('?action=view&page=' . rawurlencode($rev['page_slug']));
}

$recent_pages = $pdo->query('SELECT p.*, u.display_name AS author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id ORDER BY p.updated_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
$recent_changes = $pdo->query('SELECT e.*, p.title, p.slug, u.display_name FROM edits e JOIN pages p ON e.page_id = p.id LEFT JOIN users u ON e.user_id = u.id ORDER BY e.created_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
$leaders = $pdo->query('SELECT id, display_name, username, credits FROM users ORDER BY credits DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);

$page = null;
$history = [];

if ($action === 'view' && !empty($_GET['page'])) {
    $page = get_page_by_slug($pdo, (string)$_GET['page']);
    if ($page) {
        $stmt = $pdo->prepare('SELECT e.*, u.display_name FROM edits e LEFT JOIN users u ON e.user_id = u.id WHERE e.page_id=? ORDER BY e.created_at DESC LIMIT 100');
        $stmt->execute([$page['id']]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$edit_page = null;
$edit_title = '';

if ($action === 'edit') {
    require_login();
    if (!empty($_GET['page'])) {
        $edit_page = get_page_by_slug($pdo, (string)$_GET['page']);
        if (!$edit_page) {
            $edit_title = ucwords(str_replace('-', ' ', (string)$_GET['page']));
        }
    } elseif (!empty($_GET['title'])) {
        $edit_title = trim((string)$_GET['title']);
    }
}

$search_q = trim((string)($_GET['q'] ?? ''));
$search_results = [];

if ($action === 'search' && $search_q !== '') {
    $escaped_q = str_replace(['%', '_'], ['\%', '\_'], $search_q);
    $stmt = $pdo->prepare("SELECT p.*, u.display_name AS author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id WHERE p.title LIKE ? ESCAPE '\\' OR p.content LIKE ? ESCAPE '\\' ORDER BY p.updated_at DESC LIMIT 100");
    $stmt->execute(['%' . $escaped_q . '%', '%' . $escaped_q . '%']);
    $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MiniWiki</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f6f8fb;color:#111827}
a{color:#1d4ed8;text-decoration:none}
a:hover{text-decoration:underline}
.redlink{color:#b91c1c}
header{background:#1f2937;color:#fff;padding:12px 18px}
header a{color:#fff}
.wrap{max-width:1000px;margin:20px auto;padding:16px;background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.row{display:flex;gap:18px;flex-wrap:wrap}
.col-main{flex:2;min-width:280px}
.col-side{flex:1;min-width:240px}
.card{border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:0 0 12px 0}
input,textarea,select,button{font:inherit}
input[type=text],input[type=password],textarea{width:100%;box-sizing:border-box;padding:8px;border:1px solid #d1d5db;border-radius:6px}
textarea{min-height:220px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
button{padding:8px 12px;border:0;background:#1d4ed8;color:#fff;border-radius:6px;cursor:pointer}
.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:10px;border-radius:6px;margin-bottom:12px}
.success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:10px;border-radius:6px;margin-bottom:12px}
.meta{color:#6b7280;font-size:13px;margin:6px 0 12px}
.content{border:1px solid #e5e7eb;border-radius:8px;padding:14px;line-height:1.5}
.content code{background:#f3f4f6;padding:2px 4px;border-radius:4px}
table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid #e5e7eb;padding:8px;text-align:left;vertical-align:top}
.small{font-size:13px;color:#6b7280}
.inline-form{display:inline}
</style>
</head>
<body>
<header>
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div><strong>MiniWiki</strong></div>
    <div>
      <a href="?">Home</a> |
      <a href="?action=edit">New page</a> |
      <a href="?action=search">Search</a>
      <?php if ($me): ?>
        | <?php echo h($me['display_name']); ?> (<?php echo h($me['credits']); ?> credits)
        <form method="post" action="?action=logout" class="inline-form">
          <?php echo csrf_field(); ?>
          <button type="submit">Logout</button>
        </form>
      <?php else: ?>
        | <a href="?action=login">Login</a> | <a href="?action=register">Register</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="wrap">
<?php if ($error): ?><div class="error"><?php echo h($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="success"><?php echo h($success); ?></div><?php endif; ?>

<?php if ($action === 'home'): ?>
  <div class="row">
    <div class="col-main">
      <h1>Welcome</h1>
      <?php if ($me): ?>
        <div class="card">
          <h2>Create a new page</h2>
          <form method="post" action="?action=save_page">
            <?php echo csrf_field(); ?>
            <p><input type="text" name="title" placeholder="Page title" required></p>
            <p><textarea name="content" placeholder="Write wiki text..."></textarea></p>
            <p><input type="text" name="summary" placeholder="Summary (optional)"></p>
            <p><button type="submit">Create page</button></p>
          </form>
        </div>
      <?php else: ?>
        <p><a href="?action=login">Log in</a> to create pages.</p>
      <?php endif; ?>

      <h2>Recently updated pages</h2>
      <?php foreach ($recent_pages as $p): ?>
        <div class="card">
          <a href="?action=view&page=<?php echo h(rawurlencode($p['slug'])); ?>"><strong><?php echo h($p['title']); ?></strong></a>
          <div class="small">by <?php echo h($p['author_name'] ?? 'Unknown'); ?> — <?php echo h($p['updated_at']); ?></div>
        </div>
      <?php endforeach; ?>

      <h2>Recent changes</h2>
      <div class="card">
        <?php if (!$recent_changes): ?>
          <div class="small">No edits yet.</div>
        <?php else: ?>
          <table>
            <tr><th>Page</th><th>Summary</th><th>User</th><th>Date</th></tr>
            <?php foreach ($recent_changes as $rc): ?>
              <tr>
                <td><a href="?action=view&page=<?php echo h(rawurlencode($rc['slug'])); ?>"><?php echo h($rc['title']); ?></a></td>
                <td><?php echo h($rc['summary']); ?></td>
                <td><?php echo h($rc['display_name'] ?? 'Anonymous'); ?></td>
                <td><?php echo h($rc['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-side">
      <div class="card">
        <h2>Leaderboard</h2>
        <?php foreach ($leaders as $l): ?>
          <div><?php echo h($l['display_name'] ?: $l['username']); ?> — <?php echo h($l['credits']); ?></div>
        <?php endforeach; ?>
      </div>
      <div class="card">
        <h2>Quick tips</h2>
        <ul>
          <li>Use <code>[[Page Name]]</code> to link.</li>
          <li>Red links create pages.</li>
          <li>Every save is stored in history.</li>
        </ul>
      </div>
    </div>
  </div>

<?php elseif ($action === 'view' && $page): ?>
  <p><a href="?">← Home</a> | <a href="?action=edit&page=<?php echo h(rawurlencode($page['slug'])); ?>">Edit</a></p>
  <h1><?php echo h($page['title']); ?></h1>
  <div class="meta">Created <?php echo h($page['created_at']); ?> — updated <?php echo h($page['updated_at']); ?> by <?php echo h($page['author_name'] ?? 'Unknown'); ?></div>
  <div class="content"><?php echo render_wiki($pdo, $page['content']); ?></div>

  <h2>History</h2>
  <table>
    <tr><th>Date</th><th>User</th><th>Summary</th><th>Action</th></tr>
    <?php foreach ($history as $rev): ?>
      <tr>
        <td><?php echo h($rev['created_at']); ?></td>
        <td><?php echo h($rev['display_name'] ?? 'Anonymous'); ?></td>
        <td><?php echo h($rev['summary']); ?></td>
        <td>
          <?php if ($me): ?>
            <form method="post" action="?action=restore" class="inline-form">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="edit_id" value="<?php echo h($rev['id']); ?>">
              <input type="hidden" name="slug" value="<?php echo h($page['slug']); ?>">
              <button type="submit">Restore</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

<?php elseif ($action === 'view'): ?>
  <h1>Page not found</h1>
  <?php $missing = ucwords(str_replace('-', ' ', (string)($_GET['page'] ?? ''))); ?>
  <p>The page “<?php echo h($missing); ?>” does not exist yet.</p>
  <p><a href="?action=edit&title=<?php echo h(rawurlencode($missing)); ?>">Create this page</a></p>

<?php elseif ($action === 'edit'): ?>
  <h1><?php echo $edit_page ? 'Edit: ' . h($edit_page['title']) : 'Create page'; ?></h1>
  <form method="post" action="?action=save_page">
    <?php echo csrf_field(); ?>
    <?php if ($edit_page): ?>
      <input type="hidden" name="page_id" value="<?php echo h($edit_page['id']); ?>">
      <input type="hidden" name="slug" value="<?php echo h($edit_page['slug']); ?>">
    <?php endif; ?>
    <p><input type="text" name="title" value="<?php echo h($edit_page['title'] ?? $edit_title); ?>" required></p>
    <p><textarea name="content"><?php echo h($edit_page['content'] ?? ''); ?></textarea></p>
    <p><input type="text" name="summary" placeholder="Edit summary (optional)"></p>
    <p>
      <button type="submit">Save page</button>
      <a href="<?php echo $edit_page ? '?action=view&page=' . h(rawurlencode($edit_page['slug'])) : '?'; ?>">Cancel</a>
    </p>
  </form>
  <div class="card">
    <h2>Markup</h2>
    <ul>
      <li><code># Heading</code>, <code>## Subheading</code></li>
      <li><code>**bold**</code>, <code>_italic_</code>, <code>`code`</code></li>
      <li><code>[[Page Name]]</code> or <code>[[Page Name|link text]]</code></li>
      <li><code>[https://example.com Label]</code></li>
    </ul>
  </div>

<?php elseif ($action === 'search'): ?>
  <h1>Search</h1>
  <form method="get" action="?">
    <input type="hidden" name="action" value="search">
    <p><input type="text" name="q" value="<?php echo h($search_q); ?>" placeholder="Search pages..."></p>
    <p><button type="submit">Search</button></p>
  </form>

  <?php if ($search_q !== ''): ?>
    <h2>Results for “<?php echo h($search_q); ?>”</h2>
    <?php if (!$search_results): ?>
      <p>No pages found. You can <a href="?action=edit&title=<?php echo h(rawurlencode($search_q)); ?>">create “<?php echo h($search_q); ?>”</a>.</p>
    <?php else: ?>
      <?php foreach ($search_results as $sr): ?>
        <div class="card">
          <a href="?action=view&page=<?php echo h(rawurlencode($sr['slug'])); ?>"><strong><?php echo h($sr['title']); ?></strong></a>
          <div class="small">by <?php echo h($sr['author_name'] ?? 'Unknown'); ?> — <?php echo h($sr['updated_at']); ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

<?php elseif ($action === 'login'): ?>
  <h1>Login</h1>
  <form method="post" action="?action=login">
    <?php echo csrf_field(); ?>
    <p><input type="text" name="username" placeholder="Username" required></p>
    <p><input type="password" name="password" placeholder="Password" required></p>
    <p><button type="submit">Login</button></p>
  </form>
  <p>Default demo admin: <code>admin / admin</code>. No account? <a href="?action=register">Register</a>.</p>

<?php elseif ($action === 'register'): ?>
  <h1>Register</h1>
  <form method="post" action="?action=register">
    <?php echo csrf_field(); ?>
    <p><input type="text" name="username" placeholder="Username" required></p>
    <p><input type="text" name="display_name" placeholder="Display name"></p>
    <p><input type="password" name="password" placeholder="Password" required></p>
    <p><button type="submit">Register</button></p>
  </form>
  <p>Already have an account? <a href="?action=login">Login</a>.</p>

<?php else: ?>
  <h1>Not found</h1>
  <p>That action is not supported.</p>
<?php endif; ?>

</div>
</body>
</html>