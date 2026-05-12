<?php
// complaint/feedback_popup.php
// Include this at the bottom of homepage.php: <?php include 'feedback_popup.php'; ?>
// Requires: user must be logged in (session active)
if (empty($_SESSION['user_id']) && empty($_SESSION['staff_id'])) return;
?>

<!-- ══════════════ FEEDBACK POPUP ══════════════ -->
<style>
#fb-overlay {
    display: none; position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,0.55); backdrop-filter: blur(4px);
    align-items: center; justify-content: center;
}
#fb-overlay.active { display: flex; animation: fbFadeIn .3s ease; }
@keyframes fbFadeIn { from{opacity:0} to{opacity:1} }

#fb-card {
    background: #fff; border-radius: 20px; width: 90%; max-width: 460px;
    padding: 36px 32px 28px; box-shadow: 0 24px 80px rgba(0,0,0,0.35);
    position: relative; animation: fbSlideUp .35s cubic-bezier(.22,1,.36,1);
}
@keyframes fbSlideUp { from{transform:translateY(40px);opacity:0} to{transform:translateY(0);opacity:1} }

#fb-card .fb-close {
    position: absolute; top: 16px; right: 18px; background: none; border: none;
    font-size: 20px; cursor: pointer; color: #aaa; line-height: 1;
}
#fb-card .fb-close:hover { color: #333; }

#fb-card .fb-icon { text-align: center; font-size: 48px; margin-bottom: 10px; }
#fb-card h2 { text-align: center; font-size: 20px; font-weight: 700; color: #1a1a2e; margin-bottom: 6px; }
#fb-card .fb-sub { text-align: center; font-size: 13.5px; color: #666; margin-bottom: 20px; line-height: 1.6; }
#fb-card .fb-ticket { text-align:center; font-size:12px; color:#888; margin-bottom:20px; }
#fb-card .fb-ticket span { background:#f0f4ff; padding:3px 10px; border-radius:20px; color:#3b5bdb; font-weight:600; }

/* Stars */
.fb-stars { display: flex; justify-content: center; gap: 8px; margin-bottom: 18px; }
.fb-stars input { display: none; }
.fb-stars label {
    font-size: 36px; cursor: pointer; color: #ddd;
    transition: color .15s, transform .15s;
}
.fb-stars input:checked ~ label,
.fb-stars label:hover,
.fb-stars label:hover ~ label { color: #fcc419; }
/* reverse trick for CSS-only star rating */
.fb-stars { flex-direction: row-reverse; }
.fb-stars label:hover,
.fb-stars label:hover ~ label,
.fb-stars input:checked ~ label { color: #fcc419; transform: scale(1.15); }

#fb-comment {
    width: 100%; border: 1.5px solid #e0e0e0; border-radius: 10px;
    padding: 11px 14px; font-size: 13.5px; font-family: inherit;
    resize: vertical; min-height: 80px; outline: none; margin-bottom: 18px;
    transition: border-color .2s;
}
#fb-comment:focus { border-color: #3b5bdb; }

#fb-submit {
    width: 100%; padding: 13px; border: none; border-radius: 11px;
    background: linear-gradient(135deg, #1a6ef5, #3b5bdb);
    color: #fff; font-size: 15px; font-weight: 700; cursor: pointer;
    transition: transform .18s, box-shadow .2s;
    box-shadow: 0 4px 16px rgba(59,91,219,0.35);
}
#fb-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(59,91,219,0.45); }
#fb-submit:disabled { opacity: .6; cursor: not-allowed; transform: none; }

/* Countdown timer */
#fb-countdown { text-align: center; margin-bottom: 14px; font-size: 13px; color: #888; }
#fb-countdown strong { color: #e03131; }
#fb-countdown .fb-cd-bar {
    margin: 8px auto 0; width: 80%; height: 5px;
    background: #f0f0f0; border-radius: 99px; overflow: hidden;
}
#fb-countdown .fb-cd-fill {
    height: 100%; background: linear-gradient(90deg, #e03131, #fcc419);
    border-radius: 99px; transition: width 1s linear;
}

/* Skip link */
.fb-skip { text-align: center; margin-top: 12px; font-size: 12px; color: #bbb; }
.fb-skip a { color: #aaa; text-decoration: underline; cursor: pointer; }
.fb-skip a:hover { color: #666; }
</style>

<div id="fb-overlay" role="dialog" aria-modal="true" aria-label="Feedback popup">
  <div id="fb-card">
    <button class="fb-close" id="fb-close-btn" aria-label="Close">✕</button>
    <div class="fb-icon">⭐</div>
    <h2>How was our service?</h2>
    <p class="fb-sub">Your ticket has been resolved. A quick rating helps us improve!</p>
    <div class="fb-ticket">Ticket: <span id="fb-ticket-label">—</span></div>

    <div class="fb-stars">
      <input type="radio" name="fb_rating" id="s5" value="5"><label for="s5" title="Excellent">★</label>
      <input type="radio" name="fb_rating" id="s4" value="4"><label for="s4" title="Good">★</label>
      <input type="radio" name="fb_rating" id="s3" value="3"><label for="s3" title="Okay">★</label>
      <input type="radio" name="fb_rating" id="s2" value="2"><label for="s2" title="Poor">★</label>
      <input type="radio" name="fb_rating" id="s1" value="1"><label for="s1" title="Very Poor">★</label>
    </div>

    <div id="fb-countdown" style="display:none">
      Auto-submitting 5 ⭐ in <strong id="fb-cd-num">—</strong>s
      <div class="fb-cd-bar"><div class="fb-cd-fill" id="fb-cd-fill" style="width:100%"></div></div>
    </div>

    <textarea id="fb-comment" placeholder="Optional: Tell us more about your experience…"></textarea>

    <button id="fb-submit" disabled>Submit Feedback</button>
    <div class="fb-skip"><a id="fb-skip-link">Skip for now</a></div>
  </div>
</div>

<script>
(function () {
    'use strict';

    // ── session key — one per user so incognito doesn't share flags ──────────
    const userId = <?= json_encode(
        !empty($_SESSION['user_id'])  ? 'u'.$_SESSION['user_id'] :
        (!empty($_SESSION['staff_id']) ? 's'.$_SESSION['staff_id'] : 'guest')
    ) ?>;
    const SS_KEY = 'fb_shown_' + userId;

    let ticketId = null;
    let cdTimer  = null;
    let cdTotal  = 0;
    let cdLeft   = 0;

    // ── 1. Check if we should show popup ─────────────────────────────────────
    function init() {
        // Already shown this session?
        if (sessionStorage.getItem(SS_KEY)) return;

        fetch('feedback_api.php?action=check', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.pending) return;
                ticketId = data.ticket_id;
                document.getElementById('fb-ticket-label').textContent =
                    data.ticket_id + (data.ticket_title ? ' — ' + data.ticket_title : '');

                if (data.auto_ready) {
                    // Pre-select 5 stars, show countdown
                    document.getElementById('s5').checked = true;
                    enableSubmit();
                    startCountdown(data.remaining_secs > 0 ? data.remaining_secs : 30);
                } else {
                    // Show remaining time, no pre-selection
                    const h = Math.floor(data.remaining_secs / 3600);
                    const m = Math.floor((data.remaining_secs % 3600) / 60);
                    const cdEl = document.getElementById('fb-countdown');
                    cdEl.style.display = 'block';
                    cdEl.innerHTML = `<span>Auto-rating available in <strong>${h}h ${m}m</strong> (after 8 office hours)</span>`;
                }

                setTimeout(showPopup, 1500);
            })
            .catch(() => {}); // Silently fail — don't break homepage
    }

    // ── 2. Show / hide ────────────────────────────────────────────────────────
    function showPopup() {
        document.getElementById('fb-overlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function hidePopup() {
        document.getElementById('fb-overlay').classList.remove('active');
        document.body.style.overflow = '';
        if (cdTimer) { clearInterval(cdTimer); cdTimer = null; }
        sessionStorage.setItem(SS_KEY, '1');
        fetch('feedback_mark_shown.php', { method: 'POST', credentials: 'same-origin' }).catch(() => {});
    }

    // ── 3. Star rating → enable submit ────────────────────────────────────────
    function enableSubmit() {
        document.getElementById('fb-submit').disabled = false;
    }
    document.querySelectorAll('input[name="fb_rating"]').forEach(r => {
        r.addEventListener('change', () => {
            enableSubmit();
            if (cdTimer) { clearInterval(cdTimer); cdTimer = null; }
            document.getElementById('fb-countdown').style.display = 'none';
        });
    });

    // ── 4. Countdown (auto-submit) ────────────────────────────────────────────
    function startCountdown(seconds) {
        cdTotal = seconds;
        cdLeft  = seconds;
        const cdEl   = document.getElementById('fb-countdown');
        const cdNum  = document.getElementById('fb-cd-num');
        const cdFill = document.getElementById('fb-cd-fill');
        cdEl.style.display = 'block';
        cdNum.textContent  = cdLeft;

        cdTimer = setInterval(() => {
            cdLeft--;
            cdNum.textContent = cdLeft;
            cdFill.style.width = (cdLeft / cdTotal * 100) + '%';
            if (cdLeft <= 0) {
                clearInterval(cdTimer); cdTimer = null;
                submitFeedback(true);
            }
        }, 1000);
    }

    // ── 5. Submit ─────────────────────────────────────────────────────────────
    function submitFeedback(auto = false) {
        const ratingEl = document.querySelector('input[name="fb_rating"]:checked');
        const rating   = ratingEl ? ratingEl.value : (auto ? 5 : 0);
        const comment  = document.getElementById('fb-comment').value.trim();

        if (!auto && !rating) {
            alert('Please select a star rating before submitting.');
            return;
        }

        const btn = document.getElementById('fb-submit');
        btn.disabled = true;
        btn.textContent = 'Submitting…';

        const body = new URLSearchParams({
            action:    'submit',
            ticket_id: ticketId,
            rating:    rating,
            comment:   comment,
            auto:      auto ? 1 : 0,
        });

        fetch('feedback_api.php', { method: 'POST', body, credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    btn.textContent = '✅ Thank you!';
                    btn.style.background = 'linear-gradient(135deg,#2f9e44,#40c057)';
                    setTimeout(hidePopup, 1200);
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Submit Feedback';
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = 'Submit Feedback';
            });
    }

    // ── 6. Event listeners ────────────────────────────────────────────────────
    document.getElementById('fb-submit').addEventListener('click',    () => submitFeedback(false));
    document.getElementById('fb-close-btn').addEventListener('click', hidePopup);
    document.getElementById('fb-skip-link').addEventListener('click', hidePopup);
    document.getElementById('fb-overlay').addEventListener('click', e => {
        if (e.target === document.getElementById('fb-overlay')) hidePopup();
    });

    // ── 7. Kick off ───────────────────────────────────────────────────────────
    document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', init)
        : init();
})();
</script>