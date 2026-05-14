/* =============================================================
   staff-report.js  —  HCD Dept Admin · Staff Activity Tab
   + Custom date range filter (matches Tickets tab behaviour) 
   ============================================================= */

(function () {
  'use strict';

  const ALL_STAFF   = window.STAFF_DATA?.staff   || [];
  const PERIOD_INIT = window.STAFF_DATA?.period  || '30';

  /* ── State ── */
  let staffSearchQuery  = '';
  let staffFilterByName = 'all';
  let staffSortDir      = {};
  let staffActiveRows   = [];
  let staffChartInst    = null;
  const STAFF_PER_PAGE  = 10;
  let staffCurrentPage  = 1;
  let staffFilterPeriod = PERIOD_INIT;
  let staffCustomFrom   = null;   // Date object | null
  let staffCustomTo     = null;   // Date object | null

  const periodLabels = {
    '7':   'Last 7 days',  '30':  'Last 30 days',
    '60':  'Last 60 days', '90':  'Last 90 days',
    '180': 'Last 6 months','365': 'Last year',
    'all': 'All time'
  };

  /* ══════════════════════════════════════════════
     PERIOD HELPERS
  ══════════════════════════════════════════════ */
  function toggleStaffCustomRange(show) {
    const from = document.getElementById('staffCustomRangeFrom');
    const to   = document.getElementById('staffCustomRangeTo');
    if (from) from.style.display = show ? '' : 'none';
    if (to)   to.style.display   = show ? '' : 'none';
    if (!show) {
      staffCustomFrom = null;
      staffCustomTo   = null;
      const f = document.getElementById('staffCustomDateFrom');
      const t = document.getElementById('staffCustomDateTo');
      if (f) f.value = '';
      if (t) t.value = '';
    }
  }

  function tsInStaffPeriod(tsSeconds) {
    const now = Date.now();
    if (staffFilterPeriod === 'all') return true;
    if (staffFilterPeriod === 'custom') {
      const t = tsSeconds * 1000;
      if (staffCustomFrom && t < staffCustomFrom.getTime()) return false;
      if (staffCustomTo   && t > staffCustomTo.getTime())   return false;
      return true;
    }
    const days   = parseInt(staffFilterPeriod, 10);
    const cutoff = now - days * 24 * 60 * 60 * 1000;
    return tsSeconds * 1000 >= cutoff;
  }

  function getStaffPeriodLabel() {
    if (staffFilterPeriod === 'custom') {
      const fmt = d => d ? d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '?';
      return `${fmt(staffCustomFrom)} → ${fmt(staffCustomTo)}`;
    }
    return periodLabels[staffFilterPeriod] || staffFilterPeriod;
  }

  /* ══════════════════════════════════════════════
     INIT
  ══════════════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', () => {

    /* Period dropdown */
    const periodSel = document.getElementById('staffFilterPeriod');
    if (periodSel) {
      periodSel.value = staffFilterPeriod;
      periodSel.addEventListener('change', () => {
        staffFilterPeriod = periodSel.value;
        toggleStaffCustomRange(staffFilterPeriod === 'custom');
        applyStaffFilters();
      });
    }

    /* Custom date — From */
    document.getElementById('staffCustomDateFrom')?.addEventListener('change', e => {
      staffCustomFrom = e.target.value ? new Date(e.target.value + 'T00:00:00') : null;
      applyStaffFilters();
    });

    /* Custom date — To */
    document.getElementById('staffCustomDateTo')?.addEventListener('change', e => {
      staffCustomTo = e.target.value ? new Date(e.target.value + 'T23:59:59') : null;
      applyStaffFilters();
    });

    /* Staff name dropdown */
    const nameSel = document.getElementById('staffFilterName');
    if (nameSel) {
      nameSel.addEventListener('change', () => {
        staffFilterByName = nameSel.value;
        applyStaffFilters();
      });
    }

    /* Reset */
    document.getElementById('staffFilterResetBtn')?.addEventListener('click', () => {
      staffFilterPeriod = '30';
      staffFilterByName = 'all';
      staffSearchQuery  = '';
      staffCustomFrom   = null;
      staffCustomTo     = null;
      if (periodSel) periodSel.value = '30';
      if (nameSel)   nameSel.value   = 'all';
      const s  = document.getElementById('staffSearch');
      const sf = document.getElementById('staffCustomDateFrom');
      const st = document.getElementById('staffCustomDateTo');
      if (s)  s.value  = '';
      if (sf) sf.value = '';
      if (st) st.value = '';
      toggleStaffCustomRange(false);
      applyStaffFilters();
    });

    /* Search */
    document.getElementById('staffSearch')?.addEventListener('input', e => {
      staffSearchQuery = e.target.value.toLowerCase().trim();
      staffCurrentPage = 1;
      applyStaffFilters();
    });

    applyStaffFilters();
  });

  /* ══════════════════════════════════════════════
     APPLY ALL FILTERS
  ══════════════════════════════════════════════ */
  function applyStaffFilters() {
    staffCurrentPage = 1;
    staffActiveRows  = getStaffRows();
    renderStaffChart();
    renderStaffPage();
    updateStaffSummary();
    updateStaffFilterPill();
    renderStaffChartSubtitle();
  }

  /* ══════════════════════════════════════════════
     FILTER PILL
  ══════════════════════════════════════════════ */
  function updateStaffFilterPill() {
    const el = document.getElementById('staffActiveFilterSummary');
    if (!el) return;
    const parts = [];
    if (staffFilterPeriod === 'custom') {
      parts.push(`📅 ${getStaffPeriodLabel()}`);
    } else {
      parts.push(`📅 ${periodLabels[staffFilterPeriod] || staffFilterPeriod}`);
    }
    if (staffFilterByName !== 'all') parts.push(`Staff: <strong>${staffFilterByName}</strong>`);
    if (staffSearchQuery)             parts.push(`Search: <strong>"${staffSearchQuery}"</strong>`);
    el.innerHTML = parts.map(p => `<span class="filter-pill">${p}</span>`).join('');
    el.style.display = 'flex';
  }

  function renderStaffChartSubtitle() {
    const el = document.getElementById('staffChartSubtitle');
    if (!el) return;
    const filtered = staffFilterByName !== 'all'
      ? ALL_STAFF.filter(s => s.full_name === staffFilterByName)
      : ALL_STAFF;
    el.textContent = `${filtered.length} staff member${filtered.length !== 1 ? 's' : ''} — ${getStaffPeriodLabel()}`;
  }

  /* ══════════════════════════════════════════════
     ROW FILTERING
     Staff rows carry data-submitted-ts (epoch seconds from
     the earliest ticket assigned). We use that for date gating.
     If a row has no ts, we include it when period = 'all'.
  ══════════════════════════════════════════════ */
  function getAllStaffRows() {
    return Array.from(document.querySelectorAll('#staffTableBody tr[data-name]'));
  }

  function getStaffRows() {
    return getAllStaffRows().filter(r => {
      /* Name filter */
      if (staffFilterByName !== 'all' && r.dataset.name !== staffFilterByName) return false;

      /* Period filter — uses data-submitted-ts if present */
      if (staffFilterPeriod !== 'all') {
        const ts = parseInt(r.dataset.submittedTs || r.dataset['submitted-ts'] || 0, 10);
        if (ts && !tsInStaffPeriod(ts)) return false;
      }

      /* Search */
      if (staffSearchQuery) {
        const hay = [r.dataset.name, r.dataset.code].join(' ').toLowerCase();
        if (!hay.includes(staffSearchQuery)) return false;
      }
      return true;
    });
  }

  /* ══════════════════════════════════════════════
     CHART — horizontal bar
  ══════════════════════════════════════════════ */
  function renderStaffChart() {
    const ctx = document.getElementById('staffChart');
    if (!ctx) return;

    const data = staffFilterByName !== 'all'
      ? ALL_STAFF.filter(s => s.full_name === staffFilterByName)
      : ALL_STAFF;

    if (!data.length) {
      if (staffChartInst) staffChartInst.destroy();
      staffChartInst = null;
      return;
    }

    const wrapEl = document.getElementById('staffChartWrap');
    if (wrapEl) {
      const needed = Math.max(200, data.length * (44 * 2 + 24) + 60);
      wrapEl.style.minHeight = needed + 'px';
    }

    const names    = data.map(s => s.full_name);
    const resolved = data.map(s => s.resolved);
    const handled  = data.map(s => s.tickets_handled);

    if (staffChartInst) staffChartInst.destroy();

    staffChartInst = new Chart(ctx.getContext('2d'), {
      type: 'bar',
      data: {
        labels: names,
        datasets: [
          {
            label: 'Resolved (Closed)',
            data: resolved,
            backgroundColor: 'rgba(22,163,74,0.85)',
            borderRadius: { topRight: 10, bottomRight: 10, topLeft: 0, bottomLeft: 0 },
            borderSkipped: false,
            barThickness: 22,
          },
          {
            label: 'Tickets Assigned',
            data: handled,
            backgroundColor: 'rgba(99,102,241,0.55)',
            borderRadius: { topRight: 10, bottomRight: 10, topLeft: 0, bottomLeft: 0 },
            borderSkipped: false,
            barThickness: 22,
          }
        ]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 8, bottom: 8, left: 4, right: 24 } },
        plugins: {
          legend: {
            position: 'top', align: 'end',
            labels: { font: { size: 12, weight: '600' }, padding: 20, usePointStyle: true, pointStyle: 'rectRounded' }
          },
          tooltip: {
            backgroundColor: 'rgba(15,23,42,.96)',
            titleColor: '#fff', bodyColor: 'rgba(255,255,255,.85)',
            padding: 14, cornerRadius: 12, displayColors: true, boxPadding: 6,
            callbacks: {
              title: items => data[items[0].dataIndex]?.full_name || items[0].label,
              label: item  => {
                const d = data[item.dataIndex];
                if (!d) return `  ${item.dataset.label}: ${item.parsed.x}`;
                if (item.datasetIndex === 0) {
                  const rate = d.tickets_handled > 0
                    ? Math.round(d.resolved / d.tickets_handled * 100) : 0;
                  return [`  Resolved: ${item.parsed.x}`, `  Resolution Rate: ${rate}%`];
                }
                return [
                  `  Tickets Assigned: ${item.parsed.x}`,
                  `  Open: ${d.open_count}  |  In Progress: ${d.in_progress_count}  |  Closed: ${d.resolved}`
                ];
              }
            }
          }
        },
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: 'rgba(226,232,240,.55)', drawBorder: false },
            border: { display: false },
            ticks: { precision: 0, stepSize: 1, font: { size: 11 }, color: '#94a3b8', padding: 6 }
          },
          y: {
            grid: { display: false },
            border: { display: false },
            ticks: { font: { size: 13, weight: '600' }, color: '#334155', padding: 12 }
          }
        }
      }
    });

    const totalResolved = data.reduce((s, d) => s + d.resolved, 0);
    const totalHandled  = data.reduce((s, d) => s + d.tickets_handled, 0);
    const avgResolved   = data.length ? Math.round(totalResolved / data.length * 10) / 10 : 0;
    const setK = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    setK('skpi-staff',        data.length);
    setK('skpi-resolved',     totalResolved);
    setK('skpi-actions',      totalHandled);
    setK('skpi-avg-resolved', avgResolved);
  }

  /* ══════════════════════════════════════════════
     PAGINATION
  ══════════════════════════════════════════════ */
  function renderStaffPage() {
    const rows  = staffActiveRows;
    const total = rows.length;
    const pages = Math.max(1, Math.ceil(total / STAFF_PER_PAGE));
    staffCurrentPage = Math.min(staffCurrentPage, pages);
    const s = (staffCurrentPage - 1) * STAFF_PER_PAGE;
    const e = s + STAFF_PER_PAGE;

    getAllStaffRows().forEach(r => r.style.display = 'none');
    rows.forEach((r, i) => { r.style.display = (i >= s && i < e) ? '' : 'none'; });

    const pi = document.getElementById('staffPageInfo');
    if (pi) pi.textContent = total
      ? `Showing ${s + 1}–${Math.min(e, total)} of ${total} staff`
      : 'No staff match your filters';

    const pb = document.getElementById('staffPageBtns');
    if (!pb) return;
    pb.innerHTML = '';
    pb.appendChild(mkBtn('‹', staffCurrentPage === 1, () => { staffCurrentPage--; renderStaffPage(); }));
    let sp = Math.max(1, staffCurrentPage - 2), ep = Math.min(pages, sp + 4);
    if (ep - sp < 4) sp = Math.max(1, ep - 4);
    for (let p = sp; p <= ep; p++) {
      const b = mkBtn(p, false, () => { staffCurrentPage = p; renderStaffPage(); });
      if (p === staffCurrentPage) b.classList.add('active');
      pb.appendChild(b);
    }
    pb.appendChild(mkBtn('›', staffCurrentPage === pages, () => { staffCurrentPage++; renderStaffPage(); }));
  }

  function mkBtn(label, disabled, fn) {
    const b = document.createElement('button');
    b.className = 'page-btn'; b.textContent = label; b.disabled = disabled;
    b.addEventListener('click', fn); return b;
  }

  function updateStaffSummary() {
    const rows = staffActiveRows.length ? staffActiveRows : getAllStaffRows();
    let totalHandled = 0, totalResolved = 0, totalInProg = 0, totalOpen = 0;
    rows.forEach(r => {
      totalHandled  += parseInt(r.dataset.handled  || 0, 10);
      totalResolved += parseInt(r.dataset.resolved || 0, 10);
      totalInProg   += parseInt(r.dataset.inprog   || 0, 10);
      totalOpen     += parseInt(r.dataset.open     || 0, 10);
    });
    const el = id => document.getElementById(id);
    if (el('ss-staff-total'))    el('ss-staff-total').textContent    = rows.length;
    if (el('ss-staff-actions'))  el('ss-staff-actions').textContent  = totalHandled;
    if (el('ss-staff-resolved')) el('ss-staff-resolved').textContent = totalResolved;
    if (el('ss-staff-inprog'))   el('ss-staff-inprog').textContent   = totalInProg;
    if (el('ss-staff-open'))     el('ss-staff-open').textContent     = totalOpen;
  }

  /* ══════════════════════════════════════════════
     SORT
  ══════════════════════════════════════════════ */
  window.sortStaffTable = function (col) {
    const keys = ['rank', 'name', 'code', 'handled', 'resolved', 'inprog', 'rate'];
    const key  = keys[col];
    staffSortDir[key] = !staffSortDir[key];

    staffActiveRows.sort((a, b) => {
      const aV = a.dataset[key] || '';
      const bV = b.dataset[key] || '';
      const numA = parseFloat(aV), numB = parseFloat(bV);
      if (!isNaN(numA) && !isNaN(numB))
        return staffSortDir[key] ? numA - numB : numB - numA;
      return staffSortDir[key]
        ? aV.localeCompare(bV, undefined, { numeric: true })
        : bV.localeCompare(aV, undefined, { numeric: true });
    });

    const tbody = document.getElementById('staffTableBody');
    staffActiveRows.forEach(r => tbody.appendChild(r));

    document.querySelectorAll('#staffTable thead th').forEach((th, i) => {
      th.classList.toggle('sorted', i === col);
      const ic = th.querySelector('.sort-icon');
      if (ic) ic.textContent = i === col ? (staffSortDir[key] ? '↑' : '↓') : '⇅';
    });

    staffCurrentPage = 1;
    renderStaffPage();
  };

  /* ══════════════════════════════════════════════
     DOWNLOAD CSV
  ══════════════════════════════════════════════ */
  window.staffDownloadCSV = function () {
    const rows  = staffActiveRows.length ? staffActiveRows : getAllStaffRows();
    const label = getStaffPeriodLabel().replace(/\s+/g, '-').replace(/[→]/g, 'to').toLowerCase();
    const lines = [['No','Staff Name','Staff Code','Role','Tickets Assigned','Closed','In Progress','Open','Resolution Rate (%)'].join(',')];
rows.forEach(r => {
  lines.push([
    r.dataset.rank,
    `"${(r.dataset.name  || '').replace(/"/g,'""')}"`,
    r.dataset.code,
    r.dataset.role || '—',
    r.dataset.handled,
        r.dataset.resolved,
        r.dataset.inprog,
        r.dataset.open,
        r.dataset.rate
      ].join(','));
    });
    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    Object.assign(document.createElement('a'), {
      href: URL.createObjectURL(blob),
      download: `staff-activity-${label}.csv`
    }).click();
  };

  /* ══════════════════════════════════════════════
     DOWNLOAD PDF
  ══════════════════════════════════════════════ */
  window.staffDownloadPDF = async function () {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const label = getStaffPeriodLabel();

    doc.setFillColor(87, 68, 118);
    doc.rect(0, 0, 297, 22, 'F');

    try {
      const logoImg = new Image();
      logoImg.crossOrigin = 'anonymous';
      logoImg.src = '../../img/RCMP.png';
      await new Promise(res => { logoImg.onload = res; logoImg.onerror = res; });
      const canvas = document.createElement('canvas');
      canvas.width  = logoImg.naturalWidth  || 100;
      canvas.height = logoImg.naturalHeight || 100;
      const ctx2d = canvas.getContext('2d');
      ctx2d.fillStyle = '#574476';
      ctx2d.fillRect(0, 0, canvas.width, canvas.height);
      ctx2d.drawImage(logoImg, 0, 0);
      doc.addImage(canvas.toDataURL('image/png'), 'PNG', 6, 3.5, 26, 15);
    } catch (e) {}

    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold'); doc.setFontSize(12);
    doc.text('UniKL Help Desk — HCD Department Staff Activity Report', 36, 10);
    doc.setFontSize(8); doc.setFont('helvetica', 'normal');
    doc.text('Generated: ' + new Date().toLocaleString(), 36, 16);

    doc.setTextColor(87, 68, 118); doc.setFontSize(8); doc.setFont('helvetica', 'bold');
    const filterLine = [
      `Period: ${label}`,
      staffFilterByName !== 'all' ? `Staff: ${staffFilterByName}` : null,
      staffSearchQuery  ? `Search: "${staffSearchQuery}"` : null,
    ].filter(Boolean).join('   |   ');
    doc.text(filterLine, 14, 28);

    const rows = staffActiveRows;
    let totalHandled = 0, totalResolved = 0, totalInProg = 0, totalOpen = 0;
    rows.forEach(r => {
      totalHandled  += parseInt(r.dataset.handled  || 0, 10);
      totalResolved += parseInt(r.dataset.resolved || 0, 10);
      totalInProg   += parseInt(r.dataset.inprog   || 0, 10);
      totalOpen     += parseInt(r.dataset.open     || 0, 10);
    });
    doc.setTextColor(30, 41, 59); doc.setFontSize(8.5);
    doc.text(
      `Staff: ${rows.length}  |  Assigned: ${totalHandled}  |  Closed: ${totalResolved}  |  In Progress: ${totalInProg}  |  Open: ${totalOpen}`,
      14, 36
    );

    doc.autoTable({
      startY: 42,
      margin: { left: 14, right: 14 },
      tableWidth: 'auto',
      head: [['No','Staff Name','Staff Code','Role','Assigned','Closed','In Progress','Open','Resolution Rate']],
body: rows.map(r => [
  r.dataset.rank, r.dataset.name, r.dataset.code,
  r.dataset.role || '—',
  r.dataset.handled, r.dataset.resolved, r.dataset.inprog, r.dataset.open,
  r.dataset.rate ? r.dataset.rate + '%' : '—'
]),
      styles: { fontSize: 7.5, cellPadding: 2.5, overflow: 'linebreak' },
      headStyles: { fillColor: [87, 68, 118], textColor: 255, fontStyle: 'bold', fontSize: 8 },
      alternateRowStyles: { fillColor: [248, 247, 252] },
      columnStyles: {
  0: { cellWidth: 12 },   // No
  1: { cellWidth: 65 },   // Staff Name
  2: { cellWidth: 28 },   // Staff Code
  3: { cellWidth: 18 },   // Role
  4: { cellWidth: 22 },   // Assigned
  5: { cellWidth: 22 },   // Closed
  6: { cellWidth: 28 },   // In Progress
  7: { cellWidth: 16 },   // Open
  8: { cellWidth: 36 },   // Resolution Rate
},
      didParseCell: data => {
        if (data.section === 'body') {
          if (data.column.index === 4) { data.cell.styles.textColor = [99,102,241];  data.cell.styles.fontStyle = 'bold'; }
if (data.column.index === 5) { data.cell.styles.textColor = [22,163,74];   data.cell.styles.fontStyle = 'bold'; }
if (data.column.index === 6) { data.cell.styles.textColor = [99,102,241]; }
if (data.column.index === 7) { data.cell.styles.textColor = [245,158,11]; }
if (data.column.index === 8) {
            const rate = parseFloat(data.cell.raw);
            if (!isNaN(rate)) {
              data.cell.styles.textColor = rate >= 70 ? [22,163,74] : (rate >= 40 ? [249,115,22] : [220,38,38]);
              data.cell.styles.fontStyle = 'bold';
            }
          }
        }
      }
    });

    const pc = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pc; i++) {
      doc.setPage(i);
      doc.setFontSize(7); doc.setTextColor(148, 163, 184);
      doc.text(`Page ${i} of ${pc} — UniKL Help Desk HCD Dept`, 14, 200);
    }

    doc.save(`staff-activity-${label.replace(/[\s→]+/g, '-').toLowerCase()}.pdf`);
  };

})();