<?php
// Final optimized index.php — PART 1 (Backend)
// Keep this as the very first lines of your file.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

/* -------------------------
   InfinityFree DB Config
---------------------------- */
$cfg = [
    'host' => 'sql307.infinityfree.com',
    'db'   => 'if0_40654733_samarth',
    'user' => 'if0_40654733',
    'pass' => 'ihFYBDgdxzI', // <-- confirmed password
    'port' => 3306,
];

/* -------------------------
   PDO Connection (safe)
---------------------------- */
try {
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",
        $cfg['user'],
        $cfg['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    // Friendly error message — helpful while debugging
    http_response_code(500);
    echo "<h2>Database Connection Failed</h2><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

/* -------------------------
   Create required tables if missing (safe)
   -> Using IF NOT EXISTS so running multiple times is fine.
---------------------------- */
try {
    $pdo->exec("\n      CREATE TABLE IF NOT EXISTS users (\n        id INT AUTO_INCREMENT PRIMARY KEY,\n        username VARCHAR(100) NOT NULL UNIQUE,\n        email VARCHAR(120) NOT NULL UNIQUE,\n        password VARCHAR(255) NOT NULL,\n        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");

    $pdo->exec("\n      CREATE TABLE IF NOT EXISTS people (\n        id INT AUTO_INCREMENT PRIMARY KEY,\n        name VARCHAR(120) NOT NULL,\n        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");

    $pdo->exec("\n      CREATE TABLE IF NOT EXISTS activities (\n        id INT AUTO_INCREMENT PRIMARY KEY,\n        user_id INT NOT NULL,\n        action VARCHAR(255) NOT NULL,\n        meta TEXT,\n        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n        INDEX(user_id)\n      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");

    $pdo->exec("\n      CREATE TABLE IF NOT EXISTS calc_history (\n        id INT AUTO_INCREMENT PRIMARY KEY,\n        user_id INT NOT NULL,\n        expression VARCHAR(255) NOT NULL,\n        result VARCHAR(255) NOT NULL,\n        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n        INDEX(user_id)\n      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");
} catch (Throwable $e) {
    // Non-fatal: show in logs but continue (tables might be created manually)
    error_log('Table creation warning: ' . $e->getMessage());
}

/* -------------------------
   Helper: safe JSON response
---------------------------- */
function json_ok($data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

function json_err($msg = 'error') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

/* -------------------------
   Application actions (signup/login/logout/insert/logs)
---------------------------- */
$err = '';
$msg = '';
$view = $_GET['view'] ?? 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    // SIGNUP
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
                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
                $stmt->execute([$u, $e]);
                if ($stmt->fetch()) {
                    $err = 'User already exists.';
                } else {
                    $hash = password_hash($p, PASSWORD_BCRYPT);
                    $ins = $pdo->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)');
                    $ins->execute([$u, $e, $hash]);
                    $_SESSION['uid'] = (int)$pdo->lastInsertId();
                    $_SESSION['uname'] = $u;
                    header('Location: ?view=dashboard');
                    exit;
                }
            } catch (Throwable $ex) {
                $err = 'Sign up failed: ' . $ex->getMessage();
            }
        }
    }

    // LOGIN
    elseif ($act === 'login') {
        $u = trim($_POST['user'] ?? '');
        $p = trim($_POST['pass'] ?? '');
        if (!$u || !$p) {
            $err = 'Enter username and password.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$u]);
                $row = $stmt->fetch();
                if ($row && password_verify($p, $row['password'])) {
                    $_SESSION['uid'] = (int)$row['id'];
                    $_SESSION['uname'] = $row['username'];
                    header('Location: ?view=dashboard');
                    exit;
                } else {
                    $err = 'Invalid credentials.';
                }
            } catch (Throwable $ex) {
                $err = 'Login error: ' . $ex->getMessage();
            }
        }
    }

    // LOGOUT
    elseif ($act === 'logout') {
        session_unset();
        session_destroy();
        header('Location: ?view=login');
        exit;
    }

    // ADD PERSON (DB Insert) — only for logged in users
    elseif ($act === 'add_person' && isset($_SESSION['uid'])) {
        $person = trim($_POST['person'] ?? '');
        if ($person === '') {
            $err = 'Name required.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO people (name) VALUES (?)');
                $stmt->execute([$person]);
                $msg = 'Added ' . htmlspecialchars($person, ENT_QUOTES) . ' to the database.';
            } catch (Throwable $ex) {
                $err = 'Insert failed: ' . $ex->getMessage();
            }
        }
    }

    // LOG EVENT (AJAX)
    elseif ($act === 'log_event' && isset($_SESSION['uid'])) {
        $action = substr(trim($_POST['msg'] ?? ''), 0, 255);
        $meta = substr(trim($_POST['meta'] ?? ''), 0, 2000);
        if ($action === '') json_ok(); // nothing to save
        try {
            $stmt = $pdo->prepare('INSERT INTO activities (user_id, action, meta) VALUES (?, ?, ?)');
            $stmt->execute([$_SESSION['uid'], $action, $meta]);
        } catch (Throwable $ex) {
            // ignore
        }
        json_ok();
    }

    // LOG CALC (AJAX)
    elseif ($act === 'log_calc' && isset($_SESSION['uid'])) {
        $expr = substr(trim($_POST['expr'] ?? ''), 0, 255);
        $res  = substr(trim($_POST['res'] ?? ''), 0, 255);
        if ($expr === '' || $res === '') json_ok();
        try {
            $stmt = $pdo->prepare('INSERT INTO calc_history (user_id, expression, result) VALUES (?, ?, ?)');
            $stmt->execute([$_SESSION['uid'], $expr, $res]);
        } catch (Throwable $ex) {
            // ignore
        }
        json_ok();
    }

    // Unknown POST action
    else {
        // Fall through — handlers above set $err or returned JSON
    }
}

/* -------------------------
   Prepare data used by UI
---------------------------- */
$recent = [];
$counts = ['people' => 0, 'activities' => 0, 'calc' => 0];
try {
    $recent = $pdo->query('SELECT id, name, created_at FROM people ORDER BY id DESC LIMIT 8')->fetchAll();
    $counts['people'] = (int)$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn();
    $counts['activities'] = (int)$pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();
    $counts['calc'] = (int)$pdo->query('SELECT COUNT(*) FROM calc_history')->fetchColumn();
} catch (Throwable $e) {
    $recent = [];
}

$loggedIn = isset($_SESSION['uid']) && $_SESSION['uid'];

$activities = [];
if ($loggedIn) {
    try {
        $stmtAct = $pdo->prepare('SELECT action, meta, created_at FROM activities WHERE user_id = ? ORDER BY id DESC LIMIT 8');
        $stmtAct->execute([$_SESSION['uid']]);
        $activities = $stmtAct->fetchAll();
    } catch (Throwable $e) {
        $activities = [];
    }
}

$calcHistory = [];
if ($loggedIn) {
    try {
        $stmtCalc = $pdo->prepare('SELECT expression, result, created_at FROM calc_history WHERE user_id = ? ORDER BY id DESC LIMIT 10');
        $stmtCalc->execute([$_SESSION['uid']]);
        $calcHistory = $stmtCalc->fetchAll();
    } catch (Throwable $e) {
        $calcHistory = [];
    }
}

/* If not logged and trying to access dashboard, show login */
if (!$loggedIn && $view === 'dashboard') {
    $view = 'login';
}

/* End of PART 1 (Backend) — next: PART 2 (HTML UI) */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Smart Student Toolkit</title>
<style>
:root{
  --bg:#040914;--panel:#0b1424;--card:#0f172a;--text:#e2e8f0;--muted:#94a3b8;--accent:#38bdf8;--accent2:#a855f7;--border:#132036;--danger:#f87171;--success:#34d399;
  --shadow:0 20px 45px -28px rgba(0,0,0,.45);
}
[data-theme="light"]{
  --bg:#f6f7fb;--panel:#eef2ff;--card:#ffffff;--text:#0f172a;--muted:#475569;--accent:#2563eb;--accent2:#9333ea;--border:#d9e2ec;--danger:#b91c1c;--success:#15803d;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;background:
  radial-gradient(circle at 20% 20%,rgba(56,189,248,.06),transparent 25%),
  radial-gradient(circle at 80% 0,rgba(168,85,247,.09),transparent 28%),
  var(--bg);color:var(--text);min-height:100vh;padding:0 0 48px}
a{color:var(--accent);text-decoration:none}
.topbar{position:sticky;top:0;z-index:30;backdrop-filter:blur(8px);background:rgba(10,15,30,.85);border-bottom:1px solid var(--border);padding:12px 20px;display:flex;align-items:center;justify-content:space-between}
body[data-theme="light"] .topbar{background:rgba(255,255,255,.85);}
.brand{font-weight:800;font-size:20px;display:flex;align-items:center;gap:10px}
.brand .dot{width:10px;height:10px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2))}
.nav-links{display:flex;gap:8px;align-items:center;overflow:auto}
.nav-links a, .nav-links button{background:transparent;border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:10px;cursor:pointer;font-weight:600;font-size:13px}
.nav-links button.active{border-color:var(--accent);color:var(--accent)}
.actions{display:flex;gap:10px;align-items:center}
.pill{padding:7px 12px;border-radius:999px;background:var(--card);border:1px solid var(--border);color:var(--muted);font-size:13px}
button.action{background:linear-gradient(120deg,var(--accent),var(--accent2));color:#081225;border:0;padding:9px 12px;border-radius:10px;cursor:pointer;font-weight:700}
.container{max-width:1100px;margin:26px auto;padding:0 18px;display:flex;gap:18px;flex-wrap:wrap}
.sidebar{flex:0 0 260px;min-width:240px}
.content{flex:1 1 640px;min-width:320px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px;box-shadow:var(--shadow)}
.section-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.section-title h3{font-size:15px;font-weight:700}
.badge{padding:6px 10px;border-radius:10px;background:var(--panel);border:1px solid var(--border);color:var(--muted);font-size:12px}
input,select,textarea{width:100%;padding:9px 10px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text);font-size:14px}
.table{width:100%;border-collapse:collapse;margin-top:8px;font-size:14px}
.table th,.table td{border-bottom:1px solid var(--border);padding:8px;text-align:left}
.history{max-height:180px;overflow:auto;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:10px;font-size:13px}
.tab-panel{display:none}
.tab-panel.active{display:block}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}
.stat{padding:10px;border-radius:10px;background:var(--panel);border:1px solid var(--border)}
.calc-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:10px}
.calc-grid button{padding:10px;border-radius:8px;background:var(--panel);border:1px solid var(--border);cursor:pointer;font-weight:700}
.chip{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:var(--panel);border:1px solid var(--border);color:var(--accent)}
.icon-link{display:inline-flex;align-items:center;gap:6px;color:var(--accent);font-weight:600;text-decoration:none}
.icon-link svg{width:18px;height:18px;fill:currentColor}
.mini-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px}
.task-item{display:flex;align-items:center;justify-content:space-between;padding:8px;border:1px solid var(--border);border-radius:10px;background:var(--panel);margin-bottom:8px;gap:10px}
.task-title{font-weight:600}
.task-item.done .task-title{text-decoration:line-through;color:var(--muted)}
.task-item.done{opacity:.85}
@media (max-width:960px){.sidebar{flex:1 1 100%}.topbar{flex-wrap:wrap}}
</style>
</head>
<body data-theme="dark">
<header class="topbar">
    <div class="brand"><span class="dot"></span>Smart Student Toolkit</div>
  <div class="nav-links" id="navTabs">
    <?php if($loggedIn): ?>
      <button data-tab="dashboard" class="active">Dashboard</button>
      <button data-tab="calc">Calculator</button>
      <button data-tab="form">Validator</button>
      <button data-tab="db">DB Insert</button>
      <button data-tab="class">CSE Table</button>
      <button data-tab="info">Info</button>
      <button data-tab="events">Events</button>
      <button data-tab="hobby">Hobby</button>
      <button data-tab="weekday">Weekday</button>
      <button data-tab="about">About</button>
            <button data-tab="convert">Converters</button>
            <button data-tab="tasks">Tasks</button>
    <?php else: ?>
      <a href="?view=login">Login</a>
      <a href="?view=signup">Sign up</a>
    <?php endif; ?>
  </div>
  <div class="actions">
    <?php if($loggedIn): ?>
      <span class="pill">Signed in as <?php echo htmlspecialchars($_SESSION['uname']); ?></span>
      <button class="pill" id="themeToggle">Toggle theme</button>
      <form method="post" style="margin:0"><input type="hidden" name="act" value="logout"><button class="action" type="submit">Logout</button></form>
    <?php else: ?>
      <button class="pill" id="themeToggle">Toggle theme</button>
      <span class="pill">Welcome</span>
    <?php endif; ?>
  </div>
</header>

<div class="container">
  <?php if(!$loggedIn && $view==='login'): ?>
    <div class="content" style="width:100%">
      <div class="card">
        <div class="section-title"><h3>Login</h3><span class="badge">Secure</span></div>
        <?php if($err): ?><div class="card" style="margin-bottom:10px;color:var(--danger)"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="act" value="login"/>
          <label>Username</label><input name="user" required/>
          <label>Password</label><input name="pass" type="password" required/>
          <div style="margin-top:10px"><button class="action" type="submit">Login</button></div>
        </form>
        <p style="margin-top:10px;color:var(--muted)">Need an account? <a href="?view=signup">Sign up</a></p>
      </div>
    </div>

  <?php elseif(!$loggedIn && $view==='signup'): ?>
    <div class="content" style="width:100%">
      <div class="card">
        <div class="section-title"><h3>Create account</h3><span class="badge">New</span></div>
        <?php if($err): ?><div class="card" style="margin-bottom:10px;color:var(--danger)"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="act" value="signup"/>
          <label>Username</label><input name="user" required/>
          <label>Email</label><input name="email" type="email" required/>
          <label>Password</label><input name="pass" type="password" placeholder="At least 8 characters" required/>
          <div style="margin-top:10px"><button class="action" type="submit">Sign up</button></div>
        </form>
        <p style="margin-top:10px;color:var(--muted)">Already have an account? <a href="?view=login">Login</a></p>
      </div>
    </div>

  <?php else: ?>

    <aside class="sidebar">
      <div class="card">
                <div class="section-title"><h3>Quick stats</h3><span class="badge">Overview</span></div>
                <?php if($msg): ?><div class="card" style="margin-bottom:10px;color:var(--success)"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
                <div class="grid">
                        <div class="stat"><div style="color:var(--muted)">People</div><div style="font-weight:800;"><?php echo number_format($counts['people']); ?></div></div>
                        <div class="stat"><div style="color:var(--muted)">Activities Logged</div><div style="font-weight:800;"><?php echo number_format($counts['activities']); ?></div></div>
                        <div class="stat"><div style="color:var(--muted)">Calc History</div><div style="font-weight:800;"><?php echo number_format($counts['calc']); ?></div></div>
                        <div class="stat"><div style="color:var(--muted)">Database</div><div style="font-weight:800;"><?php echo htmlspecialchars($cfg['db']); ?></div></div>
                        <div class="stat"><div style="color:var(--muted)">User</div><div style="font-weight:800;"><?php echo htmlspecialchars($_SESSION['uname']); ?></div></div>
                </div>
            </div>

            <div class="card" style="margin-top:12px">
                <div class="section-title"><h3>Live status</h3><span class="badge">System</span></div>
                <div class="stat" style="margin-bottom:10px">
                    <div style="color:var(--muted)">Today</div>
                    <div style="font-weight:800;"><?php echo date('l, d M Y'); ?></div>
                </div>
                <div id="liveClock" class="pill" style="display:block;text-align:center;font-size:18px;font-weight:700">--:--:--</div>
            </div>

            <div class="card" style="margin-top:12px">
                <div class="section-title"><h3>Recent activity</h3><span class="badge">You</span></div>
                <div class="history">
                    <?php if($activities): foreach($activities as $a): ?>
                        <div style="margin-bottom:8px">
                            <div style="font-weight:600"><?php echo htmlspecialchars($a['action']); ?></div>
                            <?php if($a['meta']): ?><div style="color:var(--muted);font-size:13px"><?php echo htmlspecialchars($a['meta']); ?></div><?php endif; ?>
                            <div style="color:var(--muted);font-size:12px"><?php echo $a['created_at']; ?></div>
                        </div>
                    <?php endforeach; else: ?>
                        <div style="color:var(--muted)">No activity yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

        <section class="content">
            <div class="card tab-panel active" id="panel-dashboard">
                <div class="section-title"><h3>IWT — Control Center</h3><span class="badge">Dashboard</span></div>
                <div class="grid" style="margin-bottom:12px">
                    <div class="stat"><div style="color:var(--muted)">Front-end</div><div style="font-weight:800">HTML/CSS/JS</div></div>
                    <div class="stat"><div style="color:var(--muted)">Back-end</div><div style="font-weight:800">PHP/MySQL</div></div>
                    <div class="stat"><div style="color:var(--muted)">Mode</div><div style="font-weight:800">Full-stack Lab</div></div>
                    <div class="stat"><div style="color:var(--muted)">Status</div><div style="font-weight:800">Logged in</div></div>
                </div>

                <div class="card" style="margin-bottom:12px">
                    <div class="section-title"><h3>Quick actions</h3><span class="badge">Tools</span></div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button class="action" type="button" onclick="openTab('calc')">Calculator</button>
                        <button class="action" type="button" onclick="openTab('form')">Validator</button>
                        <button class="action" type="button" onclick="openTab('db')">DB Insert</button>
                        <button class="action" type="button" onclick="openTab('events')">Events Lab</button>
                        <button class="action" type="button" onclick="openTab('convert')">Converters Hub</button>
                        <button class="action" type="button" onclick="openTab('tasks')">Tasks Board</button>
                    </div>
                </div>
            </div>

            <div class="card tab-panel" id="panel-calc">
                <div class="section-title"><h3>Calculator</h3><span class="badge">Expression</span></div>
                <input id="calcExpr" placeholder="e.g. (5+3)*2 + sin(30)"/>
                <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
                    <button class="action" type="button" onclick="evalExpr()">Compute</button>
                    <button class="pill" type="button" onclick="clearExpr()">Clear</button>
                    <button class="pill" id="angleMode" type="button" onclick="toggleAngle()">Mode: Degrees</button>
                    <span id="calcOut" class="pill">Result: 0</span>
                </div>

                <div class="calc-grid" style="margin-top:10px">
                    <button onclick="push('7')">7</button><button onclick="push('8')">8</button><button onclick="push('9')">9</button><button onclick="push('/')">÷</button><button onclick="push('^')">^</button>
                    <button onclick="push('4')">4</button><button onclick="push('5')">5</button><button onclick="push('6')">6</button><button onclick="push('*')">×</button><button onclick="pushAns()">Ans</button>
                    <button onclick="push('1')">1</button><button onclick="push('2')">2</button><button onclick="push('3')">3</button><button onclick="push('-')">−</button><button onclick="clearExpr()">AC</button>
                    <button onclick="push('0')">0</button><button onclick="push('.')">.</button><button onclick="push('+')">+</button><button class="primary wide" onclick="evalExpr()" style="grid-column:span 2">=</button>
                </div>

                <div style="margin-top:12px" class="card">
                    <div class="section-title"><h3>Saved history</h3><span class="badge">Per user</span></div>
                    <div class="history">
                        <?php if($calcHistory): foreach($calcHistory as $c): ?>
                            <div style="margin-bottom:8px"><div style="font-weight:600"><?php echo htmlspecialchars($c['expression']); ?></div>
                                <div style="color:var(--muted)">= <?php echo htmlspecialchars($c['result']); ?> <span style="float:right;font-size:12px;color:var(--muted)"><?php echo $c['created_at']; ?></span></div>
                            </div>
                        <?php endforeach; else: ?>
                            <div style="color:var(--muted)">No saved history yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card tab-panel" id="panel-form">
                <div class="section-title"><h3>Registration Validator</h3><span class="badge">Client-side</span></div>
                <form id="regForm" onsubmit="return false">
                    <label>Name *</label><input id="f_nm" maxlength="15"/>
                    <label>Address *</label><textarea id="f_ad" maxlength="50"></textarea>
                    <label>Email *</label><input id="f_em" type="email"/>
                    <label>Confirm Email *</label><input id="f_ce" type="email"/>
                    <label>Phone (10 digits) *</label><input id="f_ph"/>
                    <label>Password *</label><input id="f_pw" type="password"/>
                    <label>Repeat Password *</label><input id="f_rp" type="password"/>
                    <div style="margin-top:10px"><button class="action" type="button" onclick="validateReg()">Validate</button></div>
                </form>
                <div id="regMsg" class="pill" style="margin-top:8px">Waiting for input</div>
            </div>

            <div class="card tab-panel" id="panel-db">
                <div class="section-title"><h3>Database quick add</h3><span class="badge">people</span></div>
                <form method="post" class="flex" style="display:flex;gap:8px">
                    <input type="hidden" name="act" value="add_person"/>
                    <input name="person" placeholder="Name" required style="flex:1"/>
                    <button class="action" type="submit">Insert</button>
                </form>

                <?php if($recent): ?>
                    <table class="table" style="margin-top:10px">
                        <thead><tr><th>#</th><th>Name</th><th>Created</th></tr></thead>
                        <tbody><?php foreach($recent as $r): ?><tr><td><?php echo $r['id']; ?></td><td><?php echo htmlspecialchars($r['name']); ?></td><td><?php echo $r['created_at']; ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                <?php else: ?>
                    <p style="color:var(--muted);margin-top:10px">No entries yet.</p>
                <?php endif; ?>
            </div>

            <div class="card tab-panel" id="panel-class">
                <div class="section-title"><h3>CSE V'C Table</h3><span class="badge">Static</span></div>
                <div class="history">
                    <table class="table">
                        <thead><tr><th>S.No</th><th>Name</th><th>Roll</th><th>Phone</th><th>Email</th></tr></thead>
                        <tbody>
                            <tr><td>1</td><td>Samarth Kapdi</td><td>0863CS*****1</td><td>9843556387</td><td>Samarth@domain.com</td></tr>
                            <tr><td>2</td><td>Rohit Rajure</td><td>0863CS*****7</td><td>654464643</td><td>Rohit@domain.com</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card tab-panel" id="panel-info">
                <div class="section-title"><h3>Program Info</h3><span class="badge">Overview</span></div>
                <p style="color:var(--muted)">IWT covers front-end and back-end basics — HTML/CSS/JS, PHP, MySQL, sessions, and deployment fundamentals.</p>
            </div>

            <div class="card tab-panel" id="panel-about">
                <div class="section-title"><h3>About</h3><span class="badge">Contributors</span></div>
                <table class="table">
                    <thead><tr><th>#</th><th>Name</th><th>Role</th><th>LinkedIn</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>Samarth Kapdi</td>
                            <td>Lead Developer</td>
                            <td>
                                <a class="icon-link" href="https://www.linkedin.com/in/samarthkapdi" target="_blank" rel="noopener noreferrer" aria-label="Samarth Kapdi on LinkedIn">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.45 20.45h-3.55V14.9c0-1.32-.02-3.01-1.84-3.01-1.85 0-2.13 1.44-2.13 2.92v5.64H9.38V9h3.41v1.56h.05c.48-.91 1.64-1.87 3.38-1.87 3.62 0 4.29 2.38 4.29 5.48v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.12 2.06 2.06 0 0 1 0 4.12ZM3.57 20.45h3.53V9H3.57v11.45ZM22.23 0H1.77C.8 0 0 .77 0 1.72v20.56C0 23.23.8 24 1.77 24h20.46c.97 0 1.77-.77 1.77-1.72V1.72C24 .77 23.2 0 22.23 0Z"/></svg>
                                    <span>Connect</span>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td>2</td>
                            <td>Rohit Rajure</td>
                            <td>Co-Developer</td>
                            <td>
                                <a class="icon-link" href="https://www.linkedin.com/in/rohit-rajure-bb9508302" target="_blank" rel="noopener noreferrer" aria-label="Rohit Rajure on LinkedIn">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.45 20.45h-3.55V14.9c0-1.32-.02-3.01-1.84-3.01-1.85 0-2.13 1.44-2.13 2.92v5.64H9.38V9h3.41v1.56h.05c.48-.91 1.64-1.87 3.38-1.87 3.62 0 4.29 2.38 4.29 5.48v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.12 2.06 2.06 0 0 1 0 4.12ZM3.57 20.45h3.53V9H3.57v11.45ZM22.23 0H1.77C.8 0 0 .77 0 1.72v20.56C0 23.23.8 24 1.77 24h20.46c.97 0 1.77-.77 1.77-1.72V1.72C24 .77 23.2 0 22.23 0Z"/></svg>
                                    <span>Connect</span>
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card tab-panel" id="panel-hobby">
                <div class="section-title"><h3>Hobby Board</h3><span class="badge">Local</span></div>
                <div style="display:flex;gap:8px">
                    <input id="hobbyInput" placeholder="e.g., Reading" style="flex:1"/>
                    <button class="action" id="hobbyAddBtn" type="button">Add</button>
                    <button class="pill" id="hobbyClearBtn" type="button">Clear</button>
                </div>
                <div id="hobbyList" style="margin-top:10px"></div>
            </div>

            <div class="card tab-panel" id="panel-weekday">
                <div class="section-title"><h3>Weekday Status</h3><span class="badge">0-6</span></div>
                <input id="dayInput" type="number" min="0" max="6" placeholder="0-6"/>
                <div style="margin-top:8px"><button class="action" onclick="showDayMessage()">Show</button></div>
                <div id="dayResult" class="pill" style="margin-top:8px">Waiting</div>
            </div>

            <div class="card tab-panel" id="panel-events">
                <div class="section-title"><h3>Event Lab</h3><span class="badge">Try</span></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <div class="chip" onclick="logEvent('click')">Click me</div>
                    <div class="chip" ondblclick="logEvent('double-click')">Double click</div>
                    <div class="chip" oncontextmenu="event.preventDefault();logEvent('right-click')">Right click</div>
                    <input class="chip" placeholder="Type here" oninput="logEvent('input: '+this.value)"/>
                </div>
                <div id="elog" class="history" style="margin-top:10px">Interact to see events.</div>
            </div>

            <div class="card tab-panel" id="panel-convert">
                <div class="section-title"><h3>Converters Hub</h3><span class="badge">Multi</span></div>
                <p style="color:var(--muted);margin-bottom:12px">Currency, temperature, weight and length conversions with a single click.</p>
                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
                    <div class="mini-card">
                        <div class="section-title"><h3>Currency</h3><span class="badge">USD→INR</span></div>
                        <input id="usd" type="number" placeholder="Amount in USD" min="0" step="0.01"/>
                        <div style="margin-top:8px;display:flex;gap:8px">
                            <button class="action" type="button" onclick="convertCurrency()">Convert</button>
                            <span id="curOut" class="pill" style="flex:1">Result: 0 INR</span>
                        </div>
                    </div>
                    <div class="mini-card">
                        <div class="section-title"><h3>Temperature</h3><span class="badge">C⇄F</span></div>
                        <input id="tempVal" type="number" placeholder="Value" step="0.1"/>
                        <select id="tempMode" style="margin-top:8px">
                            <option value="c2f">Celsius → Fahrenheit</option>
                            <option value="f2c">Fahrenheit → Celsius</option>
                        </select>
                        <div style="margin-top:8px;display:flex;gap:8px">
                            <button class="action" type="button" onclick="convertTemp()">Convert</button>
                            <span id="tempOut" class="pill" style="flex:1">Result: 0</span>
                        </div>
                    </div>
                    <div class="mini-card">
                        <div class="section-title"><h3>Weight</h3><span class="badge">kg/g/lb/oz</span></div>
                        <input id="weightVal" type="number" placeholder="Value" step="0.01"/>
                        <div style="display:flex;gap:8px;margin-top:8px">
                            <select id="weightFrom">
                                <option value="kg">Kilogram</option>
                                <option value="g">Gram</option>
                                <option value="lb">Pound</option>
                                <option value="oz">Ounce</option>
                            </select>
                            <select id="weightTo">
                                <option value="lb">Pound</option>
                                <option value="kg">Kilogram</option>
                                <option value="g">Gram</option>
                                <option value="oz">Ounce</option>
                            </select>
                        </div>
                        <div style="margin-top:8px;display:flex;gap:8px">
                            <button class="action" type="button" onclick="convertWeight()">Convert</button>
                            <span id="weightOut" class="pill" style="flex:1">Result: 0</span>
                        </div>
                    </div>
                    <div class="mini-card">
                        <div class="section-title"><h3>Length</h3><span class="badge">m/km/cm/ft/in</span></div>
                        <input id="lengthVal" type="number" placeholder="Value" step="0.01"/>
                        <div style="display:flex;gap:8px;margin-top:8px">
                            <select id="lengthFrom">
                                <option value="m">Meter</option>
                                <option value="km">Kilometer</option>
                                <option value="cm">Centimeter</option>
                                <option value="mm">Millimeter</option>
                                <option value="ft">Feet</option>
                                <option value="in">Inches</option>
                            </select>
                            <select id="lengthTo">
                                <option value="ft">Feet</option>
                                <option value="m">Meter</option>
                                <option value="km">Kilometer</option>
                                <option value="cm">Centimeter</option>
                                <option value="mm">Millimeter</option>
                                <option value="in">Inches</option>
                            </select>
                        </div>
                        <div style="margin-top:8px;display:flex;gap:8px">
                            <button class="action" type="button" onclick="convertLength()">Convert</button>
                            <span id="lengthOut" class="pill" style="flex:1">Result: 0</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card tab-panel" id="panel-tasks">
                <div class="section-title"><h3>Task Board</h3><span class="badge">Local</span></div>
                <p style="color:var(--muted)">Keep an eye on your study/work todos. Data stays in your browser.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <input id="taskInput" placeholder="Add a new task" style="flex:1 1 200px"/>
                    <select id="taskPriority" style="flex:0 0 140px">
                        <option value="low">Low priority</option>
                        <option value="medium">Medium priority</option>
                        <option value="high">High priority</option>
                    </select>
                    <button class="action" id="taskAddBtn" type="button">Add task</button>
                    <button class="pill" id="taskClearBtn" type="button">Clear all</button>
                </div>
                <div id="taskStats" class="pill" style="margin-top:10px">0 tasks total</div>
                <div id="tasksList" class="history" style="margin-top:10px;min-height:120px">No tasks yet.</div>
            </div>

        </section>
    <?php endif; ?>
</div>

<!-- PART 2 End: Next paste PART 3 (JS) after this block -->
<!-- PART 3 — JavaScript & final closures -->
<script>
/*
    PART 3 — JS (paste this after PART 2)
    - Handles: tabs, calculator, validators, hobby board, converters, event logging, theme toggle
    - Uses the PHP-provided $loggedIn variable (rendered below)
*/
const isLogged = <?php echo $loggedIn ? 'true' : 'false'; ?>;

// --------- Helpers ----------
function qs(sel){ return document.querySelector(sel); }
function qsa(sel){ return Array.from(document.querySelectorAll(sel)); }
function safeText(s){ return String(s===null||s===undefined ? '' : s); }
function escHtml(str){
    return safeText(str).replace(/[&<>"']/g, ch => {
        switch(ch){
            case '&': return '&amp;';
            case '<': return '&lt;';
            case '>': return '&gt;';
            case '"': return '&quot;';
            default: return '&#39;';
        }
    });
}

// Small helper to POST form data and return parsed JSON
async function postForm(data){
    try{
        const res = await fetch('', { method: 'POST', body: data });
        const txt = await res.text();
        // If response is JSON, parse. Some endpoints return HTML on errors.
        try { return JSON.parse(txt); } catch(e){ return { ok: true }; }
    }catch(e){
        return { ok: false, error: e.message };
    }
}

// --------- NAV / Tabs ----------
const navTabs = qsa('#navTabs button');
const panels = qsa('.tab-panel');
function openTab(tab){
    navTabs.forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    panels.forEach(p => p.classList.remove('active'));
    const el = document.getElementById('panel-' + tab);
    if(el) el.classList.add('active');
    // track activity
    sendActivity('Open tab', tab);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
navTabs.forEach(b => b.addEventListener('click', ()=> openTab(b.dataset.tab) ));

// Auto open default active (already set in HTML) — but ensure one active panel
(function ensureActivePanel(){
    if(!document.querySelector('.tab-panel.active')){
        const def = document.getElementById('panel-dashboard') || document.querySelector('.tab-panel');
        if(def) def.classList.add('active');
    }
})();

// --------- THEME ----------
const bodyEl = document.body;
const themeBtn = qs('#themeToggle');
const THEME_KEY = 'iwt-theme';
const stored = localStorage.getItem(THEME_KEY);
if(stored) bodyEl.dataset.theme = stored;
function setTheme(t){ bodyEl.dataset.theme = t; localStorage.setItem(THEME_KEY, t); }
function toggleTheme(){ setTheme(bodyEl.dataset.theme === 'light' ? 'dark' : 'light'); }
if(themeBtn) themeBtn.addEventListener('click', toggleTheme);

// --------- CLOCK ----------
const clockEl = qs('#liveClock');
function updateClock(){
    if(!clockEl) return;
    const now = new Date();
    clockEl.textContent = now.toLocaleTimeString();
}
if(clockEl){
    updateClock();
    setInterval(updateClock, 1000);
}

// --------- ACTIVITY / AJAX logging ----------
async function sendActivity(action, meta=''){
    if(!isLogged) return;
    const form = new FormData();
    form.append('act','log_event');
    form.append('msg', action);
    form.append('meta', meta);
    await postForm(form);
}
async function sendCalcLog(expr, res){
    if(!isLogged) return;
    const form = new FormData();
    form.append('act','log_calc');
    form.append('expr', expr);
    form.append('res', res);
    await postForm(form);
}

// --------- EVENT LOG UI ----------
let elog = [];
function renderLog(){
    const el = qs('#elog');
    if(!el) return;
    if(!elog.length){ el.textContent = 'Interact to see events.'; return; }
    el.innerHTML = elog.map(it => `<div>${it}</div>`).join('');
}
function logEvent(msg){
    const ts = new Date().toLocaleTimeString();
    elog.unshift(ts + ': ' + msg);
    if(elog.length > 80) elog.length = 80;
    renderLog();
    sendActivity(msg);
}
function clearLog(){ elog = []; renderLog(); }

// Attach event handlers for Event Lab chip inputs (some are inline in HTML, we still ensure button bindings)
qsa('.chip').forEach(ch => {
    // some chips already have inline handler, this is safe fallback for any new chips
    if(!ch.onclick) ch.addEventListener('click', ()=> logEvent(ch.textContent || 'chip click'));
});
renderLog();

// --------- CALCULATOR ----------
let hist = [];
let lastResult = 0;
let useDegrees = true;

function push(v){
    const el = qs('#calcExpr');
    if(!el) return;
    el.value += v;
}
function pushAns(){ push(String(lastResult)); }
function backspace(){
    const el = qs('#calcExpr'); if(!el) return; el.value = el.value.slice(0,-1);
}
function clearExpr(){ const el = qs('#calcExpr'); if(el) el.value = ''; updateCalcOut('Result: 0'); }
function toggleAngle(){ useDegrees = !useDegrees; const btn = qs('#angleMode'); if(btn) btn.textContent = useDegrees ? 'Mode: Degrees' : 'Mode: Radians'; }
function toRad(v){ return useDegrees ? (v * Math.PI / 180) : v; }

function normalizeExpr(raw){
    let expr = raw;
    expr = expr.replace(/π/g, 'Math.PI');
    expr = expr.replace(/\^/g, '**');
    expr = expr.replace(/\bln\(/g, 'Math.log(');
    expr = expr.replace(/\blog\(/g, 'Math.log10(');
    expr = expr.replace(/\bsqrt\(/g, 'Math.sqrt(');
    expr = expr.replace(/\bpow\(/g, 'Math.pow(');
    expr = expr.replace(/\babs\(/g, 'Math.abs(');
    ['sin','cos','tan'].forEach(fn => {
        const repl = useDegrees ? `Math.${fn}(toRad(` : `Math.${fn}(`;
        expr = expr.replace(new RegExp('\\b' + fn + '\\(', 'g'), repl);
    });
    return expr;
}
function updateCalcOut(text){ const o = qs('#calcOut'); if(o) o.textContent = text; }

function renderHist(){
    const box = qs('#hist');
    if(!box) return;
    if(!hist.length){ box.textContent = 'No history yet'; return; }
    box.innerHTML = hist.map(h => '<div>' + h + '</div>').join('');
}

function evalExpr(){
    const el = qs('#calcExpr');
    if(!el) return;
    const raw = el.value.trim();
    if(!raw){ updateCalcOut('Result: 0'); return; }
    const prepared = normalizeExpr(raw);
    try {
        // Use Function to evaluate prepared JS expression safely in our scope
        const res = Function('toRad', 'return ' + prepared)(toRad);
        lastResult = res;
        updateCalcOut('Result: ' + res);
        hist.unshift(raw + ' = ' + res);
        if(hist.length > 50) hist.length = 50;
        renderHist();
        sendActivity('Calculator', raw + ' = ' + res);
        sendCalcLog(raw, String(res));
    } catch(e) {
        updateCalcOut('Error');
    }
}
function clearHist(){ hist = []; renderHist(); }
renderHist();

// Bind calculator numeric buttons (some inline already exist; this is safe)
qsa('.calc-grid button').forEach(b => {
    if(!b.onclick){ b.addEventListener('click', ()=> { const v = b.textContent.trim(); if(v==='=' || b.classList.contains('primary')) evalExpr(); else if(v==='AC') clearExpr(); else push(v); }); }
});

// --------- CONVERTERS ----------
function convertCurrency(){
    const usd = parseFloat(qs('#usd')?.value || '0');
    const rate = 83.0; // static
    const out = qs('#curOut'); if(out) out.textContent = 'Result: ' + (usd * rate).toFixed(2) + ' INR';
    sendActivity('Currency convert','USD ' + usd + ' -> INR');
}
function convertTemp(){
    const v = parseFloat(qs('#tempVal')?.value || '0');
    const mode = qs('#tempMode')?.value || 'c2f';
    const out = qs('#tempOut'); const res = mode === 'c2f' ? (v * 9/5 + 32) : ((v - 32) * 5/9);
    if(out) out.textContent = 'Result: ' + res.toFixed(2);
    sendActivity('Temp convert', mode + ' value ' + v);
}
function convertWeight(){
    const v = parseFloat(qs('#weightVal')?.value || '0');
    const from = qs('#weightFrom')?.value || 'kg';
    const to = qs('#weightTo')?.value || 'lb';
    const map = { kg:1, g:0.001, lb:0.453592, oz:0.0283495 };
    const kg = v * map[from];
    const res = kg / map[to];
    const out = qs('#weightOut'); if(out) out.textContent = 'Result: ' + res.toFixed(4) + ' ' + to;
    sendActivity('Weight convert', v + ' ' + from + ' -> ' + to);
}
function convertLength(){
    const v = parseFloat(qs('#lengthVal')?.value || '0');
    const from = qs('#lengthFrom')?.value || 'm';
    const to = qs('#lengthTo')?.value || 'ft';
    const map = { m:1, km:1000, cm:0.01, mm:0.001, ft:0.3048, in:0.0254 };
    const meters = v * map[from];
    const res = meters / map[to];
    const out = qs('#lengthOut'); if(out) out.textContent = 'Result: ' + res.toFixed(4) + ' ' + to;
    sendActivity('Length convert', v + ' ' + from + ' -> ' + to);
}

// Attach converter buttons
if(qs('#usd')) qs('#usd').addEventListener('keydown',e=>{ if(e.key==='Enter') convertCurrency(); });
qsa('button').forEach(b=>{
    if(b.textContent && b.textContent.toLowerCase().includes('convert') && b.onclick===null){
        // ignore — converters have inline handlers in HTML
    }
});

// --------- HOBBY BOARD (localStorage) ----------
const HOBBY_KEY = 'iwt_hobbies_v1';
function loadHobbies(){
    try{ const raw = localStorage.getItem(HOBBY_KEY); return raw ? JSON.parse(raw) : []; } catch(e){ return []; }
}
function saveHobbies(items){
    try{ localStorage.setItem(HOBBY_KEY, JSON.stringify(items)); renderHobbies(items); } catch(e){}
}
function renderHobbies(items = loadHobbies()){
    const listEl = qs('#hobbyList');
    if(!listEl) return;
    listEl.innerHTML = '';
    if(!items.length){ listEl.innerHTML = '<div style="color:var(--muted)">No hobbies yet.</div>'; return; }
    items.forEach((h, idx) => {
        const d = document.createElement('div');
        d.className = 'chip';
        d.style.margin = '6px';
        d.textContent = h;
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.style.marginLeft = '8px';
        rm.textContent = '×';
        rm.onclick = ()=>{ items.splice(idx,1); saveHobbies(items); sendActivity('Hobby removed', h); };
        d.appendChild(rm);
        listEl.appendChild(d);
    });
}
function addHobby(){
    const input = qs('#hobbyInput'); if(!input) return;
    const raw = (input.value || '').trim(); if(!raw) return;
    const items = loadHobbies();
    if(items.some(it => it.toLowerCase() === raw.toLowerCase())) { input.value=''; input.focus(); return; }
    items.push(raw);
    saveHobbies(items);
    input.value='';
    input.focus();
    sendActivity('Hobby added', raw);
}
function clearHobbies(){
    if(!confirm('Clear all hobbies?')) return;
    localStorage.removeItem(HOBBY_KEY);
    renderHobbies([]);
    sendActivity('Hobby cleared', 'all removed');
}
const hbAdd = qs('#hobbyAddBtn'), hbClear = qs('#hobbyClearBtn');
if(hbAdd) hbAdd.addEventListener('click', addHobby);
if(hbClear) hbClear.addEventListener('click', clearHobbies);
if(qs('#hobbyInput')) qs('#hobbyInput').addEventListener('keydown', e => { if(e.key==='Enter') addHobby(); });
renderHobbies();

// --------- TASK BOARD (localStorage) ----------
const TASK_KEY = 'iwt_tasks_v1';
function loadTasksStore(){
    try{ const raw = localStorage.getItem(TASK_KEY); return raw ? JSON.parse(raw) : []; } catch(e){ return []; }
}
function saveTasksStore(items){
    try{ localStorage.setItem(TASK_KEY, JSON.stringify(items)); renderTasks(items); } catch(e){}
}
function renderTasks(items = loadTasksStore()){
    const listEl = qs('#tasksList');
    const statsEl = qs('#taskStats');
    if(!listEl) return;
    if(!items.length){
        listEl.textContent = 'No tasks yet.';
        if(statsEl) statsEl.textContent = '0 tasks total';
        return;
    }
    listEl.innerHTML = items.map((task, idx) => {
        const priority = (task.priority || 'low');
        const label = priority.charAt(0).toUpperCase() + priority.slice(1);
        return `
        <div class="task-item${task.done ? ' done' : ''}" data-idx="${idx}">
            <div style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" data-action="toggle" data-idx="${idx}" ${task.done ? 'checked' : ''}/>
                <div>
                    <div class="task-title">${escHtml(task.text || '')}</div>
                    <div style="color:var(--muted);font-size:12px">${label} priority</div>
                </div>
            </div>
            <div style="display:flex;gap:6px;align-items:center">
                <span class="pill" style="font-size:11px">${label}</span>
                <button class="pill" data-action="delete" data-idx="${idx}" type="button" style="background:transparent;color:var(--danger)">×</button>
            </div>
        </div>`;
    }).join('');
    if(statsEl){
        const pending = items.filter(t => !t.done).length;
        statsEl.textContent = `${items.length} tasks • ${pending} open`;
    }
}
function addTask(){
    const input = qs('#taskInput');
    const prioritySel = qs('#taskPriority');
    if(!input) return;
    const text = (input.value || '').trim();
    if(!text){ input.focus(); return; }
    const tasks = loadTasksStore();
    tasks.unshift({ text, priority: prioritySel ? prioritySel.value : 'low', done:false, created: Date.now() });
    saveTasksStore(tasks);
    input.value = '';
    input.focus();
    sendActivity('Task added', text);
}
function toggleTask(idx){
    const tasks = loadTasksStore();
    if(typeof tasks[idx] === 'undefined') return;
    tasks[idx].done = !tasks[idx].done;
    saveTasksStore(tasks);
    sendActivity('Task toggled', tasks[idx].text || '');
}
function removeTask(idx){
    const tasks = loadTasksStore();
    const removed = tasks.splice(idx,1);
    saveTasksStore(tasks);
    if(removed[0]) sendActivity('Task removed', removed[0].text || '');
}
function clearTasks(){
    if(!confirm('Clear all tasks?')) return;
    localStorage.removeItem(TASK_KEY);
    renderTasks([]);
    sendActivity('All tasks cleared');
}
const taskAddBtn = qs('#taskAddBtn');
if(taskAddBtn) taskAddBtn.addEventListener('click', addTask);
const taskInput = qs('#taskInput');
if(taskInput) taskInput.addEventListener('keydown', e => { if(e.key==='Enter') addTask(); });
const taskClearBtn = qs('#taskClearBtn');
if(taskClearBtn) taskClearBtn.addEventListener('click', clearTasks);
const tasksWrap = qs('#tasksList');
if(tasksWrap){
    tasksWrap.addEventListener('change', e => {
        const action = e.target.dataset?.action;
        if(action === 'toggle'){ toggleTask(parseInt(e.target.dataset.idx, 10)); }
    });
    tasksWrap.addEventListener('click', e => {
        const action = e.target.dataset?.action;
        if(action === 'delete') removeTask(parseInt(e.target.dataset.idx, 10));
    });
}
renderTasks();

// --------- WEEKDAY HELPER ----------
function showDayMessage(){
    const input = qs('#dayInput'), out = qs('#dayResult');
    if(!input || !out) return;
    const v = parseInt(input.value, 10);
    if(Number.isNaN(v) || v < 0 || v > 6){ out.textContent = 'Please enter a valid day number (0-6).'; out.style.color = 'var(--danger)'; return; }
    let message = 'I am looking forward to this Weekend';
    if(v === 5) message = 'Finally Friday';
    else if(v === 6) message = 'Super Saturday';
    else if(v === 0) message = 'Sleepy Sunday';
    out.textContent = 'Day ' + v + ': ' + message;
    out.style.color = '';
    sendActivity('Weekday status', 'Day ' + v + ' -> ' + message);
}
if(qs('#dayInput')) qs('#dayInput').addEventListener('keydown', e => { if(e.key==='Enter') showDayMessage(); });

// --------- REGISTRATION VALIDATOR (client-side) ----------
function validateReg(){
    const g = id => qs('#'+id)?.value?.trim() ?? '';
    let ok = true; const msgs = [];
    const nm = g('f_nm'), ad = g('f_ad'), em = g('f_em'), ce = g('f_ce'), ph = g('f_ph'), pw = g('f_pw'), rp = g('f_rp');
    if(!nm || nm.length > 15){ ok = false; msgs.push('Name required (<=15 chars)'); }
    if(!ad || ad.length > 50){ ok = false; msgs.push('Address required (<=50 chars)'); }
    if(!/^\S+@\S+\.\S+$/.test(em)){ ok = false; msgs.push('Invalid email'); }
    if(em !== ce){ ok = false; msgs.push('Emails do not match'); }
    if(!/^\d{10}$/.test(ph)){ ok = false; msgs.push('Phone must be 10 digits'); }
    if(!/(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}/.test(pw)){ ok = false; msgs.push('Weak password'); }
    if(pw !== rp){ ok = false; msgs.push('Passwords do not match'); }
    const out = qs('#regMsg');
    if(out){ out.textContent = ok ? 'Form is valid!' : msgs.join(' | '); out.style.color = ok ? 'var(--success)' : 'var(--danger)'; }
    sendActivity('Form validate', ok ? 'valid' : msgs.join(' | '));
}

// --------- SAVE/LOAD state tidbits (optional) ----------
window.addEventListener('beforeunload', () => {
    // nothing heavy here; leave hooks for later
});

// --------- Bindings for some UI controls that didn't have inline handlers ----------
if(qs('#angleMode')) qs('#angleMode').addEventListener('click', toggleAngle);
if(qs('#curOut')) { /* no-op: element used for output */ }
if(qs('#usd')) qs('#usd').addEventListener('keyup', e => { if(e.key==='Enter') convertCurrency(); });
if(qs('#tempVal')) qs('#tempVal').addEventListener('keyup', e => { if(e.key==='Enter') convertTemp(); });
if(qs('#weightVal')) qs('#weightVal').addEventListener('keyup', e => { if(e.key==='Enter') convertWeight(); });
if(qs('#lengthVal')) qs('#lengthVal').addEventListener('keyup', e => { if(e.key==='Enter') convertLength(); });

// Also bind the simple explicit buttons used in PART 2 (they might already have inline handlers)
qsa('[onclick="convertCurrency()"]').forEach(b => b.addEventListener('click', convertCurrency));
qsa('[onclick="convertTemp()"]').forEach(b => b.addEventListener('click', convertTemp));
qsa('[onclick="convertWeight()"]').forEach(b => b.addEventListener('click', convertWeight));
qsa('[onclick="convertLength()"]').forEach(b => b.addEventListener('click', convertLength));

// --------- Final: small UX niceties ----------
(function init(){
    // focus on login username if on login view
    if(document.location.search.includes('view=login')){
        const u=qs('input[name="user"]'); if(u) u.focus();
    }
    // ensure nav open matches current panel (if page loaded with ?view=... you can map here)
    // done earlier through server-side default
})();

</script>
</body>
</html>
