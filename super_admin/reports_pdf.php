<!-- super_admin/reports_pdf.php -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<title>UniKL RCMP – Department Report</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; color: #111827; background: white; padding: 32px; }

  .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; padding-bottom: 16px; border-bottom: 2px solid #7D1128; }
  .header-left h1 { font-size: 20px; font-weight: 700; color: #7D1128; margin-bottom: 3px; }
  .header-left p  { font-size: 12px; color: #6B7280; }
  .header-right   { font-size: 11px; color: #6B7280; text-align: right; }

  .meta-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 22px; }
  .meta-pill  { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; background: #F3F4F6; color: #374151; }

.kpi-summary { font-size: 13px; font-weight: 700; color: #111827; margin-bottom: 22px; }
.kpi-summary span { margin-right: 4px; }
.kpi-summary .sep { color: #9CA3AF; margin: 0 8px; }

  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  thead th { background: #7D1128; color: white; padding: 9px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; }
  thead th:not(:first-child) { text-align: center; }
  tbody td { padding: 10px 12px; border-bottom: 1px solid #F3F4F6; }
  tbody td:not(:first-child) { text-align: center; }
  tbody tr:nth-child(even) td { background: #F9FAFB; }
  tfoot td { padding: 10px 12px; background: #F3F4F6; font-weight: 700; border-top: 2px solid #E5E7EB; }
  tfoot td:not(:first-child) { text-align: center; }

  .dept-cell { display: flex; align-items: center; gap: 7px; font-weight: 600; }
  .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }

  .rate-wrap { display: inline-flex; align-items: center; gap: 6px; justify-content: center; }
  .rate-bar-outer { width: 50px; height: 5px; background: #E5E7EB; border-radius: 3px; overflow: hidden; display: inline-block; }
  .rate-bar-inner { height: 100%; background: #059669; border-radius: 3px; }
  .rate-pct { font-weight: 700; font-size: 12px; }

  .footer { margin-top: 28px; font-size: 10px; color: #9CA3AF; border-top: 1px solid #E5E7EB; padding-top: 12px; display: flex; justify-content: space-between; }

  @media print {
    body { padding: 20px; }
    .no-print { display: none; }
  }
</style>
</head>
<body onload="window.print()">

<?php
// $deptRows, $deptColors, $deptShort, $deptFull, $kpiTotal, etc. are inherited from reports.php
$filterLabel  = $filterDept > 0 ? ($deptFull[$filterDept] ?? 'All Departments') : 'All Departments';
$statusLabel  = $filterStatus !== 'all' ? ucfirst(str_replace('_', ' ', $filterStatus)) : 'All Statuses';
$fromLabel    = $dateFrom ?: 'All time';
$toLabel      = $dateTo   ?: 'Present';
?>

<div class="header">
  <div class="header-left" style="display:flex;align-items:center;gap:14px;">
    <img src="../img/RCMP.png" alt="RCMP Logo" style="height:52px;width:auto;">
    <div>
      <h1>UniKL RCMP — Department Report</h1>
      <p>Help Desk Performance Overview</p>
    </div>
  </div>
  <div class="header-right">
    Generated: <?= date('j M Y, g:i A') ?><br>
    Super Admin
  </div>
</div>

<div class="meta-pills">
  <span class="meta-pill">Dept: <?= htmlspecialchars($filterLabel) ?></span>
  <span class="meta-pill">Status: <?= htmlspecialchars($statusLabel) ?></span>
  <span class="meta-pill">From: <?= htmlspecialchars($fromLabel) ?></span>
  <span class="meta-pill">To: <?= htmlspecialchars($toLabel) ?></span>
</div>

<div class="kpi-summary">
  Total: <span><?= $kpiTotal ?></span>
  <span class="sep">|</span>
  Open: <span><?= $kpiOpen ?></span>
  <span class="sep">|</span>
  In Progress: <span><?= $kpiProgress ?></span>
  <span class="sep">|</span>
  Closed: <span><?= $kpiClosed ?></span>
</div>

<table>
  <thead>
    <tr>
      <th>Department</th>
      <th>Total</th>
      <th>Open</th>
      <th>In Progress</th>
      <th>Closed</th>
      <th>Resolution Rate</th>
    </tr>
  </thead>
  <tbody>
    <?php
    $gT = $gO = $gI = $gC = 0;
    foreach ($deptRows as $row):
      $dId  = (int)$row['dept_id'];
      $t    = (int)$row['total'];
      $op   = (int)$row['open_count'];
      $ip   = (int)$row['inprogress_count'];
      $cl   = (int)$row['closed_count'];
      $rate = $t > 0 ? round($cl / $t * 100) : 0;
      $color = $deptColors[$dId] ?? '#888';
      $short = $deptShort[$dId]  ?? htmlspecialchars($row['dept_name']);
      $gT += $t; $gO += $op; $gI += $ip; $gC += $cl;
    ?>
    <tr>
      <td>
        <div class="dept-cell">
          <span class="dot" style="background:<?= $color ?>;"></span>
          <?= htmlspecialchars($short) ?>
        </div>
      </td>
      <td><?= $t ?></td>
      <td><?= $op ?></td>
      <td><?= $ip ?></td>
      <td><?= $cl ?></td>
      <td>
        <div class="rate-wrap">
          <span class="rate-bar-outer"><span class="rate-bar-inner" style="width:<?= $rate ?>%;"></span></span>
          <span class="rate-pct"><?= $rate ?>%</span>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <?php $gR = $gT > 0 ? round($gC / $gT * 100) : 0; ?>
    <tr>
      <td>All Departments</td>
      <td><?= $gT ?></td>
      <td><?= $gO ?></td>
      <td><?= $gI ?></td>
      <td><?= $gC ?></td>
      <td><?= $gR ?>%</td>
    </tr>
  </tfoot>
</table>

<div class="footer">
  <span>UniKL RCMP Help Desk System</span>
  <span>Confidential — Super Admin Use Only</span>
</div>

</body>
</html>