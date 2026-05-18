<?php
// uniKL/complaint/complaint/my_complaints.php 
session_start();

$allowedRoles = ['student', 'lecturer', 'dept_handler', 'admin', 'super_admin', 'report_viewer', 'staff'];
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowedRoles)) {
    header('Location: ../login.php');
    exit;
}

require '../db_connect.php';

$userName      = htmlspecialchars($_SESSION['user_name']  ?? 'User');
$userEmail     = htmlspecialchars($_SESSION['user_email'] ?? '');
$userRole      = $_SESSION['user_role'];
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 0);
$submitterType = ($userRole === 'student') ? 'student' : 'staff';

// ── Filters from GET ──────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');

// ── Pagination ────────────────────────────────────────────────────────────────
$perPage     = 20;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($currentPage - 1) * $perPage;

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where  = "WHERE c.submitter_id = ? AND c.submitter_type = ?";
$params = [$userId, $submitterType];
$types  = "is";

$allowedStatuses = ['open', 'in_progress', 'closed'];
if ($filterStatus !== '' && in_array($filterStatus, $allowedStatuses)) {
    $where   .= " AND c.status = ?";
    $params[] = $filterStatus;
    $types   .= "s";
} elseif ($filterStatus !== '') {
    $filterStatus = '';
}

if ($filterSearch !== '') {
    $where   .= " AND (c.ticket_id LIKE ? OR c.title LIKE ?)";
    $like     = "%{$filterSearch}%";
    $params[] = $like;
    $params[] = $like;
    $types   .= "ss";
}

// ── Count total ───────────────────────────────────────────────────────────────
$stmtCount = $conn->prepare("SELECT COUNT(*) AS total FROM complaints c $where");
$stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$totalRows  = $stmtCount->get_result()->fetch_assoc()['total'] ?? 0;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$stmtCount->close();

// ── Fetch rows ────────────────────────────────────────────────────────────────
$stmtList = $conn->prepare("
    SELECT c.ticket_id, c.title, c.description, c.status, c.created_at,
           c.assigned_to,
           cat.category_name, d.dept_name,
           (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id = c.ticket_id) AS reply_count,
           (SELECT MAX(tl.changed_at) FROM ticket_logs tl WHERE tl.ticket_id = c.ticket_id AND tl.new_status = c.status) AS status_changed_at,
           tf.rating  AS feedback_rating,
           tf.comment AS feedback_comment
    FROM complaints c
    JOIN categories  cat ON c.category_id = cat.category_id
    JOIN departments d   ON c.dept_id     = d.dept_id
    LEFT JOIN ticket_feedback tf ON tf.ticket_id = c.ticket_id
    $where
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
");
$paramsFull = array_merge($params, [$perPage, $offset]);
$typesFull  = $types . "ii";
$stmtList->bind_param($typesFull, ...$paramsFull);
$stmtList->execute();
$complaints = $stmtList->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtList->close();

// ── Summary counts ────────────────────────────────────────────────────────────
$stmtSummary = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'open')        AS open,
        SUM(status = 'in_progress') AS in_progress,
        SUM(status = 'closed')      AS closed
    FROM complaints
    WHERE submitter_id = ? AND submitter_type = ?
");
$stmtSummary->bind_param("is", $userId, $submitterType);
$stmtSummary->execute();
$summary = $stmtSummary->get_result()->fetch_assoc();
$stmtSummary->close();

// ── Status meta ───────────────────────────────────────────────────────────────
$statusMeta = [
    'open'        => ['label' => 'Open',        'color' => '#B45309', 'bg' => '#FFFBEB', 'dot' => '#F59E0B', 'border' => '#FDE68A'],
    'in_progress' => ['label' => 'In Progress',  'color' => '#1D4ED8', 'bg' => '#EFF6FF', 'dot' => '#3B82F6', 'border' => '#BFDBFE'],
    'closed'      => ['label' => 'Closed',       'color' => '#374151', 'bg' => '#F9FAFB', 'dot' => '#9CA3AF', 'border' => '#E5E7EB'],
];

// ── Rating meta ───────────────────────────────────────────────────────────────
$ratingMeta = [
    1 => ['label' => 'Very Dissatisfied', 'color' => '#EF4444', 'bg' => '#FEF2F2', 'border' => '#FECACA'],
    2 => ['label' => 'Dissatisfied',      'color' => '#F97316', 'bg' => '#FFF7ED', 'border' => '#FED7AA'],
    3 => ['label' => 'Neutral',           'color' => '#D97706', 'bg' => '#FFFBEB', 'border' => '#FDE68A'],
    4 => ['label' => 'Satisfied',         'color' => '#16A34A', 'bg' => '#F0FDF4', 'border' => '#BBF7D0'],
    5 => ['label' => 'Very Satisfied',    'color' => '#15803D', 'bg' => '#DCFCE7', 'border' => '#86EFAC'],
];

// ── Emoji SVGs — same faces as layout.php popup ──────────────────────────────
$ratingEmoji = [
    1 => '<svg viewBox="0 0 48 48" fill="none" width="20" height="20"><circle cx="24" cy="24" r="22" stroke="#EF4444" stroke-width="2.5" fill="#FEE2E2"/><circle cx="17" cy="20" r="2.5" fill="#EF4444"/><circle cx="31" cy="20" r="2.5" fill="#EF4444"/><path d="M16 33c2-4 14-4 16 0" stroke="#EF4444" stroke-width="2.5" stroke-linecap="round"/><path d="M15 15l4 3M33 15l-4 3" stroke="#EF4444" stroke-width="2" stroke-linecap="round"/></svg>',
    2 => '<svg viewBox="0 0 48 48" fill="none" width="20" height="20"><circle cx="24" cy="24" r="22" stroke="#F97316" stroke-width="2.5" fill="#FFEDD5"/><circle cx="17" cy="20" r="2.5" fill="#F97316"/><circle cx="31" cy="20" r="2.5" fill="#F97316"/><path d="M17 32c2-3 12-3 14 0" stroke="#F97316" stroke-width="2.5" stroke-linecap="round"/></svg>',
    3 => '<svg viewBox="0 0 48 48" fill="none" width="20" height="20"><circle cx="24" cy="24" r="22" stroke="#EAB308" stroke-width="2.5" fill="#FEF9C3"/><circle cx="17" cy="20" r="2.5" fill="#EAB308"/><circle cx="31" cy="20" r="2.5" fill="#EAB308"/><line x1="17" y1="32" x2="31" y2="32" stroke="#EAB308" stroke-width="2.5" stroke-linecap="round"/></svg>',
    4 => '<svg viewBox="0 0 48 48" fill="none" width="20" height="20"><circle cx="24" cy="24" r="22" stroke="#22C55E" stroke-width="2.5" fill="#DCFCE7"/><circle cx="17" cy="20" r="2.5" fill="#22C55E"/><circle cx="31" cy="20" r="2.5" fill="#22C55E"/><path d="M16 28c2 4 14 4 16 0" stroke="#22C55E" stroke-width="2.5" stroke-linecap="round"/></svg>',
    5 => '<svg viewBox="0 0 48 48" fill="none" width="20" height="20"><circle cx="24" cy="24" r="22" stroke="#16A34A" stroke-width="2.5" fill="#D1FAE5"/><circle cx="17" cy="19" r="2.5" fill="#16A34A"/><circle cx="31" cy="19" r="2.5" fill="#16A34A"/><path d="M14 27c2 6 18 6 20 0" stroke="#16A34A" stroke-width="2.5" stroke-linecap="round"/></svg>',
];

$_fbApiUrl = '/uniKL/complaint/feedback_api.php';

$pageTitle    = 'My Complaints';
$pageSubtitle = date('l, d F Y');
$activeNav    = 'my_complaints';

ob_start();
?>
<style>
/* ══════════════════════════════════════════════════════════════
   MY COMPLAINTS — Two-row ticket card design
══════════════════════════════════════════════════════════════ */

.mc-page {
  display: flex; flex-direction: column; gap: 14px;
  width: 100%; align-items: flex-start;
}

/* Breadcrumb */
.tp-breadcrumb {
  display: flex; align-items: center; gap: 6px;
  font-size: 13px; color: var(--g500); flex-wrap: wrap;
}
.tp-breadcrumb a { color: var(--g500); text-decoration: none; }
.tp-breadcrumb a:hover { color: var(--blue); }
.tp-sep { color: var(--g300); }

/* Back button */
.tp-back-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 16px; border-radius: 9px;
  border: 0.5px solid var(--g200); background: white;
  color: var(--g700); font-size: 13px; font-weight: 500;
  text-decoration: none;
  transition: border-color .2s, background .2s, color .2s;
  font-family: 'DM Sans', sans-serif;
}
.tp-back-btn:hover { border-color: #2d5986; background: #2d5986; color: #fff; }
.tp-back-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; }

/* ── Header card ── */
.mc-header-card {
  background: white; border-radius: 16px;
  border: 0.5px solid var(--g200);
  box-shadow: 0 1px 4px rgba(0,0,0,.04);
  width: 100%; max-width: 1020px; align-self: center;
  box-sizing: border-box; overflow: hidden;
}

.mc-top-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 24px 28px 20px; border-bottom: 1px solid #f0f2f8;
  flex-wrap: wrap; gap: 12px;
}
.mc-icon-title { display: flex; align-items: center; gap: 12px; }
.mc-icon-box {
  width: 42px; height: 42px; border-radius: 11px; background: #E6F1FB;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.mc-icon-box svg { width: 20px; height: 20px; fill: none; stroke: #0C447C; stroke-width: 1.8; }
.mc-card-label { font-size: 15px; font-weight: 700; color: #0D1F3C; }
.mc-card-sub   { font-size: 12px; color: #9299b0; margin-top: 2px; }

/* ══ TAB BAR ══ */
.mc-tabs {
  display: flex; align-items: stretch; padding: 0 24px;
  gap: 0; overflow-x: auto; scrollbar-width: none;
  border-bottom: 2px solid #edf0f7;
}
.mc-tabs::-webkit-scrollbar { display: none; }

.mc-tab {
  position: relative;
  display: inline-flex; align-items: center; gap: 7px;
  padding: 13px 20px 12px; font-size: 13px; font-weight: 500;
  color: #9299b0; text-decoration: none; white-space: nowrap; cursor: pointer;
  border: none; background: none;
  transition: color .2s;
  margin-bottom: -2px;
}
.mc-tab::after {
  content: '';
  position: absolute;
  bottom: 0; left: 50%; right: 50%;
  height: 2.5px; border-radius: 2px 2px 0 0;
  background: #185FA5;
  transition: left .2s cubic-bezier(.4,0,.2,1),
              right .2s cubic-bezier(.4,0,.2,1);
}
.mc-tab:hover           { color: #185FA5; }
.mc-tab:hover::after    { left: 16px; right: 16px; }
.mc-tab.active          { color: #0D1F3C; font-weight: 700; }
.mc-tab.active::after   { left: 16px; right: 16px; background: #0D1F3C; }

.mc-tab-count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 18px; padding: 0 6px;
  border-radius: 9px; font-size: 11px; font-weight: 700;
  background: #edf0f7; color: #6b7394;
  transition: background .2s, color .2s;
}
.mc-tab:hover .mc-tab-count  { background: #dde8f8; color: #185FA5; }
.mc-tab.active .mc-tab-count { background: #0D1F3C; color: #fff; }

/* Search pushed to right end of tabs row */
.mc-tabs-search {
  margin-left: auto; display: flex; align-items: center;
  gap: 8px; padding: 6px 0; flex-shrink: 0;
}

.mc-search-wrap { position: relative; flex: 1; max-width: 380px; }
.mc-search-wrap svg {
  position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
  width: 13px; height: 13px; fill: none; stroke: #b0b7cc; stroke-width: 2; pointer-events: none;
}
.mc-search {
  width: 100%; padding: 8px 12px 8px 32px; border: 1px solid #dde1ef;
  border-radius: 8px; font-size: 13px; font-family: 'DM Sans', 'Inter', sans-serif;
  color: #1e2235; background: #fff; outline: none;
  transition: border-color .15s, box-shadow .15s; box-sizing: border-box;
}
.mc-search::placeholder { color: #b0b7cc; }
.mc-search:focus { border-color: #185FA5; box-shadow: 0 0 0 3px rgba(24,95,165,.08); }

.mc-clear-btn {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 12px; color: #dc2626; font-weight: 500;
  padding: 6px 10px; border-radius: 6px;
  border: 1px solid #fecaca; background: #fff5f5;
  text-decoration: none; transition: background .15s;
}
.mc-clear-btn:hover { background: #fee2e2; }
.mc-clear-btn svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2.5; }

/* Result bar */
.mc-result-bar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 24px; font-size: 12.5px; color: #9299b0; flex-wrap: wrap; gap: 8px;
}
.mc-result-bar strong { color: #1e2235; font-weight: 600; }

/* ══════════════════════════════════════════════════════════════
   TICKET CARDS
══════════════════════════════════════════════════════════════ */
.mc-ticket-list {
  display: flex; flex-direction: column; gap: 10px;
  width: 100%; max-width: 1020px; align-self: center; box-sizing: border-box;
}

.mc-ticket-card {
  display: block; text-decoration: none; color: inherit;
  background: #fff; border: 1px solid #e4e8f2; border-radius: 12px;
  position: relative;
  box-shadow: 0 1px 4px rgba(0,0,0,.04);
  transition: border-color .18s, box-shadow .18s, background .15s;
  overflow: hidden;
}
.mc-ticket-card:hover {
  border-color: #aac4e4;
  box-shadow: 0 6px 22px rgba(13,31,60,.09);
  background: #f8fbff;
}

/* Left accent bar */
.mc-ticket-card::before {
  content: ''; position: absolute; left: 0; top: 0; bottom: 0;
  width: 4px; border-radius: 12px 0 0 12px;
}
.mc-ticket-card[data-status="open"]::before        { background: #F59E0B; }
.mc-ticket-card[data-status="in_progress"]::before { background: #3B82F6; }
.mc-ticket-card[data-status="resolved"]::before    { background: #22C55E; }
.mc-ticket-card[data-status="closed"]::before      { background: #9CA3AF; }

/* ── ROW 1: Title + Description (left) | rating + reply count (right) ── */
.mc-row1 {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 17px 54px 13px 22px;
  border-bottom: 1px solid #f0f2f8;
  flex-wrap: wrap;
}
.mc-row1-left { display: flex; flex-direction: column; gap: 7px; flex: 1; min-width: 0; }

.mc-title-line {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}

.mc-title {
  font-size: 14px; font-weight: 700; color: #0D1F3C;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  transition: color .15s;
}
.mc-ticket-card:hover .mc-title { color: #185FA5; }

.mc-tid {
  font-family: "SFMono-Regular", Consolas, monospace;
  font-size: 11.5px; color: #7a82a0; background: #f2f4fa;
  border: 1px solid #e4e7f2; border-radius: 5px;
  padding: 2px 8px; white-space: nowrap; flex-shrink: 0;
}

/* Description snippet below title */
.mc-desc {
  font-size: 12.5px; color: #9299b0; line-height: 1.5;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  max-width: 100%;
}

/* ── ROW 1 RIGHT side ── */
.mc-row1-right {
  display: flex; align-items: center; gap: 8px; flex-shrink: 0;
  padding-top: 2px;
}

.mc-status-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 13px; border-radius: 20px;
  font-size: 11.5px; font-weight: 700; white-space: nowrap;
  border: 1px solid transparent;
}
.mc-status-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }

/* ── ROW 2: Dept | Category | Date ── */
.mc-row2 {
  display: flex; align-items: center;
  padding: 10px 54px 10px 22px;
  flex-wrap: wrap; gap: 0;
}

.mc-detail-cell {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12.5px; color: #7a82a0;
  padding-right: 18px; margin-right: 18px;
  border-right: 1px solid #edf0f7;
  white-space: nowrap;
}
.mc-detail-cell:last-child { border-right: none; padding-right: 0; margin-right: 0; }
.mc-detail-cell svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; opacity: .6; flex-shrink: 0; }
.mc-detail-label { color: #4b5270; font-weight: 600; }

.mc-assign-avatar {
  width: 20px; height: 20px; border-radius: 50%;
  background: #DDE8F8; color: #185FA5;
  font-size: 9px; font-weight: 800;
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0; text-transform: uppercase;
}

/* Reply count badge */
.mc-reply-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 12.5px; color: #7a82a0; white-space: nowrap; flex-shrink: 0;
}
.mc-reply-badge svg { width: 14px; height: 14px; fill: none; stroke: #9299b0; stroke-width: 1.8; }
.mc-reply-badge span { font-size: 12.5px; font-weight: 600; color: #4b5270; }

/* Status date on the right of row2 */
.mc-row2-right {
  margin-left: auto; display: flex; align-items: center; flex-shrink: 0; gap: 8px;
}

/* ══════════════════════════════════════════════════════════════
   RATING — Row 1, beside message icon
══════════════════════════════════════════════════════════════ */

/* Emoji face + label — shown when feedback has been submitted */
.mc-rating-display {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 10px 3px 5px; border-radius: 20px;
  border: 1px solid transparent; flex-shrink: 0; line-height: 1;
}
.mc-rating-display .mc-rate-face { display: flex; align-items: center; flex-shrink: 0; }
.mc-rating-display .mc-rate-score {
  font-size: 11.5px; font-weight: 700;
}
.mc-rating-display .mc-rate-lbl {
  font-size: 11px; color: inherit; opacity: .8;
}

/* "Give Feedback" button — shown on closed tickets with no rating */
.mc-rate-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 11px 5px 8px; border-radius: 20px;
  font-size: 11.5px; font-weight: 600; white-space: nowrap; flex-shrink: 0;
  background: #FFF8EC; color: #92400E;
  border: 1px solid #FDE68A; cursor: pointer;
  font-family: 'Inter', 'DM Sans', sans-serif;
  transition: background .15s, border-color .15s, box-shadow .15s;
  line-height: 1;
  /* float above the card <a> so the click is captured by the button, not the link */
  position: relative; z-index: 2;
}
.mc-rate-btn:hover {
  background: #FEF3C7; border-color: #F59E0B;
  box-shadow: 0 2px 8px rgba(245,158,11,.2);
}
.mc-rate-btn svg { width: 13px; height: 13px; fill: none; stroke: #D97706; stroke-width: 2; flex-shrink: 0; }

/* Hover arrow */
.mc-card-arrow {
  position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
  width: 28px; height: 28px; border-radius: 7px;
  background: #EEF3FB; border: 0.5px solid #C8DCEF; color: #185FA5;
  display: flex; align-items: center; justify-content: center;
  opacity: 0; transition: opacity .15s;
}
.mc-ticket-card:hover .mc-card-arrow { opacity: 1; }
.mc-card-arrow svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 2.5; }

/* Empty state */
.mc-empty {
  display: flex; flex-direction: column; align-items: center;
  justify-content: center; padding: 70px 20px; gap: 10px; text-align: center;
  background: white; border-radius: 12px; border: 1px solid #e4e8f2;
  width: 100%; max-width: 1020px; align-self: center; box-sizing: border-box;
}
.mc-empty-icon {
  width: 50px; height: 50px; border-radius: 14px; background: #f3f4f8;
  display: flex; align-items: center; justify-content: center;
}
.mc-empty-icon svg { width: 24px; height: 24px; fill: none; stroke: #c4c9d9; stroke-width: 1.5; }
.mc-empty-title { font-size: 14px; font-weight: 700; color: #374151; }
.mc-empty-sub   { font-size: 13px; color: #9ca3af; max-width: 280px; line-height: 1.6; }
.mc-empty-sub a { color: #185FA5; font-weight: 600; text-decoration: none; }

/* Pagination */
.mc-pagination-card {
  width: 100%; max-width: 1020px; align-self: center; box-sizing: border-box;
  background: white; border: 0.5px solid #e4e8f2; border-radius: 12px;
  padding: 12px 20px; display: flex; align-items: center;
  justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.mc-pager { display: flex; align-items: center; gap: 4px; }
.mc-pager-info { font-size: 12px; color: #9299b0; }
.pg-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border-radius: 7px; border: 1px solid #e4e8f2;
  background: #fff; color: #4b5270; font-size: 12.5px; font-weight: 500;
  text-decoration: none; transition: all .13s; cursor: pointer; font-family: inherit;
}
.pg-btn:hover:not(.disabled):not(.active) { border-color: #185FA5; color: #185FA5; background: #EEF3FB; }
.pg-btn.active { background: #0D1F3C; border-color: #0D1F3C; color: #fff; font-weight: 700; }
.pg-btn.disabled { opacity: 0.35; pointer-events: none; }
.pg-btn svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2.5; }

/* ══════════════════════════════════════════════════════════════
   INLINE FEEDBACK POPUP
   Identical look to layout.php popup but with mcfb- prefix
   to avoid any style collisions.
══════════════════════════════════════════════════════════════ */
#mcFbOverlay {
  display: none; position: fixed; inset: 0; z-index: 10000;
  background: rgba(10,20,45,0.55);
  backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
  align-items: center; justify-content: center;
}
#mcFbOverlay.fb-active { display: flex; animation: mcFbFadeIn .25s ease; }
@keyframes mcFbFadeIn { from{opacity:0}to{opacity:1} }

#mcFbModal {
  background: #fff; border-radius: 22px;
  box-shadow: 0 28px 72px rgba(0,30,80,.24), 0 4px 18px rgba(0,0,0,.09);
  width: 100%; max-width: 500px; margin: 16px; overflow: hidden;
  animation: mcFbSlideIn .32s cubic-bezier(.34,1.26,.64,1); position: relative;
}
@keyframes mcFbSlideIn { from{opacity:0;transform:scale(.86) translateY(24px)}to{opacity:1;transform:scale(1) translateY(0)} }

.mcfb-close-x {
  position: absolute; top: 14px; right: 14px; width: 32px; height: 32px;
  border-radius: 8px; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.28);
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  z-index: 10; transition: background .15s;
}
.mcfb-close-x:hover { background: rgba(255,255,255,.32); }
.mcfb-close-x svg { width: 14px; height: 14px; fill: none; stroke: #fff; stroke-width: 2.5; }

.mcfb-header {
  background: linear-gradient(135deg,#0D1F3C 0%,#1a3a6e 60%,#1e4a8a 100%);
  padding: 30px 30px 24px; position: relative; overflow: hidden;
}
.mcfb-header::before {
  content: ''; position: absolute; top: -50px; right: -50px;
  width: 160px; height: 160px; border-radius: 50%; background: rgba(255,255,255,.05);
}
.mcfb-header::after {
  content: ''; position: absolute; bottom: -30px; left: -30px;
  width: 100px; height: 100px; border-radius: 50%; background: rgba(255,255,255,.04);
}
.mcfb-header-icon {
  width: 56px; height: 56px; border-radius: 16px;
  background: rgba(255,255,255,.12); border: 1.5px solid rgba(255,255,255,.22);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 16px; position: relative; z-index: 1;
}
.mcfb-header-icon svg { width: 26px; height: 26px; fill: none; stroke: #fff; stroke-width: 2; }
.mcfb-header-title {
  font-family: 'Inter', sans-serif; font-size: 18px; font-weight: 700;
  color: #fff; line-height: 1.4; position: relative; z-index: 1; padding-right: 44px;
}
.mcfb-header-sub {
  font-family: 'Inter', sans-serif; font-size: 13px; color: rgba(255,255,255,.6);
  margin-top: 6px; line-height: 1.5; position: relative; z-index: 1;
}
.mcfb-ticket-pill {
  display: inline-flex; align-items: center; gap: 6px; margin-top: 14px;
  background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.22);
  border-radius: 20px; padding: 5px 14px; font-size: 12px; font-weight: 600;
  color: rgba(255,255,255,.9); font-family: 'Inter', sans-serif; position: relative; z-index: 1;
}
.mcfb-ticket-pill svg { width: 12px; height: 12px; fill: none; stroke: rgba(255,255,255,.7); stroke-width: 2; }

.mcfb-body { padding: 26px 30px 30px; }

.mcfb-rating-question {
  font-family: 'Inter', sans-serif; font-size: 14px; font-weight: 600;
  color: #0D1F3C; margin-bottom: 20px; line-height: 1.5;
}

.mcfb-emoji-row { display: flex; justify-content: center; gap: 10px; margin-bottom: 8px; }
.mcfb-emoji-btn {
  cursor: pointer; width: 58px; height: 58px; border-radius: 50%;
  border: 2.5px solid transparent; background: #f4f6fb; padding: 0;
  display: flex; align-items: center; justify-content: center;
  transition: all .22s cubic-bezier(.34,1.26,.64,1); flex-shrink: 0; outline: none;
}
.mcfb-emoji-btn svg { width: 36px; height: 36px; }
.mcfb-emoji-btn:hover { transform: scale(1.18) translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,.13); }
.mcfb-emoji-btn[data-val="1"]{--ec:#EF4444;--eb:#FEE2E2;}
.mcfb-emoji-btn[data-val="2"]{--ec:#F97316;--eb:#FFEDD5;}
.mcfb-emoji-btn[data-val="3"]{--ec:#EAB308;--eb:#FEF9C3;}
.mcfb-emoji-btn[data-val="4"]{--ec:#22C55E;--eb:#DCFCE7;}
.mcfb-emoji-btn[data-val="5"]{--ec:#16A34A;--eb:#D1FAE5;}
.mcfb-emoji-btn.mcfb-selected {
  background: var(--eb); border-color: var(--ec);
  transform: scale(1.15) translateY(-4px); box-shadow: 0 8px 22px rgba(0,0,0,.14);
}

.mcfb-rating-desc {
  text-align: center; font-size: 13px; font-weight: 600; min-height: 20px;
  margin-bottom: 22px; transition: color .15s; font-family: 'Inter', sans-serif;
}

.mcfb-comment-label {
  font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600;
  color: #374151; margin-bottom: 8px; display: block;
}
.mcfb-textarea {
  width: 100%; min-height: 90px; border: 1.5px solid #dde1ef; border-radius: 10px;
  padding: 11px 14px; font-size: 13.5px; font-family: 'Inter', sans-serif;
  color: #1e2235; resize: vertical; outline: none; box-sizing: border-box;
  transition: border-color .15s, box-shadow .15s; background: #fff;
}
.mcfb-textarea::placeholder { color: #b0b7cc; }
.mcfb-textarea:focus { border-color: #185FA5; box-shadow: 0 0 0 3px rgba(24,95,165,.08); }

.mcfb-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 22px; gap: 12px; }
.mcfb-skip-btn {
  font-size: 13px; font-weight: 500; color: #9299b0; background: none; border: none;
  cursor: pointer; font-family: 'Inter', sans-serif; padding: 0; transition: color .15s;
}
.mcfb-skip-btn:hover { color: #5a607a; }
.mcfb-submit-btn {
  display: inline-flex; align-items: center; gap: 8px; padding: 12px 26px; border-radius: 10px;
  background: linear-gradient(135deg,#166534,#15803d); color: #fff;
  font-size: 13.5px; font-weight: 600; font-family: 'Inter', sans-serif; border: none; cursor: pointer;
  transition: opacity .18s, transform .15s, box-shadow .15s;
  box-shadow: 0 2px 10px rgba(22,101,52,.28);
}
.mcfb-submit-btn:hover:not(:disabled) { opacity: .9; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(22,101,52,.32); }
.mcfb-submit-btn:disabled { opacity: .42; cursor: not-allowed; }
.mcfb-submit-btn svg { width: 14px; height: 14px; fill: none; stroke: #fff; stroke-width: 2.5; }

.mcfb-success {
  display: none; flex-direction: column; align-items: center;
  justify-content: center; padding: 56px 32px; text-align: center;
}
.mcfb-success.mcfb-show { display: flex; animation: mcFbSuccessIn .35s cubic-bezier(.34,1.26,.64,1); }
@keyframes mcFbSuccessIn { from{opacity:0;transform:scale(.8)}to{opacity:1;transform:scale(1)} }
.mcfb-success-ring {
  width: 80px; height: 80px; border-radius: 50%;
  background: linear-gradient(135deg,#E6F9EE,#C6F0D6); border: 2px solid #A7E3BB;
  display: flex; align-items: center; justify-content: center; margin-bottom: 20px;
}
.mcfb-success-ring svg { width: 36px; height: 36px; fill: none; stroke: #22C55E; stroke-width: 2.5; }
.mcfb-success-title { font-family: 'Inter', sans-serif; font-size: 22px; font-weight: 700; color: #0D1F3C; margin-bottom: 10px; }
.mcfb-success-sub   { font-family: 'Inter', sans-serif; font-size: 14px; color: #7a82a0; line-height: 1.65; max-width: 300px; }

@media (max-width: 640px) {
  .mc-row1 { padding: 14px 44px 10px 18px; }
  .mc-row2 { padding: 10px 44px 10px 18px; flex-wrap: wrap; gap: 8px; }
  .mc-detail-cell { border-right: none; padding-right: 0; margin-right: 8px; }
}
</style>
<?php
$extraHead = ob_get_clean();
require 'layout.php';
?>

<!-- ══ INLINE FEEDBACK POPUP ══════════════════════════════════════════════════ -->
<div id="mcFbOverlay" role="dialog" aria-modal="true" aria-labelledby="mcFbTitle">
  <div id="mcFbModal">

    <button class="mcfb-close-x" id="mcFbCloseX" type="button" aria-label="Close">
      <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>

    <div class="mcfb-header">
      <div class="mcfb-header-icon">
        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <div class="mcfb-header-title" id="mcFbTitle">How was your Help Desk experience?</div>
      <div class="mcfb-header-sub">Your feedback helps us serve you better. Takes less than a minute.</div>
      <div class="mcfb-ticket-pill">
        <svg viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        <span id="mcFbTicketId">—</span>
      </div>
    </div>

    <div class="mcfb-body" id="mcFbBody">
      <div class="mcfb-rating-question">Rate your overall experience with this ticket:</div>

      <div class="mcfb-emoji-row" role="group" aria-label="Satisfaction rating">
        <button class="mcfb-emoji-btn" data-val="1" type="button" aria-label="Very dissatisfied">
          <svg viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="22" stroke="#EF4444" stroke-width="2.5" fill="#FEE2E2"/>
            <circle cx="17" cy="20" r="2.5" fill="#EF4444"/><circle cx="31" cy="20" r="2.5" fill="#EF4444"/>
            <path d="M16 33c2-4 14-4 16 0" stroke="#EF4444" stroke-width="2.5" stroke-linecap="round"/>
            <path d="M15 15l4 3M33 15l-4 3" stroke="#EF4444" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
        <button class="mcfb-emoji-btn" data-val="2" type="button" aria-label="Dissatisfied">
          <svg viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="22" stroke="#F97316" stroke-width="2.5" fill="#FFEDD5"/>
            <circle cx="17" cy="20" r="2.5" fill="#F97316"/><circle cx="31" cy="20" r="2.5" fill="#F97316"/>
            <path d="M17 32c2-3 12-3 14 0" stroke="#F97316" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </button>
        <button class="mcfb-emoji-btn" data-val="3" type="button" aria-label="Neutral">
          <svg viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="22" stroke="#EAB308" stroke-width="2.5" fill="#FEF9C3"/>
            <circle cx="17" cy="20" r="2.5" fill="#EAB308"/><circle cx="31" cy="20" r="2.5" fill="#EAB308"/>
            <line x1="17" y1="32" x2="31" y2="32" stroke="#EAB308" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </button>
        <button class="mcfb-emoji-btn" data-val="4" type="button" aria-label="Satisfied">
          <svg viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="22" stroke="#22C55E" stroke-width="2.5" fill="#DCFCE7"/>
            <circle cx="17" cy="20" r="2.5" fill="#22C55E"/><circle cx="31" cy="20" r="2.5" fill="#22C55E"/>
            <path d="M16 28c2 4 14 4 16 0" stroke="#22C55E" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </button>
        <button class="mcfb-emoji-btn" data-val="5" type="button" aria-label="Very satisfied">
          <svg viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="22" stroke="#16A34A" stroke-width="2.5" fill="#D1FAE5"/>
            <circle cx="17" cy="19" r="2.5" fill="#16A34A"/><circle cx="31" cy="19" r="2.5" fill="#16A34A"/>
            <path d="M14 27c2 6 18 6 20 0" stroke="#16A34A" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </button>
      </div>

      <div class="mcfb-rating-desc" id="mcFbRatingDesc" style="color:#9299b0;">Click a face to rate</div>

      <label class="mcfb-comment-label" for="mcFbComment">
        What could be improved? <span style="font-weight:400;color:#9299b0;">(optional)</span>
      </label>
      <textarea id="mcFbComment" class="mcfb-textarea"
        placeholder="Share any thoughts about the response time, staff helpfulness, or anything else…"
        maxlength="1000"></textarea>

      <div class="mcfb-footer">
        <button class="mcfb-skip-btn" id="mcFbSkipBtn" type="button">Skip for now</button>
        <button class="mcfb-submit-btn" id="mcFbSubmitBtn" type="button" disabled>
          Submit Feedback
          <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </div>
    </div>

    <div class="mcfb-success" id="mcFbSuccess">
      <div class="mcfb-success-ring">
        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <div class="mcfb-success-title">Thank you! 🎉</div>
      <div class="mcfb-success-sub">Your feedback has been recorded and will help us improve our Help Desk service.</div>
    </div>

  </div>
</div>

<!-- ══ PAGE BODY ══════════════════════════════════════════════════════════ -->
<div class="mc-page">

  <!-- Breadcrumb -->
  <div class="tp-breadcrumb">
    <a href="homepage.php">Dashboard</a>
    <span class="tp-sep">›</span>
    <span>My Complaints</span>
  </div>

  <!-- Back button -->
  <a href="homepage.php" class="tp-back-btn">
    <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
    Back to Dashboard
  </a>

  <!-- ── Header card ── -->
  <div class="mc-header-card">

    <div class="mc-top-row">
      <div class="mc-icon-title">
        <div class="mc-icon-box">
          <svg viewBox="0 0 24 24">
            <rect x="4" y="2" width="16" height="20" rx="2" ry="2" stroke="#0C447C" stroke-width="1.6" fill="none"/>
            <line x1="8" y1="7"  x2="16" y2="7"  stroke="#0C447C" stroke-width="1.4" stroke-linecap="round"/>
            <line x1="8" y1="11" x2="16" y2="11" stroke="#0C447C" stroke-width="1.4" stroke-linecap="round"/>
            <line x1="8" y1="15" x2="13" y2="15" stroke="#0C447C" stroke-width="1.4" stroke-linecap="round"/>
          </svg>
        </div>
        <div>
          <div class="mc-card-label">My Complaints</div>
          <div class="mc-card-sub">All tickets you have submitted to the Help Desk.</div>
        </div>
      </div>
    </div>

    <!-- ── Status tabs + Search ── -->
    <form method="GET" id="filterForm">
      <?php if ($filterStatus !== ''): ?>
      <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
      <?php endif; ?>

      <div class="mc-tabs">
        <?php
        $tabs = [
            ''            => ['label' => 'All',        'count' => $summary['total']],
            'open'        => ['label' => 'Open',        'count' => $summary['open']],
            'in_progress' => ['label' => 'In Progress', 'count' => $summary['in_progress']],
            'closed'      => ['label' => 'Closed',      'count' => $summary['closed']],
        ];
        foreach ($tabs as $val => $tab):
            $isActive = ($filterStatus === $val);
            $href = '?' . http_build_query(array_merge($_GET, ['status' => $val, 'page' => 1]));
        ?>
        <a href="<?php echo htmlspecialchars($href); ?>"
           class="mc-tab <?php echo $isActive ? 'active' : ''; ?>">
          <?php echo $tab['label']; ?>
          <span class="mc-tab-count"><?php echo (int)($tab['count'] ?? 0); ?></span>
        </a>
        <?php endforeach; ?>

        <!-- Search pushed to the right end of the tab row -->
        <div class="mc-tabs-search">
          <div class="mc-search-wrap">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="search" class="mc-search"
              placeholder="Search by ticket ID or title…"
              value="<?php echo htmlspecialchars($filterSearch); ?>"
              autocomplete="off">
          </div>
          <?php if ($filterSearch !== ''): ?>
          <a href="my_complaints.php<?php echo $filterStatus ? '?status=' . urlencode($filterStatus) : ''; ?>" class="mc-clear-btn">
            <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            Clear
          </a>
          <?php endif; ?>
        </div>
      </div>
    </form>

    <!-- Result bar -->
    <div class="mc-result-bar">
      <span>
        Showing <strong><?php echo number_format($totalRows); ?></strong>
        <?php echo $totalRows === 1 ? 'ticket' : 'tickets'; ?>
        <?php if ($totalPages > 1): ?>
          &nbsp;·&nbsp; Page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong>
        <?php endif; ?>
      </span>
      <?php if ($totalRows > 0): ?>
      <span><?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo number_format($totalRows); ?></span>
      <?php endif; ?>
    </div>

  </div><!-- /mc-header-card -->

  <!-- ══ TICKET CARDS ══ -->
  <div class="mc-ticket-list">
    <?php if (empty($complaints)): ?>
    <div class="mc-empty">
      <div class="mc-empty-icon">
        <svg viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      </div>
      <div class="mc-empty-title">No tickets found.</div>
      <div class="mc-empty-sub">
        <?php if ($filterStatus === '' && $filterSearch === ''): ?>
          You haven't submitted any complaints yet.
        <?php else: ?>
          No results match your current filters. <a href="my_complaints.php">Clear all filters</a>
        <?php endif; ?>
      </div>
    </div>

    <?php else:
      foreach ($complaints as $c):
        $smeta = $statusMeta[$c['status']] ?? ['label' => ucfirst($c['status']), 'color' => '#6B7280', 'bg' => '#F3F4F6', 'dot' => '#9CA3AF', 'border' => '#E5E7EB'];
        $detailUrl = 'my_ticket_detail.php?id=' . urlencode($c['ticket_id']);

        $catName  = $c['category_name'];
        $slashPos = strpos($catName, ' / ');
        $catShort = $slashPos !== false ? substr($catName, $slashPos + 3) : $catName;
        $deptShort = strlen($c['dept_name']) > 36 ? substr($c['dept_name'], 0, 34) . '…' : $c['dept_name'];

        // Truncate description to ~100 chars
        $descRaw   = trim($c['description'] ?? '');
        $descShort = mb_strlen($descRaw) > 100 ? mb_substr($descRaw, 0, 100) . '…' : $descRaw;

        // Rating
        $feedbackRating = isset($c['feedback_rating']) ? (int)$c['feedback_rating'] : 0;
        $isClosed       = $c['status'] === 'closed';
        $hasFeedback    = $feedbackRating >= 1 && $feedbackRating <= 5;
        $rmeta          = $hasFeedback ? ($ratingMeta[$feedbackRating] ?? null) : null;

        // Popup label: "RCMP-XXX — Title…"
        $titleShort = mb_strlen($c['title']) > 36 ? mb_substr($c['title'], 0, 34) . '…' : $c['title'];
        $popupLabel = htmlspecialchars($c['ticket_id'] . ' — ' . $titleShort, ENT_QUOTES);
    ?>
    <a href="<?php echo htmlspecialchars($detailUrl); ?>"
       class="mc-ticket-card"
       data-status="<?php echo htmlspecialchars($c['status']); ?>">

      <!-- ── ROW 1: Title + Desc (left) | Rating/Feedback btn + Reply count (right) ── -->
      <div class="mc-row1">
        <div class="mc-row1-left">
          <div class="mc-title-line">
    <span class="mc-title"><?php echo htmlspecialchars($c['title']); ?></span>
    <span class="mc-tid"><?php echo htmlspecialchars($c['ticket_id']); ?></span>
    <span class="mc-status-badge"
        style="background:<?php echo $smeta['bg']; ?>;color:<?php echo $smeta['color']; ?>;border-color:<?php echo $smeta['border']; ?>;">
        <span class="mc-status-dot" style="background:<?php echo $smeta['dot']; ?>"></span>
        <?php echo $smeta['label']; ?>
    </span>
</div>
          <?php if ($descShort !== ''): ?>
          <span class="mc-desc"><?php echo htmlspecialchars($descShort); ?></span>
          <?php endif; ?>
        </div>

        <div class="mc-row1-right">

          <?php if ($isClosed && $hasFeedback && $rmeta): ?>
          <!-- ─ Emoji face + score badge ─ -->
          <span class="mc-rating-display"
            style="background:<?php echo $rmeta['bg']; ?>;color:<?php echo $rmeta['color']; ?>;border-color:<?php echo $rmeta['border']; ?>;"
            title="Your rating: <?php echo $rmeta['label']; ?>">
            <span class="mc-rate-face"><?php echo $ratingEmoji[$feedbackRating]; ?></span>
            <span class="mc-rate-score"><?php echo $feedbackRating; ?>/5</span>
            <span class="mc-rate-lbl">&middot; <?php echo $rmeta['label']; ?></span>
          </span>

          <?php elseif ($isClosed && !$hasFeedback): ?>
          <!-- ─ "Give Feedback" button — opens popup, does NOT navigate ─ -->
          <button type="button" class="mc-rate-btn"
            onclick="mcFbOpen(event,'<?php echo htmlspecialchars($c['ticket_id'], ENT_QUOTES); ?>','<?php echo $popupLabel; ?>')"
            title="Rate your experience">
            <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            Give Feedback
          </button>
          <?php endif; ?>

          <?php if ((int)$c['reply_count'] > 0): ?>
          <span class="mc-reply-badge" title="<?php echo (int)$c['reply_count']; ?> replies">
            <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            <span><?php echo (int)$c['reply_count']; ?></span>
          </span>
          <?php endif; ?>

        </div>
      </div>

      <!-- ── ROW 2: Dept | Category | Date | Assignee | Status badge ── -->
      <div class="mc-row2">

        <span class="mc-detail-cell" title="<?php echo htmlspecialchars($c['dept_name']); ?>">
          <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          <span class="mc-detail-label"><?php echo htmlspecialchars($deptShort); ?></span>
        </span>

        <span class="mc-detail-cell">
          <svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
          <?php echo htmlspecialchars($catShort); ?>
        </span>

        <?php
          $statusDateTs   = !empty($c['status_changed_at']) ? strtotime($c['status_changed_at']) : null;
          $showStatusDate = in_array($c['status'], ['closed', 'in_progress']) && $statusDateTs;
        ?>

        <span class="mc-detail-cell">
          <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <?php echo date('d M Y', strtotime($c['created_at'])); ?>
          <?php if ($showStatusDate): ?>
          &nbsp;·&nbsp;
          <svg viewBox="0 0 24 24" style="width:12px;height:12px;fill:none;stroke:<?php echo $smeta['color']; ?>;stroke-width:2;opacity:.9;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span style="color:<?php echo $smeta['color']; ?>;font-weight:600;margin-left:2px;">
            <?php echo $smeta['label']; ?> : <?php echo date('d M Y, g:i A', $statusDateTs); ?>
          </span>
          <?php endif; ?>
        </span>

        

      </div>

      <!-- Hover arrow -->
      <div class="mc-card-arrow">
        <svg viewBox="0 0 24 24"><polyline points="9,18 15,12 9,6"/></svg>
      </div>

    </a>
    <?php endforeach; endif; ?>
  </div><!-- /mc-ticket-list -->

  <!-- ══ PAGINATION ══ -->
  <?php if ($totalPages > 1): ?>
  <div class="mc-pagination-card">
    <span class="mc-pager-info">
      <?php
        $from = $offset + 1;
        $to   = min($offset + $perPage, $totalRows);
        echo "{$from}–{$to} of " . number_format($totalRows) . " tickets";
      ?>
    </span>
    <div class="mc-pager">
      <?php
      $baseQuery    = http_build_query(array_diff_key($_GET, ['page' => '']));
      $prevDisabled = $currentPage <= 1 ? 'disabled' : '';
      $prevHref     = $prevDisabled ? '#' : '?' . $baseQuery . '&page=' . ($currentPage - 1);
      ?>
      <a href="<?php echo htmlspecialchars($prevHref); ?>" class="pg-btn <?php echo $prevDisabled; ?>" title="Previous">
        <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
      </a>
      <?php
      $start = max(1, $currentPage - 3);
      $end   = min($totalPages, $currentPage + 3);
      if ($start > 1): ?>
        <a href="?<?php echo $baseQuery; ?>&page=1" class="pg-btn">1</a>
        <?php if ($start > 2): ?><span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;color:#adb3c8;font-size:13px;">…</span><?php endif; ?>
      <?php endif;
      for ($p = $start; $p <= $end; $p++): ?>
      <a href="?<?php echo $baseQuery; ?>&page=<?php echo $p; ?>"
         class="pg-btn <?php echo $p === $currentPage ? 'active' : ''; ?>">
        <?php echo $p; ?>
      </a>
      <?php endfor;
      if ($end < $totalPages):
        if ($end < $totalPages - 1): ?><span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;color:#adb3c8;font-size:13px;">…</span><?php endif; ?>
        <a href="?<?php echo $baseQuery; ?>&page=<?php echo $totalPages; ?>" class="pg-btn"><?php echo $totalPages; ?></a>
      <?php endif;
      $nextDisabled = $currentPage >= $totalPages ? 'disabled' : '';
      $nextHref     = $nextDisabled ? '#' : '?' . $baseQuery . '&page=' . ($currentPage + 1);
      ?>
      <a href="<?php echo htmlspecialchars($nextHref); ?>" class="pg-btn <?php echo $nextDisabled; ?>" title="Next">
        <svg viewBox="0 0 24 24"><polyline points="9,18 15,12 9,6"/></svg>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <br>
</div><!-- /mc-page -->

<?php
ob_start();
?>
<script>
/* ── Search ── */
const searchInput = document.querySelector('.mc-search');
if (searchInput) {
  searchInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('filterForm').submit(); }
  });
}

/* ══════════════════════════════════════════════════════════════
   INLINE FEEDBACK POPUP
   — same API endpoint as layout.php (feedback_api.php)
   — triggered by "Give Feedback" button on closed cards
   — after successful submit, page reloads so emoji badge appears
══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var FB_API_URL = '<?php echo addslashes($_fbApiUrl); ?>';

  var overlay    = document.getElementById('mcFbOverlay');
  var ticketEl   = document.getElementById('mcFbTicketId');
  var ratingDesc = document.getElementById('mcFbRatingDesc');
  var commentEl  = document.getElementById('mcFbComment');
  var submitBtn  = document.getElementById('mcFbSubmitBtn');
  var skipBtn    = document.getElementById('mcFbSkipBtn');
  var closeXBtn  = document.getElementById('mcFbCloseX');
  var bodyEl     = document.getElementById('mcFbBody');
  var successEl  = document.getElementById('mcFbSuccess');

  var currentTicketId = null;
  var selectedRating  = 0;

  var DESCS  = ['','Very Dissatisfied','Dissatisfied','Neutral','Satisfied','Very Satisfied'];
  var COLORS = ['','#EF4444','#F97316','#EAB308','#22C55E','#16A34A'];

  /* ── Open from card button ── */
  window.mcFbOpen = function (e, ticketId, ticketLabel) {
    e.preventDefault();  // prevent <a> navigation
    e.stopPropagation(); // prevent card click bubbling

    currentTicketId = ticketId;
    selectedRating  = 0;
    submitBtn.disabled = true;

    document.querySelectorAll('.mcfb-emoji-btn').forEach(function(b){ b.classList.remove('mcfb-selected'); });
    ratingDesc.textContent = 'Click a face to rate';
    ratingDesc.style.color = '#9299b0';
    commentEl.value = '';
    bodyEl.style.display = '';
    successEl.classList.remove('mcfb-show');
    ticketEl.textContent = ticketLabel;

    overlay.classList.add('fb-active');
    document.body.style.overflow = 'hidden';
  };

  function closePopup() {
    overlay.classList.remove('fb-active');
    document.body.style.overflow = '';
    currentTicketId = null;
    selectedRating  = 0;
  }

  function setRating(val) {
    selectedRating = val;
    document.querySelectorAll('.mcfb-emoji-btn').forEach(function(b){
      b.classList.toggle('mcfb-selected', parseInt(b.dataset.val, 10) === val);
    });
    ratingDesc.textContent = DESCS[val] || '';
    ratingDesc.style.color = COLORS[val] || '#9299b0';
    submitBtn.disabled = false;
  }

  document.querySelectorAll('.mcfb-emoji-btn').forEach(function(b){
    b.addEventListener('click', function(){ setRating(parseInt(b.dataset.val, 10)); });
  });

  function submitFeedback() {
    if (!currentTicketId || selectedRating < 1) return;
    submitBtn.disabled    = true;
    submitBtn.textContent = 'Submitting…';

    var fd = new FormData();
    fd.append('action',    'submit');
    fd.append('ticket_id', currentTicketId);
    fd.append('rating',    String(selectedRating));
    fd.append('comment',   commentEl.value.trim());
    fd.append('auto',      '0');

    fetch(FB_API_URL, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.success) {
          bodyEl.style.display = 'none';
          successEl.classList.add('mcfb-show');
          /* Reload after 2.2s so the emoji face badge replaces the button */
          setTimeout(function(){ window.location.reload(); }, 2200);
        } else {
          submitBtn.disabled = false;
          submitBtn.innerHTML = 'Submit Feedback <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:#fff;stroke-width:2.5"><polyline points="9 18 15 12 9 6"/></svg>';
        }
      })
      .catch(function(){
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Submit Feedback <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:#fff;stroke-width:2.5"><polyline points="9 18 15 12 9 6"/></svg>';
      });
  }

  submitBtn.addEventListener('click',  submitFeedback);
  skipBtn.addEventListener('click',    closePopup);
  closeXBtn.addEventListener('click',  closePopup);
  overlay.addEventListener('click',    function(e){ if (e.target === overlay) closePopup(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && overlay.classList.contains('fb-active')) closePopup(); });
})();
</script>
<?php
$extraFoot = ob_get_clean();
require 'layout_end.php';
?>