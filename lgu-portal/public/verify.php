<?php
session_start();
date_default_timezone_set('Asia/Manila');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require __DIR__ . '/db.php';

// ── Base URLs (mirrors login.php) ─────────────────────────────────────────
if ($_SERVER['HTTP_HOST'] === 'localhost') {
    $BASE_URL      = '/LGU/lgu-portal/public/';
    $OFFICIAL_LOGO = '/LGU/lgu-portal/public/assets/img/officiallogo.png';
    $loginUrl      = '/LGU/lgu-portal/public/login.php';
} else {
    $BASE_URL      = '/lgu-portal/public/';
    $OFFICIAL_LOGO = '/lgu-portal/public/assets/img/officiallogo.png';
    $loginUrl      = '/lgu-portal/public/login.php';
}

// ─────────────────────────────────────────────────────────────────────────
//  VERIFICATION LOGIC
//  Schema facts (from cimm_lgu dump):
//  - pending_registrations: penreg_id, first_name, last_name, email, role,
//                           password, verification_token, verification_token_expires
//  - employees: user_id, first_name, last_name, email, role, password,
//               is_first_login (default 1), email_verified (default 0),
//               verification_token, verification_token_expires, ...
//
//  Flow: register → pending_registrations row (email_verified=0 implicit)
//        verify  → INSERT into employees (email_verified=1) + DELETE pending
// ─────────────────────────────────────────────────────────────────────────

$state         = 'invalid';
$firstName     = '';
$email         = '';
$redirectDelay = 6;

$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if ($token !== '') {

    // ── STEP 1: Look up token in pending_registrations ────────────────────
    $stmt = $conn->prepare("
        SELECT penreg_id, first_name, last_name, email, role, password,
               verification_token_expires
        FROM   pending_registrations
        WHERE  verification_token = ?
        LIMIT  1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($pending) {
        $firstName = $pending['first_name'];
        $lastName  = $pending['last_name'];
        $email     = $pending['email'];

        // ── STEP 2: Check expiry ──────────────────────────────────────────
        if (strtotime($pending['verification_token_expires']) < time()) {

            // Expired — clean up pending row and report
            $del = $conn->prepare("DELETE FROM pending_registrations WHERE penreg_id = ?");
            $del->bind_param("i", $pending['penreg_id']);
            $del->execute();
            $del->close();

            $state = 'expired';

        } else {
            // ── STEP 3: Check if employee was already created (duplicate click) ──
            $chk = $conn->prepare("SELECT user_id, email_verified FROM employees WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $chk->bind_param("s", $email);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($existing) {
                // Employee row already exists
                if ($existing['email_verified'] == 1) {
                    // Already fully verified on a previous visit
                    $state = 'already_verified';
                } else {
                    // Row exists but still unverified — just flip the flag
                    $upd = $conn->prepare("
                        UPDATE employees
                        SET    email_verified = 1,
                               verification_token = NULL,
                               verification_token_expires = NULL
                        WHERE  user_id = ?
                    ");
                    $upd->bind_param("i", $existing['user_id']);
                    $upd->execute();
                    $upd->close();

                    // Clean up pending row
                    $del = $conn->prepare("DELETE FROM pending_registrations WHERE penreg_id = ?");
                    $del->bind_param("i", $pending['penreg_id']);
                    $del->execute();
                    $del->close();

                    $state = 'success';
                }

            } else {
                // ── STEP 4: Create the employee row ──────────────────────
                $ins = $conn->prepare("
                    INSERT INTO employees
                        (first_name, last_name, email, role, password,
                         is_first_login, email_verified,
                         verification_token, verification_token_expires)
                    VALUES (?, ?, ?, ?, ?, 1, 1, NULL, NULL)
                ");
                $ins->bind_param(
                    "sssss",
                    $pending['first_name'],
                    $pending['last_name'],
                    $pending['email'],
                    $pending['role'],
                    $pending['password']
                );

                if ($ins->execute()) {
                    $ins->close();

                    // ── STEP 5: Delete from pending_registrations ─────────
                    $del = $conn->prepare("DELETE FROM pending_registrations WHERE penreg_id = ?");
                    $del->bind_param("i", $pending['penreg_id']);
                    $del->execute();
                    $del->close();

                    $state = 'success';
                } else {
                    // INSERT failed (e.g. duplicate email race condition)
                    error_log('verify.php INSERT failed: ' . $conn->error . ' | email: ' . $email);
                    $ins->close();
                    $state = 'invalid';
                }
            }
        }

    } else {
        // Token not found in pending_registrations.
        // Check if it was already used (employee exists and is verified).
        $chk = $conn->prepare("
            SELECT email_verified, first_name
            FROM   employees
            WHERE  LOWER(email) = (
                SELECT LOWER(email) FROM employees
                WHERE  verification_token = ? LIMIT 1
            )
            LIMIT 1
        ");
        // Simpler: just check employees.verification_token directly
        $chk->close();

        $chk2 = $conn->prepare("
            SELECT first_name, email, email_verified
            FROM   employees
            WHERE  verification_token = ?
            LIMIT  1
        ");
        $chk2->bind_param("s", $token);
        $chk2->execute();
        $emp = $chk2->get_result()->fetch_assoc();
        $chk2->close();

        if ($emp) {
            $firstName = $emp['first_name'];
            $email     = $emp['email'];
            $state     = $emp['email_verified'] ? 'already_verified' : 'invalid';
        } else {
            // Token was consumed on a previous successful visit — treat as already verified
            // (pending row deleted + employee created = link already worked once)
            $state = 'already_verified';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="<?= $OFFICIAL_LOGO ?>" type="image/png">
    <title>LGU Portal · Email Verification</title>
    <link rel="stylesheet" href="<?= $BASE_URL ?>citizen_global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Apply saved theme before first paint -->
    <script>
    (function(){
        var t = localStorage.getItem('theme') || localStorage.getItem('theme_backup') || 'light';
        if (t === 'dark') document.documentElement.setAttribute('data-theme','dark');
    })();
    </script>

    <style>
    /* ── CSS Variables ───────────────────────────────────────────────── */
    :root {
        --text-primary:   #000;
        --text-secondary: #555;
        --border-color:   rgba(0,0,0,.1);
        --shadow-color:   rgba(0,0,0,.18);
        --card-bg:        #fff;
        --nav-bg:         rgba(255,255,255,.87);
    }
    [data-theme="dark"] {
        --text-primary:   #fff;
        --text-secondary: #ccc;
        --border-color:   rgba(255,255,255,.1);
        --shadow-color:   rgba(0,0,0,.5);
        --card-bg:        rgba(30,30,30,.97);
        --nav-bg:         rgba(26,26,26,.87);
    }

    *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }

    body {
        font-family:'Poppins',Arial,sans-serif;
        background:url("cityhall.jpeg") center/cover no-repeat fixed;
        min-height:100vh;
        display:flex;
        flex-direction:column;
    }

    /* ── Wrapper & Card ─────────────────────────────────────────────── */
    .verify-wrapper {
        flex:1;
        display:flex;
        justify-content:center;
        align-items:center;
        padding:120px 16px 60px;
    }

    .verify-card {
        width:100%;
        max-width:440px;
        background:var(--card-bg);
        border-radius:24px;
        box-shadow:0 24px 60px var(--shadow-color);
        padding:44px 40px 40px;
        text-align:center;
        animation:cardIn .55s cubic-bezier(.34,1.56,.64,1) both;
    }

    @keyframes cardIn {
        from { opacity:0; transform:translateY(40px) scale(.95); }
        to   { opacity:1; transform:translateY(0)    scale(1);   }
    }

    .verify-logo { width:72px; margin-bottom:20px; border-radius:14px; }

    /* ── Status Icon Ring ───────────────────────────────────────────── */
    .icon-ring {
        width:86px; height:86px;
        border-radius:50%;
        display:flex; align-items:center; justify-content:center;
        margin:0 auto 22px;
        font-size:36px;
        position:relative;
    }
    .icon-ring.success { background:linear-gradient(135deg,#10b759,#059669); box-shadow:0 8px 28px rgba(16,183,89,.35); }
    .icon-ring.expired { background:linear-gradient(135deg,#f59e0b,#d97706); box-shadow:0 8px 28px rgba(245,158,11,.35); }
    .icon-ring.invalid { background:linear-gradient(135deg,#ef4444,#dc2626); box-shadow:0 8px 28px rgba(239,68,68,.35); }
    .icon-ring.already { background:linear-gradient(135deg,#6384d2,#285ccd); box-shadow:0 8px 28px rgba(99,132,210,.35); }

    .icon-ring.success::after {
        content:''; position:absolute; inset:-6px; border-radius:50%;
        border:2px solid rgba(16,183,89,.35);
        animation:pulse 2s ease-in-out infinite;
    }
    @keyframes pulse {
        0%,100% { transform:scale(1);   opacity:.7; }
        50%      { transform:scale(1.1); opacity:.2; }
    }

    /* ── Text ────────────────────────────────────────────────────────── */
    .verify-title {
        font-size:1.9rem; font-weight:700;
        color:var(--text-primary);
        margin-bottom:10px; line-height:1.25;
    }
    .verify-subtitle {
        font-size:15px; color:var(--text-secondary);
        line-height:1.65; margin-bottom:28px;
    }
    .verify-subtitle strong { color:var(--text-primary); }

    /* ── Countdown Strip ─────────────────────────────────────────────── */
    .countdown-strip {
        background:rgba(43,108,176,.08);
        border:1px solid rgba(43,108,176,.18);
        border-radius:14px;
        padding:14px 18px; margin-bottom:26px;
        display:flex; align-items:center; justify-content:center; gap:10px;
        font-size:14px; color:var(--text-secondary);
    }
    [data-theme="dark"] .countdown-strip {
        background:rgba(99,132,210,.12);
        border-color:rgba(99,132,210,.25);
    }
    #countdown-num {
        font-size:22px; font-weight:700;
        color:#2b6cb0; min-width:28px; display:inline-block;
    }

    /* ── Progress Bar ────────────────────────────────────────────────── */
    .progress-track {
        width:100%; height:4px;
        background:rgba(0,0,0,.08);
        border-radius:4px; overflow:hidden; margin-bottom:28px;
    }
    [data-theme="dark"] .progress-track { background:rgba(255,255,255,.08); }
    .progress-fill { height:100%; border-radius:4px; background:#10b759; width:100%; }

    /* ── Buttons ─────────────────────────────────────────────────────── */
    .btn-verify {
        display:inline-flex; align-items:center; justify-content:center; gap:8px;
        width:100%; padding:14px 24px;
        border:none; border-radius:14px;
        font-family:'Poppins',Arial,sans-serif; font-size:16px; font-weight:600;
        cursor:pointer; text-decoration:none;
        transition:all .25s ease; margin-bottom:10px;
    }
    .btn-verify.primary {
        background:linear-gradient(135deg,#6384d2,#285ccd); color:#fff;
        box-shadow:0 4px 16px rgba(40,92,205,.3);
    }
    .btn-verify.primary:hover {
        transform:translateY(-3px);
        box-shadow:0 8px 24px rgba(40,92,205,.4);
        background:linear-gradient(135deg,#4d76d6,#1651d0);
    }
    .btn-verify.secondary { background:rgba(0,0,0,.06); color:var(--text-secondary); }
    [data-theme="dark"] .btn-verify.secondary { background:rgba(255,255,255,.08); }
    .btn-verify.secondary:hover { background:rgba(0,0,0,.10); transform:translateY(-2px); }
    [data-theme="dark"] .btn-verify.secondary:hover { background:rgba(255,255,255,.14); }

    .divider { height:1px; background:var(--border-color); margin:22px 0; }
    .help-text { font-size:13px; color:var(--text-secondary); }
    .help-text a { color:#2b6cb0; text-decoration:none; font-weight:500; }
    .help-text a:hover { text-decoration:underline; }

    /* ── Mobile ──────────────────────────────────────────────────────── */
    @media (max-width:768px) {
        .nav { display:none !important; }
        .mobile-top-nav { display:flex !important; }
        .verify-wrapper { padding:90px 14px 40px; }
        .verify-card { padding:30px 24px 28px; }
        .verify-title { font-size:1.55rem; }
    }
    @media (min-width:769px) { .mobile-top-nav { display:none !important; } }
    </style>
</head>
<body>

<!-- ── Desktop Nav ──────────────────────────────────────────────────────── -->
<header class="nav">
    <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
        <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo" style="width:40px;border-radius:8px;">
        <span>InfraGovServices</span>
    </a>
    <div class="nav-center">
        <div class="nav-links">
            <a href="<?= $BASE_URL ?>citizencimm.php">Home</a>
            <a href="<?= htmlspecialchars($loginUrl) ?>" class="active">Log in</a>
            <a href="<?= $BASE_URL ?>citizenreports.php">Reports</a>
            <a href="<?= $BASE_URL ?>citizenrepform.php">Requests</a>
            <a href="<?= $BASE_URL ?>about.php">About</a>
        </div>
        <div class="nav-divider"></div>
        <div class="nav-actions">
            <div class="desktop-clock" id="desktopClock"></div>
            <button class="nav-btn dark-mode-btn dark-toggle" id="darkModeBtn" title="Toggle Dark Mode">
                <span class="dark-icon">🌙</span>
                <span class="light-icon" style="display:none;">☀️</span>
            </button>
        </div>
    </div>
</header>

<!-- ── Mobile Top Bar ──────────────────────────────────────────────────── -->
<div class="mobile-top-nav" style="display:none;">
    <a href="https://infragovservices.com/" target="_blank" rel="noopener noreferrer">
        <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo">
    </a>
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn dark-mode-btn mobile-dark-mode-btn" id="mobileDarkModeBtn" title="Toggle Dark Mode">
        <span class="dark-icon">🌙</span>
        <span class="light-icon" style="display:none;">☀️</span>
    </button>
</div>

<!-- ── Main ─────────────────────────────────────────────────────────────── -->
<div class="verify-wrapper">
    <div class="verify-card">
        <?php if ($state === 'success'): ?>

            <div class="icon-ring success">✅</div>
            <h1 class="verify-title">Email Verified!</h1>
            <p class="verify-subtitle">
                <?php if ($firstName): ?>
                    Welcome, <strong><?= htmlspecialchars($firstName) ?></strong>!<br>
                <?php endif; ?>
                Your account has been successfully activated.<br>
                You can now sign in to the LGU Portal.
            </p>
            <div class="countdown-strip">
                <i class="fas fa-circle-notch fa-spin" style="color:#2b6cb0;"></i>
                Redirecting to Login in&nbsp;<span id="countdown-num"><?= $redirectDelay ?></span>s
            </div>
            <div class="progress-track">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn-verify primary">
                <i class="fas fa-sign-in-alt"></i> Go to Login Now
            </a>

        <?php elseif ($state === 'already_verified'): ?>

            <div class="icon-ring already">✔️</div>
            <h1 class="verify-title">Already Verified</h1>
            <p class="verify-subtitle">
                <?php if ($firstName): ?>
                    Hi <strong><?= htmlspecialchars($firstName) ?></strong>,<br>
                <?php endif; ?>
                This account has already been verified.<br>
                You can go ahead and log in.
            </p>
            <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn-verify primary">
                <i class="fas fa-sign-in-alt"></i> Go to Login
            </a>

        <?php elseif ($state === 'expired'): ?>

            <div class="icon-ring expired">⏳</div>
            <h1 class="verify-title">Link Expired</h1>
            <p class="verify-subtitle">
                Your verification link has expired.<br>
                Please ask an administrator to resend your invitation,<br>
                or contact support for assistance.
            </p>
            <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn-verify primary">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
            <div class="divider"></div>
            <p class="help-text">Need help? <a href="<?= htmlspecialchars($BASE_URL) ?>about.php">Contact Support</a></p>

        <?php else: /* invalid */ ?>

            <div class="icon-ring invalid">❌</div>
            <h1 class="verify-title">Invalid Link</h1>
            <p class="verify-subtitle">
                This verification link is invalid or has already been used.<br>
                If you haven't verified yet, please check your email for the original link or contact support.
            </p>
            <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn-verify primary">
                <i class="fas fa-sign-in-alt"></i> Go to Login
            </a>
            <div class="divider"></div>
            <p class="help-text">Need help? <a href="<?= htmlspecialchars($BASE_URL) ?>about.php">Contact Support</a></p>

        <?php endif; ?>

    </div>
</div>

<?php include 'citizen_global.php'; ?>

<script>
// ── Dark Mode ─────────────────────────────────────────────────────────────
(function(){
    const btns = [
        document.getElementById('darkModeBtn'),
        document.getElementById('mobileDarkModeBtn')
    ].filter(Boolean);
    const html = document.documentElement;

    function apply(dark, anim) {
        dark ? html.setAttribute('data-theme','dark') : html.removeAttribute('data-theme');
        localStorage.setItem('theme',        dark ? 'dark' : 'light');
        localStorage.setItem('theme_backup', dark ? 'dark' : 'light');
        btns.forEach(b => {
            b.querySelector('.dark-icon').style.display  = dark ? 'none'   : 'inline';
            b.querySelector('.light-icon').style.display = dark ? 'inline' : 'none';
            if (anim) {
                b.classList.add('active');
                setTimeout(() => b.classList.remove('active'), 500);
            }
        });
    }

    let saved = localStorage.getItem('theme') || localStorage.getItem('theme_backup') || 'light';
    if (saved !== 'dark' && saved !== 'light') saved = 'light';
    apply(saved === 'dark', false);

    btns.forEach(b => b.addEventListener('click', () =>
        apply(html.getAttribute('data-theme') !== 'dark', true)
    ));
})();

// ── Clock ─────────────────────────────────────────────────────────────────
(function(){
    let t = <?= time() * 1000 ?>;
    function tick(){
        const n  = new Date(t);
        const dc = document.getElementById('desktopClock');
        const mc = document.getElementById('mobileClock');
        const d  = n.toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
        const tm = n.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',second:'2-digit',hour12:true});
        if (dc) dc.innerHTML = `<span class="date-part">${d}</span>&nbsp;&nbsp;&nbsp;<span class="time-part">${tm}</span>`;
        if (mc) mc.textContent = tm;
        t += 1000;
    }
    tick();
    setInterval(tick, 1000);
})();

// ── Countdown + redirect (success only) ──────────────────────────────────
<?php if ($state === 'success'): ?>
(function(){
    const TOTAL   = <?= $redirectDelay ?>;
    const target  = <?= json_encode($loginUrl) ?>;
    let   left    = TOTAL;
    const numEl   = document.getElementById('countdown-num');
    const fill    = document.getElementById('progressFill');

    // Kick off the CSS transition on the next frame
    requestAnimationFrame(() => {
        if (fill) {
            fill.style.transition = 'width 1s linear';
            fill.style.width = ((TOTAL - 1) / TOTAL * 100) + '%';
        }
    });

    const timer = setInterval(() => {
        left--;
        if (numEl) numEl.textContent = left;
        if (fill)  fill.style.width  = (left / TOTAL * 100) + '%';
        if (left <= 0) {
            clearInterval(timer);
            window.location.href = target;
        }
    }, 1000);
})();
<?php endif; ?>
</script>

</body>
</html>