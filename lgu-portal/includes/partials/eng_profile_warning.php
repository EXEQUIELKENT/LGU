<?php
/**
 * eng_profile_warning.php
 * ─────────────────────────────────────────────────────────────────
 * Reusable include for any page that has a sidebar with #profileIconBtn.
 *
 * What it does:
 *  • [Engineer]      Queries engineer_profiles to detect an incomplete profile
 *                    → red pulse dot + red popup until profile is filled in
 *  • [Area Engineer] Queries engineer_profiles to detect a missing district
 *                    → amber pulse dot + amber popup until district is set
 *  • Renders CSS, popup HTML, and the JS that:
 *      – injects the correct pulse dot into #profileIconBtn
 *      – shows the popup permanently (always visible, not hover-only)
 *      – repositions on sidebar toggle / window resize
 *      – falls back to the normal tooltip when everything is set
 *
 * Usage — add ONE line right after <div id="sidebarNavTooltip"> on any page:
 *   <?php include __DIR__ . '/../../includes/partials/eng_profile_warning.php'; ?>
 *
 * Prerequisites (already present on every sidebar page):
 *   • session_start() called
 *   • $conn (mysqli) available
 *   • $_SESSION['employee_role'] and $_SESSION['employee_id'] set
 * ─────────────────────────────────────────────────────────────────
 */

// ── 1. Detect incomplete engineer profile ────────────────────────
$_engIsEngineer     = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'engineer';
$_engIsAreaEngineer = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'area engineer';
$_engUserId         = (int)($_SESSION['employee_id'] ?? 0);
$_engIncomplete     = false;
$_aeDistrictMissing = false;

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

// ── 2. Detect missing district (Area Engineer) ───────────────────
if ($_engIsAreaEngineer && $_engUserId > 0) {
    $aeChk = $conn->prepare(
        "SELECT district FROM engineer_profiles WHERE user_id = ?"
    );
    $aeChk->bind_param("i", $_engUserId);
    $aeChk->execute();
    $aeChkResult = $aeChk->get_result();

    if ($aeChkResult->num_rows === 0) {
        $_aeDistrictMissing = true;
    } else {
        $aeRow = $aeChkResult->fetch_assoc();
        $_aeDistrictMissing = empty(trim($aeRow['district'] ?? ''));
    }
    $aeChk->close();
}
?>

<?php if ($_engIsEngineer || $_engIsAreaEngineer): ?>
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

/* ── Amber pulse dot variant (Area Engineer district missing) ── */
.profile-incomplete-dot.amber {
    background: #f59e0b;
    box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7);
    animation: ae-dot-pulse 1.6s infinite;
}
@keyframes ae-dot-pulse {
    0%   { box-shadow: 0 0 0 0   rgba(245, 158, 11, 0.7); }
    70%  { box-shadow: 0 0 0 7px rgba(245, 158, 11, 0);   }
    100% { box-shadow: 0 0 0 0   rgba(245, 158, 11, 0);   }
}

/* ── Warning popup (shared base) ── */
#engProfileWarningPop,
#aeDistrictWarningPop {
    position: fixed;
    z-index: 6000;
    left: 0;
    top: 0;
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
    pointer-events: none;
    opacity: 0;
    display: none;
    transform: scale(0.97);
    transition: opacity 0.22s, transform 0.22s;
}
#engProfileWarningPop.visible,
#aeDistrictWarningPop.visible {
    opacity: 1;
    display: block;
    transform: scale(1);
}

/* ── Red theme (Engineer incomplete profile) ── */
#engProfileWarningPop {
    background: linear-gradient(135deg, #ef4444, #b91c1c);
    box-shadow: 0 4px 16px rgba(239, 68, 68, 0.35);
}
#engProfileWarningPop::before {
    content: "";
    position: absolute;
    top: -8px;
    left: 16px;
    border-width: 0 7px 8px 7px;
    border-style: solid;
    border-color: transparent transparent #ef4444 transparent;
}

/* ── Amber theme (Area Engineer missing district) ── */
#aeDistrictWarningPop {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    box-shadow: 0 4px 16px rgba(245, 158, 11, 0.35);
    pointer-events: auto; /* allow the profile link inside to be clicked */
}
#aeDistrictWarningPop::before {
    content: "";
    position: absolute;
    top: -8px;
    left: 16px;
    border-width: 0 7px 8px 7px;
    border-style: solid;
    border-color: transparent transparent #f59e0b transparent;
}

/* ── Popup inner elements (shared) ── */
#engProfileWarningPop .eng-warn-title,
#aeDistrictWarningPop .eng-warn-title {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 5px;
}
#engProfileWarningPop .eng-warn-body,
#aeDistrictWarningPop .eng-warn-body {
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

<!-- Area Engineer district warning popup -->
<div id="aeDistrictWarningPop">
    <div class="eng-warn-title">
        <i class="fas fa-map-marker-alt"></i> No District Set
    </div>
    <div class="eng-warn-body">
        Set your district in your <a href="profile.php#aeDistrictSection" style="color:#fff;text-decoration:underline;pointer-events:auto;">profile</a> to view and manage reports in your area.
    </div>
</div>

<script>
(function () {
    // ── Config ────────────────────────────────────────────────────
    var INCOMPLETE          = <?= $_engIncomplete      ? 'true' : 'false' ?>;
    var AE_DISTRICT_MISSING = <?= $_aeDistrictMissing  ? 'true' : 'false' ?>;
    var IS_AREA_ENGINEER    = <?= $_engIsAreaEngineer  ? 'true' : 'false' ?>;

    // ── Wait for DOM ready ────────────────────────────────────────
    function onReady(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    onReady(function () {
        var profileBtn       = document.getElementById('profileIconBtn');
        var warningPop       = document.getElementById('engProfileWarningPop');
        var aeDistrictPop    = document.getElementById('aeDistrictWarningPop');
        var sidebarEl        = document.getElementById('sidebarNav');
        var toggleBtn        = document.getElementById('sidebarToggle');

        if (!profileBtn) return;

        // ── Determine active popup and dot colour ─────────────────
        // Only one role is ever active; AE_DISTRICT_MISSING only
        // fires when IS_AREA_ENGINEER is true.
        var needsWarning = IS_AREA_ENGINEER ? AE_DISTRICT_MISSING : INCOMPLETE;
        var activePop    = IS_AREA_ENGINEER ? aeDistrictPop : warningPop;

        if (!activePop) return;

        // ── Inject pulse dot into the profile button ──────────────
        if (needsWarning) {
            var dot = document.createElement('span');
            dot.className = 'profile-incomplete-dot visible' +
                            (IS_AREA_ENGINEER ? ' amber' : '');
            dot.id = 'engIncompleteDot';
            profileBtn.appendChild(dot);
        }

        // ── Position popup directly below the profile button ──────
        function positionPopup() {
            var rect = profileBtn.getBoundingClientRect();
            activePop.style.left = rect.left + 'px';
            activePop.style.top  = (rect.bottom + 10 + window.scrollY) + 'px';
        }

        // ── Show / hide helpers ───────────────────────────────────
        function showPopup() {
            positionPopup();
            activePop.classList.add('visible');
        }
        function hidePopup() {
            activePop.classList.remove('visible');
        }

        function isMobile() {
            return window.innerWidth <= 900;
        }

        if (needsWarning) {

            function update() {
                if (isMobile()) {
                    if (sidebarEl && sidebarEl.classList.contains('mobile-active')) {
                        // Wait for the slide-in CSS transition to finish (transition: left .35s)
                        setTimeout(function () {
                            positionPopup();
                            activePop.classList.add('visible');
                        }, 380);
                    } else {
                        activePop.classList.remove('visible');
                    }
                } else {
                    // Desktop — always shown, reposition
                    positionPopup();
                    activePop.classList.add('visible');
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
            // Profile/district is set — restore the normal collapsed-sidebar tooltip
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