<?php
// complaint/my_ticket_detail.php 
session_start();

$allowedRoles = ['user', 'lecturer', 'dept_handler', 'admin', 'super_admin', 'report_viewer', 'staff'];
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowedRoles)) {
    header('Location: ../login.php');
    exit;
}

require '../db_connect.php';

$userName      = htmlspecialchars($_SESSION['user_name']  ?? 'User');
$userEmail     = htmlspecialchars($_SESSION['user_email'] ?? '');
$userRole      = $_SESSION['user_role'];
$userId = (int)(isset($_SESSION['user_id']) ? $_SESSION['user_id'] : (isset($_SESSION['staff_id']) ? $_SESSION['staff_id'] : 0));
$submitterType = ($userRole === 'user') ? 'user' : 'staff';

$ticketId = trim($_GET['id'] ?? '');
$ticket   = null;

if ($ticketId !== '') {
    $stmt = $conn->prepare("
        SELECT c.*, cat.category_name, d.dept_name, d.dept_id,
               u.email AS submitter_email,
               st.full_name AS submitter_staff_name, st.email AS submitter_staff_email, st.phone AS submitter_staff_phone
        FROM complaints c
        LEFT JOIN categories cat ON cat.category_id = c.category_id
        LEFT JOIN departments d ON d.dept_id = c.dept_id
        LEFT JOIN users u ON c.submitter_type = 'user' AND u.user_id = c.submitter_id
        LEFT JOIN staff st ON c.submitter_type = 'staff' AND st.staff_id = c.submitter_id
        WHERE c.ticket_id = ? AND c.submitter_id = ? AND c.submitter_type = ?
        LIMIT 1
    ");
    $stmt->bind_param("sis", $ticketId, $userId, $submitterType);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Fetch "handled by" — only the assigned staff
$handledBy = [];
if ($ticket && !empty($ticket['assigned_to'])) {
    $hs = $conn->prepare("SELECT full_name, role FROM staff WHERE staff_id = ? LIMIT 1");
    $hs->bind_param("i", $ticket['assigned_to']);
    $hs->execute();
    $handledBy = $hs->get_result()->fetch_all(MYSQLI_ASSOC);
    $hs->close();
}

// Fetch replies (read-only)
$replies = [];
if ($ticket) {
    $rq = $conn->prepare("
        SELECT reply_id, sender_id, sender_name, sender_role, message, attachment_path, created_at
        FROM ticket_replies WHERE ticket_id = ? ORDER BY created_at ASC
    ");
    $rq->bind_param("s", $ticketId);
    $rq->execute();
    $replies = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
    $rq->close();
}

// Fetch status-change logs to show inline in messages
$statusLogs = [];
if ($ticket) {
    $lq = $conn->prepare("
        SELECT changed_by, new_status, changed_at
        FROM ticket_logs
        WHERE ticket_id = ? AND field_changed = 'status'
        ORDER BY changed_at ASC
    ");
    $lq->bind_param("s", $ticketId);
    $lq->execute();
    $statusLogs = $lq->get_result()->fetch_all(MYSQLI_ASSOC);
    $lq->close();
}

// Merge replies and status logs into a single sorted timeline
$timeline = [];

foreach ($replies as $r) {
    $timeline[] = ['type' => 'reply', 'time' => $r['created_at'], 'data' => $r];
}
foreach ($statusLogs as $l) {
    $timeline[] = ['type' => 'status_change', 'time' => $l['changed_at'], 'data' => $l];
}

usort($timeline, function($a, $b) {
    $diff = strtotime($a['time']) - strtotime($b['time']);
    if ($diff !== 0) return $diff;
    // Same timestamp: status_change always comes before reply
    if ($a['type'] === 'status_change' && $b['type'] === 'reply') return -1;
    if ($a['type'] === 'reply' && $b['type'] === 'status_change') return 1;
    return 0;
});

$feedback = null;
if ($ticket && strtolower($ticket['status']) === 'closed') {
    $fq = $conn->prepare("SELECT tf.rating, tf.comment, tf.is_auto_submitted, tf.created_at FROM ticket_feedback tf WHERE tf.ticket_id = ? LIMIT 1");
    $fq->bind_param("s", $ticketId);
    $fq->execute();
    $feedback = $fq->get_result()->fetch_assoc();
    $fq->close();
}

function feedbackEmojiLabel($rating) {
    switch ($rating) {
        case 1: return 'Very Unsatisfied';
        case 2: return 'Unsatisfied';
        case 3: return 'Neutral';
        case 4: return 'Satisfied';
        case 5: return 'Very Satisfied';
        default: return 'Unknown';
    }
}

function feedbackRatingColors($rating) {
    switch ($rating) {
        case 1: return ['#FEF2F2', '#DC2626'];
        case 2: return ['#FFF7ED', '#F97316'];
        case 3: return ['#FEFCE8', '#EAB308'];
        case 4: return ['#F0FDF4', '#22C55E'];
        case 5: return ['#ECFDF5', '#16A34A'];
        default: return ['#F3F4F6', '#6B7280'];
    }
}

function getInitials(string $name): string {
    $p = explode(' ', trim($name));
    $i = strtoupper(substr($p[0], 0, 1));
    if (count($p) > 1) $i .= strtoupper(substr($p[count($p)-1], 0, 1));
    return $i;
}
function isImageAttachment(?string $path): bool {
    if (empty($path)) return false;
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp']);
}

function fileCardMeta($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf':  return ['label' => 'PDF', 'bg' => '#FEF2F2', 'color' => '#DC2626'];
        case 'doc':
        case 'docx': return ['label' => 'DOC', 'bg' => '#EFF6FF', 'color' => '#1D4ED8'];
        case 'xls':
        case 'xlsx': return ['label' => 'XLS', 'bg' => '#F0FDF4', 'color' => '#15803D'];
        case 'txt':  return ['label' => 'TXT', 'bg' => '#F9FAFB', 'color' => '#374151'];
        default:     return ['label' => strtoupper($ext) ?: 'FILE', 'bg' => '#F3F4F6', 'color' => '#6B7280'];
    }
}

$statusMap = [
    'open'        => ['label' => 'Open',        'color' => '#854F0B', 'bg' => '#FAEEDA', 'dot' => '#EF9F27'],
    'in_progress' => ['label' => 'In Progress',  'color' => '#0C447C', 'bg' => '#E6F1FB', 'dot' => '#378ADD'],
    'closed'      => ['label' => 'Closed',       'color' => '#27500A', 'bg' => '#EAF3DE', 'dot' => '#1D9E75'],
];
$priorityMap = [
    'low'    => ['label' => 'Low',    'color' => '#27500A', 'bg' => '#EAF3DE'],
    'medium' => ['label' => 'Medium', 'color' => '#854F0B', 'bg' => '#FAEEDA'],
    'high'   => ['label' => 'High',   'color' => '#A32D2D', 'bg' => '#FCEBEB'],
];

$curStatus   = $statusMap[($ticket['status']   ?? 'open')]   ?? $statusMap['open'];
$curPriority = $priorityMap[($ticket['priority'] ?? 'medium')] ?? $priorityMap['medium'];

// Name and phone are no longer stored in DB — use session for current user,
// or show anonymised label for others viewing the ticket.
$submitterName  = ($submitterType === 'user')
    ? ($_SESSION['user_name'] ?? 'User')        // name from Graph session
    : ($ticket['submitter_staff_name'] ?? '—');
$submitterEmail = ($ticket['submitter_email'] ?? $ticket['submitter_staff_email'] ?? '—');
$submitterPhone = '—';   // removed from DB (PDPA)
$submitterType2 = ($submitterType === 'user') ? 'User' : 'Staff';

$pageTitle    = 'My Ticket';
$pageSubtitle = date('l, d F Y');
$activeNav    = 'dashboard';

ob_start();
?>
<style>
/* ── PAGE WRAPPER ─────────────────────────────────────────── */
.tp-page {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: 100%;
  align-items: flex-start;
}

/* ── BREADCRUMB ───────────────────────────────────────────── */
.tp-breadcrumb {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--g500);
  flex-wrap: wrap;
}
.tp-breadcrumb a { color: var(--g500); text-decoration: none; }
.tp-breadcrumb a:hover { color: var(--blue); }
.tp-sep { color: var(--g300); }

/* ── BACK BUTTON ──────────────────────────────────────────── */
.tp-back-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 16px;
  border-radius: 9px;
  border: 0.5px solid var(--g200);
  background: white;
  color: var(--g700);
  font-size: 13px;
  font-weight: 500;
  text-decoration: none;
  transition: border-color .2s, background .2s, color .2s;
  font-family: 'DM Sans', sans-serif;
}
/* FIX 1: hover colour #2d5986 */
.tp-back-btn:hover {
  border-color: #2d5986;
  background: #2d5986;
  color: #ffffff;
}
.tp-back-btn svg {
  width: 14px; height: 14px;
  fill: none; stroke: currentColor; stroke-width: 2;
}

/* ── MAIN CARD ────────────────────────────────────────────── */
.tp-card {
  background: white;
  border-radius: 16px;
  border: 0.5px solid var(--g200);
  padding: 28px 32px;
  box-shadow: 0 1px 4px rgba(0,0,0,.04);
  width: 100%;
  max-width: 1000px; /* was 860px */
  align-self: center;
  box-sizing: border-box;
}

/* ── NOT-FOUND ────────────────────────────────────────────── */
.tp-notfound {
  text-align: center;
  padding: 80px 24px;
  max-width: 440px;
  align-self: center;
}
.tp-notfound svg { width: 40px; height: 40px; fill: none; stroke: var(--g300); stroke-width: 1.5; margin: 0 auto 16px; display: block; }
.tp-notfound h2  { font-size: 22px; color: var(--g900); margin-bottom: 8px; }
.tp-notfound p   { font-size: 14px; color: var(--g500); line-height: 1.65; margin-bottom: 20px; }

/* ── TOP ROW ──────────────────────────────────────────────── */
.tp-top-row { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 22px; }
.tp-icon-title { display: flex; align-items: center; gap: 12px; }

/* FIX 2: ticket icon box — blue tinted background with coloured icon */
.tp-icon-box {
  width: 44px; height: 44px;
  border-radius: 12px;
  background: #E6F1FB;          /* blue-light tint */
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.tp-icon-box svg {
  width: 22px; height: 22px;
  fill: none;
  stroke: #0C447C;              /* deep blue */
  stroke-width: 1.8;
}

.tp-card-label { font-size: 15px; font-weight: 600; color: var(--g900); }
.tp-card-tid   { font-size: 12px; color: var(--g500); margin-top: 2px; font-family: monospace; }
/* Status dot */
.tp-status-badge {
  font-size: 12px;
  font-weight: 500;
  padding: 5px 12px 5px 10px;
  border-radius: 20px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.tp-status-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
  display: inline-block;
}

/* Reorganized meta grid — 2 rows of clean info blocks */
.tp-meta-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0;
  margin-bottom: 16px;
}
.tp-meta-cell {
  padding: 14px 18px;
}

.tp-meta-label { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--g400); margin-bottom: 5px; }
.tp-meta-value { font-size: 13.5px; font-weight: 500; color: var(--g900); line-height: 1.4; }
.tp-priority-badge { font-size: 12px; font-weight: 500; padding: 3px 12px; border-radius: 20px; display: inline-block; }

/* ── TITLE ROW ────────────────────────────────────────────── */
.tp-title-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 22px; flex-wrap: wrap; }
.tp-title     { font-size: 22px; font-weight: 600; color: var(--g900); line-height: 1.3; }
.tp-tid-pill  { background: var(--g100); color: var(--g500); font-size: 11px; padding: 4px 11px; border-radius: 6px; font-family: monospace; white-space: nowrap; flex-shrink: 0; }

/* ── META GRIDS ───────────────────────────────────────────── */


/* ── DESCRIPTION ─────────────────────────────────────────── */
.tp-desc-label {
  font-size: 11px; text-transform: uppercase;
  letter-spacing: .06em; color: var(--g400);
  margin-bottom: 2px;
}
/* FIX 3: tighter padding so text isn't lost at the bottom of a big box */
.tp-desc-box {
  background: transparent;
  border-radius: 10px;
  border: none;
  padding: 0;
  font-size: 13.5px;
  color: var(--g900);
  line-height: 1.4;
  white-space: pre-wrap;
  word-break: break-word;
  min-height: 0;
  display: block;
}
.tp-attach-link {
  display: inline-flex; align-items: center; gap: 6px;
  margin-top: 10px; font-size: 13px; color: var(--blue);
  font-weight: 500; text-decoration: none;
  padding: 6px 12px; background: var(--blue-light,#e6f1fb); border-radius: 7px;
}
.tp-attach-link svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; }

/* ── FILE CARD ────────────────────────────────────────────── */
.tp-file-card {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 6px;
  padding: 10px 14px;
  border-radius: 10px;
  text-decoration: none;
  max-width: 260px;
  transition: opacity .15s;
  background: white;
  border: 0.5px solid var(--g200);
  color: var(--g900);
}
.tp-msg-row.tp-me .tp-file-card {
  background: rgba(255,255,255,.15);
  border: 0.5px solid rgba(255,255,255,.25);
  color: white;
}
.tp-file-card:hover { opacity: .82; }

.tp-file-card-icon {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
  flex-shrink: 0;
  letter-spacing: .03em;
}
.tp-file-card-info { flex: 1; min-width: 0; }
.tp-file-card-name {
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  display: block;
}
.tp-file-card-meta {
  font-size: 11px;
  margin-top: 2px;
  opacity: .65;
}
.tp-file-card-dl {
  width: 14px;
  height: 14px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  flex-shrink: 0;
  opacity: .7;
}

/* ── DIVIDER ──────────────────────────────────────────────── */
.tp-divider { height: 0.5px; background: var(--g100); margin: 24px 0; }

/* ── SECTION HEADER ───────────────────────────────────────── */
.tp-section-header { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
.tp-section-header svg  { width: 15px; height: 15px; fill: none; stroke: var(--g500); stroke-width: 2; }
.tp-section-header span { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; font-weight: 600; color: var(--g500); }

/* ── SUBMITTER TABLE ──────────────────────────────────────── */
.tp-info-table { width: 100%; border-radius: 10px; border: 0.5px solid var(--g100); overflow: hidden; border-collapse: collapse; }
.tp-info-table td { padding: 12px 16px; font-size: 13px; border-bottom: 0.5px solid var(--g100); }
.tp-info-table tr:last-child td { border-bottom: none; }
.tp-info-table .tl { color: var(--g500); font-size: 11px; text-transform: uppercase; letter-spacing: .05em; background: var(--g50,#fafaf8); width: 18%; white-space: nowrap; }
.tp-info-table .tv { color: var(--g900); font-weight: 500; }

/* ── STAFF CARDS ──────────────────────────────────────────── */
.tp-staff-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(220px,1fr)); gap: 10px; }
.tp-staff-card {
  background: #f5f9ff;
  border: 1px solid #c2d9f0;
  border-radius: 10px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.tp-staff-avatar { width: 38px; height: 38px; border-radius: 50%; background: #B5D4F4; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: #0C447C; flex-shrink: 0; }
.tp-staff-name { font-size: 13px; font-weight: 500; color: var(--g900); }
.tp-staff-dept { font-size: 12px; color: var(--g500); margin-top: 2px; }
.tp-no-staff   { font-size: 13px; color: var(--g400); padding: 10px 0; }

/* ── MESSAGES ─────────────────────────────────────────────── */
.tp-messages-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.tp-msg-count-pill  { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; border-radius: 11px; background: var(--blue,#003B8E); color: white; font-size: 11px; font-weight: 700; padding: 0 7px; }
.tp-messages { display: flex; flex-direction: column; gap: 14px; min-height: 120px; max-height: 420px; overflow-y: auto; scroll-behavior: smooth; padding: 4px 0; }
.tp-messages::-webkit-scrollbar       { width: 3px; }
.tp-messages::-webkit-scrollbar-thumb { background: var(--g200); border-radius: 3px; }

.tp-empty-msg   { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; gap: 10px; text-align: center; }
.tp-empty-icon  { width: 44px; height: 44px; border-radius: 12px; background: var(--g100); display: flex; align-items: center; justify-content: center; }
.tp-empty-icon svg { width: 20px; height: 20px; fill: none; stroke: var(--g300); stroke-width: 1.6; }
.tp-empty-title { font-size: 14px; font-weight: 600; color: var(--g700); }
.tp-empty-sub   { font-size: 13px; color: var(--g400); max-width: 260px; line-height: 1.6; }

.tp-date-sep { display: flex; align-items: center; gap: 10px; margin: 4px 0; }
.tp-date-sep::before, .tp-date-sep::after { content: ''; flex: 1; height: 0.5px; background: var(--g200); }
.tp-date-sep span { font-size: 11px; color: var(--g400); white-space: nowrap; }

/* ── STATUS CHANGE NOTIFICATION ─────────────────────────── */
.tp-status-event {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 8px;
  margin: 2px 0;
  padding-left: 39px; /* aligns with message bubbles (avatar width 30px + gap 9px) */
}
.tp-status-event-pill {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
  border: 0.5px solid transparent;
}
.tp-status-event-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.tp-status-event-time {
  font-size: 11px;
  color: var(--g400);
  white-space: nowrap;
}

.tp-msg-row    { display: flex; gap: 9px; align-items: flex-end; }
.tp-msg-row.tp-me { flex-direction: row-reverse; }
.tp-avatar     { width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; }
.tp-avatar.av-me   { background: #B5D4F4; color: #0C447C; }
.tp-avatar.av-dept { background: #C0DD97; color: #27500A; }
.tp-msg-body   { max-width: 72%; display: flex; flex-direction: column; gap: 3px; }
.tp-msg-row.tp-me   .tp-msg-body { align-items: flex-end; }
.tp-msg-row.tp-dept .tp-msg-body { align-items: flex-start; }
.tp-msg-name   { font-size: 11px; color: var(--g400); }
.tp-bubble     { padding: 10px 14px; border-radius: 12px; font-size: 14px; line-height: 1.65; word-break: break-word; white-space: pre-wrap; }
.tp-msg-row.tp-me   .tp-bubble { background: var(--blue,#003B8E); color: white; border-radius: 12px 12px 3px 12px; }
.tp-msg-row.tp-dept .tp-bubble { background: var(--g100); color: var(--g900); border: 0.5px solid var(--g200); border-radius: 12px 12px 12px 3px; }

.tp-img-bubble { display: block; border-radius: 12px; overflow: hidden; max-width: 200px; width: 100%; cursor: pointer; position: relative; line-height: 0; }
.tp-msg-row.tp-me   .tp-img-bubble { border-radius: 12px 12px 3px 12px; }
.tp-msg-row.tp-dept .tp-img-bubble { border-radius: 12px 12px 12px 3px; }
.tp-img-bubble img   { width: 100%; height: auto; max-height: 180px; object-fit: cover; display: block; border-radius: inherit; transition: filter .2s; }
.tp-img-bubble:hover img { filter: brightness(.9); }
.tp-img-bubble::after { content: ''; position: absolute; bottom: 7px; right: 7px; width: 22px; height: 22px; background: rgba(0,0,0,.45) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2.5'%3E%3Cpolyline points='15 3 21 3 21 9'/%3E%3Cpolyline points='9 21 3 21 3 15'/%3E%3Cline x1='21' y1='3' x2='14' y2='10'/%3E%3Cline x1='3' y1='21' x2='10' y2='14'/%3E%3C/svg%3E") center/12px no-repeat; border-radius: 5px; backdrop-filter: blur(2px); }

.tp-file-link { display: inline-flex; align-items: center; gap: 6px; margin-top: 6px; font-size: 12px; font-weight: 500; text-decoration: none; padding: 6px 11px; border-radius: 8px; }
.tp-msg-row.tp-me   .tp-file-link { background: rgba(255,255,255,.2); color: white; border: 0.5px solid rgba(255,255,255,.3); }
.tp-msg-row.tp-me   .tp-file-link:hover { background: rgba(255,255,255,.3); }
.tp-msg-row.tp-dept .tp-file-link { background: white; color: var(--blue); border: 0.5px solid var(--g200); }
.tp-file-link svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; }

/* ── READ-ONLY BAR ────────────────────────────────────────── */
.tp-readonly-bar { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 16px; background: var(--g50,#fafaf8); border-radius: 10px; border: 0.5px solid var(--g100); font-size: 13px; color: var(--g500); margin-top: 16px; }
.tp-readonly-bar svg { width: 14px; height: 14px; fill: none; stroke: var(--g400); stroke-width: 2; flex-shrink: 0; }

/* ── LIGHTBOX ─────────────────────────────────────────────── */
#tpLightbox { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,.88); backdrop-filter: blur(6px); align-items: center; justify-content: center; animation: lbFadeIn .18s ease; }
#tpLightbox.active { display: flex; }
@keyframes lbFadeIn { from { opacity: 0; } to { opacity: 1; } }
#tpLightboxInner { position: relative; max-width: 92vw; max-height: 90vh; display: flex; flex-direction: column; align-items: center; gap: 14px; animation: lbSlideIn .2s cubic-bezier(.34,1.26,.64,1); }
@keyframes lbSlideIn { from { transform: scale(.88); opacity: 0; } to { transform: scale(1); opacity: 1; } }
#tpLightboxImg { max-width: 92vw; max-height: 80vh; border-radius: 10px; object-fit: contain; box-shadow: 0 20px 60px rgba(0,0,0,.6); display: block; }
#tpLightboxClose { position: fixed; top: 18px; right: 20px; width: 38px; height: 38px; border-radius: 50%; border: none; background: rgba(255,255,255,.12); color: white; cursor: pointer; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(4px); z-index: 10000; transition: background .15s; }
#tpLightboxClose:hover { background: rgba(255,255,255,.25); }
#tpLightboxDownload { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 22px; background: rgba(255,255,255,.13); color: white; font-size: 13px; font-weight: 500; text-decoration: none; border: 0.5px solid rgba(255,255,255,.25); backdrop-filter: blur(4px); }
#tpLightboxDownload svg { width: 13px; height: 13px; fill: none; stroke: white; stroke-width: 2; }

@media (max-width: 600px) {
  .tp-card       { padding: 20px 18px; }
  .tp-meta-grid  { grid-template-columns: 1fr; }
  .tp-title      { font-size: 18px; }
  .tp-staff-grid { grid-template-columns: 1fr; }
}
</style>
<?php
$extraHead = ob_get_clean();
require 'layout.php';
?>

<div class="tp-page">

  <!-- ── BREADCRUMB ── -->
  <div class="tp-breadcrumb">
    <a href="homepage.php">Dashboard</a>
    <span class="tp-sep">›</span>
    <span><?php echo htmlspecialchars($ticketId ?: 'Ticket Detail'); ?></span>
  </div>

  <!-- ── BACK BUTTON ── -->
  <a href="homepage.php" class="tp-back-btn">
    <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
    Back to All Tickets
  </a>

  <?php if (!$ticket): ?>

  <div class="tp-notfound">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <h2>Ticket Not Found</h2>
    <p>Ticket <strong><?php echo htmlspecialchars($ticketId ?: '—'); ?></strong> doesn't exist or you don't have access to it.</p>
    <a href="homepage.php" class="tp-back-btn">
      <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
      Back to Dashboard
    </a>
  </div>

  <?php else: ?>

  <div class="tp-card">

    <!-- Top row: icon + label + status badge -->
    <div class="tp-top-row">
      <div class="tp-icon-title">

        <!-- FIX 2: ticket icon (tag/ticket shape) -->
        <div class="tp-icon-box">
  <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
    <!-- formal document/report icon -->
    <rect x="4" y="2" width="16" height="20" rx="2" ry="2"
          stroke="#0C447C" stroke-width="1.6" fill="none"/>
    <line x1="8" y1="7"  x2="16" y2="7"  stroke="#0C447C" stroke-width="1.4" stroke-linecap="round"/>
    <line x1="8" y1="11" x2="16" y2="11" stroke="#0C447C" stroke-width="1.4" stroke-linecap="round"/>
    <line x1="8" y1="15" x2="13" y2="15" stroke="#0C447C" stroke-width="1.4" stroke-linecap="round"/>
  </svg>
</div>

        <div>
          <div class="tp-card-label">Ticket Detail</div>
          <div class="tp-card-tid"><?php echo htmlspecialchars($ticket['ticket_id']); ?></div>
        </div>
      </div>
      <span class="tp-status-badge" style="background:<?php echo $curStatus['bg']; ?>;color:<?php echo $curStatus['color']; ?>">
    <span class="tp-status-dot" style="background:<?php echo $curStatus['dot']; ?>"></span>
    <?php echo $curStatus['label']; ?>
</span>
    </div>

    <!-- Title + ticket ID pill -->
    <div class="tp-title-row">
      <div class="tp-title"><?php echo htmlspecialchars($ticket['title']); ?></div>
      <div class="tp-tid-pill"><?php echo htmlspecialchars($ticket['ticket_id']); ?></div>
    </div>

    <!-- Meta: category / from dept / priority -->
   <!-- Unified 6-cell info grid -->
<div class="tp-meta-grid">
  <div class="tp-meta-cell">
  <div class="tp-meta-label">Department In Charge</div>
  <div class="tp-meta-value"><?php echo htmlspecialchars($ticket['dept_name'] ?? '—'); ?></div>
</div>
  <div class="tp-meta-cell">
    <div class="tp-meta-label">From Department</div>
    <div class="tp-meta-value"><?php echo htmlspecialchars($ticket['my_department'] ?? '—'); ?></div>
  </div>
  <div class="tp-meta-cell">
    <div class="tp-meta-label">Priority</div>
    <div class="tp-meta-value">
      <span class="tp-priority-badge" style="background:<?php echo $curPriority['bg']; ?>;color:<?php echo $curPriority['color']; ?>">
        <?php echo $curPriority['label']; ?>
      </span>
    </div>
  </div>
  <div class="tp-meta-cell">
    <div class="tp-meta-label">Category</div>
    <div class="tp-meta-value"><?php
        // Extract department prefix (e.g. "IT Dept") and sub-category from category_name
        $catFull = $ticket['category_name'] ?? '—';
        $catParts = explode(' / ', $catFull, 2);
        echo htmlspecialchars($catParts[0] ?? '—');
    ?></div>
  </div>
  <div class="tp-meta-cell">
    <div class="tp-meta-label">Sub-category</div>
    <div class="tp-meta-value"><?php
        $catParts2 = explode(' / ', $ticket['category_name'] ?? '', 2);
        echo htmlspecialchars($catParts2[1] ?? '—');
    ?></div>
  </div>
  <div class="tp-meta-cell">
    <div class="tp-meta-label">Submitted</div>
    <div class="tp-meta-value"><?php echo date('d M Y, H:i', strtotime($ticket['created_at'])); ?></div>
  </div>
  <div class="tp-meta-cell">
    <div class="tp-meta-label">Last Updated</div>
    <div class="tp-meta-value"><?php
      $updAt = !empty($ticket['updated_at'] ?? null)
        ? $ticket['updated_at']
        : ($ticket['created_at'] ?? null);
      echo $updAt ? date('d M Y, H:i', strtotime($updAt)) : '—';
    ?></div>
  </div>
  
</div>

  
    <!-- Description — FIX 3: box now hugs the content -->
    <div style="padding: 14px 18px;">
  <div class="tp-desc-label">Description</div>
  <div class="tp-desc-box">
    <?php echo nl2br(htmlspecialchars($ticket['description'])); ?>
  </div>
</div>
    <?php if (!empty($ticket['attachment_path'])): ?>
    <a href="../<?php echo htmlspecialchars($ticket['attachment_path']); ?>" target="_blank" rel="noopener" class="tp-attach-link">
      <svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
      View Attachment
    </a>
    <?php endif; ?>

   

    <div class="tp-divider"></div>

    <!-- Handled by -->
    <div class="tp-section-header">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span>Handled By</span>
    </div>
    <?php if (!empty($handledBy)): ?>
    <div class="tp-staff-grid">
      <?php foreach ($handledBy as $s):
        $ini = getInitials($s['full_name']);
      ?>
      <div class="tp-staff-card">
        <div class="tp-staff-avatar"><?php echo htmlspecialchars($ini); ?></div>
        <div>
          <div class="tp-staff-name"><?php echo htmlspecialchars($s['full_name']); ?></div>
          <div class="tp-staff-dept"><?php echo htmlspecialchars($ticket['dept_name'] ?? '—'); ?> · <?php echo ucfirst($s['role']); ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="tp-staff-card" style="max-width: 320px;">
        <div class="tp-staff-avatar" style="background: #EEF2F7; color: #5A7A9A;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="#5A7A9A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
        </div>
        <div>
            <div class="tp-staff-name">Awaiting Assignment</div>
            <div class="tp-staff-dept">Ticket is pending Technical assignment</div>
            <div style="
                display: inline-flex;
                align-items: center;
                gap: 5px;
                margin-top: 6px;
                background: #F3F4F6;
                border: 0.5px solid #E5E7EB;
                border-radius: 20px;
                padding: 3px 10px;
                font-size: 10.5px;
                font-weight: 600;
                color: #6B7280;
                letter-spacing: .05em;
            ">
                <span style="width:6px;height:6px;border-radius:50%;background:#9CA3AF;display:inline-block;"></span>
                IN QUEUE
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="tp-divider"></div>

    <?php if ($ticket && strtolower($ticket['status']) === 'closed'): ?>
    <div class="tp-section-header">
      <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      <span>Your Feedback</span>
    </div>

    <?php if ($feedback):
      $r = (int)$feedback['rating'];
      $fbColors = feedbackRatingColors($r);
$fbBg     = $fbColors[0];
$fbColor  = $fbColors[1];
      $label = feedbackEmojiLabel($r);
      $stars = str_repeat('★', $r) . str_repeat('☆', 5 - $r);
    ?>
    <div style="background:<?php echo $fbBg; ?>;border-radius:12px;padding:16px 20px;border:1px solid <?php echo $fbColor; ?>22;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:<?php echo !empty($feedback['comment']) ? '10px' : '0'; ?>">
        <div style="font-size:26px;line-height:1"><?php echo ['','😠','😟','😐','😊','😄'][$r]; ?></div>
        <div>
          <div style="font-size:14px;font-weight:600;color:<?php echo $fbColor; ?>"><?php echo $label; ?></div>
          <div style="font-size:13px;color:#6B7280;margin-top:2px;">
            <span style="color:<?php echo $fbColor; ?>;letter-spacing:2px;"><?php echo $stars; ?></span>
            &nbsp;<?php echo $r; ?>/5
            <?php if ($feedback['is_auto_submitted']): ?>
            &nbsp;·&nbsp;<span style="font-size:11px;background:#F3F4F6;color:#6B7280;padding:1px 7px;border-radius:10px;">Auto-submitted</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php if (!empty($feedback['comment'])): ?>
      <div style="font-size:13.5px;color:#374151;line-height:1.65;border-left:3px solid <?php echo $fbColor; ?>;padding-left:12px;">
        <?php echo nl2br(htmlspecialchars($feedback['comment'])); ?>
      </div>
      <?php endif; ?>
      <div style="font-size:11px;color:#9CA3AF;margin-top:10px;">
        Submitted <?php echo date('d M Y, H:i', strtotime($feedback['created_at'])); ?>
      </div>
    </div>

    <?php else: ?>
    <div style="text-align:center;padding:24px 16px;background:#F9FAFB;border-radius:12px;border:1px dashed #E5E7EB;">
      <div style="font-size:22px;margin-bottom:8px;">💬</div>
      <div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;">No Feedback Yet</div>
      <div style="font-size:12px;color:#9CA3AF;">You haven't submitted feedback for this ticket.</div>
    </div>
    <?php endif; ?>

    <div class="tp-divider"></div>
    <?php endif; ?>

    <!-- Messages (read-only) -->
    <div class="tp-messages-header">
      <div class="tp-section-header" style="margin-bottom:0">
        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <span>Messages</span>
      </div>
<?php $replyCount = count(array_filter($timeline, function($i) { return $i['type'] === 'reply'; })); ?>
<?php if ($replyCount > 0): ?>
<div class="tp-msg-count-pill"><?php echo $replyCount; ?></div>
      <?php endif; ?>
    </div>

    <div class="tp-messages" id="tpMessages">
      <?php if (empty($timeline)): ?>
<div class="tp-empty-msg">
  <div class="tp-empty-icon">
    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
  </div>
  <div class="tp-empty-title">No messages yet</div>
  <div class="tp-empty-sub">The department will reply here once they review your complaint.</div>
</div>

<?php else:
  $prevDate = '';
  foreach ($timeline as $item):
    $msgDate = date('d M Y', strtotime($item['time']));
?>

  <?php if ($msgDate !== $prevDate): $prevDate = $msgDate; ?>
  <div class="tp-date-sep">
    <span><?php echo ($msgDate === date('d M Y')) ? 'Today' : $msgDate; ?></span>
  </div>
  <?php endif; ?>

  <?php if ($item['type'] === 'status_change'):
    $ns = $item['data']['new_status'];
    $by = $item['data']['changed_by'];
    $at = date('H:i', strtotime($item['time']));

    // colours matching your $statusMap
    $pillStyles = [
      'in_progress' => ['label'=>'In Progress', 'bg'=>'#E6F1FB', 'color'=>'#0C447C', 'dot'=>'#378ADD', 'border'=>'#B5D4F4'],
      'closed'      => ['label'=>'Closed',      'bg'=>'#EAF3DE', 'color'=>'#27500A', 'dot'=>'#1D9E75', 'border'=>'#B5D4B0'],
      'open'        => ['label'=>'Open',         'bg'=>'#FAEEDA', 'color'=>'#854F0B', 'dot'=>'#EF9F27', 'border'=>'#F5D39A'],
    ];
    $ps = $pillStyles[$ns] ?? $pillStyles['open'];
  ?>
  <div class="tp-status-event">
    <div class="tp-status-event-pill"
         style="background:<?php echo $ps['bg']; ?>;
                color:<?php echo $ps['color']; ?>;
                border-color:<?php echo $ps['border']; ?>;">
      <span class="tp-status-event-dot" style="background:<?php echo $ps['dot']; ?>;"></span>
      Ticket marked as <strong><?php echo $ps['label']; ?></strong> by <?php echo htmlspecialchars($by); ?>
    </div>
    <span class="tp-status-event-time"><?php echo $at; ?></span>
  </div>

  <?php else:
    $r        = $item['data'];
    $isMe     = ($r['sender_role'] === $submitterType && (int)$r['sender_id'] === $userId);
    $rowClass = $isMe ? 'tp-me' : 'tp-dept';
    $avClass  = $isMe ? 'av-me' : 'av-dept';
    $initials    = getInitials($r['sender_name']);
    $senderLabel = $isMe ? 'You' : $r['sender_name'];
    $hasAttach   = !empty($r['attachment_path']);
    $attachPath  = $hasAttach ? $r['attachment_path'] : '';
    $isImg       = $hasAttach && isImageAttachment($attachPath);
    $fileName    = $hasAttach ? basename($attachPath) : '';
    $fileMeta    = ($hasAttach && !$isImg) ? fileCardMeta($attachPath) : [];
    $extUpper    = $hasAttach ? strtoupper(pathinfo($attachPath, PATHINFO_EXTENSION)) : '';
    $displayName = strlen($fileName) > 28 ? substr($fileName, 0, 25) . '…' : $fileName;
  ?>

  <div class="tp-msg-row <?php echo $rowClass; ?>">
    <div class="tp-avatar <?php echo $avClass; ?>"><?php echo htmlspecialchars($initials); ?></div>
    <div class="tp-msg-body">
      <div class="tp-msg-name">
        <?php echo htmlspecialchars($senderLabel); ?> · <?php echo date('H:i', strtotime($r['created_at'])); ?>
      </div>

      <?php if ($isImg): ?>
        <a href="../<?php echo htmlspecialchars($attachPath); ?>"
           class="tp-img-bubble" title="View full image"
           onclick="tpOpenLightbox(this.href); return false;">
          <img src="../<?php echo htmlspecialchars($attachPath); ?>"
               alt="Image attachment" loading="lazy"
               onerror="this.closest('.tp-img-bubble').style.display='none'">
        </a>
        <?php if (!empty($r['message'])): ?>
          <div class="tp-bubble"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div>
        <?php endif; ?>

      <?php elseif ($hasAttach): ?>
        <?php if (!empty($r['message'])): ?>
          <div class="tp-bubble"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div>
        <?php endif; ?>
        <a href="../<?php echo htmlspecialchars($attachPath); ?>"
           class="tp-file-card" target="_blank" rel="noopener"
           title="<?php echo htmlspecialchars($fileName); ?>">
          <div class="tp-file-card-icon"
               style="background:<?php echo $fileMeta['bg']; ?>;color:<?php echo $fileMeta['color']; ?>;">
            <?php echo htmlspecialchars($fileMeta['label']); ?>
          </div>
          <div class="tp-file-card-info">
            <span class="tp-file-card-name"><?php echo htmlspecialchars($displayName); ?></span>
            <span class="tp-file-card-meta"><?php echo $extUpper; ?> file &nbsp;·&nbsp; tap to open</span>
          </div>
          <svg class="tp-file-card-dl" viewBox="0 0 24 24">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="7 10 12 15 17 10"/>
            <line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
        </a>

      <?php else: ?>
        <?php if (!empty($r['message'])): ?>
          <div class="tp-bubble"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>

  <?php endif; ?>
<?php endforeach; endif; ?>
    </div>

    <div class="tp-readonly-bar">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      This conversation is read-only.
    </div>

  

  </div><!-- end tp-card -->

  <br>
  <?php endif; ?>
</div><!-- end tp-page -->

<!-- Lightbox -->
<div id="tpLightbox" role="dialog" aria-modal="true" onclick="tpCloseLightbox(event)">
  <button id="tpLightboxClose" onclick="tpCloseLightbox()">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="white" stroke-width="2.5">
      <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
    </svg>
  </button>
  <div id="tpLightboxInner" onclick="event.stopPropagation()">
    <img id="tpLightboxImg" src="" alt="Full image">
    <a id="tpLightboxDownload" href="" download>
      <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Download
    </a>
  </div>
</div>

<?php
ob_start();
?>
<script>
(function(){
  const box = document.getElementById('tpMessages');
  if (box) box.scrollTop = box.scrollHeight;
})();

const _lb    = document.getElementById('tpLightbox');
const _lbImg = document.getElementById('tpLightboxImg');
const _lbDl  = document.getElementById('tpLightboxDownload');

function tpOpenLightbox(src) {
  _lbImg.src = src; _lbDl.href = src;
  _lb.classList.add('active');
  document.body.style.overflow = 'hidden';
}
function tpCloseLightbox(e) {
  if (e && e.currentTarget === _lb && e.target !== _lb) return;
  _lb.classList.remove('active');
  document.body.style.overflow = '';
  setTimeout(() => { _lbImg.src = ''; _lbDl.href = ''; }, 180);
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && _lb.classList.contains('active')) tpCloseLightbox();
});
</script>
<?php
$extraFoot = ob_get_clean();
require 'layout_end.php';
?>