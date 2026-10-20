<?php
// miniwiki_singlefile.php
// Functional single-file wiki demo (PHP + SQLite)
// Demo quality. Change admin password, use HTTPS, real payment webhooks, backups.

session_start();

$dbfile = __DIR__ . '/miniwiki.sqlite';
$pdo = new PDO('sqlite:' . $dbfile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

function table_exists($pdo, $name) {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$name]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function init_db($pdo) {
    $sql = <<<SQL
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    display_name TEXT,
    credits INTEGER NOT NULL DEFAULT 0,
    is_admin INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    credits INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS group_members (
    group_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'member',
    PRIMARY KEY (group_id, user_id),
    FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    parent_id INTEGER NULL,
    content TEXT NOT NULL DEFAULT '',
    author_id INTEGER NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_locked INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(parent_id) REFERENCES pages(id) ON DELETE SET NULL,
    FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS edits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL,
    user_id INTEGER NULL,
    title TEXT NOT NULL,
    content TEXT NOT NULL,
    summary TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS redirects (
    old_slug TEXT PRIMARY KEY,
    page_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rewards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    group_id INTEGER NULL,
    amount INTEGER NOT NULL,
    reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_pages_parent ON pages(parent_id);
CREATE INDEX IF NOT EXISTS idx_edits_page ON edits(page_id);
CREATE INDEX IF NOT EXISTS idx_edits_user ON edits(user_id);
CREATE INDEX IF NOT EXISTS idx_rewards_user_date ON rewards(user_id, created_at);
SQL;

    $pdo->exec($sql);

    $pw = password_hash('admin', PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users (username, password_hash, display_name, is_admin, credits) VALUES (?,?,?,?,?)')
        ->execute(['admin', $pw, 'Administrator', 1, 1000]);
    $admin_id = (int)$pdo->lastInsertId();

    $main = "= Welcome to MiniWiki =\n\nThis demo supports:\n\n* pages and subpages\n* wiki links: [[Help]]\n* edit history\n* search\n* user credits and groups\n\nTry editing this page.\n\n== External links ==\n\n[https://www.php.net PHP documentation]";
    $pdo->prepare('INSERT INTO pages (title, slug, parent_id, content, author_id) VALUES (?,?,?,?,?)')
        ->execute(['Main Page', 'main-page', null, $main, $admin_id]);
    $main_id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO edits (page_id, user_id, title, content, summary) VALUES (?,?,?,?,?)')
        ->execute([$main_id, $admin_id, 'Main Page', $main, 'Created main page']);

    $help = "== Help ==\n\nUse '''bold''' and ''italic''.\n\nCreate links with [[Page Name]] or [[Page Name|link text]].\n\nSubpages can be assigned in the edit form.\n\n* bullet list\n# numbered list";
    $pdo->prepare('INSERT INTO pages (title, slug, parent_id, content, author_id) VALUES (?,?,?,?,?)')
        ->execute(['Help', 'help', null, $help, $admin_id]);
    $help_id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO edits (page_id, user_id, title, content, summary) VALUES (?,?,?,?,?)')
        ->execute([$help_id, $admin_id, 'Help', $help, 'Created help page']);
}

if (!table_exists($pdo, 'users')) {
    init_db($pdo);
}

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function e_text($s) {
    // Escapes HTML-significant characters but leaves single quotes for wiki markup.
    return htmlspecialchars((string)$s, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function consume_flash() {
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf() {
    if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Invalid CSRF token. Go back, refresh, and try again.');
    }
}

function current_user($pdo) {
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

function require_login() {
    if (empty($_SESSION['user_id'])) {
        $_SESSION['intended'] = '?' . ltrim($_SERVER['QUERY_STRING'] ?? '', '?');
        redirect('?action=login');
    }
}

function intended_url($default = '?') {
    $url = $_SESSION['intended'] ?? $default;
    unset($_SESSION['intended']);
    if (!is_string($url) || $url === '' || $url[0] !== '?') {
        return $default;
    }
    return $url;
}

function slugify($s) {
    $s = trim((string)$s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
    $s = trim($s, '-');
    return $s === '' ? 'page' : $s;
}

function unique_slug($pdo, $slug, $ignore_id = null) {
    $candidate = $slug;
    $i = 2;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug=?');
        $stmt->execute([$candidate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($ignore_id !== null && (int)$row['id'] === (int)$ignore_id)) {
            return $candidate;
        }
        $candidate = $slug . '-' . $i;
        $i++;
    }
}

function get_page_by_slug($pdo, $slug) {
    $stmt = $pdo->prepare('SELECT p.*, u.display_name AS author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id WHERE p.slug=?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_page_by_id($pdo, $id) {
    $stmt = $pdo->prepare('SELECT p.*, u.display_name AS author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id WHERE p.id=?');
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_page_by_name($pdo, $name) {
    $slug = slugify($name);
    $stmt = $pdo->prepare('SELECT p.* FROM pages p WHERE p.slug=? OR p.title COLLATE NOCASE = ? LIMIT 1');
    $stmt->execute([$slug, $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function is_descendant($pdo, $ancestor_id, $node_id) {
    $current = (int)$node_id;
    $guard = 0;
    while ($current > 0 && $guard++ < 100) {
        if ($current === (int)$ancestor_id) {
            return true;
        }
        $stmt = $pdo->prepare('SELECT parent_id FROM pages WHERE id=?');
        $stmt->execute([$current]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $current = $row['parent_id'] ? (int)$row['parent_id'] : 0;
    }
    return false;
}

function award_credits($pdo, $user_id, $amount, $reason, $daily_cap = 100) {
    $amount = (int)$amount;
    if ($amount <= 0) {
        return 0;
    }
    $reason = substr((string)$reason, 0, 255);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS today FROM rewards WHERE user_id=? AND DATE(created_at) = DATE('now')");
    $stmt->execute([$user_id]);
    $today = (int)$stmt->fetchColumn();

    $remaining = max(0, $daily_cap - $today);
    $grant = min($amount, $remaining);
    if ($grant <= 0) {
        return 0;
    }

    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([$grant, $user_id]);
    $pdo->prepare('INSERT INTO rewards (user_id, amount, reason) VALUES (?,?,?)')->execute([$user_id, $grant, $reason]);
    return $grant;
}

function render_wikitext($text, $pdo) {
    $text = (string)$text;
    $html = e_text($text);

    // Horizontal rules
    $html = preg_replace('/^-{4,}$/m', '<hr>', $html);

    // Headings: = H2 =, == H3 ==, etc. The page title is already H1.
    $html = preg_replace_callback('/^(={1,6})\s*(.*?)\s*\1\s*$/m', function ($m) {
        $level = min(strlen($m[1]) + 1, 6);
        return "<h{$level}>{$m[2]}</h{$level}>";
    }, $html);

    // Bold, italic, inline code
    $html = preg_replace("/'''(.+?)'''/s", '<strong>$1</strong>', $html);
    $html = preg_replace("/''(.+?)''/s", '<em>$1</em>', $html);
    $html = preg_replace('/`([^`]+)`/s', '<code>$1</code>', $html);

    // Wiki links: [[Page]] or [[Page|label]]
    $html = preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u', function ($m) use ($pdo) {
        $title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        $label = isset($m[2]) && $m[2] !== '' ? html_entity_decode($m[2], ENT_QUOTES, 'UTF-8') : $title;
        $page = get_page_by_name($pdo, $title);
        if ($page) {
            $href = '?action=view&page=' . urlencode($page['slug']);
            $class = '';
        } else {
            $href = '?action=edit&title=' . urlencode($title);
            $class = ' class="newpage"';
        }
        return '<a href="' . e($href) . '"' . $class . '>' . e($label) . '</a>';
    }, $html);

    // External links: [https://example.com Label]
    $html = preg_replace_callback('/\[(https?:\/\/[^\s\]]+)(?:\s+([^\]]+))?\]/u', function ($m) {
        $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        $label = isset($m[2]) && $m[2] !== '' ? html_entity_decode($m[2], ENT_QUOTES, 'UTF-8') : $url;
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return e($m[0]);
        }
        return '<a href="' . e($url) . '" rel="nofollow noopener" target="_blank">' . e($label) . '</a>';
    }, $html);

    // Simple lists
    $lines = explode("\n", $html);
    $out = [];
    $in_ul = false;
    $in_ol = false;
    foreach ($lines as $line) {
        if (preg_match('/^\*\s+(.*)$/u', $line, $m)) {
            if ($in_ol) { $out[] = '</ol>'; $in_ol = false; }
            if (!$in_ul) { $out[] = '<ul>'; $in_ul = true; }
            $out[] = '<li>' . $m[1] . '</li>';
        } elseif (preg_match('/^#\s+(.*)$/u', $line, $m)) {
            if ($in_ul) { $out[] = '</ul>'; $in_ul = false; }
            if (!$in_ol) { $out[] = '<ol>'; $in_ol = true; }
            $out[] = '<li>' . $m[1] . '</li>';
        } else {
            if ($in_ul) { $out[] = '</ul>'; $in_ul = false; }
            if ($in_ol) { $out[] = '</ol>'; $in_ol = false; }
            $out[] = $line;
        }
    }
    if ($in_ul) $out[] = '</ul>';
    if ($in_ol) $out[] = '</ol>';
    $html = implode("\n", $out);

    // Line breaks, but avoid ugly <br> immediately around block elements.
    $html = nl2br($html, false);
    $html = preg_replace('#<br\s*/?>\s*(?=<(?:h[1-6]|ul|ol|li|hr|p)[ >])#i', '', $html);
    $html = preg_replace('#(</(?:h[1-6]|ul|ol|li|p)>)<br\s*/?>#i', '$1', $html);

    return $html;
}

function page_options($pages, $selected = null, $exclude_id = null) {
    $children = [];
    foreach ($pages as $p) {
        $parent = $p['parent_id'] ? (int)$p['parent_id'] : 0;
        $children[$parent][] = $p;
    }

    $html = '';
    $walk = function ($pid, $depth) use (&$walk, $children, $selected, $exclude_id, &$html) {
        if (empty($children[$pid])) {
            return;
        }
        foreach ($children[$pid] as $p) {
            if ($exclude_id !== null && (int)$p['id'] === (int)$exclude_id) {
                continue;
            }
            $label = ($depth ? str_repeat('— ', $depth) : '') . $p['title'];
            $sel = ($selected !== null && (int)$p['id'] === (int)$selected) ? ' selected' : '';
            $html .= '<option value="' . e($p['id']) . '"' . $sel . '>' . e($label) . '</option>';
            $walk((int)$p['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $html;
}

$action = $_GET['action'] ?? 'home';
$user = current_user($pdo);

$flash = consume_flash();
$error = ($flash && $flash['type'] === 'error') ? $flash['message'] : '';
$success = ($flash && $flash['type'] === 'success') ? $flash['message'] : '';

// ---------------- POST actions ----------------

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
        flash('success', 'Registration successful. Welcome!');
        redirect(intended_url());
    } catch (PDOException $ex) {
        flash('error', 'Registration failed. That username may already be taken.');
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
        redirect(intended_url());
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

    $title = trim($_POST['title'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    $summary = substr(trim($_POST['summary'] ?? ''), 0, 255);
    $parent_raw = $_POST['parent'] ?? '';
    $parent = $parent_raw === '' ? null : (int)$parent_raw;
    $page_id = (int)($_POST['page_id'] ?? 0);
    $page_slug = trim($_POST['page_slug'] ?? '');

    $error_redirect = $page_id
        ? '?action=edit&page=' . urlencode($page_slug)
        : '?action=edit&title=' . urlencode($title);

    if ($title === '') {
        flash('error', 'Title is required.');
        redirect($error_redirect);
    }

    $pdo->beginTransaction();
    try {
        if ($page_id > 0) {
            $existing = get_page_by_id($pdo, $page_id);
            if (!$existing) {
                throw new Exception('Page not found.');
            }
            if ((int)$existing['is_locked'] && !$user['is_admin']) {
                throw new Exception('This page is locked.');
            }

            if ($parent !== null) {
                $parent_page = get_page_by_id($pdo, $parent);
                if (!$parent_page) {
                    throw new Exception('Parent page does not exist.');
                }
                if ($parent === $page_id) {
                    throw new Exception('A page cannot be its own parent.');
                }
                if (is_descendant($pdo, $parent, $page_id)) {
                    throw new Exception('Invalid parent: that would create a loop.');
                }
            }

            $old_slug = $existing['slug'];
            $new_slug = $old_slug;

            if (strcasecmp($title, $existing['title']) !== 0) {
                $dupe = get_page_by_name($pdo, $title);
                if ($dupe && (int)$dupe['id'] !== $page_id) {
                    throw new Exception('A page with that title already exists.');
                }
                $new_slug = unique_slug($pdo, slugify($title), $page_id);
            }

            $existing_parent = $existing['parent_id'] === null ? null : (int)$existing['parent_id'];
            if ($title === $existing['title'] && $content === $existing['content'] && $parent === $existing_parent && $new_slug === $old_slug) {
                $pdo->rollBack();
                flash('success', 'No changes were made.');
                redirect('?action=view&page=' . urlencode($old_slug));
            }

            $pdo->prepare('UPDATE pages SET title=?, slug=?, parent_id=?, content=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
                ->execute([$title, $new_slug, $parent, $content, $page_id]);

            if ($new_slug !== $old_slug) {
                $pdo->prepare('INSERT OR REPLACE INTO redirects (old_slug, page_id) VALUES (?,?)')
                    ->execute([$old_slug, $page_id]);
            }

            $pdo->prepare('INSERT INTO edits (page_id, user_id, title, content, summary) VALUES (?,?,?,?,?)')
                ->execute([$page_id, $user['id'], $title, $content, $summary !== '' ? $summary : 'Edited page']);

            $grant = award_credits($pdo, $user['id'], 1, 'Edit contribution');
            $pdo->commit();

            flash('success', $grant ? "Page saved. +{$grant} credits." : 'Page saved.');
            redirect('?action=view&page=' . urlencode($new_slug));
        } else {
            $existing_by_title = get_page_by_name($pdo, $title);
            if ($existing_by_title) {
                $pdo->rollBack();
                redirect('?action=view&page=' . urlencode($existing_by_title['slug']));
            }

            if ($parent !== null) {
                $parent_page = get_page_by_id($pdo, $parent);
                if (!$parent_page) {
                    throw new Exception('Parent page does not exist.');
                }
            }

            $slug = unique_slug($pdo, slugify($title));
            $pdo->prepare('INSERT INTO pages (title, slug, parent_id, content, author_id) VALUES (?,?,?,?,?)')
                ->execute([$title, $slug, $parent, $content, $user['id']]);
            $new_id = (int)$pdo->lastInsertId();

            $pdo->prepare('INSERT INTO edits (page_id, user_id, title, content, summary) VALUES (?,?,?,?,?)')
                ->execute([$new_id, $user['id'], $title, $content, $summary !== '' ? $summary : 'Created page']);

            $grant = award_credits($pdo, $user['id'], 5, 'New page created');
            $pdo->commit();

            flash('success', $grant ? "Page created. +{$grant} credits." : 'Page created.');
            redirect('?action=view&page=' . urlencode($slug));
        }
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $ex->getMessage());
        redirect($error_redirect);
    }
}

if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_login();

    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $slug = trim($_POST['page_slug'] ?? '');

    $stmt = $pdo->prepare('SELECT e.*, p.slug, p.is_locked FROM edits e JOIN pages p ON e.page_id = p.id WHERE e.id=?');
    $stmt->execute([$edit_id]);
    $rev = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rev) {
        flash('error', 'Revision not found.');
        redirect('?action=view&page=' . urlencode($slug));
    }
    if ((int)$rev['is_locked'] && !$user['is_admin']) {
        flash('error', 'This page is locked.');
        redirect('?action=view&page=' . urlencode($rev['slug']));
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE pages SET title=?, content=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$rev['title'], $rev['content'], $rev['page_id']]);
        $pdo->prepare('INSERT INTO edits (page_id, user_id, title, content, summary) VALUES (?,?,?,?,?)')
            ->execute([$rev['page_id'], $user['id'], $rev['title'], $rev['content'], "Restored revision #{$edit_id}"]);
        $pdo->commit();
        flash('success', 'Revision restored.');
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
    }
    redirect('?action=view&page=' . urlencode($rev['slug']));
}

if ($action === 'create_group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_login();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        flash('error', 'Group name is required.');
        redirect('?');
    }
    try {
        $pdo->prepare('INSERT INTO groups (name, credits) VALUES (?,?)')->execute([$name, 0]);
        $gid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?,?,?)')
            ->execute([$gid, $user['id'], 'owner']);
        flash('success', 'Group created.');
        redirect('?action=group&id=' . $gid);
    } catch (PDOException $ex) {
        flash('error', 'Could not create group. Name may already exist.');
        redirect('?');
    }
}

if ($action === 'join_group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_login();
    $gid = (int)($_POST['group_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id FROM groups WHERE id=?');
    $stmt->execute([$gid]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        flash('error', 'Group not found.');
        redirect('?');
    }
    $pdo->prepare('INSERT OR IGNORE INTO group_members (group_id, user_id, role) VALUES (?,?,?)')
        ->execute([$gid, $user['id'], 'member']);
    flash('success', 'Joined group.');
    redirect('?action=group&id=' . $gid);
}

if ($action === 'distribute_group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_login();
    $gid = (int)($_POST['group_id'] ?? 0);
    $pool = (int)($_POST['pool'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM groups WHERE id=?');
    $stmt->execute([$gid]);
    $group_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group_row) {
        flash('error', 'Group not found.');
        redirect('?');
    }
    if ($pool <= 0) {
        flash('error', 'Distribution amount must be positive.');
        redirect('?action=group&id=' . $gid);
    }
    if ($pool > (int)$group_row['credits']) {
        flash('error', 'Group pool does not contain enough credits.');
        redirect('?action=group&id=' . $gid);
    }

    $stmt = $pdo->prepare('SELECT role FROM group_members WHERE group_id=? AND user_id=?');
    $stmt->execute([$gid, $user['id']]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_owner = $membership && $membership['role'] === 'owner';
    if (!$is_owner && !$user['is_admin']) {
        flash('error', 'Not authorized to distribute this group pool.');
        redirect('?action=group&id=' . $gid);
    }

    $stmt = $pdo->prepare('
        SELECT u.id AS user_id, COUNT(e.id) AS edits
        FROM users u
        JOIN group_members gm ON u.id = gm.user_id
        LEFT JOIN edits e ON e.user_id = u.id
        WHERE gm.group_id = ?
        GROUP BY u.id
        HAVING edits > 0
        ORDER BY edits DESC
    ');
    $stmt->execute([$gid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        flash('error', 'No member contributions found to distribute.');
        redirect('?action=group&id=' . $gid);
    }

    $total_edits = 0;
    foreach ($rows as $r) {
        $total_edits += (int)$r['edits'];
    }

    $alloc = [];
    foreach ($rows as $r) {
        $alloc[(int)$r['user_id']] = (int)floor($pool * ((int)$r['edits'] / max(1, $total_edits)));
    }

    $distributed = array_sum($alloc);
    $remainder = $pool - $distributed;
    if ($remainder > 0) {
        $first_uid = null;
        foreach ($alloc as $uid => $share) {
            $first_uid = $uid;
            break;
        }
        if ($first_uid !== null) {
            $alloc[$first_uid] += $remainder;
        }
    }

    $pdo->beginTransaction();
    try {
        foreach ($alloc as $uid => $share) {
            if ($share > 0) {
                $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([$share, $uid]);
                $pdo->prepare('INSERT INTO rewards (user_id, group_id, amount, reason) VALUES (?,?,?,?)')
                    ->execute([$uid, $gid, $share, 'Group distribution']);
            }
        }
        $pdo->prepare('UPDATE groups SET credits = credits - ? WHERE id = ?')->execute([$pool, $gid]);
        $pdo->commit();
        flash('success', "Distributed {$pool} credits.");
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
    }
    redirect('?action=group&id=' . $gid);
}

if ($action === 'buy_credits' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_login();
    $amount = (int)($_POST['amount'] ?? 0);
    if ($amount <= 0 || $amount > 1000000) {
        flash('error', 'Invalid credit amount.');
        redirect('?');
    }

    // DEMO ONLY: replace with Stripe/PayPal webhook confirmation before granting credits.
    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([$amount, $user['id']]);
    $pdo->prepare('INSERT INTO rewards (user_id, amount, reason) VALUES (?,?,?)')
        ->execute([$user['id'], $amount, 'Purchase (demo)']);
    flash('success', "Purchased {$amount} demo credits.");
    redirect('?');
}

if ($action === 'award' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_login();
    if (!$user['is_admin']) {
        flash('error', 'Only admins can award credits.');
        redirect('?');
    }

    $to = (int)($_POST['user_id'] ?? 0);
    $amount = (int)($_POST['amount'] ?? 0);
    $reason = substr(trim($_POST['reason'] ?? ''), 0, 255);

    if ($amount === 0) {
        flash('error', 'Amount must not be zero.');
        redirect('?');
    }

    $stmt = $pdo->prepare('SELECT id, credits FROM users WHERE id=?');
    $stmt->execute([$to]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        flash('error', 'User not found.');
        redirect('?');
    }
    if ($amount < 0 && (int)$target['credits'] + $amount < 0) {
        flash('error', 'Cannot reduce credits below zero.');
        redirect('?');
    }

    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([$amount, $to]);
    $pdo->prepare('INSERT INTO rewards (user_id, amount, reason) VALUES (?,?,?)')
        ->execute([$to, $amount, $reason !== '' ? $reason : 'Manual award']);
    flash('success', 'Credits awarded.');
    redirect('?');
}

// ---------------- GET data ----------------

$all_pages = $pdo->query('SELECT id, title, slug, parent_id FROM pages ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
$recent_pages = $pdo->query('SELECT p.*, u.display_name AS author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id ORDER BY p.updated_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
$recent_changes = $pdo->query('SELECT e.id, e.summary, e.created_at, e.page_id, p.title, p.slug, u.display_name FROM edits e JOIN pages p ON e.page_id = p.id LEFT JOIN users u ON e.user_id = u.id ORDER BY e.created_at DESC LIMIT 25')->fetchAll(PDO::FETCH_ASSOC);
$leaders = $pdo->query('SELECT id, display_name, username, credits FROM users ORDER BY credits DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
$groups = $pdo->query('SELECT * FROM groups ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$all_users = ($user && $user['is_admin']) ? $pdo->query('SELECT id, display_name, username, credits FROM users ORDER BY display_name')->fetchAll(PDO::FETCH_ASSOC) : [];

$page = null;
$subpages = [];
$backlinks = [];
$page_history = [];

if ($action === 'view' && !empty($_GET['page'])) {
    $slug = $_GET['page'];
    $page = get_page_by_slug($pdo, $slug);

    if (!$page) {
        $stmt = $pdo->prepare('SELECT page_id FROM redirects WHERE old_slug=?');
        $stmt->execute([$slug]);
        $redirect_row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($redirect_row) {
            $target = get_page_by_id($pdo, $redirect_row['page_id']);
            if ($target) {
                redirect('?action=view&page=' . urlencode($target['slug']));
            }
        }
    }

    if ($page) {
        $stmt = $pdo->prepare('SELECT id, title, slug FROM pages WHERE parent_id=? ORDER BY title');
        $stmt->execute([$page['id']]);
        $subpages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $escaped_title = str_replace(['%', '_'], ['\%', '\_'], $page['title']);
        $stmt = $pdo->prepare("SELECT id, title, slug FROM pages WHERE id != ? AND (content LIKE ? ESCAPE '\\' OR content LIKE ? ESCAPE '\\') ORDER BY title LIMIT 50");
        $stmt->execute([$page['id'], "%[[$escaped_title]]%", "%[[$escaped_title|%"]);
        $backlinks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare('SELECT e.*, u.display_name FROM edits e LEFT JOIN users u ON e.user_id = u.id WHERE e.page_id=? ORDER BY e.created_at DESC LIMIT 100');
        $stmt->execute([$page['id']]);
        $page_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$edit_page = null;
$edit_title = '';
if ($action === 'edit') {
    require_login();
    if (!empty($_GET['page'])) {
        $edit_page = get_page_by_slug($pdo, $_GET['page']);
        if (!$edit_page) {
            $edit_title = ucwords(str_replace('-', ' ', $_GET['page']));
        }
    } elseif (!empty($_GET['title'])) {
        $edit_title = trim($_GET['title']);
    }
}

$history_page = null;
$history_rows = [];
if ($action === 'history' && !empty($_GET['page'])) {
    $history_page = get_page_by_slug($pdo, $_GET['page']);
    if ($history_page) {
        $stmt = $pdo->prepare('SELECT e.*, u.display_name FROM edits e LEFT JOIN users u ON e.user_id = u.id WHERE e.page_id=? ORDER BY e.created_at DESC LIMIT 200');
        $stmt->execute([$history_page['id']]);
        $history_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$search_q = trim($_GET['q'] ?? '');
$search_results = [];
if ($action === 'search' && $search_q !== '') {
    $escaped_q = str_replace(['%', '_'], ['\%', '\_'], $search_q);
    $stmt = $pdo->prepare("SELECT p.*, u.display_name AS author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id WHERE p.title LIKE ? ESCAPE '\\' OR p.content LIKE ? ESCAPE '\\' ORDER BY p.updated_at DESC LIMIT 100");
    $stmt->execute(["%{$escaped_q}%", "%{$escaped_q}%"]);
    $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$group = null;
$group_member_stats = [];
$group_role = null;
if ($action === 'group' && !empty($_GET['id'])) {
    $gid = (int)$_GET['id'];
    $stmt = $pdo->prepare('SELECT * FROM groups WHERE id=?');
    $stmt->execute([$gid]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($group) {
        if ($user) {
            $stmt = $pdo->prepare('SELECT role FROM group_members WHERE group_id=? AND user_id=?');
            $stmt->execute([$gid, $user['id']]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $group_role = $r ? $r['role'] : null;
        }

        $stmt = $pdo->prepare('
            SELECT u.id, u.display_name, u.username, u.credits, gm.role, COUNT(e.id) AS edits
            FROM users u
            JOIN group_members gm ON u.id = gm.user_id
            LEFT JOIN edits e ON e.user_id = u.id
            WHERE gm.group_id = ?
            GROUP BY u.id
            ORDER BY edits DESC, u.display_name ASC
        ');
        $stmt->execute([$gid]);
        $group_member_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MiniWiki Demo</title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f6f8fb;color:#111827}
a{color:#1d4ed8;text-decoration:none}
a:hover{text-decoration:underline}
.header{background:#1f2937;color:#fff;padding:12px 20px}
.header a,.header button.linkbutton{color:#fff}
.nav{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.nav .spacer{flex:1}
.container{max-width:1100px;margin:20px auto;padding:16px;background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.layout{display:flex;gap:20px;flex-wrap:wrap}
.main{flex:2;min-width:280px}
.sidebar{flex:1;min-width:240px}
.card{padding:12px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:12px}
.form-row{margin-bottom:10px}
input,select,textarea,button{font:inherit}
input[type=text],input[type=password],input[type=number],select,textarea{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box}
textarea{min-height:180px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
button{padding:8px 12px;border:1px solid #1d4ed8;background:#1d4ed8;color:#fff;border-radius:6px;cursor:pointer}
button.secondary{background:#fff;color:#1d4ed8}
.linkbutton{background:none;border:none;color:#1d4ed8;cursor:pointer;padding:0;font:inherit;text-decoration:underline}
.muted{color:#6b7280;font-size:13px}
.error{color:#991b1b;background:#fee2e2;border:1px solid #fecaca;padding:10px;border-radius:6px;margin-bottom:12px}
.success{color:#166534;background:#dcfce7;border:1px solid #bbf7d0;padding:10px;border-radius:6px;margin-bottom:12px}
.content{border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin-top:10px;line-height:1.45}
.content h2,.content h3,.content h4{margin-top:18px}
.content code{background:#f3f4f6;padding:2px 4px;border-radius:4px}
.newpage{color:#b91c1c}
table{width:100%;border-collapse:collapse}
th,td{padding:8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
.footer{margin-top:20px;color:#6b7280;font-size:13px}
.inline{display:inline}
</style>
</head>
<body>
<div class="header">
  <div class="nav">
    <div><strong>MiniWiki Demo</strong></div>
    <div class="spacer"></div>
    <div>
      <a href="?">Home</a> |
      <a href="?action=edit">New page</a> |
      <a href="?action=search">Search</a>
      <?php if ($user): ?>
        | Hello, <?=e($user['display_name'])?> (<?=e($user['credits'])?> credits)
        <form method="post" action="?action=logout" class="inline"><?= csrf_field() ?><button type="submit" class="linkbutton">Logout</button></form>
      <?php else: ?>
        | <a href="?action=login">Login</a> | <a href="?action=register">Register</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="container">
<?php if ($error): ?><div class="error"><?=e($error)?></div><?php endif; ?>
<?php if ($success): ?><div class="success"><?=e($success)?></div><?php endif; ?>

<?php if ($action === 'home'): ?>
<div class="layout">
  <div class="main">
    <h1>Recently updated pages</h1>
    <?php if ($user): ?>
      <div class="card">
        <h3>Create a new page</h3>
        <form method="post" action="?action=save_page">
          <?= csrf_field() ?>
          <div class="form-row"><input type="text" name="title" placeholder="Page title" required></div>
          <div class="form-row"><select name="parent"><option value="">-- no parent --</option><?= page_options($all_pages) ?></select></div>
          <div class="form-row"><textarea name="content" placeholder="Wiki content..."></textarea></div>
          <div class="form-row"><input type="text" name="summary" placeholder="Edit summary (optional)"></div>
          <div><button type="submit">Create page</button></div>
        </form>
      </div>
    <?php else: ?>
      <p><a href="?action=login">Log in</a> to create pages.</p>
    <?php endif; ?>

    <?php foreach ($recent_pages as $p): ?>
      <div class="card">
        <a href="?action=view&page=<?=e($p['slug'])?>"><strong><?=e($p['title'])?></strong></a>
        <div class="muted">by <?=e($p['author_name'] ?? 'Unknown')?> — updated <?=e($p['updated_at'])?></div>
      </div>
    <?php endforeach; ?>

    <h2>Recent changes</h2>
    <div class="card">
      <?php if (!$recent_changes): ?>
        <div class="muted">No edits yet.</div>
      <?php else: ?>
        <?php foreach ($recent_changes as $rc): ?>
          <div>
            <a href="?action=view&page=<?=e($rc['slug'])?>"><?=e($rc['title'])?></a>
            — <?=e($rc['summary'])?> by <?=e($rc['display_name'] ?? 'Anonymous')} — <?=e($rc['created_at'])?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="sidebar">
    <div class="card">
      <h3>Leaderboard</h3>
      <?php foreach ($leaders as $l): ?>
        <div><?=e($l['display_name'] ?: $l['username'])?> — <?=e($l['credits'])?> credits</div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <h3>Groups</h3>
      <?php if (!$groups): ?><div class="muted">No groups yet.</div><?php endif; ?>
      <?php foreach ($groups as $g): ?>
        <div><a href="?action=group&id=<?=e($g['id'])?>"><?=e($g['name'])?></a> — pool <?=e($g['credits'])?></div>
      <?php endforeach; ?>
      <?php if ($user): ?>
        <h4>Create group</h4>
        <form method="post" action="?action=create_group"><?= csrf_field() ?>
          <div class="form-row"><input type="text" name="name" placeholder="Group name" required></div>
          <button type="submit">Create</button>
        </form>
      <?php else: ?>
        <div class="muted">Log in to create groups.</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>Credits / monetization</h3>
      <?php if ($user): ?>
        <form method="post" action="?action=buy_credits"><?= csrf_field() ?>
          <div class="form-row"><input type="number" name="amount" value="50" min="1" max="1000000" required></div>
          <button type="submit">Buy demo credits</button>
        </form>
        <p class="muted">Demo only. In production, grant credits only after Stripe/PayPal webhook confirmation.</p>
      <?php else: ?>
        <div class="muted">Log in to buy credits.</div>
      <?php endif; ?>
    </div>

    <?php if ($user && $user['is_admin']): ?>
    <div class="card">
      <h3>Admin: award credits</h3>
      <form method="post" action="?action=award"><?= csrf_field() ?>
        <div class="form-row">
          <select name="user_id">
            <?php foreach ($all_users as $u): ?>
              <option value="<?=e($u['id'])?>"><?=e($u['display_name'] ?: $u['username'])?> (<?=e($u['credits'])?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row"><input type="number" name="amount" value="10" required></div>
        <div class="form-row"><input type="text" name="reason" placeholder="Reason"></div>
        <button type="submit">Award</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($action === 'view' && $page): ?>
  <p><a href="?">← Home</a> | <a href="?action=edit&page=<?=e($page['slug'])?>">Edit</a> | <a href="?action=history&page=<?=e($page['slug'])?>">History</a></p>
  <h1><?=e($page['title'])?></h1>
  <div class="muted">Created <?=e($page['created_at'])?> — updated <?=e($page['updated_at'])?> by <?=e($page['author_name'] ?? 'Unknown')?></div>
  <div class="content"><?= render_wikitext($page['content'], $pdo) ?></div>

  <?php if ($subpages): ?>
    <h2>Subpages</h2>
    <div class="card">
      <?php foreach ($subpages as $sp): ?>
        <div><a href="?action=view&page=<?=e($sp['slug'])?>"><?=e($sp['title'])?></a></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($backlinks): ?>
    <h2>Pages linking here</h2>
    <div class="card">
      <?php foreach ($backlinks as $bl): ?>
        <div><a href="?action=view&page=<?=e($bl['slug'])?>"><?=e($bl['title'])?></a></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($user && !(int)$page['is_locked']): ?>
    <h2>Edit this page</h2>
    <form method="post" action="?action=save_page">
      <?= csrf_field() ?>
      <input type="hidden" name="page_id" value="<?=e($page['id'])?>">
      <input type="hidden" name="page_slug" value="<?=e($page['slug'])?>">
      <div class="form-row"><input type="text" name="title" value="<?=e($page['title'])?>" required></div>
      <div class="form-row"><select name="parent"><option value="">-- no parent --</option><?= page_options($all_pages, $page['parent_id'], $page['id']) ?></select></div>
      <div class="form-row"><textarea name="content"><?=e($page['content'])?></textarea></div>
      <div class="form-row"><input type="text" name="summary" placeholder="Edit summary"></div>
      <button type="submit">Save page</button>
    </form>
  <?php elseif ((int)$page['is_locked']): ?>
    <p class="muted">This page is locked. Only admins can edit it.</p>
  <?php else: ?>
    <p><a href="?action=login">Log in</a> to edit this page.</p>
  <?php endif; ?>

<?php elseif ($action === 'view'): ?>
  <h1>Page not found</h1>
  <?php $missing_title = ucwords(str_replace('-', ' ', $_GET['page'] ?? 'Page')); ?>
  <p>The page “<?=e($missing_title)?>” does not exist yet.</p>
  <p><a href="?action=edit&title=<?=e(rawurlencode($missing_title))?>">Create this page</a>.</p>

<?php elseif ($action === 'edit'): ?>
  <h1><?= $edit_page ? 'Edit: ' . e($edit_page['title']) : 'Create page' ?></h1>
  <?php if ($edit_page && (int)$edit_page['is_locked'] && !$user['is_admin']): ?>
    <p>This page is locked. Only admins can edit it.</p>
  <?php else: ?>
    <form method="post" action="?action=save_page">
      <?= csrf_field() ?>
      <?php if ($edit_page): ?>
        <input type="hidden" name="page_id" value="<?=e($edit_page['id'])?>">
        <input type="hidden" name="page_slug" value="<?=e($edit_page['slug'])?>">
      <?php endif; ?>
      <div class="form-row">
        <label>Title<br><input type="text" name="title" value="<?=e($edit_page['title'] ?? $edit_title)?>" required></label>
      </div>
      <div class="form-row">
        <label>Parent page<br>
          <select name="parent">
            <option value="">-- no parent --</option>
            <?= page_options($all_pages, $edit_page['parent_id'] ?? null, $edit_page['id'] ?? null) ?>
          </select>
        </label>
      </div>
      <div class="form-row">
        <label>Content<br><textarea name="content"><?=e($edit_page['content'] ?? '')?></textarea></label>
      </div>
      <div class="form-row">
        <label>Edit summary<br><input type="text" name="summary" placeholder="Optional"></label>
      </div>
      <div>
        <button type="submit">Save page</button>
        <a class="linkbutton" href="<?= $edit_page ? '?action=view&page=' . e($edit_page['slug']) : '?' ?>">Cancel</a>
      </div>
    </form>
    <div class="card">
      <h3>Markup quick reference</h3>
      <ul>
        <li><code>= Heading =</code>, <code>== Subheading ==</code></li>
        <li><code>'''bold'''</code>, <code>''italic''</code>, <code>`code`</code></li>
        <li><code>[[Page Name]]</code> or <code>[[Page Name|link text]]</code></li>
        <li><code>[https://example.com Label]</code></li>
        <li><code>* bullet</code>, <code># numbered</code></li>
      </ul>
    </div>
  <?php endif; ?>

<?php elseif ($action === 'history' && $history_page): ?>
  <p><a href="?action=view&page=<?=e($history_page['slug'])?>">← Back to page</a></p>
  <h1>History: <?=e($history_page['title'])?></h1>
  <table>
    <tr><th>Date</th><th>User</th><th>Summary</th><th>Action</th></tr>
    <?php foreach ($history_rows as $h): ?>
      <tr>
        <td><?=e($h['created_at'])?></td>
        <td><?=e($h['display_name'] ?? 'Anonymous')?></td>
        <td><?=e($h['summary'])?></td>
        <td>
          <?php if ($user && !(int)$history_page['is_locked']): ?>
            <form method="post" action="?action=restore" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="edit_id" value="<?=e($h['id'])?>">
              <input type="hidden" name="page_slug" value="<?=e($history_page['slug'])?>">
              <button type="submit" class="linkbutton">Restore</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

<?php elseif ($action === 'history'): ?>
  <h1>History not found</h1>

<?php elseif ($action === 'search'): ?>
  <h1>Search</h1>
  <form method="get" action="?">
    <input type="hidden" name="action" value="search">
    <div class="form-row"><input type="text" name="q" value="<?=e($search_q)?>" placeholder="Search pages..."></div>
    <button type="submit">Search</button>
  </form>

  <?php if ($search_q !== ''): ?>
    <h2>Results for “<?=e($search_q)?>”</h2>
    <?php if (!$search_results): ?>
      <p>No pages found. You can <a href="?action=edit&title=<?=e(rawurlencode($search_q))?>">create “<?=e($search_q)?>”</a>.</p>
    <?php else: ?>
      <?php foreach ($search_results as $sr): ?>
        <div class="card">
          <a href="?action=view&page=<?=e($sr['slug'])?>"><strong><?=e($sr['title'])?></strong></a>
          <div class="muted">by <?=e($sr['author_name'] ?? 'Unknown')} — updated <?=e($sr['updated_at'])?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

<?php elseif ($action === 'group' && $group): ?>
  <p><a href="?">← Home</a></p>
  <h1>Group: <?=e($group['name'])?></h1>
  <div class="card">Pool: <?=e($group['credits'])?> credits</div>

  <h2>Members</h2>
  <table>
    <tr><th>User</th><th>Role</th><th>Edits</th><th>Credits</th></tr>
    <?php foreach ($group_member_stats as $m): ?>
      <tr>
        <td><?=e($m['display_name'] ?: $m['username'])?></td>
        <td><?=e($m['role'])?></td>
        <td><?=e($m['edits'])?></td>
        <td><?=e($m['credits'])?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <?php if ($user): ?>
    <?php if (!$group_role): ?>
      <form method="post" action="?action=join_group" style="margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="group_id" value="<?=e($group['id'])?>">
        <button type="submit">Join group</button>
      </form>
    <?php endif; ?>

    <?php if ($group_role === 'owner' || $user['is_admin']): ?>
      <h2>Distribute pool</h2>
      <form method="post" action="?action=distribute_group">
        <?= csrf_field() ?>
        <input type="hidden" name="group_id" value="<?=e($group['id'])?>">
        <div class="form-row"><input type="number" name="pool" min="1" placeholder="Amount to distribute" required></div>
        <button type="submit">Distribute by edits</button>
      </form>
      <p class="muted">Simple demo distribution: splits credits by total edits from group members. For a real wiki, tie edits to group-owned namespaces/pages.</p>
    <?php endif; ?>
  <?php else: ?>
    <p><a href="?action=login">Log in</a> to join or manage groups.</p>
  <?php endif; ?>

<?php elseif ($action === 'login'): ?>
  <h1>Login</h1>
  <form method="post" action="?action=login">
    <?= csrf_field() ?>
    <div class="form-row"><input type="text" name="username" placeholder="Username" required></div>
    <div class="form-row"><input type="password" name="password" placeholder="Password" required></div>
    <button type="submit">Login</button>
  </form>
  <p>No account? <a href="?action=register">Register</a>. Default demo admin is <code>admin / admin</code>.</p>

<?php elseif ($action === 'register'): ?>
  <h1>Register</h1>
  <form method="post" action="?action=register">
    <?= csrf_field() ?>
    <div class="form-row"><input type="text" name="username" placeholder="Username" required></div>
    <div class="form-row"><input type="text" name="display_name" placeholder="Display name"></div>
    <div class="form-row"><input type="password" name="password" placeholder="Password" required></div>
    <button type="submit">Register</button>
  </form>
  <p>Already have an account? <a href="?action=login">Login</a>.</p>

<?php else: ?>
  <h1>Not found</h1>
  <p>That action is not supported.</p>
<?php endif; ?>

<div class="footer">
  Demo notes: replace credit purchases with real payment webhooks, add rate limiting, content moderation, file uploads, and backups before public use.
</div>
</div>
</body>
</html>