<?php
session_start();

// Basic MySQL configuration (adjust if needed)
function envOr(string $key, string $default=''): string {
  $v = getenv($key);
  return ($v === false || $v === '') ? $default : $v;
}

$cfg = [
  'db'        => envOr('DB_NAME', 'iwt_suite'),
  'app_user'  => envOr('DB_USER', 'samarth'),
  'app_pass'  => envOr('DB_PASS', 'Sam@123'),
  'root_user' => envOr('DB_ROOT_USER', 'root'),
  'root_pass' => envOr('DB_ROOT_PASS', ''),
  'host'      => envOr('DB_HOST', '127.0.0.1'),
];

function bootstrapDb(array $c): void {
  if ($c['root_user'] === '') {
    // skip bootstrapping when root credentials are not provided (common on hosted MySQL)
    return;
  }
    try {
        $root = new PDO("mysql:host={$c['host']};charset=utf8mb4", $c['root_user'], $c['root_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $root->exec("CREATE DATABASE IF NOT EXISTS `{$c['db']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        foreach ([$c['host'], 'localhost'] as $h) {
            $root->exec("CREATE USER IF NOT EXISTS `{$c['app_user']}`@'{$h}' IDENTIFIED BY '{$c['app_pass']}'");
            $root->exec("GRANT ALL PRIVILEGES ON `{$c['db']}`.* TO `{$c['app_user']}`@'{$h}'");
        }
        $root->exec('FLUSH PRIVILEGES');
    } catch (Throwable $e) {
        // keep silent; connection attempts will expose errors if any
    }
}

function connectApp(array $c): PDO {
    try {
        return new PDO("mysql:host={$c['host']};dbname={$c['db']};charset=utf8mb4", $c['app_user'], $c['app_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        bootstrapDb($c);
        return new PDO("mysql:host={$c['host']};dbname={$c['db']};charset=utf8mb4", $c['app_user'], $c['app_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}

$pdo = connectApp($cfg);
$pdo->exec('CREATE TABLE IF NOT EXISTS users(
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(120) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec('CREATE TABLE IF NOT EXISTS people(
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec('CREATE TABLE IF NOT EXISTS activities(
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  action VARCHAR(255) NOT NULL,
  meta TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(user_id),
  CONSTRAINT fk_user_activity FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec('CREATE TABLE IF NOT EXISTS calc_history(
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  expression VARCHAR(255) NOT NULL,
  result VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(user_id),
  CONSTRAINT fk_user_calc FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$err = '';
$msg = '';
$view = $_GET['view'] ?? 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'signup') {
        $u = trim($_POST['user'] ?? '');
        $e = trim($_POST['email'] ?? '');
        $p = trim($_POST['pass'] ?? '');
        if (!$u || !$e || !$p) {
            $err = 'All fields are required.';
        } elseif (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $err = 'Invalid email address.';
        } elseif (strlen($p) < 8) {
            $err = 'Password must be at least 8 characters.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE username=? OR email=?');
                $stmt->execute([$u, $e]);
                if ($stmt->fetch()) {
                    $err = 'User already exists.';
                } else {
                    $hash = password_hash($p, PASSWORD_BCRYPT);
                    $ins = $pdo->prepare('INSERT INTO users(username,email,password) VALUES(?,?,?)');
                    $ins->execute([$u, $e, $hash]);
                    $_SESSION['uid'] = $pdo->lastInsertId();
                    $_SESSION['uname'] = $u;
                    header('Location: ?view=dashboard');
                    exit;
                }
            } catch (Throwable $e) {
                $err = 'Sign up failed: ' . $e->getMessage();
            }
        }
    } elseif ($act === 'login') {
        $u = trim($_POST['user'] ?? '');
        $p = trim($_POST['pass'] ?? '');
        if (!$u || !$p) {
            $err = 'Enter username and password.';
        } else {
            $stmt = $pdo->prepare('SELECT id,username,password FROM users WHERE username=?');
            $stmt->execute([$u]);
            if ($row = $stmt->fetch()) {
                if (password_verify($p, $row['password'])) {
                    $_SESSION['uid'] = $row['id'];
                    $_SESSION['uname'] = $row['username'];
                    header('Location: ?view=dashboard');
                    exit;
                }
            }
            $err = 'Invalid credentials.';
        }
    } elseif ($act === 'logout') {
        session_unset();
        session_destroy();
        header('Location: ?view=login');
        exit;
    } elseif ($act === 'add_person' && isset($_SESSION['uid'])) {
        $person = trim($_POST['person'] ?? '');
        if ($person) {
            $stmt = $pdo->prepare('INSERT INTO people(name) VALUES(?)');
            $stmt->execute([$person]);
            $msg = 'Added ' . htmlspecialchars($person, ENT_QUOTES) . ' to the database.';
        }
    } elseif ($act === 'log_event' && isset($_SESSION['uid'])) {
      $action = substr(trim($_POST['msg'] ?? ''), 0, 255);
      $meta = substr(trim($_POST['meta'] ?? ''), 0, 2000);
      if ($action) {
        $stmt = $pdo->prepare('INSERT INTO activities(user_id,action,meta) VALUES(?,?,?)');
        $stmt->execute([$_SESSION['uid'], $action, $meta]);
      }
      exit(json_encode(['ok' => true]));
    } elseif ($act === 'log_calc' && isset($_SESSION['uid'])) {
      $expr = substr(trim($_POST['expr'] ?? ''), 0, 255);
      $res  = substr(trim($_POST['res'] ?? ''), 0, 255);
      if ($expr !== '' && $res !== '') {
        $stmt = $pdo->prepare('INSERT INTO calc_history(user_id,expression,result) VALUES(?,?,?)');
        $stmt->execute([$_SESSION['uid'], $expr, $res]);
      }
      exit(json_encode(['ok' => true]));
    }
}

$recent = [];
try {
    $recent = $pdo->query('SELECT id,name,created_at FROM people ORDER BY id DESC LIMIT 8')->fetchAll();
} catch (Throwable $e) {
    $recent = [];
}

$loggedIn = isset($_SESSION['uid']);

$activities = [];
if($loggedIn){
  try {
    $stmtAct = $pdo->prepare('SELECT action,meta,created_at FROM activities WHERE user_id=? ORDER BY id DESC LIMIT 8');
    $stmtAct->execute([$_SESSION['uid']]);
    $activities = $stmtAct->fetchAll();
  } catch (Throwable $e) {
    $activities = [];
  }
}
$calcHistory = [];
if($loggedIn){
  try {
    $stmtCalc = $pdo->prepare('SELECT expression,result,created_at FROM calc_history WHERE user_id=? ORDER BY id DESC LIMIT 10');
    $stmtCalc->execute([$_SESSION['uid']]);
    $calcHistory = $stmtCalc->fetchAll();
  } catch (Throwable $e) {
    $calcHistory = [];
  }
}
if (!$loggedIn && $view === 'dashboard') {
    $view = 'login';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MINI PROJECT IWT</title>
<style>
:root{
  --bg:#040914;--panel:#0b1424;--card:#0f172a;--text:#e2e8f0;--muted:#94a3b8;--accent:#38bdf8;--accent2:#a855f7;--border:#132036;--danger:#f87171;--success:#34d399;
  --shadow:0 25px 60px -30px rgba(0,0,0,.55);
}
[data-theme="light"]{
  --bg:#f6f7fb;--panel:#eef2ff;--card:#ffffff;--text:#0f172a;--muted:#475569;--accent:#2563eb;--accent2:#9333ea;--border:#d9e2ec;--danger:#b91c1c;--success:#15803d;
  --shadow:0 20px 45px -28px rgba(15,23,42,.25);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:radial-gradient(circle at 20% 20%,rgba(56,189,248,.08),transparent 25%),radial-gradient(circle at 80% 0,rgba(168,85,247,.12),transparent 28%),var(--bg);color:var(--text);min-height:100vh;padding:0 0 48px;transition:background .3s,color .3s}
a{color:var(--accent);text-decoration:none}

/* Top navigation */
.topbar{position:sticky;top:0;z-index:30;backdrop-filter:blur(10px);background:rgba(10,15,30,.82);border-bottom:1px solid var(--border);padding:14px 22px;display:flex;align-items:center;justify-content:space-between}
[data-theme="light"] .topbar{background:rgba(255,255,255,.9)}
.brand{font-weight:800;font-size:18px;letter-spacing:.3px;display:flex;align-items:center;gap:10px}
.brand .dot{width:10px;height:10px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2))}
.nav-links{display:flex;gap:10px;flex-wrap:wrap}
.nav-links button, .nav-links a{background:transparent;border:1px solid var(--border);color:var(--text);padding:8px 12px;border-radius:10px;cursor:pointer;font-weight:600}
.nav-links button.active{border-color:var(--accent);color:var(--accent)}
.actions{display:flex;gap:10px;align-items:center}
.pill{padding:8px 12px;border-radius:999px;background:var(--card);border:1px solid var(--border);color:var(--muted);font-size:13px}
button.action{background:linear-gradient(120deg,var(--accent),var(--accent2));color:#0b1220;border:0;padding:10px 14px;border-radius:10px;cursor:pointer;font-weight:700}
button.action:hover{filter:brightness(1.05)}
button.ghost{background:transparent;border:1px solid var(--border);color:var(--text);padding:8px 12px;border-radius:10px;cursor:pointer;font-weight:600}
button.ghost:hover{border-color:var(--accent);color:var(--accent)}

.container{max-width:1200px;margin:28px auto;padding:0 22px;display:flex;gap:18px;flex-wrap:wrap}
.sidebar{flex:0 0 260px;min-width:260px;display:flex;flex-direction:column;gap:12px}
.content{flex:1 1 620px;min-width:320px;display:flex;flex-direction:column;gap:16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;box-shadow:var(--shadow)}
.section-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.section-title h3{font-size:16px;font-weight:700}
.badge{padding:6px 10px;border-radius:10px;background:var(--panel);border:1px solid var(--border);color:var(--muted);font-size:12px}
input,select,textarea{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:var(--card);color:var(--text);font-size:14px}
label{display:block;margin:8px 0 4px;font-weight:600}
.alert{padding:10px 12px;border-radius:10px;margin-bottom:10px;font-weight:600}
.alert.error{background:rgba(248,113,113,.15);border:1px solid rgba(248,113,113,.3);color:var(--danger)}
.alert.success{background:rgba(52,211,153,.15);border:1px solid rgba(52,211,153,.3);color:var(--success)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
.stat{padding:12px;border-radius:12px;background:var(--panel);border:1px solid var(--border);display:flex;flex-direction:column;gap:4px}
.stat span:first-child{color:var(--muted);font-size:12px}
.stat span:last-child{font-size:20px;font-weight:800}
.table{width:100%;border-collapse:collapse;margin-top:10px;font-size:14px}
.table th,.table td{border-bottom:1px solid var(--border);padding:10px;text-align:left}
.table th{color:var(--muted);font-weight:700}
.tab-panel{display:none}
.tab-panel.active{display:block}
.history{max-height:180px;overflow:auto;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:10px;font-size:13px}
.flex{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.calc-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:10px}
.calc-grid button{padding:12px;border-radius:10px;background:var(--panel);border:1px solid var(--border);color:var(--text);font-weight:700;cursor:pointer;box-shadow:var(--shadow)}
.calc-grid button.op{color:var(--accent)}
.calc-grid button.fn{color:var(--accent2)}
.calc-grid button.danger{color:var(--danger)}
.calc-grid button.primary{background:linear-gradient(120deg,var(--accent),var(--accent2));color:#0b1220}
.calc-grid button.wide{grid-column:span 2}
.converter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-top:10px}
.event-pad{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}
.event-btn{padding:14px;border-radius:10px;background:var(--panel);border:1px dashed var(--border);text-align:center;cursor:pointer}
.event-log{max-height:160px;overflow:auto;background:#0b1220;border:1px solid var(--border);border-radius:10px;padding:10px;font-size:13px}
.menu-list{display:grid;grid-template-columns:1fr;gap:8px}
.menu-list .ghost{justify-content:flex-start;width:100%}
.chip-list{display:flex;flex-wrap:wrap;gap:10px;min-height:40px;padding:6px 0}
.chip{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:var(--panel);border:1px solid var(--border);color:var(--accent)}
.chip button{border:0;background:transparent;color:var(--danger);cursor:pointer;font-weight:800}
.chip button:hover{color:var(--accent2)}
@media(max-width:960px){.sidebar{flex:1 1 100%}.topbar{flex-wrap:wrap;gap:10px}.nav-links{width:100%}}
</style>
</head>
<body data-theme="dark">
<header class="topbar">
  <div class="brand"><span class="dot"></span>MINI PROJECT IWT</div>
  <div class="nav-links" id="navTabs">
    <?php if($loggedIn): ?>
      <button data-tab="dashboard" class="active">Dashboard</button>
      <button data-tab="calc">Calculator</button>
      <button data-tab="form">Validator</button>
      <button data-tab="db">DB Insert</button>
      <button data-tab="class">CSE Table</button>
      <button data-tab="info">Info</button>
      <button data-tab="events">Events</button>
        <button data-tab="hobby">Hobby Board</button>
        <button data-tab="weekday">Weekday Status</button>
    <?php else: ?>
      <a href="?view=login">Login</a>
      <a href="?view=signup">Sign up</a>
    <?php endif; ?>
  </div>
  <div class="actions">
    <?php if($loggedIn): ?>
      <span class="pill">Signed in as <?php echo htmlspecialchars($_SESSION['uname']); ?></span>
      <button class="ghost" type="button" id="themeToggle">Toggle theme</button>
      <form method="post" style="margin:0"><input type="hidden" name="act" value="logout"><button class="action" type="submit">Logout</button></form>
    <?php else: ?>
      <button class="ghost" type="button" id="themeToggle">Toggle theme</button>
      <span class="pill">Welcome</span>
    <?php endif; ?>
  </div>
</header>

<div class="container">
  <?php if(!$loggedIn && $view==='login'): ?>
    <div class="content" style="width:100%">
      <div class="card">
        <div class="section-title"><h3>Login</h3><span class="badge">Secure access</span></div>
        <?php if($err): ?><div class="alert error"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="act" value="login">
          <label>Username</label><input name="user" required>
          <label>Password</label><input name="pass" type="password" required>
          <button class="action" type="submit" style="margin-top:12px">Login</button>
        </form>
        <p style="margin-top:10px;color:var(--muted)">Need an account? <a href="?view=signup">Sign up</a></p>
      </div>
    </div>
  <?php elseif(!$loggedIn && $view==='signup'): ?>
    <div class="content" style="width:100%">
      <div class="card">
        <div class="section-title"><h3>Create account</h3><span class="badge">New user</span></div>
        <?php if($err): ?><div class="alert error"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="act" value="signup">
          <label>Username</label><input name="user" required>
          <label>Email</label><input name="email" type="email" required>
          <label>Password</label><input name="pass" type="password" placeholder="At least 8 characters" required>
          <button class="action" type="submit" style="margin-top:12px">Sign up</button>
        </form>
        <p style="margin-top:10px;color:var(--muted)">Already have an account? <a href="?view=login">Login</a></p>
      </div>
    </div>
  <?php else: ?>
    <aside class="sidebar">
      <div class="card">
        <div class="section-title"><h3>Quick stats</h3><span class="badge">Overview</span></div>
        <?php if($msg): ?><div class="alert success"><?php echo $msg; ?></div><?php endif; ?>
        <div class="grid">
          <div class="stat"><span>People entries</span><span><?php echo count($recent); ?></span></div>
          <div class="stat"><span>Database</span><span><?php echo htmlspecialchars($cfg['db']); ?></span></div>
          <div class="stat"><span>User</span><span><?php echo htmlspecialchars($_SESSION['uname']); ?></span></div>
        </div>
      </div>
      <div class="card">
        <div class="section-title"><h3>Quick menu</h3><span class="badge">Shortcuts</span></div>
        <div class="menu-list">
          <button type="button" class="ghost" onclick="openTab('dashboard')">Dashboard</button>
          <button type="button" class="ghost" onclick="openTab('calc')">Calculator</button>
          <button type="button" class="ghost" onclick="openTab('form')">Validator</button>
          <button type="button" class="ghost" onclick="openTab('db')">DB Insert</button>
          <button type="button" class="ghost" onclick="openTab('class')">CSE Table</button>
          <button type="button" class="ghost" onclick="openTab('info')">Info</button>
          <button type="button" class="ghost" onclick="openTab('hobby')">Hobby Board</button>
          <button type="button" class="ghost" onclick="openTab('weekday')">Weekday Status</button>
          <button type="button" class="ghost" onclick="openTab('events')">Events</button>
        </div>
      </div>
      <div class="card">
        <div class="section-title"><h3>Recent activity</h3><span class="badge">Per user</span></div>
        <div class="history" style="max-height:200px">
          <?php if($activities): ?>
            <?php foreach($activities as $a): ?>
              <div style="margin-bottom:6px">
                <div style="font-weight:600;color:var(--text)"><?php echo htmlspecialchars($a['action']); ?></div>
                <?php if($a['meta']): ?><div style="color:var(--muted);font-size:12px"><?php echo htmlspecialchars($a['meta']); ?></div><?php endif; ?>
                <div style="color:var(--muted);font-size:11px"><?php echo $a['created_at']; ?></div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div style="color:var(--muted)">No activity yet.</div>
          <?php endif; ?>
        </div>
      </div>
    </aside>

    <section class="content">
      <div class="card tab-panel active" id="panel-dashboard">
        <div class="section-title"><h3>IWT @ RGPV — Command Center</h3><span class="badge">Dashboard</span></div>
        <div class="grid" style="margin-bottom:12px">
          <div class="stat"><span>Discipline</span><span>Internet & Web Technology</span></div>
          <div class="stat"><span>Board</span><span>RGPV</span></div>
          <div class="stat"><span>Mode</span><span>Full-stack Lab</span></div>
          <div class="stat"><span>Status</span><span>Logged in</span></div>
        </div>
        <div class="card" style="margin-bottom:12px">
          <div class="section-title"><h3>What is IWT?</h3><span class="badge">Core topics</span></div>
          <p style="color:var(--muted);line-height:1.6">IWT (Internet & Web Technology) in the RGPV curriculum blends front-end, back-end, and deployment basics. You’ll build responsive UIs, connect to databases, and ship secure web apps.</p>
          <div class="grid" style="margin-top:10px">
            <div class="stat"><span>Front-end</span><span>HTML, CSS, JS, events</span></div>
            <div class="stat"><span>Back-end</span><span>PHP, MySQL, sessions</span></div>
            <div class="stat"><span>Deployment</span><span>Server setup, security</span></div>
          </div>
        </div>
        <div class="card" style="margin-bottom:12px">
          <div class="section-title"><h3>RGPV syllabus snapshot</h3><span class="badge">Exam friendly</span></div>
          <ul style="color:var(--muted);line-height:1.6;margin-left:18px">
            <li>Unit 1: Web fundamentals — HTTP, HTML5 semantics, CSS layout, responsive design.</li>
            <li>Unit 2: Client-side JS — DOM, events, validation, fetch/AJAX.</li>
            <li>Unit 3: Server-side — PHP basics, sessions, cookies, form handling.</li>
            <li>Unit 4: Databases — MySQL CRUD, joins, security (SQLi, hashing).</li>
            <li>Unit 5: Deployment & security — hosting stack, HTTPS, hardening.</li>
          </ul>
        </div>
        <div class="card">
          <div class="section-title"><h3>Quick actions</h3><span class="badge">Explore tools</span></div>
          <div class="flex">
            <button class="action" type="button" onclick="openTab('calc')">Open Calculator</button>
            <button class="ghost" type="button" onclick="openTab('form')">Validate a form</button>
            <button class="ghost" type="button" onclick="openTab('db')">Insert DB record</button>
            <button class="ghost" type="button" onclick="openTab('events')">Try Event Lab</button>
          </div>
        </div>
      </div>

      <div class="card tab-panel" id="panel-calc">
        <div class="section-title"><h3>Advanced Calculator</h3><span class="badge">Expression + converters</span></div>
        <input id="calcExpr" placeholder="e.g. (5+3)*2 + sin(30)" aria-label="expression">
        <div class="flex" style="margin-top:10px">
          <button class="action" type="button" onclick="evalExpr()">Compute</button>
          <button class="ghost" type="button" onclick="clearExpr()">Clear</button>
          <button class="ghost" type="button" id="angleMode" onclick="toggleAngle()">Mode: Degrees</button>
          <span id="calcOut" class="pill">Result: 0</span>
        </div>
        <div class="calc-grid">
          <button class="fn" type="button" onclick="push('sin(')">sin</button>
          <button class="fn" type="button" onclick="push('cos(')">cos</button>
          <button class="fn" type="button" onclick="push('tan(')">tan</button>
          <button class="fn" type="button" onclick="push('π')">π</button>
          <button class="op" type="button" onclick="backspace()">⌫</button>

          <button class="fn" type="button" onclick="push('ln(')">ln</button>
          <button class="fn" type="button" onclick="push('log(')">log</button>
          <button class="fn" type="button" onclick="push('sqrt(')">√</button>
          <button type="button" onclick="push('(')">(</button>
          <button type="button" onclick="push(')')">)</button>

          <button type="button" onclick="push('7')">7</button>
          <button type="button" onclick="push('8')">8</button>
          <button type="button" onclick="push('9')">9</button>
          <button class="op" type="button" onclick="push('/')">÷</button>
          <button class="op" type="button" onclick="push('^')">^</button>

          <button type="button" onclick="push('4')">4</button>
          <button type="button" onclick="push('5')">5</button>
          <button type="button" onclick="push('6')">6</button>
          <button class="op" type="button" onclick="push('*')">×</button>
          <button class="fn" type="button" onclick="pushAns()">Ans</button>

          <button type="button" onclick="push('1')">1</button>
          <button type="button" onclick="push('2')">2</button>
          <button type="button" onclick="push('3')">3</button>
          <button class="op" type="button" onclick="push('-')">−</button>
          <button class="danger" type="button" onclick="clearExpr()">AC</button>

          <button type="button" onclick="push('0')">0</button>
          <button type="button" onclick="push('.')">.</button>
          <button class="op" type="button" onclick="push('+')">+</button>
          <button class="primary wide" type="button" onclick="evalExpr()">=</button>
        </div>
        <div class="converter-grid">
          <div>
            <div class="section-title"><h3>Currency (USD → INR)</h3><span class="badge">Static</span></div>
            <input id="usd" type="number" placeholder="USD amount">
            <button class="action" type="button" style="margin-top:8px;width:100%" onclick="convertCurrency()">Convert</button>
            <div id="curOut" class="pill" style="margin-top:6px">Result: 0 INR</div>
          </div>
          <div>
            <div class="section-title"><h3>Temperature</h3><span class="badge">°C ⇄ °F</span></div>
            <input id="tempVal" type="number" placeholder="Enter value">
            <select id="tempMode"><option value="c2f">Celsius to Fahrenheit</option><option value="f2c">Fahrenheit to Celsius</option></select>
            <button class="action" type="button" style="margin-top:8px;width:100%" onclick="convertTemp()">Convert</button>
            <div id="tempOut" class="pill" style="margin-top:6px">Result: --</div>
          </div>
          <div>
            <div class="section-title"><h3>Weight</h3><span class="badge">kg ⇄ lb</span></div>
            <input id="weightVal" type="number" placeholder="Enter weight">
            <div class="flex" style="margin-top:6px">
              <select id="weightFrom" style="flex:1"><option value="kg">Kilogram</option><option value="g">Gram</option><option value="lb">Pound</option><option value="oz">Ounce</option></select>
              <select id="weightTo" style="flex:1"><option value="lb">Pound</option><option value="kg">Kilogram</option><option value="g">Gram</option><option value="oz">Ounce</option></select>
            </div>
            <button class="action" type="button" style="margin-top:8px;width:100%" onclick="convertWeight()">Convert</button>
            <div id="weightOut" class="pill" style="margin-top:6px">Result: --</div>
          </div>
          <div>
            <div class="section-title"><h3>Length</h3><span class="badge">m ⇄ ft</span></div>
            <input id="lengthVal" type="number" placeholder="Enter length">
            <div class="flex" style="margin-top:6px">
              <select id="lengthFrom" style="flex:1"><option value="m">Meter</option><option value="km">Kilometer</option><option value="cm">Centimeter</option><option value="mm">Millimeter</option><option value="ft">Foot</option><option value="in">Inch</option></select>
              <select id="lengthTo" style="flex:1"><option value="ft">Foot</option><option value="m">Meter</option><option value="km">Kilometer</option><option value="cm">Centimeter</option><option value="mm">Millimeter</option><option value="in">Inch</option></select>
            </div>
            <button class="action" type="button" style="margin-top:8px;width:100%" onclick="convertLength()">Convert</button>
            <div id="lengthOut" class="pill" style="margin-top:6px">Result: --</div>
          </div>
        </div>
        <div class="section-title" style="margin-top:14px"><h3>History</h3><button class="pill" type="button" onclick="clearHist()">Clear</button></div>
        <div id="hist" class="history"></div>
        <div class="section-title" style="margin-top:14px"><h3>Saved history</h3><span class="badge">Per user</span></div>
        <div class="history">
          <?php if($calcHistory): ?>
            <?php foreach($calcHistory as $c): ?>
              <div style="margin-bottom:6px">
                <div style="font-weight:600;color:var(--text)"><?php echo htmlspecialchars($c['expression']); ?></div>
                <div style="color:var(--muted);font-size:12px">= <?php echo htmlspecialchars($c['result']); ?></div>
                <div style="color:var(--muted);font-size:11px"><?php echo $c['created_at']; ?></div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div style="color:var(--muted)">No saved history yet.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card tab-panel" id="panel-form">
        <div class="section-title"><h3>Registration Validator</h3><span class="badge">Client-side</span></div>
        <form id="regForm">
          <label>Name *</label><input id="f_nm" maxlength="15">
          <label>Address *</label><textarea id="f_ad" maxlength="50"></textarea>
          <label>Email *</label><input id="f_em" type="email">
          <label>Confirm Email *</label><input id="f_ce" type="email">
          <label>Phone (10 digits) *</label><input id="f_ph">
          <label>Website</label><input id="f_wb">
          <label>Occupation</label><input id="f_oc">
          <label>Password *</label><input id="f_pw" type="password">
          <label>Repeat Password *</label><input id="f_rp" type="password">
          <button class="action" type="button" style="margin-top:10px" onclick="validateReg()">Validate</button>
        </form>
        <div id="regMsg" class="pill" style="margin-top:8px">Waiting for input</div>
      </div>

      <div class="card tab-panel" id="panel-db">
        <div class="section-title"><h3>Database quick add</h3><span class="badge">people table</span></div>
        <form method="post" class="flex">
          <input type="hidden" name="act" value="add_person">
          <input name="person" placeholder="Name" required style="flex:1">
          <button class="action" type="submit">Insert</button>
        </form>
        <?php if($recent): ?>
          <table class="table">
            <thead><tr><th>#</th><th>Name</th><th>Created</th></tr></thead>
            <tbody><?php foreach($recent as $r): ?><tr><td><?php echo $r['id']; ?></td><td><?php echo htmlspecialchars($r['name']); ?></td><td><?php echo $r['created_at']; ?></td></tr><?php endforeach; ?></tbody>
          </table>
        <?php else: ?>
          <p style="margin-top:10px;color:var(--muted)">No entries yet.</p>
        <?php endif; ?>
      </div>

      <div class="card tab-panel" id="panel-class">
        <div class="section-title"><h3>CSE V'C Table</h3><span class="badge">Static view</span></div>
        <div class="history" style="max-height:340px">
          <table class="table">
            <thead><tr><th>S.No</th><th>Name</th><th>Roll</th><th>Phone</th><th>Email</th><th>DS</th><th>Algo</th><th>DBMS</th><th>OS</th><th>CN</th></tr></thead>
            <tbody>
              <tr><td>1</td><td>Samarth Kapdi</td><td>0863CS*****1</td><td>9843556387</td><td>Samarth@domain.com</td><td>85</td><td>92</td><td>88</td><td>78</td><td>90</td></tr>
              <tr><td>2</td><td>Rohit Rajure</td><td>0863CS*****7</td><td>654464643</td><td>Rohit@domain.com</td><td>91</td><td>89</td><td>94</td><td>85</td><td>88</td></tr>
              <tr><td>3</td><td>Sanket Kapoor</td><td>0863CS*****5</td><td>316593232</td><td>Sanket@domain.com</td><td>76</td><td>80</td><td>82</td><td>79</td><td>81</td></tr>
              <tr><td>4</td><td>Ujjwal Singh</td><td>0863CS*****0</td><td>4567890123</td><td>ujjwal.singh@domain.com</td><td>95</td><td>98</td><td>96</td><td>92</td><td>97</td></tr>
              <tr><td>5</td><td>Suryansh pal</td><td>0863CS*****8</td><td>5678901234</td><td>suryansh.pal@domain.com</td><td>88</td><td>85</td><td>90</td><td>86</td><td>89</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card tab-panel" id="panel-info">
        <div class="section-title"><h3>CSE Program Info</h3><span class="badge">Overview</span></div>
        <p style="color:var(--muted);line-height:1.6">Internet and Web Technology (IWT) covers HTML, CSS, JavaScript, client/server scripting, web servers, protocols, and full-stack basics. Goals include mastering structure, styling, interactivity, deployment, and security across the stack.</p>
        <ul style="margin-top:10px;line-height:1.6;color:var(--muted)">
          <li>HTML, CSS, JavaScript fundamentals</li>
          <li>Client-server architecture and databases</li>
          <li>Responsive design and security foundations</li>
          <li>Front-end and back-end toolchains</li>
        </ul>
      </div>

      <div class="card tab-panel" id="panel-hobby">
        <div class="section-title"><h3>Hobby Board</h3><span class="badge">Saved locally</span></div>
        <p style="color:var(--muted);margin-bottom:10px">Add your hobbies, prevent duplicates, and persist them in your browser.</p>
        <div class="flex" style="margin-bottom:10px">
          <input id="hobbyInput" type="text" placeholder="e.g., Reading, Coding" maxlength="60" style="flex:1">
          <button class="action" type="button" id="hobbyAddBtn">Add</button>
          <button class="ghost" type="button" id="hobbyClearBtn">Clear all</button>
        </div>
        <div id="hobbyHint" class="pill">Hobbies are stored in this browser only.</div>
        <div id="hobbyList" class="chip-list" style="margin-top:10px"></div>
        <div id="hobbyCount" class="pill" style="margin-top:10px">No hobbies yet</div>
      </div>

      <div class="card tab-panel" id="panel-weekday">
        <div class="section-title"><h3>Weekday Status</h3><span class="badge">0-6 helper</span></div>
        <p style="color:var(--muted);margin-bottom:8px">Enter a day number (0-6) to get a friendly status.</p>
        <div class="flex" style="margin-bottom:10px">
          <input id="dayInput" type="number" min="0" max="6" placeholder="0-6" style="flex:1">
          <button class="action" type="button" onclick="showDayMessage()">Show message</button>
        </div>
        <div id="dayResult" class="pill">Waiting for input</div>
      </div>

      <div class="card tab-panel" id="panel-events">
        <div class="section-title"><h3>Event Lab</h3><span class="badge">Try interactions</span></div>
        <div class="event-pad">
          <div class="event-btn" onclick="logEvent('click')">Click me</div>
          <div class="event-btn" ondblclick="logEvent('double-click')">Double click</div>
          <div class="event-btn" oncontextmenu="event.preventDefault();logEvent('right-click');">Right click</div>
          <div class="event-btn" onmouseenter="logEvent('hover in')" onmouseleave="logEvent('hover out')">Hover</div>
          <input class="event-btn" placeholder="Type here" oninput="logEvent('input: '+this.value)" />
          <div class="event-btn" onwheel="logEvent('mouse wheel')">Scroll over me</div>
        </div>
        <div class="section-title" style="margin-top:12px"><h3>Live log</h3><button class="pill" type="button" onclick="clearLog()">Clear</button></div>
        <div id="elog" class="event-log"></div>
      </div>

    </section>
  <?php endif; ?>
</div>

<script>
// Theme toggle
const bodyEl=document.body;
const storedTheme=localStorage.getItem('iwt-theme');
if(storedTheme){bodyEl.dataset.theme=storedTheme;}
const themeBtn=document.getElementById('themeToggle');
function setTheme(t){bodyEl.dataset.theme=t;localStorage.setItem('iwt-theme',t);}
function toggleTheme(){setTheme(bodyEl.dataset.theme==='light'?'dark':'light');}
if(themeBtn){themeBtn.addEventListener('click',toggleTheme);} 
const isLogged=<?php echo $loggedIn?'true':'false'; ?>;

async function sendActivity(action,meta=''){
  if(!isLogged) return;
  try{
    const form=new FormData();
    form.append('act','log_event');
    form.append('msg',action);
    form.append('meta',meta);
    await fetch('',{method:'POST',body:form});
  }catch(e){/* ignore */}
}
async function sendCalcLog(expr,res){
  if(!isLogged) return;
  try{
    const form=new FormData();
    form.append('act','log_calc');
    form.append('expr',expr);
    form.append('res',res);
    await fetch('',{method:'POST',body:form});
  }catch(e){/* ignore */}
}

// Nav tab switching
const navTabs=document.querySelectorAll('#navTabs button');
const panels=document.querySelectorAll('.tab-panel');
function openTab(tab){
  navTabs.forEach(b=>{
    if(b.dataset.tab===tab) b.classList.add('active'); else b.classList.remove('active');
  });
  panels.forEach(p=>p.classList.remove('active'));
  const el=document.getElementById('panel-'+tab);
  if(el) el.classList.add('active');
  window.scrollTo({top:0,behavior:'smooth'});
  sendActivity('Open tab',tab);
}
navTabs.forEach(btn=>btn.addEventListener('click',()=>openTab(btn.dataset.tab)));

// Calculator
let hist=[];
let lastResult=0;
let useDegrees=true;
function push(v){
  const el=document.getElementById('calcExpr');
  el.value+=v;
}
function pushAns(){
  push(String(lastResult));
}
function backspace(){
  const el=document.getElementById('calcExpr');
  el.value=el.value.slice(0,-1);
}
function clearExpr(){
  const el=document.getElementById('calcExpr');
  el.value='';
  updateCalcOut('Result: 0');
}
function toggleAngle(){
  useDegrees=!useDegrees;
  const btn=document.getElementById('angleMode');
  if(btn){btn.textContent=useDegrees?'Mode: Degrees':'Mode: Radians';}
}
function toRad(v){return useDegrees? (v*Math.PI/180):v;}
function normalizeExpr(raw){
  let expr=raw;
  expr=expr.replace(/π/g,'Math.PI');
  expr=expr.replace(/\^/g,'**');
  expr=expr.replace(/\bln\(/g,'Math.log(');
  expr=expr.replace(/\blog\(/g,'Math.log10(');
  expr=expr.replace(/\bsqrt\(/g,'Math.sqrt(');
  expr=expr.replace(/\bpow\(/g,'Math.pow(');
  expr=expr.replace(/\babs\(/g,'Math.abs(');
  ['sin','cos','tan'].forEach(fn=>{
    const repl=useDegrees?`Math.${fn}(toRad(`:`Math.${fn}(`;
    expr=expr.replace(new RegExp(`\\b${fn}\\(`,'g'),repl);
  });
  return expr;
}
function updateCalcOut(text){
  const out=document.getElementById('calcOut');
  if(out){out.textContent=text;}
}
function evalExpr(){
  const el=document.getElementById('calcExpr');
  let raw=el.value.trim();
  if(!raw){updateCalcOut('Result: 0');return;}
  const prepared=normalizeExpr(raw);
  try{
    const res=Function('toRad','return '+prepared)(toRad);
    lastResult=res;
    updateCalcOut('Result: '+res);
    hist.unshift(raw+' = '+res);
    if(hist.length>50)hist.length=50;
    renderHist();
    sendActivity('Calculator',raw+' = '+res);
    sendCalcLog(raw,String(res));
  }catch(err){updateCalcOut('Error');}
}
function renderHist(){
  const box=document.getElementById('hist');
  if(!hist.length){box.textContent='No history yet';return;}
  box.innerHTML=hist.map(h=>'<div>'+h+'</div>').join('');
}
function clearHist(){hist=[];renderHist();}
renderHist();

// Converters
function convertCurrency(){
  const usd=parseFloat(document.getElementById('usd').value||'0');
  const rate=83.0;
  document.getElementById('curOut').textContent='Result: '+(usd*rate).toFixed(2)+' INR';
  sendActivity('Currency convert','USD '+usd+' -> INR');
}
function convertTemp(){
  const v=parseFloat(document.getElementById('tempVal').value||'0');
  const mode=document.getElementById('tempMode').value;
  let out=0;
  if(mode==='c2f') out=v*9/5+32; else out=(v-32)*5/9;
  document.getElementById('tempOut').textContent='Result: '+out.toFixed(2);
  sendActivity('Temp convert',mode+' value '+v);
}
function convertWeight(){
  const v=parseFloat(document.getElementById('weightVal').value||'0');
  const from=document.getElementById('weightFrom').value;
  const to=document.getElementById('weightTo').value;
  const map={kg:1,g:0.001,lb:0.453592,oz:0.0283495};
  const kg=v*map[from];
  const res=kg/map[to];
  document.getElementById('weightOut').textContent='Result: '+res.toFixed(4)+' '+to;
  sendActivity('Weight convert',v+' '+from+' -> '+to);
}
function convertLength(){
  const v=parseFloat(document.getElementById('lengthVal').value||'0');
  const from=document.getElementById('lengthFrom').value;
  const to=document.getElementById('lengthTo').value;
  const map={m:1,km:1000,cm:0.01,mm:0.001,ft:0.3048,in:0.0254};
  const meters=v*map[from];
  const res=meters/map[to];
  document.getElementById('lengthOut').textContent='Result: '+res.toFixed(4)+' '+to;
  sendActivity('Length convert',v+' '+from+' -> '+to);
}

// Hobby board
const HOBBY_KEY='iwt_hobbies_v1';
function loadHobbies(){
  try{const raw=localStorage.getItem(HOBBY_KEY);return raw?JSON.parse(raw):[];}catch{return[];}
}
function saveHobbies(items){localStorage.setItem(HOBBY_KEY,JSON.stringify(items));renderHobbies(items);}
function renderHobbies(items=loadHobbies()){
  const listEl=document.getElementById('hobbyList');
  const countEl=document.getElementById('hobbyCount');
  if(!listEl||!countEl){return;}
  listEl.innerHTML='';
  if(!items.length){countEl.textContent='No hobbies yet';return;}
  items.forEach((hobby,idx)=>{
    const chip=document.createElement('div');
    chip.className='chip';
    chip.textContent=hobby;
    const rm=document.createElement('button');
    rm.type='button';
    rm.textContent='×';
    rm.addEventListener('click',()=>{items.splice(idx,1);saveHobbies(items);sendActivity('Hobby removed',hobby);});
    chip.appendChild(rm);
    listEl.appendChild(chip);
  });
  countEl.textContent=items.length+' hobby(ies)';
}
function addHobby(){
  const input=document.getElementById('hobbyInput');
  if(!input)return;
  const raw=(input.value||'').trim();
  if(!raw) return;
  const items=loadHobbies();
  if(items.some(h=>h.toLowerCase()===raw.toLowerCase())){input.value='';input.focus();return;}
  items.push(raw);
  saveHobbies(items);
  input.value='';
  input.focus();
  sendActivity('Hobby added',raw);
}
function clearHobbies(){
  if(!confirm('Clear all hobbies?')) return;
  localStorage.removeItem(HOBBY_KEY);
  renderHobbies([]);
  sendActivity('Hobby cleared','all removed');
}
function bindHobbyUI(){
  const addBtn=document.getElementById('hobbyAddBtn');
  const clearBtn=document.getElementById('hobbyClearBtn');
  const input=document.getElementById('hobbyInput');
  if(addBtn) addBtn.addEventListener('click',addHobby);
  if(clearBtn) clearBtn.addEventListener('click',clearHobbies);
  if(input) input.addEventListener('keydown',e=>{if(e.key==='Enter') addHobby();});
  renderHobbies();
}

// Weekday helper
function showDayMessage(){
  const input=document.getElementById('dayInput');
  const out=document.getElementById('dayResult');
  if(!input||!out)return;
  const val=parseInt(input.value,10);
  if(Number.isNaN(val)||val<0||val>6){out.textContent='Please enter a valid day number (0-6).';out.style.color='var(--danger)';return;}
  let message='I am looking forward to this Weekend';
  if(val===5) message='Finally Friday';
  else if(val===6) message='Super Saturday';
  else if(val===0) message='Sleepy Sunday';
  out.textContent='Day '+val+': '+message;
  out.style.color='var(--text)';
  sendActivity('Weekday status','Day '+val+' -> '+message);
}

// Registration validator
function validateReg(){
  const g=id=>document.getElementById(id).value.trim();
  let ok=true; let msgs=[];
  const nm=g('f_nm'), ad=g('f_ad'), em=g('f_em'), ce=g('f_ce'), ph=g('f_ph'), wb=g('f_wb'), pw=g('f_pw'), rp=g('f_rp');
  if(!nm || nm.length>15){ok=false;msgs.push('Name required (<=15 chars)');}
  if(!ad || ad.length>50){ok=false;msgs.push('Address required (<=50 chars)');}
  if(!/^\S+@\S+\.\S+$/.test(em)){ok=false;msgs.push('Invalid email');}
  if(em!==ce){ok=false;msgs.push('Emails do not match');}
  if(!/^\d{10}$/.test(ph)){ok=false;msgs.push('Phone must be 10 digits');}
  if(wb){try{new URL(wb);}catch{ok=false;msgs.push('Invalid website');}}
  if(!/(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}/.test(pw)){ok=false;msgs.push('Weak password');}
  if(pw!==rp){ok=false;msgs.push('Passwords do not match');}
  const out=document.getElementById('regMsg');
  out.textContent=ok?'Form is valid!':msgs.join(' | ');
  out.style.color=ok?'var(--success)':'var(--danger)';
}

// Event log
let elog=[];
function logEvent(msg){
  const ts=new Date().toLocaleTimeString();
  elog.unshift(ts+': '+msg);
  if(elog.length>40)elog.length=40;
  renderLog();
}
function clearLog(){elog=[];renderLog();}
function renderLog(){
  const box=document.getElementById('elog');
  if(!elog.length){box.textContent='Interact with the buttons to see events.';return;}
  box.innerHTML=elog.map(e=>'<div>'+e+'</div>').join('');
}
renderLog();
bindHobbyUI();
</script>
</body>
</html>
