<?php
// complaint/my_requisition_detail.php
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
$userId        = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 0);
$submitterType = ($userRole === 'student') ? 'student' : 'staff';

// ── FETCH REQUISITION ─────────────────────────────────────────────────────────
$refNumber   = trim($_GET['ref'] ?? '');
$requisition = null;

if ($refNumber !== '') {
    $stmt = $conn->prepare("
        SELECT r.*
        FROM requisitions r
        WHERE r.ref_number = ? AND r.submitter_id = ? AND r.submitter_type = ?
        LIMIT 1
    ");
    $stmt->bind_param("sis", $refNumber, $userId, $submitterType);
    $stmt->execute();
    $requisition = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ── FETCH ASSIGNED STAFF (handler) ───────────────────────────────────────────
$handledBy = null;
if ($requisition && !empty($requisition['assigned_to'])) {
    $hs = $conn->prepare("
        SELECT full_name, role, department FROM staff
        WHERE staff_id = ? AND status = 'active' LIMIT 1
    ");
    $hs->bind_param("i", $requisition['assigned_to']);
    $hs->execute();
    $handledBy = $hs->get_result()->fetch_assoc();
    $hs->close();
}

// ── FETCH REPLIES / CONVERSATION ─────────────────────────────────────────────
$replies = [];
if ($requisition) {
    $rq = $conn->prepare("
        SELECT reply_id, sender_id, sender_name, sender_role, message, attachment_path, created_at
        FROM requisition_replies
        WHERE ref_number = ?
        ORDER BY created_at ASC
    ");
    $rq->bind_param("s", $refNumber);
    $rq->execute();
    $replies = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
    $rq->close();
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
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
function fileCardMeta(string $path): array {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match($ext) {
        'pdf'        => ['label' => 'PDF',  'bg' => '#FEF2F2', 'color' => '#DC2626'],
        'doc','docx' => ['label' => 'DOC',  'bg' => '#EFF6FF', 'color' => '#1D4ED8'],
        'xls','xlsx' => ['label' => 'XLS',  'bg' => '#F0FDF4', 'color' => '#15803D'],
        'txt'        => ['label' => 'TXT',  'bg' => '#F9FAFB', 'color' => '#374151'],
        default      => ['label' => strtoupper($ext) ?: 'FILE', 'bg' => '#F3F4F6', 'color' => '#6B7280'],
    };
}

// ── STATUS MAP  (pending → approved → completed | rejected) ──────────────────
// DB enum: 'pending','approved','completed','rejected'
$statusMap = [
    'pending'   => ['label' => 'Pending',   'color' => '#854F0B', 'bg' => '#FAEEDA', 'dot' => '#EF9F27', 'step' => 1],
    'approved'  => ['label' => 'Approved',  'color' => '#0C447C', 'bg' => '#E6F1FB', 'dot' => '#378ADD', 'step' => 2],
    'completed' => ['label' => 'Completed', 'color' => '#27500A', 'bg' => '#EAF3DE', 'dot' => '#1D9E75', 'step' => 3],
    'rejected'  => ['label' => 'Rejected',  'color' => '#6B2A2A', 'bg' => '#FEF2F2', 'dot' => '#DC2626', 'step' => 0],
];

$urgencyMap = [
    'normal'   => ['label' => 'Normal',   'color' => '#1D4ED8', 'bg' => '#EFF6FF', 'border' => '#93C5FD'],
    'urgent'   => ['label' => 'Urgent',   'color' => '#92520C', 'bg' => '#FEF3E2', 'border' => '#FCD34D'],
    'critical' => ['label' => 'Critical', 'color' => '#B71C1C', 'bg' => '#FDECEA', 'border' => '#FCA5A5'],
];

$curStatus  = $statusMap[$requisition['status']  ?? 'pending'] ?? $statusMap['pending'];
$curUrgency = $urgencyMap[$requisition['urgency'] ?? 'normal']  ?? $urgencyMap['normal'];
$curStep    = $curStatus['step'];

$pageTitle    = 'Requisition Detail';
$pageSubtitle = date('l, d F Y');
$activeNav    = 'dashboard';

ob_start();
?>
<style>
/* ══════════════════════════════════════════════════════════
   MY REQUISITION DETAIL
══════════════════════════════════════════════════════════ */
.rq-page { display:flex; flex-direction:column; gap:16px; width:100%; align-items:flex-start; }

.rq-breadcrumb { display:flex; align-items:center; gap:6px; font-size:13px; color:var(--g500); flex-wrap:wrap; }
.rq-breadcrumb a { color:var(--g500); text-decoration:none; }
.rq-breadcrumb a:hover { color:var(--blue); }
.rq-sep { color:var(--g300); }

.rq-back-btn { display:inline-flex; align-items:center; gap:7px; padding:9px 16px; border-radius:9px; border:0.5px solid var(--g200); background:white; color:var(--g700); font-size:13px; font-weight:500; text-decoration:none; transition:border-color .2s,background .2s,color .2s; font-family:'DM Sans',sans-serif; }
.rq-back-btn:hover { border-color:#854f0b; background:#854f0b; color:#fff; }
.rq-back-btn svg { width:14px; height:14px; fill:none; stroke:currentColor; stroke-width:2; }

.rq-card { background:white; border-radius:16px; border:0.5px solid var(--g200); padding:28px 32px; box-shadow:0 1px 4px rgba(0,0,0,.04); width:100%; max-width:1000px; align-self:center; box-sizing:border-box; }

.rq-notfound { text-align:center; padding:80px 24px; max-width:440px; align-self:center; }
.rq-notfound svg { width:40px; height:40px; fill:none; stroke:var(--g300); stroke-width:1.5; margin:0 auto 16px; display:block; }
.rq-notfound h2 { font-size:22px; color:var(--g900); margin-bottom:8px; }
.rq-notfound p  { font-size:14px; color:var(--g500); line-height:1.65; margin-bottom:20px; }

/* ── Top row ── */
.rq-top-row { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:12px; }
.rq-icon-title { display:flex; align-items:center; gap:12px; }
.rq-icon-box { width:44px; height:44px; border-radius:12px; background:#faeeda; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.rq-icon-box svg { width:22px; height:22px; fill:none; stroke:#854f0b; stroke-width:1.8; }
.rq-card-label { font-size:15px; font-weight:600; color:var(--g900); }
.rq-card-ref   { font-size:12px; color:var(--g500); margin-top:2px; font-family:monospace; }
.rq-status-badge { font-size:12px; font-weight:600; padding:5px 12px 5px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
.rq-status-dot   { width:7px; height:7px; border-radius:50%; flex-shrink:0; }

/* ── Progress tracker ── */
.rq-tracker { display:flex; align-items:flex-start; margin-bottom:28px; background:#fafaf8; border:0.5px solid #e8eaf2; border-radius:14px; padding:20px 24px; position:relative; }
.rq-tracker-line { position:absolute; top:38px; left:76px; right:76px; height:2px; background:#e0e4ef; z-index:0; }
.rq-tracker-line-fill { height:100%; background:linear-gradient(90deg,#854f0b,#D4A017); border-radius:2px; transition:width 0.5s ease; }
.rq-tracker-steps { display:flex; justify-content:space-between; width:100%; position:relative; z-index:1; }
.rq-tracker-step  { display:flex; flex-direction:column; align-items:center; gap:8px; flex:1; }

.rq-step-circle { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; border:2px solid #d0d4e0; background:#fff; color:#adb3c8; position:relative; z-index:2; transition:all 0.3s ease; box-shadow:0 0 0 3px #fafaf8; }
.rq-step-circle.done      { background:linear-gradient(135deg,#854f0b,#D4A017); border-color:#854f0b; color:#fff; box-shadow:0 0 0 3px rgba(133,79,11,.12); }
.rq-step-circle.active    { background:#faeeda; border-color:#854f0b; color:#854f0b; box-shadow:0 0 0 4px rgba(133,79,11,.15),0 0 0 7px rgba(133,79,11,.06); }
.rq-step-circle.rejected  { background:#FEF2F2; border-color:#DC2626; color:#DC2626; }
.rq-step-circle svg       { width:16px; height:16px; fill:none; stroke:currentColor; stroke-width:2.5; }

.rq-step-label { font-size:12px; font-weight:600; color:#adb3c8; text-align:center; line-height:1.3; }
.rq-step-label.done,
.rq-step-label.active    { color:#854f0b; }
.rq-step-label.rejected  { color:#DC2626; }
.rq-step-sublabel        { font-size:10.5px; color:#c8ccd8; text-align:center; }
.rq-step-sublabel.done,
.rq-step-sublabel.active { color:#b07040; }

/* ── Title row ── */
.rq-title-row { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
.rq-title    { font-size:20px; font-weight:600; color:var(--g900); line-height:1.3; }
.rq-ref-pill { background:var(--g100); color:var(--g500); font-size:11px; padding:4px 11px; border-radius:6px; font-family:monospace; white-space:nowrap; flex-shrink:0; }

/* ── Meta grid ── */
.rq-meta-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:0; margin-bottom:20px; }
.rq-meta-cell { padding:14px 18px; }
.rq-meta-label { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--g400); margin-bottom:5px; }
.rq-meta-value { font-size:13.5px; font-weight:500; color:var(--g900); line-height:1.4; }

.rq-urgency-badge { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:4px 12px; border-radius:20px; border:1px solid; }

/* ── Desc / section labels ── */
.rq-section-label { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--g400); margin-bottom:6px; display:block; }
.rq-desc-box { font-size:13.5px; color:var(--g900); line-height:1.65; white-space:pre-wrap; word-break:break-word; }

/* ── Attachment card ── */
.rq-attach-card { display:inline-flex; align-items:center; gap:10px; margin-top:8px; padding:10px 14px; border-radius:10px; text-decoration:none; max-width:280px; transition:opacity .15s; background:white; border:0.5px solid var(--g200); color:var(--g900); }
.rq-attach-card:hover { opacity:.82; }
.rq-attach-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
.rq-attach-info { flex:1; min-width:0; }
.rq-attach-name { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; }
.rq-attach-meta { font-size:11px; margin-top:2px; opacity:.65; }

/* ── Divider ── */
.rq-divider { height:0.5px; background:var(--g100); margin:24px 0; }

/* ── Section header ── */
.rq-section-header { display:flex; align-items:center; gap:8px; margin-bottom:14px; }
.rq-section-header svg  { width:15px; height:15px; fill:none; stroke:var(--g500); stroke-width:2; }
.rq-section-header span { font-size:11px; text-transform:uppercase; letter-spacing:.08em; font-weight:600; color:var(--g500); }

/* ── Handler card ── */
.rq-handler-card { background:#faeeda; border:1px solid rgba(133,79,11,.2); border-radius:12px; padding:14px 18px; display:flex; align-items:center; gap:14px; max-width:400px; }
.rq-handler-avatar { width:40px; height:40px; border-radius:50%; background:rgba(133,79,11,.18); display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; color:#854f0b; flex-shrink:0; }
.rq-handler-name { font-size:13.5px; font-weight:600; color:var(--g900); }
.rq-handler-dept { font-size:12px; color:var(--g500); margin-top:2px; }
.rq-handler-role { display:inline-flex; align-items:center; gap:5px; margin-top:6px; font-size:11px; font-weight:600; color:#854f0b; background:rgba(133,79,11,.1); padding:2px 9px; border-radius:20px; }

.rq-unassigned-card { background:#F3F4F6; border:0.5px solid #E5E7EB; border-radius:12px; padding:14px 18px; display:flex; align-items:center; gap:12px; max-width:340px; }
.rq-unassigned-avatar { width:38px; height:38px; border-radius:50%; background:#EEF2F7; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.rq-unassigned-avatar svg { width:18px; height:18px; fill:none; stroke:#9CA3AF; stroke-width:1.8; }
.rq-unassigned-name { font-size:13px; font-weight:600; color:var(--g700); }
.rq-unassigned-sub  { font-size:12px; color:var(--g500); margin-top:2px; }

/* ── Remarks ── */
.rq-remarks-box { background:#F0FDF4; border:1px solid #A7F3D0; border-radius:10px; padding:14px 18px; font-size:13.5px; color:#065F46; line-height:1.65; white-space:pre-wrap; word-break:break-word; }
.rq-remarks-box.rejected { background:#FEF2F2; border-color:#FCA5A5; color:#7F1D1D; }

/* ── Timeline ── */
.rq-timeline { display:flex; flex-direction:column; gap:0; }
.rq-tl-item  { display:flex; gap:14px; position:relative; }
.rq-tl-item:not(:last-child) .rq-tl-line { position:absolute; left:17px; top:34px; bottom:0; width:1px; background:#e0e4ef; z-index:0; }
.rq-tl-icon-wrap { width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; position:relative; z-index:1; }
.rq-tl-icon-wrap svg { width:14px; height:14px; fill:none; stroke:currentColor; stroke-width:2; }
.rq-tl-body   { flex:1; padding-bottom:18px; }
.rq-tl-action { font-size:13px; font-weight:600; color:var(--g900); }
.rq-tl-meta   { font-size:11px; color:var(--g400); margin-top:2px; }
.rq-tl-note   { font-size:12px; color:#444; background:var(--g100); border-radius:7px; padding:7px 11px; margin-top:6px; line-height:1.55; }

/* ── Messages ── */
.rq-messages-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
.rq-msg-count-pill  { display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; border-radius:11px; background:#854f0b; color:white; font-size:11px; font-weight:700; padding:0 7px; }
.rq-messages { display:flex; flex-direction:column; gap:14px; min-height:120px; max-height:420px; overflow-y:auto; scroll-behavior:smooth; padding:4px 0; }
.rq-messages::-webkit-scrollbar       { width:3px; }
.rq-messages::-webkit-scrollbar-thumb { background:var(--g200); border-radius:3px; }

.rq-empty-msg   { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:40px 20px; gap:10px; text-align:center; }
.rq-empty-icon  { width:44px; height:44px; border-radius:12px; background:var(--g100); display:flex; align-items:center; justify-content:center; }
.rq-empty-icon svg { width:20px; height:20px; fill:none; stroke:var(--g300); stroke-width:1.6; }
.rq-empty-title { font-size:14px; font-weight:600; color:var(--g700); }
.rq-empty-sub   { font-size:13px; color:var(--g400); max-width:260px; line-height:1.6; }

.rq-date-sep { display:flex; align-items:center; gap:10px; margin:4px 0; }
.rq-date-sep::before,.rq-date-sep::after { content:''; flex:1; height:0.5px; background:var(--g200); }
.rq-date-sep span { font-size:11px; color:var(--g400); white-space:nowrap; }

.rq-msg-row    { display:flex; gap:9px; align-items:flex-end; }
.rq-msg-row.rq-me   { flex-direction:row-reverse; }
.rq-msg-row.rq-dept { flex-direction:row; }
.rq-avatar { width:30px; height:30px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; }
.rq-avatar.av-me   { background:rgba(133,79,11,.2); color:#854f0b; }
.rq-avatar.av-dept { background:#faeeda; color:#854f0b; }
.rq-msg-body { max-width:72%; display:flex; flex-direction:column; gap:3px; }
.rq-msg-row.rq-me   .rq-msg-body { align-items:flex-end; }
.rq-msg-row.rq-dept .rq-msg-body { align-items:flex-start; }
.rq-msg-name { font-size:11px; color:var(--g400); }
.rq-bubble   { padding:10px 14px; border-radius:12px; font-size:14px; line-height:1.65; word-break:break-word; white-space:pre-wrap; }
.rq-msg-row.rq-me   .rq-bubble { background:#854f0b; color:white; border-radius:12px 12px 3px 12px; }
.rq-msg-row.rq-dept .rq-bubble { background:var(--g100); color:var(--g900); border:0.5px solid var(--g200); border-radius:12px 12px 12px 3px; }

.rq-img-bubble { display:block; border-radius:12px; overflow:hidden; max-width:200px; width:100%; cursor:pointer; position:relative; line-height:0; }
.rq-msg-row.rq-me   .rq-img-bubble { border-radius:12px 12px 3px 12px; }
.rq-msg-row.rq-dept .rq-img-bubble { border-radius:12px 12px 12px 3px; }
.rq-img-bubble img { width:100%; height:auto; max-height:180px; object-fit:cover; display:block; border-radius:inherit; transition:filter .2s; }
.rq-img-bubble:hover img { filter:brightness(.9); }
.rq-img-bubble::after { content:''; position:absolute; bottom:7px; right:7px; width:22px; height:22px; background:rgba(0,0,0,.45) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2.5'%3E%3Cpolyline points='15 3 21 3 21 9'/%3E%3Cpolyline points='9 21 3 21 3 15'/%3E%3Cline x1='21' y1='3' x2='14' y2='10'/%3E%3Cline x1='3' y1='21' x2='10' y2='14'/%3E%3C/svg%3E") center/12px no-repeat; border-radius:5px; backdrop-filter:blur(2px); }

.rq-file-card { display:flex; align-items:center; gap:10px; margin-top:6px; padding:10px 14px; border-radius:10px; text-decoration:none; max-width:260px; transition:opacity .15s; background:white; border:0.5px solid var(--g200); color:var(--g900); }
.rq-msg-row.rq-me .rq-file-card { background:rgba(255,255,255,.15); border:0.5px solid rgba(255,255,255,.3); color:white; }
.rq-file-card:hover { opacity:.82; }
.rq-file-card-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
.rq-file-card-info { flex:1; min-width:0; }
.rq-file-card-name { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; }
.rq-file-card-meta { font-size:11px; margin-top:2px; opacity:.65; }
.rq-file-card-dl   { width:14px; height:14px; fill:none; stroke:currentColor; stroke-width:2; flex-shrink:0; opacity:.7; }

.rq-readonly-bar { display:flex; align-items:center; justify-content:center; gap:8px; padding:12px 16px; background:var(--g50,#fafaf8); border-radius:10px; border:0.5px solid var(--g100); font-size:13px; color:var(--g500); margin-top:16px; }
.rq-readonly-bar svg { width:14px; height:14px; fill:none; stroke:var(--g400); stroke-width:2; flex-shrink:0; }

/* ── Lightbox ── */
#rqLightbox { display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,.88); backdrop-filter:blur(6px); align-items:center; justify-content:center; }
#rqLightbox.active { display:flex; }
#rqLightboxInner { position:relative; max-width:92vw; max-height:90vh; display:flex; flex-direction:column; align-items:center; gap:14px; animation:lbIn .2s cubic-bezier(.34,1.26,.64,1); }
@keyframes lbIn { from{transform:scale(.88);opacity:0} to{transform:scale(1);opacity:1} }
#rqLightboxImg { max-width:92vw; max-height:80vh; border-radius:10px; object-fit:contain; box-shadow:0 20px 60px rgba(0,0,0,.6); display:block; }
#rqLightboxClose { position:fixed; top:18px; right:20px; width:38px; height:38px; border-radius:50%; border:none; background:rgba(255,255,255,.12); color:white; cursor:pointer; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(4px); z-index:10000; transition:background .15s; }
#rqLightboxClose:hover { background:rgba(255,255,255,.25); }
#rqLightboxDownload { display:inline-flex; align-items:center; gap:7px; padding:9px 18px; border-radius:22px; background:rgba(255,255,255,.13); color:white; font-size:13px; font-weight:500; text-decoration:none; border:0.5px solid rgba(255,255,255,.25); backdrop-filter:blur(4px); }
#rqLightboxDownload svg { width:13px; height:13px; fill:none; stroke:white; stroke-width:2; }

@media (max-width:600px) {
    .rq-card      { padding:20px 18px; }
    .rq-meta-grid { grid-template-columns:1fr 1fr; }
    .rq-tracker   { padding:16px 14px; }
    .rq-title     { font-size:17px; }
}
@media (max-width:420px) { .rq-meta-grid { grid-template-columns:1fr; } }
</style>
<?php
$extraHead = ob_get_clean();
require 'layout.php';
?>

<div class="rq-page">

    <div class="rq-breadcrumb">
        <a href="homepage.php">Dashboard</a>
        <span class="rq-sep">›</span>
        <span><?php echo htmlspecialchars($refNumber ?: 'Requisition Detail'); ?></span>
    </div>

    <a href="homepage.php" class="rq-back-btn">
        <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
        Back to Dashboard
    </a>

<?php if (!$requisition): ?>

    <div class="rq-notfound">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <h2>Requisition Not Found</h2>
        <p>Reference <strong><?php echo htmlspecialchars($refNumber ?: '—'); ?></strong> doesn't exist or you don't have access to it.</p>
        <a href="homepage.php" class="rq-back-btn">
            <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
            Back to Dashboard
        </a>
    </div>

<?php else:
    $isRejected  = ($requisition['status'] === 'rejected');
    $isCompleted = ($requisition['status'] === 'completed');
    $isApproved  = ($requisition['status'] === 'approved');

    // Progress line fill: pending=0%, approved=50%, completed=100%
    $linePct = match($requisition['status']) {
        'approved'  => 50,
        'completed' => 100,
        default     => 0,
    };

    // Step state helper
    function stepState(int $num, int $cur, bool $rejected): string {
        if ($rejected) return $num === 1 ? 'rejected' : 'inactive';
        if ($num < $cur)  return 'done';
        if ($num === $cur) return 'active';
        return 'inactive';
    }

    $steps = [
        1 => ['label' => 'Pending',   'sub' => 'Request submitted'],
        2 => ['label' => 'Approved',  'sub' => 'AFSMD reviewing'],
        3 => ['label' => 'Completed', 'sub' => 'Item delivered'],
    ];

    $attachPath = $requisition['attachment_path'] ?? '';
    $hasAttach  = !empty($attachPath);
    $isImg      = $hasAttach && isImageAttachment($attachPath);
    $fileExt    = $hasAttach ? strtoupper(pathinfo($attachPath, PATHINFO_EXTENSION)) : '';
    $fileMeta2  = ($hasAttach && !$isImg) ? fileCardMeta($attachPath) : [];
    $fileName2  = $hasAttach ? basename($attachPath) : '';
?>

    <div class="rq-card">

        <!-- Top row -->
        <div class="rq-top-row">
            <div class="rq-icon-title">
                <div class="rq-icon-box">
                    <svg viewBox="0 0 24 24">
                        <path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                        <path d="M16 3H8a1 1 0 0 0-1 1v3h10V4a1 1 0 0 0-1-1z"/>
                    </svg>
                </div>
                <div>
                    <div class="rq-card-label">Equipment Requisition</div>
                    <div class="rq-card-ref"><?php echo htmlspecialchars($requisition['ref_number']); ?></div>
                </div>
            </div>
            <span class="rq-status-badge"
                  style="background:<?php echo $curStatus['bg']; ?>;color:<?php echo $curStatus['color']; ?>">
                <span class="rq-status-dot" style="background:<?php echo $curStatus['dot']; ?>"></span>
                <?php echo $curStatus['label']; ?>
            </span>
        </div>

        <!-- Progress tracker -->
        <?php if (!$isRejected): ?>
        <div class="rq-tracker">
            <div class="rq-tracker-line">
                <div class="rq-tracker-line-fill" style="width:<?php echo $linePct; ?>%"></div>
            </div>
            <div class="rq-tracker-steps">
                <?php foreach ($steps as $num => $step):
                    $state = stepState($num, $curStep, $isRejected);
                ?>
                <div class="rq-tracker-step">
                    <div class="rq-step-circle <?php echo $state; ?>">
                        <?php if ($state === 'done'): ?>
                            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php elseif ($state === 'active'): ?>
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" fill="currentColor" stroke="none"/></svg>
                        <?php else: ?>
                            <?php echo $num; ?>
                        <?php endif; ?>
                    </div>
                    <div class="rq-step-label <?php echo $state; ?>"><?php echo $step['label']; ?></div>
                    <div class="rq-step-sublabel <?php echo $state; ?>"><?php echo $step['sub']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <!-- Rejected banner -->
        <div style="display:flex;align-items:center;gap:12px;padding:14px 20px;background:#FEF2F2;border:1px solid #FCA5A5;border-radius:12px;margin-bottom:20px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="15" y1="9" x2="9" y2="15"/>
                <line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
            <div>
                <div style="font-size:13.5px;font-weight:600;color:#7F1D1D;">This requisition has been Rejected</div>
                <div style="font-size:12px;color:#B91C1C;margin-top:2px;">Please contact AFSMD if you need further clarification.</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Title row -->
        <div class="rq-title-row">
            <div class="rq-title">Requested: <?php echo htmlspecialchars($requisition['item_name']); ?></div>
            <div class="rq-ref-pill"><?php echo htmlspecialchars($requisition['ref_number']); ?></div>
        </div>

        <!-- Meta grid -->
        <div class="rq-meta-grid">
            <div class="rq-meta-cell">
                <div class="rq-meta-label">Category</div>
                <div class="rq-meta-value"><?php echo htmlspecialchars($requisition['category']); ?></div>
            </div>
            <div class="rq-meta-cell">
                <div class="rq-meta-label">Quantity</div>
                <div class="rq-meta-value">
                    <?php $qty = (int)$requisition['quantity']; echo $qty.' '.($qty===1?'unit':'units'); ?>
                </div>
            </div>
            <div class="rq-meta-cell">
                <div class="rq-meta-label">Urgency</div>
                <div class="rq-meta-value">
                    <span class="rq-urgency-badge"
                          style="background:<?php echo $curUrgency['bg']; ?>;color:<?php echo $curUrgency['color']; ?>;border-color:<?php echo $curUrgency['border']; ?>">
                        <?php echo $curUrgency['label']; ?>
                    </span>
                </div>
            </div>
            <div class="rq-meta-cell">
                <div class="rq-meta-label">Your Department</div>
                <div class="rq-meta-value"><?php echo htmlspecialchars($requisition['my_department']); ?></div>
            </div>
            <div class="rq-meta-cell">
                <div class="rq-meta-label">Delivery Location</div>
                <div class="rq-meta-value"><?php echo htmlspecialchars($requisition['location']); ?></div>
            </div>
            <div class="rq-meta-cell">
                <div class="rq-meta-label">Contact Number</div>
                <div class="rq-meta-value">+60 <?php echo htmlspecialchars($requisition['phone']); ?></div>
            </div>
            <div class="rq-meta-cell">
                <div class="rq-meta-label">Submitted</div>
                <div class="rq-meta-value"><?php echo date('d M Y, H:i', strtotime($requisition['created_at'])); ?></div>
            </div>
            <div class="rq-meta-cell">
                <div class="rq-meta-label">Last Updated</div>
                <div class="rq-meta-value"><?php echo date('d M Y, H:i', strtotime($requisition['updated_at'] ?? $requisition['created_at'])); ?></div>
            </div>
            <div class="rq-meta-cell">
                <div class="rq-meta-label">
                    <?php echo $isCompleted ? 'Completed On' : 'Forwarded To'; ?>
                </div>
                <div class="rq-meta-value">
                    <?php if ($isCompleted && !empty($requisition['completed_at'])): ?>
                        <?php echo date('d M Y, H:i', strtotime($requisition['completed_at'])); ?>
                    <?php else: ?>
                        Administration &amp; Facilities Management Dept
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Justification -->
        <div style="padding:4px 2px 16px;">
            <span class="rq-section-label">Justification / Reason</span>
            <div class="rq-desc-box"><?php echo nl2br(htmlspecialchars($requisition['reason'])); ?></div>
        </div>

        <!-- Attachment -->
        <?php if ($hasAttach): ?>
        <div style="margin-bottom:20px;">
            <span class="rq-section-label">Supporting Document</span>
            <?php if ($isImg): ?>
                <a href="../<?php echo htmlspecialchars($attachPath); ?>"
                   style="max-width:200px;display:block;border-radius:10px;overflow:hidden;line-height:0;margin-top:6px;"
                   onclick="rqOpenLightbox(this.href);return false;">
                    <img src="../<?php echo htmlspecialchars($attachPath); ?>" alt="Attachment" loading="lazy"
                         style="width:100%;height:auto;max-height:160px;object-fit:cover;display:block;">
                </a>
            <?php else: ?>
                <a href="../<?php echo htmlspecialchars($attachPath); ?>" class="rq-attach-card" target="_blank" rel="noopener">
                    <div class="rq-attach-icon" style="background:<?php echo $fileMeta2['bg']; ?>;color:<?php echo $fileMeta2['color']; ?>">
                        <?php echo $fileMeta2['label']; ?>
                    </div>
                    <div class="rq-attach-info">
                        <span class="rq-attach-name"><?php echo htmlspecialchars(strlen($fileName2)>30?substr($fileName2,0,28).'…':$fileName2); ?></span>
                        <span class="rq-attach-meta"><?php echo $fileExt; ?> file · tap to open</span>
                    </div>
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="opacity:.6">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="rq-divider"></div>

        <!-- Handled By -->
        <div class="rq-section-header">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span>Handled By</span>
        </div>

        <?php if ($handledBy): ?>
        <div class="rq-handler-card">
            <div class="rq-handler-avatar"><?php echo htmlspecialchars(getInitials($handledBy['full_name'])); ?></div>
            <div>
                <div class="rq-handler-name"><?php echo htmlspecialchars($handledBy['full_name']); ?></div>
                <div class="rq-handler-dept">Administration &amp; Facilities Management Dept</div>
                <div class="rq-handler-role"><?php echo ucfirst($handledBy['role']); ?></div>
            </div>
        </div>
        <?php else: ?>
        <div class="rq-unassigned-card">
            <div class="rq-unassigned-avatar">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="rq-unassigned-name">Awaiting Assignment</div>
                <div class="rq-unassigned-sub">Your request is in the processing queue</div>
                <div style="display:inline-flex;align-items:center;gap:5px;margin-top:6px;background:#F3F4F6;border:0.5px solid #E5E7EB;border-radius:20px;padding:3px 10px;font-size:10.5px;font-weight:600;color:#6B7280;letter-spacing:.05em;">
                    <span style="width:6px;height:6px;border-radius:50%;background:#9CA3AF;display:inline-block;"></span>
                    IN QUEUE
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Admin Remarks -->
        <?php if (!empty($requisition['remarks'])): ?>
        <div style="margin-top:16px;">
            <div class="rq-section-header" style="margin-bottom:8px;">
                <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Admin Remarks</span>
            </div>
            <div class="rq-remarks-box <?php echo $isRejected ? 'rejected' : ''; ?>">
                <?php echo nl2br(htmlspecialchars($requisition['remarks'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="rq-divider"></div>

        <!-- Status Timeline -->
        <div class="rq-section-header">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <span>Status Timeline</span>
        </div>

        <div class="rq-timeline">

            <!-- 1. Submitted (always) -->
            <div class="rq-tl-item">
                <div class="rq-tl-line"></div>
                <div class="rq-tl-icon-wrap" style="background:#faeeda;color:#854f0b;">
                    <svg viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg>
                </div>
                <div class="rq-tl-body">
                    <div class="rq-tl-action">Request Submitted</div>
                    <div class="rq-tl-meta">
                        <?php echo date('d M Y, H:i', strtotime($requisition['created_at'])); ?> · by <?php echo htmlspecialchars($userName); ?>
                    </div>
                    <div class="rq-tl-note">
                        <?php echo htmlspecialchars($requisition['category']); ?> —
                        <?php echo htmlspecialchars($requisition['item_name']); ?> ×<?php echo (int)$requisition['quantity']; ?>
                    </div>
                </div>
            </div>

            <!-- 2. Assigned / Rejected -->
            <?php if ($requisition['status'] !== 'pending'): ?>
            <div class="rq-tl-item">
                <div class="rq-tl-line"></div>
                <div class="rq-tl-icon-wrap"
                     style="background:<?php echo $isRejected?'#FEF2F2':'#faeeda'; ?>;
                            color:<?php echo $isRejected?'#DC2626':'#854f0b'; ?>">
                    <?php if ($isRejected): ?>
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    <?php endif; ?>
                </div>
                <div class="rq-tl-body">
                    <div class="rq-tl-action">
                        <?php echo $isRejected ? 'Request Rejected' : 'Assigned &amp; Under Review'; ?>
                    </div>
                    <div class="rq-tl-meta">
                        <?php echo date('d M Y, H:i', strtotime($requisition['updated_at'] ?? $requisition['created_at'])); ?>
                        <?php if ($handledBy): ?> · by <?php echo htmlspecialchars($handledBy['full_name']); ?><?php endif; ?>
                    </div>
                    <?php if (!empty($requisition['remarks']) && $isRejected): ?>
                    <div class="rq-tl-note" style="background:#FEF2F2;color:#7F1D1D;">
                        <?php echo nl2br(htmlspecialchars($requisition['remarks'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 3. Approved -->
            <?php if (in_array($requisition['status'], ['approved','completed'])): ?>
            <div class="rq-tl-item">
                <div class="rq-tl-line"></div>
                <div class="rq-tl-icon-wrap" style="background:#E6F1FB;color:#0C447C;">
                    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="rq-tl-body">
                    <div class="rq-tl-action">Request Approved</div>
                    <div class="rq-tl-meta">
                        <?php echo date('d M Y, H:i', strtotime($requisition['approved_at'] ?? $requisition['updated_at'])); ?>
                        <?php if ($handledBy): ?> · by <?php echo htmlspecialchars($handledBy['full_name']); ?><?php endif; ?>
                    </div>
                    <?php if (!empty($requisition['remarks']) && !$isRejected): ?>
                    <div class="rq-tl-note"><?php echo nl2br(htmlspecialchars($requisition['remarks'])); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 4. Completed -->
            <?php if ($isCompleted): ?>
            <div class="rq-tl-item">
                <div class="rq-tl-icon-wrap" style="background:#EAF3DE;color:#27500A;">
                    <svg viewBox="0 0 24 24">
                        <path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                        <line x1="8" y1="17" x2="8" y2="21"/>
                        <line x1="16" y1="17" x2="16" y2="21"/>
                    </svg>
                </div>
                <div class="rq-tl-body">
                    <div class="rq-tl-action">Request Completed</div>
                    <div class="rq-tl-meta">
                        <?php echo date('d M Y, H:i', strtotime($requisition['completed_at'] ?? $requisition['updated_at'])); ?>
                        <?php if ($handledBy): ?> · by <?php echo htmlspecialchars($handledBy['full_name']); ?><?php endif; ?>
                    </div>
                    <div class="rq-tl-note" style="background:#EAF3DE;color:#27500A;">
                        Delivered to: <?php echo htmlspecialchars($requisition['location']); ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /rq-timeline -->

        <div class="rq-divider"></div>

        <!-- Messages -->
        <div class="rq-messages-header">
            <div class="rq-section-header" style="margin-bottom:0">
                <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Messages</span>
            </div>
            <?php if (count($replies) > 0): ?>
            <div class="rq-msg-count-pill"><?php echo count($replies); ?></div>
            <?php endif; ?>
        </div>

        <div class="rq-messages" id="rqMessages">
            <?php if (empty($replies)): ?>
            <div class="rq-empty-msg">
                <div class="rq-empty-icon">
                    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <div class="rq-empty-title">No messages yet</div>
                <div class="rq-empty-sub">AFSMD will reply here once they review your request.</div>
            </div>
            <?php else:
                $prevDate = '';
                foreach ($replies as $r):
                    $msgDate    = date('d M Y', strtotime($r['created_at']));
                    $isMe       = ((int)$r['sender_id'] === $userId && $r['sender_role'] === $submitterType);
                    $rowClass   = $isMe ? 'rq-me' : 'rq-dept';
                    $avClass    = $isMe ? 'av-me' : 'av-dept';
                    $initials   = getInitials($r['sender_name']);
                    $senderLbl  = $isMe ? 'You' : $r['sender_name'];
                    $hasRAttach = !empty($r['attachment_path']);
                    $rAttPath   = $hasRAttach ? $r['attachment_path'] : '';
                    $rIsImg     = $hasRAttach && isImageAttachment($rAttPath);
                    $rFileName  = $hasRAttach ? basename($rAttPath) : '';
                    $rFileMeta  = ($hasRAttach && !$rIsImg) ? fileCardMeta($rAttPath) : [];
                    $rExtUpper  = $hasRAttach ? strtoupper(pathinfo($rAttPath, PATHINFO_EXTENSION)) : '';
                    $rDispName  = strlen($rFileName) > 28 ? substr($rFileName, 0, 25).'…' : $rFileName;
            ?>
                <?php if ($msgDate !== $prevDate): $prevDate = $msgDate; ?>
                <div class="rq-date-sep">
                    <span><?php echo ($msgDate === date('d M Y')) ? 'Today' : $msgDate; ?></span>
                </div>
                <?php endif; ?>

                <div class="rq-msg-row <?php echo $rowClass; ?>">
                    <div class="rq-avatar <?php echo $avClass; ?>"><?php echo htmlspecialchars($initials); ?></div>
                    <div class="rq-msg-body">
                        <div class="rq-msg-name">
                            <?php echo htmlspecialchars($senderLbl); ?> · <?php echo date('H:i', strtotime($r['created_at'])); ?>
                        </div>
                        <?php if ($rIsImg): ?>
                            <a href="../<?php echo htmlspecialchars($rAttPath); ?>"
                               class="rq-img-bubble" title="View full image"
                               onclick="rqOpenLightbox(this.href);return false;">
                                <img src="../<?php echo htmlspecialchars($rAttPath); ?>" alt="Image" loading="lazy"
                                     onerror="this.closest('.rq-img-bubble').style.display='none'">
                            </a>
                            <?php if (!empty($r['message'])): ?>
                                <div class="rq-bubble"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div>
                            <?php endif; ?>
                        <?php elseif ($hasRAttach): ?>
                            <?php if (!empty($r['message'])): ?>
                                <div class="rq-bubble"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div>
                            <?php endif; ?>
                            <a href="../<?php echo htmlspecialchars($rAttPath); ?>" class="rq-file-card" target="_blank" rel="noopener">
                                <div class="rq-file-card-icon" style="background:<?php echo $rFileMeta['bg']; ?>;color:<?php echo $rFileMeta['color']; ?>">
                                    <?php echo htmlspecialchars($rFileMeta['label']); ?>
                                </div>
                                <div class="rq-file-card-info">
                                    <span class="rq-file-card-name"><?php echo htmlspecialchars($rDispName); ?></span>
                                    <span class="rq-file-card-meta"><?php echo $rExtUpper; ?> file · tap to open</span>
                                </div>
                                <svg class="rq-file-card-dl" viewBox="0 0 24 24">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <polyline points="7 10 12 15 17 10"/>
                                    <line x1="12" y1="15" x2="12" y2="3"/>
                                </svg>
                            </a>
                        <?php else: ?>
                            <?php if (!empty($r['message'])): ?>
                                <div class="rq-bubble"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="rq-readonly-bar">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            This conversation is read-only. Contact AFSMD directly if you need to follow up.
        </div>

    </div><!-- /rq-card -->
    <br>
<?php endif; ?>
</div><!-- /rq-page -->

<!-- Lightbox -->
<div id="rqLightbox" role="dialog" aria-modal="true" onclick="rqCloseLightbox(event)">
    <button id="rqLightboxClose" onclick="rqCloseLightbox()">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="white" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
    </button>
    <div id="rqLightboxInner" onclick="event.stopPropagation()">
        <img id="rqLightboxImg" src="" alt="Full image">
        <a id="rqLightboxDownload" href="" download>
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download
        </a>
    </div>
</div>

<?php ob_start(); ?>
<script>
(function(){ const b=document.getElementById('rqMessages'); if(b) b.scrollTop=b.scrollHeight; })();
const _lb=document.getElementById('rqLightbox'),_li=document.getElementById('rqLightboxImg'),_ld=document.getElementById('rqLightboxDownload');
function rqOpenLightbox(s){_li.src=s;_ld.href=s;_lb.classList.add('active');document.body.style.overflow='hidden';}
function rqCloseLightbox(e){if(e&&e.currentTarget===_lb&&e.target!==_lb)return;_lb.classList.remove('active');document.body.style.overflow='';setTimeout(()=>{_li.src='';_ld.href='';},180);}
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&_lb.classList.contains('active'))rqCloseLightbox();});
</script>
<?php
$extraFoot = ob_get_clean();
require 'layout_end.php';
?>