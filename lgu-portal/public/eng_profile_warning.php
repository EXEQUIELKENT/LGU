<?php
/**
 * eng_profile_warning.php
 * ─────────────────────────────────────────────────────────────────
 * Reusable include for any page that has a sidebar with #profileIconBtn.
 *
 * What it does:
 *  • Queries engineer_profiles to detect an incomplete profile
 *  • Renders the CSS (red dot + warning popup)
 *  • Renders the warning popup HTML div
 *  • Renders the JS that:
 *      – injects the red pulse dot into #profileIconBtn
 *      – shows the popup permanently (always visible, not hover-only)
 *      – repositions on sidebar toggle / window resize
 *      – falls back to the normal tooltip when the profile is complete
 *
 * Usage — add ONE line right after <div id="sidebarNavTooltip"> on any page:
 *   <?php include 'eng_profile_warning.php'; ?>
 *
 * Prerequisites (already present on every sidebar page):
 *   • session_start() called
 *   • $conn (mysqli) available
 *   • $_SESSION['employee_role'] and $_SESSION['employee_id'] set
 * ─────────────────────────────────────────────────────────────────
 */

// ── 1. Detect incomplete engineer profile ────────────────────────
$_engIsEngineer  = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'engineer';
$_engUserId      = (int)($_SESSION['employee_id'] ?? 0);
$_engIncomplete  = false;

if ($_engIsEngineer && $_engUserId > 0) {
    $epChk = $conn->prepare(
        "SELECT full_name, engineering_discipline
         FROM engineer_profiles
         WHERE user_id = ?"
    );
    $epChk->bind_param("i", $_engUserId);
    $epChk->execute();
    $epChkResult = $epChk->get_result();

    if ($epChkResult->num_rows === 0) {
        $_engIncomplete = true;
    } else {
        $epRow = $epChkResult->fetch_assoc();
        if (
            empty(trim($epRow['full_name']             ?? '')) ||
            empty(trim($epRow['engineering_discipline'] ?? ''))
        ) {
            $_engIncomplete = true;
        }
    }
    $epChk->close();
}
?>

<?php if ($_engIsEngineer): ?>
<!-- ═══════════════════════════════════════════════════════
     Engineer Profile Warning — eng_profile_warning.php
════════════════════════════════════════════════════════ -->
<style>
/* ── Red pulse dot on the profile avatar ── */
.profile-incomplete-dot {
    display: none;
    position: absolute;
    top: 2px;
    right: 2px;
    width: 11px;
    height: 11px;
    background: #ef4444;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
    animation: eng-dot-pulse 1.6s infinite;
    z-index: 10;
    pointer-events: none;
}
.profile-incomplete-dot.visible { display: block; }

@keyframes eng-dot-pulse {
    0%   { box-shadow: 0 0 0 0   rgba(239, 68, 68, 0.7); }
    70%  { box-shadow: 0 0 0 7px rgba(239, 68, 68, 0);   }
    100% { box-shadow: 0 0 0 0   rgba(239, 68, 68, 0);   }
}

/* ── Warning popup ── */
#engProfileWarningPop {
    position: fixed;
    z-index: 6000;
    left: 0;
    top: 0;
    background: linear-gradient(135deg, #ef4444, #b91c1c);
    color: #fff;
    border-radius: 8px;
    padding: 7px 11px;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.4;
    width: 175px;
    max-width: 175px;
    white-space: normal;
    word-wrap: break-word;
    box-shadow: 0 4px 16px rgba(239, 68, 68, 0.35);
    pointer-events: none;
    opacity: 0;
    display: none;
    transform: scale(0.97);
    transition: opacity 0.22s, transform 0.22s;
}
#engProfileWarningPop.visible {
    opacity: 1;
    display: block;
    transform: scale(1);
}
/* Upward-pointing arrow (popup sits below the button) */
#engProfileWarningPop::before {
    content: "";
    position: absolute;
    top: -8px;
    left: 16px;
    border-width: 0 7px 8px 7px;
    border-style: solid;
    border-color: transparent transparent #ef4444 transparent;
}
#engProfileWarningPop .eng-warn-title {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 5px;
}
#engProfileWarningPop .eng-warn-body {
    font-size: 10px;
    font-weight: 500;
    opacity: 0.93;
}
</style>

<!-- Warning popup div (placed after #sidebarNavTooltip) -->
<div id="engProfileWarningPop">
    <div class="eng-warn-title">
        <i class="fas fa-exclamation-circle"></i> Profile Incomplete
    </div>
    <div class="eng-warn-body">
        Please complete your Engineer Profile to ensure proper assignment of reports.
    </div>
</div>

<script>
(function () {
    // ── Config ────────────────────────────────────────────────────
    var INCOMPLETE = <?= $_engIncomplete ? 'true' : 'false' ?>;

    // ── Wait for DOM ready ────────────────────────────────────────
    function onReady(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    onReady(function () {
        var profileBtn  = document.getElementById('profileIconBtn');
        var warningPop  = document.getElementById('engProfileWarningPop');
        var sidebarEl   = document.getElementById('sidebarNav');
        var toggleBtn   = document.getElementById('sidebarToggle');

        if (!profileBtn || !warningPop) return;

        // ── Always inject the red dot into the profile button ─────
        if (INCOMPLETE) {
            var dot = document.createElement('span');
            dot.className = 'profile-incomplete-dot visible';
            dot.id = 'engIncompleteDot';
            profileBtn.appendChild(dot);
        }

        // ── Position popup directly below the profile button ──────
        function positionPopup() {
            var rect = profileBtn.getBoundingClientRect();
            warningPop.style.left = rect.left + 'px';
            warningPop.style.top  = (rect.bottom + 10 + window.scrollY) + 'px';
        }

        // ── Show / hide helpers ───────────────────────────────────
        function showPopup() {
            positionPopup();
            warningPop.classList.add('visible');
        }
        function hidePopup() {
            warningPop.classList.remove('visible');
        }

        function isMobile() {
            return window.innerWidth <= 900;
        }

        if (INCOMPLETE) {

            function update() {
                if (isMobile()) {
                    if (sidebarEl && sidebarEl.classList.contains('mobile-active')) {
                        // Wait for the slide-in CSS transition to finish (transition: left .35s)
                        setTimeout(function () {
                            positionPopup();
                            warningPop.classList.add('visible');
                        }, 380);
                    } else {
                        warningPop.classList.remove('visible');
                    }
                } else {
                    // Desktop — always shown, reposition
                    positionPopup();
                    warningPop.classList.add('visible');
                }
            }

            // Desktop collapse/expand toggle
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    setTimeout(update, 320);
                });
            }

            // Watch sidebar class changes via MutationObserver.
            // This fires AFTER mobile-active is toggled by any handler
            // (admin_scripts.php, sched.php, or anything else),
            // so we always read the correct final state.
            if (sidebarEl) {
                var observer = new MutationObserver(function () {
                    if (isMobile()) update();
                });
                observer.observe(sidebarEl, { attributes: true, attributeFilter: ['class'] });
            }

            window.addEventListener('resize', function () {
                update();
            });

            // Initial state
            update();

        } else {
            // Profile is complete — restore the normal collapsed-sidebar tooltip
            var sidebarNavTooltip = document.getElementById('sidebarNavTooltip');

            function showNormalTooltip() {
                if (!sidebarEl || !sidebarEl.classList.contains('collapsed')) return;
                if (!sidebarNavTooltip) return;
                var text = profileBtn.getAttribute('data-tooltip') || 'Profile';
                var rect = profileBtn.getBoundingClientRect();
                var sbRect = sidebarEl.getBoundingClientRect();
                sidebarNavTooltip.textContent = text;
                sidebarNavTooltip.classList.remove('logout-pop');
                sidebarNavTooltip.style.display = 'block';
                sidebarNavTooltip.style.left = (sbRect.right + 15) + 'px';
                sidebarNavTooltip.style.top  = (rect.top + rect.height / 2 + window.scrollY) + 'px';
                setTimeout(function () { sidebarNavTooltip.classList.add('active'); }, 5);
            }
            function hideNormalTooltip() {
                if (!sidebarNavTooltip) return;
                sidebarNavTooltip.classList.remove('active', 'logout-pop');
                setTimeout(function () { sidebarNavTooltip.style.display = 'none'; }, 150);
            }

            profileBtn.addEventListener('mouseenter', showNormalTooltip);
            profileBtn.addEventListener('focus',      showNormalTooltip);
            profileBtn.addEventListener('mouseleave', hideNormalTooltip);
            profileBtn.addEventListener('blur',       hideNormalTooltip);
        }
    });
})();
</script>
<?php endif; ?>