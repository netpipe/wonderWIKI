<?php
// miniwiki_singlefile.php
// Single-file MediaWiki-like demo using PHP + SQLite
// Features: pages + subpages, users, groups, credits, leaderboards, group rewards, simple monetization hooks
// NOT production-ready. Security & rate-limiting omitted for brevity. Use as demo/starting point.

session_start();
$dbfile = __DIR__ . '/miniwiki.sqlite';
$dsn = 'sqlite:' . $dbfile;
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Init DB if missing
if (!file_exists($dbfile)) {
    init_db($pdo);
}

// Helpers
function init_db($pdo) {
    $schema = <<<SQL
PRAGMA foreign_keys = ON;
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    password_hash TEXT,
    display_name TEXT,
    credits INTEGER DEFAULT 0,
    is_admin INTEGER DEFAULT 0
);
CREATE TABLE groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE,
    credits INTEGER DEFAULT 0
);
CREATE TABLE group_members (
    group_id INTEGER,
    user_id INTEGER,
    role TEXT DEFAULT 'member',
    PRIMARY KEY (group_id,user_id),
    FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT,
    slug TEXT UNIQUE,
    parent_id INTEGER NULL,
    content TEXT,
    author_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    credits_awarded INTEGER DEFAULT 0,
    FOREIGN KEY(parent_id) REFERENCES pages(id) ON DELETE SET NULL,
    FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE TABLE edits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER,
    user_id INTEGER,
    summary TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE TABLE rewards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    group_id INTEGER,
    amount INTEGER,
    reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE
);
SQL;
    $pdo->exec($schema);
    // create default admin
    $pw = password_hash('admin', PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users (username,password_hash,display_name,is_admin,credits) VALUES (?,?,?,?,?)')
        ->execute(['admin',$pw,'Administrator',1,1000]);
}

// Authentication
function current_user($pdo) {
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return null;
}

function require_login() {
    if (empty($_SESSION['user_id'])) {
        header('Location: ?action=login');
        exit;
    }
}

// Routing - simple
$action = $_GET['action'] ?? 'home';
$user = current_user($pdo);

// Actions
if ($action === 'register' && $_SERVER['REQUEST_METHOD']==='POST') {
    $u = trim($_POST['username']);
    $d = trim($_POST['display_name']);
    $p = $_POST['password'];
    if ($u && $p) {
        $hash = password_hash($p, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username,password_hash,display_name,credits) VALUES (?,?,?,?)');
        try {
            $stmt->execute([$u,$hash,$d?:$u,10]); // new users start with 10 credits
            $_SESSION['user_id'] = $pdo->lastInsertId();
            header('Location: ?'); exit;
        } catch (Exception $e) {
            $error = 'Registration failed. Maybe username taken.';
        }
    } else $error = 'Provide username and password.';
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD']==='POST') {
    $u = $_POST['username'];
    $p = $_POST['password'];
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username=?');
    $stmt->execute([$u]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($p,$row['password_hash'])) {
        $_SESSION['user_id'] = $row['id'];
        header('Location: ?'); exit;
    } else $error = 'Invalid login.';
}

if ($action === 'logout') { session_destroy(); header('Location: ?'); exit; }

// Create/edit page
if ($action === 'save_page' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_login();
    $title = trim($_POST['title']);
    $content = $_POST['content'];
    $parent = empty($_POST['parent']) ? null : (int)$_POST['parent'];
    $slug = slugify($title);
    $author = $user['id'];
    // insert or update
    if (!empty($_POST['page_id'])) {
        $pid = (int)$_POST['page_id'];
        $stmt = $pdo->prepare('UPDATE pages SET title=?,slug=?,parent_id=?,content=?,author_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$title,$slug,$parent,$content,$author,$pid]);
        $pdo->prepare('INSERT INTO edits (page_id,user_id,summary) VALUES (?,?,?)')->execute([$pid,$author,substr($_POST['summary']??'',0,255)]);
        // award credits for edits
        award_credits($pdo,$author,1,'Edit contribution');
        header('Location: ?action=view&page='.$slug); exit;
    } else {
        $stmt = $pdo->prepare('INSERT INTO pages (title,slug,parent_id,content,author_id,credits_awarded) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$title,$slug,$parent,$content,$author,0]);
        $pid = $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO edits (page_id,user_id,summary) VALUES (?,?,?)')->execute([$pid,$author,substr($_POST['summary']??'',0,255)]);
        award_credits($pdo,$author,5,'New page created');
        header('Location: ?action=view&page='.$slug); exit;
    }
}

// Award credits endpoint (admin or group reward)
if ($action === 'award' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_login();
    if (!$user['is_admin']) { $error='Only admins can award directly.'; }
    else {
        $to = (int)$_POST['user_id']; $amount = (int)$_POST['amount']; $reason = $_POST['reason'];
        $pdo->prepare('UPDATE users SET credits=credits+? WHERE id=?')->execute([$amount,$to]);
        $pdo->prepare('INSERT INTO rewards (user_id,amount,reason) VALUES (?,?,?)')->execute([$to,$amount,$reason]);
        $success = 'Awarded.';
    }
}

// Join group
if ($action === 'join_group' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_login();
    $gid = (int)$_POST['group_id'];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO group_members (group_id,user_id) VALUES (?,?)');
    $stmt->execute([$gid,$user['id']]);
    header('Location: ?action=group&id='.$gid); exit;
}

// Create group
if ($action === 'create_group' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_login();
    $name = trim($_POST['name']);
    if ($name) {
        $pdo->prepare('INSERT INTO groups (name,credits) VALUES (?,?)')->execute([$name,0]);
        $gid = $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO group_members (group_id,user_id,role) VALUES (?,?,?)')->execute([$gid,$user['id'],'owner']);
        header('Location: ?action=group&id='.$gid); exit;
    }
}

// Reward pool distribution: split group credits to top contributors
if ($action === 'distribute_group' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_login();
    $gid = (int)$_POST['group_id'];
    // only group owner or admin
    $stmt = $pdo->prepare('SELECT role FROM group_members WHERE group_id=? AND user_id=?');
    $stmt->execute([$gid,$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_owner = $row && $row['role']==='owner';
    if (!$is_owner && !$user['is_admin']) { $error='Not authorized.'; }
    else {
        // compute contributions (number of edits to pages belonging to this group's pages) - simplified: edits by group members
        $members = $pdo->prepare('SELECT user_id FROM group_members WHERE group_id=?'); $members->execute([$gid]);
        $ids = array_column($members->fetchAll(PDO::FETCH_ASSOC),'user_id');
        if (count($ids)===0) { $error='No members.'; }
        else {
            $in = implode(',', array_fill(0,count($ids),'?'));
            $q = "SELECT user_id, COUNT(*) as edits FROM edits WHERE user_id IN ($in) GROUP BY user_id ORDER BY edits DESC";
            $stmt = $pdo->prepare($q);
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $pool = (int)$_POST['pool'];
            if ($pool<=0) $error='Pool must be positive.';
            else {
                // distribute proportionally by edits (simple)
                $total_edits = array_sum(array_column($rows,'edits')) ?: 1;
                foreach ($rows as $r) {
                    $u = $r['user_id']; $share = intval(round($pool * ($r['edits']/$total_edits)));
                    if ($share>0) {
                        $pdo->prepare('UPDATE users SET credits=credits+? WHERE id=?')->execute([$share,$u]);
                        $pdo->prepare('INSERT INTO rewards (user_id,group_id,amount,reason) VALUES (?,?,?,?)')->execute([$u,$gid,$share,'Group distribution']);
                    }
                }
                // subtract from group's credits
                $pdo->prepare('UPDATE groups SET credits=credits-? WHERE id=?')->execute([$pool,$gid]);
                $success = 'Distributed.';
            }
        }
    }
}

// Purchase credits (stub) - front-end calls to a real payment gateway should be integrated in production
if ($action === 'buy_credits' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_login();
    $amount = (int)$_POST['amount'];
    if ($amount<=0) $error='Invalid amount.';
    else {
        // Simulate immediate credit purchase. Replace this with Stripe/PayPal webhooks in production.
        $pdo->prepare('UPDATE users SET credits=credits+? WHERE id=?')->execute([$amount,$user['id']]);
        $pdo->prepare('INSERT INTO rewards (user_id,amount,reason) VALUES (?,?,?)')->execute([$user['id'],$amount,'Purchase (demo)']);
        $success = 'Purchased credits (demo).';
    }
}

// View page
if ($action === 'view' && !empty($_GET['page'])) {
    $slug = $_GET['page'];
    $stmt = $pdo->prepare('SELECT p.*,u.display_name FROM pages p LEFT JOIN users u ON p.author_id=u.id WHERE slug=?');
    $stmt->execute([$slug]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
}

// List pages
$pages = $pdo->query('SELECT p.*,u.display_name FROM pages p LEFT JOIN users u ON p.author_id=u.id ORDER BY created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);

// leaderboards
$leaders = $pdo->query('SELECT id,display_name,credits FROM users ORDER BY credits DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);

// groups list
$groups = $pdo->query('SELECT * FROM groups ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Small helpers
function slugify($s){
    $s = preg_replace('/[^a-z0-9]+/i','-',strtolower($s));
    return trim($s,'-');
}
function award_credits($pdo,$user_id,$amount,$reason){
    $pdo->prepare('UPDATE users SET credits=credits+? WHERE id=?')->execute([$amount,$user_id]);
    $pdo->prepare('INSERT INTO rewards (user_id,amount,reason) VALUES (?,?,?)')->execute([$user_id,$amount,$reason]);
}

// Simple XSS helper
function e($s){ return htmlspecialchars($s ?? '',ENT_QUOTES,'UTF-8'); }

// Render page below
?><!doctype html>
<html><head><meta charset="utf-8"><title>MiniWiki Demo</title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Arial;margin:0;padding:0;background:#f6f8fb}
.header{background:#1f2937;color:white;padding:12px 20px}
.container{max-width:1000px;margin:20px auto;padding:12px;background:white;border-radius:8px}
.nav{display:flex;gap:8px;align-items:center}
.page-list{margin-top:12px}
.card{padding:10px;border:1px solid #eee;border-radius:6px;margin-bottom:8px}
.footer{margin-top:20px;color:#666;font-size:13px}
.form-row{margin-bottom:8px}
</style>
</head><body>
<div class="header">
  <div class="nav">
    <div style="flex:1"><strong>MiniWiki Demo</strong> — single-file PHP + SQLite</div>
    <div>
    <?php if($user): ?>
      Hello, <?=e($user['display_name'])?> (<?=e($user['credits'])?> credits) | <a href="?action=logout" style="color:#fff">Logout</a>
    <?php else: ?>
      <a href="?action=login" style="color:#fff">Login</a> | <a href="?action=register" style="color:#fff">Register</a>
    <?php endif; ?>
    </div>
  </div>
</div>
<div class="container">
<?php if(!empty($error)): ?><div style="color:#900">Error: <?=e($error)?></div><?php endif; ?>
<?php if(!empty($success)): ?><div style="color:green"><?=e($success)?></div><?php endif; ?>

<?php if($action==='home'): ?>
  <h2>Pages</h2>
  <div style="display:flex;gap:20px">
    <div style="flex:2">
      <div class="page-list">
      <?php foreach($pages as $p): ?>
        <div class="card">
          <a href="?action=view&page=<?=e($p['slug'])?>"><strong><?=e($p['title'])?></strong></a>
          <div style="font-size:12px;color:#666">by <?=e($p['display_name'])?> — <?=e($p['created_at'])?> — credits awarded: <?=e($p['credits_awarded'])?></div>
          <?php if($p['parent_id']): ?><div style="font-size:12px">Subpage</div><?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
      <h3>Create New Page</h3>
      <?php if($user): ?>
      <form method="post" action="?action=save_page">
        <div class="form-row"><input name="title" placeholder="Title" style="width:100%" required></div>
        <div class="form-row"><select name="parent"><option value="">-- no parent --</option><?php foreach($pages as $pp) echo '<option value="'.e($pp['id']).'">'.e($pp['title'])."</option>"; ?></select></div>
        <div class="form-row"><textarea name="content" style="width:100%;height:140px"></textarea></div>
        <div class="form-row"><input name="summary" placeholder="Edit summary"></div>
        <div class="form-row"><button>Create page</button></div>
      </form>
      <?php else: ?>
        <div>Please log in to create pages.</div>
      <?php endif; ?>
    </div>

    <div style="flex:1">
      <div class="card">
        <h4>Leaderboards</h4>
        <?php foreach($leaders as $l): ?>
          <div><?=e($l['display_name'])?> — <?=e($l['credits'])?> credits</div>
        <?php endforeach; ?>
      </div>
      <div class="card">
        <h4>Groups</h4>
        <?php foreach($groups as $g): ?>
          <div><a href="?action=group&id=<?=e($g['id'])?>"><?=e($g['name'])?></a> — pool <?=e($g['credits'])?> credits</div>
        <?php endforeach; ?>
        <h5>Create Group</h5>
        <?php if($user): ?>
        <form method="post" action="?action=create_group"><input name="name"><button>Create</button></form>
        <?php else: ?><div>Log in to create groups.</div><?php endif; ?>
      </div>

      <div class="card">
        <h4>Monetize / Credits</h4>
        <p>Purchase credits (demo):</p>
        <?php if($user): ?>
        <form method="post" action="?action=buy_credits"><input name="amount" value="50" required><button>Buy credits (demo)</button></form>
        <p>Real apps should integrate Stripe/PayPal and use webhooks to award credits only after payment confirmation.</p>
        <?php else: ?>
          <div>Log in to buy credits.</div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h4>Admin</h4>
        <?php if($user && $user['is_admin']): ?>
          <form method="post" action="?action=award"><div><select name="user_id"><?php foreach($leaders as $l) echo '<option value="'.e($l['id']).'">'.e($l['display_name'])."</option>"; ?></select></div>
          <div><input name="amount" value="10"></div>
          <div><input name="reason" placeholder="Reason"></div>
          <div><button>Award credits</button></div>
          </form>
        <?php else: ?>
          <div>Admin-only panel.</div>
        <?php endif; ?>
      </div>

    </div>
  </div>

<?php elseif($action==='view' && !empty($page)): ?>
  <a href="?">&larr; Back</a>
  <h2><?=e($page['title'])?></h2>
  <div style="font-size:13px;color:#666">by <?=e($page['display_name'])?> — <?=e($page['created_at'])?></div>
  <div style="padding:12px;border:1px solid #eee;margin-top:10px;white-space:pre-wrap"><?=e($page['content'])?></div>
  <?php if($user): ?>
    <h4>Edit page</h4>
    <form method="post" action="?action=save_page">
      <input type="hidden" name="page_id" value="<?=e($page['id'])?>">
      <div class="form-row"><input name="title" value="<?=e($page['title'])?>"></div>
      <div class="form-row"><textarea name="content" style="width:100%;height:180px"><?=e($page['content'])?></textarea></div>
      <div class="form-row"><input name="summary" placeholder="Edit summary"></div>
      <div class="form-row"><button>Save</button></div>
    </form>
  <?php endif; ?>

<?php elseif($action==='group' && !empty($_GET['id'])):
    $gid=(int)$_GET['id'];
    $g = $pdo->prepare('SELECT * FROM groups WHERE id=?'); $g->execute([$gid]); $g = $g->fetch(PDO::FETCH_ASSOC);
    $members = $pdo->prepare('SELECT u.* FROM users u JOIN group_members gm ON u.id=gm.user_id WHERE gm.group_id=?'); $members->execute([$gid]); $members = $members->fetchAll(PDO::FETCH_ASSOC);
?>
  <a href="?">&larr; Back</a>
  <h2>Group: <?=e($g['name'])?></h2>
  <div>Pool: <?=e($g['credits'])?> credits</div>
  <h4>Members</h4>
  <?php foreach($members as $m) echo '<div>'.e($m['display_name']).' — '.e($m['credits']).' credits</div>'; ?>
  <?php if($user): ?>
    <form method="post" action="?action=join_group"><input type="hidden" name="group_id" value="<?=e($gid)"><button>Join group</button></form>
  <?php endif; ?>
  <h4>Distribute pool</h4>
  <?php if($user): ?>
    <form method="post" action="?action=distribute_group"><input type="hidden" name="group_id" value="<?=e($gid)?>"><input name="pool" placeholder="Amount to distribute"><button>Distribute</button></form>
  <?php endif; ?>

<?php elseif($action==='login'): ?>
  <h3>Login</h3>
  <form method="post" action="?action=login"><div class="form-row"><input name="username" placeholder="username"></div>
  <div class="form-row"><input type="password" name="password" placeholder="password"></div>
  <div class="form-row"><button>Login</button></div></form>

<?php elseif($action==='register'): ?>
  <h3>Register</h3>
  <form method="post" action="?action=register"><div class="form-row"><input name="username" placeholder="username" required></div>
  <div class="form-row"><input name="display_name" placeholder="Display name"></div>
  <div class="form-row"><input type="password" name="password" placeholder="password" required></div>
  <div class="form-row"><button>Register</button></div></form>

<?php else: ?>
  <div>Not found or action not supported.</div>
<?php endif; ?>

<div class="footer">Notes: Demo only. Replace "buy credits" with real payment gateway and secure endpoints. Consider adding rate-limiting, CSRF tokens, prepared statements (already used), input sanitization, and content moderation for public deployments.</div>
</div>
<!-- Added bounty/task workflow, admin task removal, and main page HTML editor -->
<?php
// --- Bounty/Task Tables (auto-create if missing) ---
$pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT,
    description TEXT,
    reward INTEGER,
    creator_id INTEGER,
    assigned_to INTEGER,
    completed INTEGER DEFAULT 0,
    approved INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(creator_id) REFERENCES users(id),
    FOREIGN KEY(assigned_to) REFERENCES users(id)
);");

// MAIN PAGE STORAGE
$pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
    key TEXT PRIMARY KEY,
    value TEXT
);");

// Fetch main page HTML
tahn:$main_page = $pdo->query("SELECT value FROM site_settings WHERE key='main_page_html'")->fetchColumn();
if(!$main_page) $main_page = '<h1>Welcome to MiniWiki</h1><p>Edit this from Admin Panel.</p>';

// Save main page html
if($action==='save_main_page' && $_SERVER['REQUEST_METHOD']==='POST'){
    require_login();
    if(!$user['is_admin']) die('No admin.');
    $html = $_POST['html'] ?? '';
    $stmt = $pdo->prepare("INSERT INTO site_settings (key,value) VALUES ('main_page_html',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
    $stmt->execute([$html]);
    $success='Main page updated.';
}

// Create task
if($action==='create_task' && $_SERVER['REQUEST_METHOD']==='POST'){
    require_login();
    $title = $_POST['title'];
    $desc = $_POST['description'];
    $reward = (int)$_POST['reward'];
    $stmt=$pdo->prepare('INSERT INTO tasks (title,description,reward,creator_id) VALUES (?,?,?,?)');
    $stmt->execute([$title,$desc,$reward,$user['id']]);
    $success='Task created';
}

// Admin remove task
if($action==='delete_task' && isset($_GET['id'])){
    require_login();
    if(!$user['is_admin']) die('Not admin');
    $tid=(int)$_GET['id'];
    $pdo->prepare('DELETE FROM tasks WHERE id=?')->execute([$tid]);
    $success='Task removed.';
}

// Assign task
if($action==='assign_task' && isset($_GET['id'])){
    require_login();
    $tid=(int)$_GET['id'];
    $pdo->prepare('UPDATE tasks SET assigned_to=? WHERE id=?')->execute([$user['id'],$tid]);
    $success='Task assigned to you.';
}

// Complete task
if($action==='complete_task' && isset($_GET['id'])){
    require_login();
    $tid=(int)$_GET['id'];
    $pdo->prepare('UPDATE tasks SET completed=1 WHERE id=? AND assigned_to=?')->execute([$tid,$user['id']]);
    $success='Task marked completed.';
}

// Approve task → pay reward
if($action==='approve_task' && isset($_GET['id'])){
    require_login();
    if(!$user['is_admin']) die('Not admin');
    $tid=(int)$_GET['id'];
    $t=$pdo->query("SELECT * FROM tasks WHERE id=$tid")->fetch();
    if($t && !$t['approved'] && $t['completed']){
        $pdo->prepare('UPDATE users SET credits=credits+? WHERE id=?')->execute([$t['reward'],$t['assigned_to']]);
        $pdo->prepare('UPDATE tasks SET approved=1 WHERE id=?')->execute([$tid]);
        $success='Task approved & rewarded!';
    }
}
?>
<!-- Added user messaging system and navigation sidebar -->
<?php
// --- USER MESSAGES TABLE ---
$pdo->exec("CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id INTEGER,
    recipient_id INTEGER,
    content TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(sender_id) REFERENCES users(id),
    FOREIGN KEY(recipient_id) REFERENCES users(id)
);");

// Send message
if($action==='send_message' && $_SERVER['REQUEST_METHOD']==='POST'){
    require_login();
    $to = (int)$_POST['recipient'];
    $content = $_POST['content'] ?? '';
    $stmt=$pdo->prepare('INSERT INTO messages (sender_id,recipient_id,content) VALUES (?,?,?)');
    $stmt->execute([$user['id'],$to,$content]);
    $success='Message sent';
}

// Delete message
if($action==='delete_message' && isset($_GET['id'])){
    require_login();
    $mid=(int)$_GET['id'];
    $pdo->prepare('DELETE FROM messages WHERE id=? AND recipient_id=?')->execute([$mid,$user['id']]);
    $success='Message removed';
}

// Fetch inbox
tahn:$inbox=[];
if($user){
    $stmt=$pdo->prepare('SELECT m.*, u.username AS sender FROM messages m LEFT JOIN users u ON m.sender_id=u.id WHERE m.recipient_id=? ORDER BY m.id DESC');
    $stmt->execute([$user['id']]);
    $inbox=$stmt->fetchAll();
}
?>

<!-- Navigation Sidebar -->
<style>
#sidebar{position:fixed;left:0;top:0;width:200px;height:100%;background:#eee;padding:10px;overflow-y:auto;}
#content{margin-left:220px;padding:20px;}
</style>
<div id="sidebar">
    <h3>Navigation</h3>
    <a href="?">Main Page</a><br>
    <a href="?action=list_pages">All Pages</a><br>
    <a href="?action=list_tasks">Tasks</a><br>
    <a href="?action=inbox">Messages</a><br>
    <?php if($user && $user['is_admin']): ?>
        <a href="?action=edit_main_page">Edit Main Page</a><br>
    <?php endif; ?>
</div>
</body></html>
