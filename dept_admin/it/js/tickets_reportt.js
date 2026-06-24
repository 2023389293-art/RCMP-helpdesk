/* =============================================================
   tickets-report.js  —  IT Dept Admin · Tickets Tab 
   ============================================================= */
 
   
(function () {
  'use strict';

  const DATA        = window.TICKET_DATA || {};
  const ALL_TICKETS = DATA.allTickets || [];

  /* ── filter state ── */
let filterPeriod   = '30';
let customDateFrom = null;
let customDateTo   = null;
let filterStatus   = 'all';
let filterPriority = 'all';
let filterStaff    = 'all';
let searchQuery    = '';
let chartInstance  = null;

  /* ── table state ── */
  const PER_PAGE   = 10;
  let currentPage  = 1;
  let activeRows   = [];        // rows after ALL filters applied
  let sortDir      = {};

  /* ══════════════════════════════════════════════
     INIT
  ══════════════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', () => {
    initPeriodDropdown();
    initCustomDateRange();
    initDropdowns();
    initSearch();
    applyAllFilters();
  });

  /* ══════════════════════════════════════════════
     PERIOD DROPDOWN
  ══════════════════════════════════════════════ */
  function initPeriodDropdown() {
    const sel = document.getElementById('filterPeriod');
    if (!sel) return;
    sel.value = filterPeriod;
    sel.addEventListener('change', () => {
      filterPeriod = sel.value;
      toggleCustomRange(filterPeriod === 'custom');
      applyAllFilters();
    });
  }

  function toggleCustomRange(show) {
    const g1 = document.getElementById('customRangeGroup');
    const g2 = document.getElementById('customRangeGroupTo');
    if (g1) g1.style.display = show ? '' : 'none';
    if (g2) g2.style.display = show ? '' : 'none';
    if (!show) {
      customDateFrom = null;
      customDateTo   = null;
    }
  }

  /* ══════════════════════════════════════════════
     CUSTOM DATE RANGE
  ══════════════════════════════════════════════ */
  function initCustomDateRange() {
    const fromEl = document.getElementById('customDateFrom');
    const toEl   = document.getElementById('customDateTo');

    if (fromEl) {
      fromEl.addEventListener('change', () => {
        customDateFrom = fromEl.value ? new Date(fromEl.value + 'T00:00:00') : null;
        applyAllFilters();
      });
    }
    if (toEl) {
      toEl.addEventListener('change', () => {
        customDateTo = toEl.value ? new Date(toEl.value + 'T23:59:59') : null;
        applyAllFilters();
      });
    }
  }

  /* ══════════════════════════════════════════════
     STATUS & PRIORITY DROPDOWNS
  ══════════════════════════════════════════════ */
  function initDropdowns() {
  document.getElementById('filterStatus')?.addEventListener('change', e => {
    filterStatus = e.target.value;
    applyAllFilters();
  });
  document.getElementById('filterPriority')?.addEventListener('change', e => {
    filterPriority = e.target.value;
    applyAllFilters();
  });
  document.getElementById('filterStaff')?.addEventListener('change', e => {
    filterStaff = e.target.value;
    applyAllFilters();
  });
  document.getElementById('filterResetBtn')?.addEventListener('click', resetAll);
}

  function resetAll() {
  filterPeriod   = '30';
  filterStatus   = 'all';
  filterPriority = 'all';
  filterStaff    = 'all';
  searchQuery    = '';
  customDateFrom = null;
  customDateTo   = null;

  const els = {
    filterPeriod:   '30',
    filterStatus:   'all',
    filterPriority: 'all',
    filterStaff:    'all',
    ticketSearch:   '',
  };
    Object.entries(els).forEach(([id, val]) => {
      const el = document.getElementById(id);
      if (el) el.value = val;
    });

    toggleCustomRange(false);
    applyAllFilters();
  }

  /* ══════════════════════════════════════════════
     SEARCH
  ══════════════════════════════════════════════ */
  function initSearch() {
    document.getElementById('ticketSearch')?.addEventListener('input', e => {
      searchQuery = e.target.value.toLowerCase().trim();
      currentPage = 1;
      applyAllFilters();
    });
  }

  /* ══════════════════════════════════════════════
     CORE: GET TICKETS MATCHING PERIOD FILTER
     Works on JS DATA (for chart) and DOM rows (for table)
  ══════════════════════════════════════════════ */

  function tsInPeriod(tsSeconds) {
    const now = Date.now();
    if (filterPeriod === 'all') return true;
    if (filterPeriod === 'custom') {
      const t = tsSeconds * 1000;
      if (customDateFrom && t < customDateFrom.getTime()) return false;
      if (customDateTo   && t > customDateTo.getTime())   return false;
      return true;
    }
    const days = parseInt(filterPeriod, 10);
    const cutoff = now - days * 24 * 60 * 60 * 1000;
    return tsSeconds * 1000 >= cutoff;
  }

  /* Filter DATA array (for chart) */
  function getFilteredData() {
  return ALL_TICKETS.filter(t => {
    if (!tsInPeriod(t.submitted_ts)) return false;
    if (filterStatus   !== 'all' && t.status   !== filterStatus)   return false;
    if (filterPriority !== 'all' && t.priority !== filterPriority) return false;
    if (filterStaff    !== 'all' && (t.assigned_staff_name || '—') !== filterStaff) return false;
    return true;
  });
}

  /* Filter DOM rows (for table, also applying search) */
  function getFilteredRows() {
  return getAllRows().filter(r => {
    const ts = parseInt(r.dataset.submittedTs || r.dataset['submitted-ts'] || 0, 10);
    if (!tsInPeriod(ts)) return false;
    if (filterStatus   !== 'all' && r.dataset.status   !== filterStatus)   return false;
    if (filterPriority !== 'all' && r.dataset.priority !== filterPriority) return false;
    if (filterStaff    !== 'all' && r.dataset.assigned !== filterStaff)    return false;
    if (searchQuery) {
      const haystack = [r.dataset.id, r.dataset.title, r.dataset.category,
                        r.dataset.status, r.dataset.priority, r.dataset.assigned].join(' ').toLowerCase();
      if (!haystack.includes(searchQuery)) return false;
    }
    return true;
  });
}
  /* ══════════════════════════════════════════════
     APPLY ALL FILTERS  (chart + table + summary)
  ══════════════════════════════════════════════ */
  function applyAllFilters() {
    renderChart();
    currentPage = 1;
    activeRows = getFilteredRows();
    renderPage();
    updateSummaryStrip();
    updateFilterSummaryPill();
  }

  /* ══════════════════════════════════════════════
     FILTER SUMMARY PILL
  ══════════════════════════════════════════════ */
  function updateFilterSummaryPill() {
    const el = document.getElementById('activeFilterSummary');
    if (!el) return;
    const parts = [];

    // Period label
    const periodLabels = {
      '7':   'Last 7 days', '30': 'Last 30 days', '60': 'Last 60 days',
      '90':  'Last 90 days', '180': 'Last 6 months', '365': 'Last year',
      'all': 'All time',
    };
    if (filterPeriod === 'custom') {
      const f = customDateFrom ? customDateFrom.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '?';
      const t = customDateTo   ? customDateTo.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '?';
      parts.push(`📅 ${f} → ${t}`);
    } else {
      parts.push(`📅 ${periodLabels[filterPeriod] || filterPeriod}`);
    }
    if (filterStatus   !== 'all') parts.push(`Status: <strong>${filterStatus.replace('_',' ')}</strong>`);
if (filterPriority !== 'all') parts.push(`Priority: <strong>${filterPriority}</strong>`);
if (filterStaff    !== 'all') parts.push(`Staff: <strong>${filterStaff}</strong>`);
if (searchQuery)               parts.push(`Search: <strong>"${searchQuery}"</strong>`);

    el.innerHTML = parts.map(p => `<span class="filter-pill">${p}</span>`).join('');
    el.style.display = 'flex';
  }

  /* ══════════════════════════════════════════════
     RENDER CHART
  ══════════════════════════════════════════════ */
  function renderChart() {
    const tickets = getFilteredData();
    const ctx = document.getElementById('ticketsBarChart');
    if (!ctx) return;

    const open   = tickets.filter(t => t.status === 'open').length;
    const prog   = tickets.filter(t => t.status === 'in_progress').length;
    const closed = tickets.filter(t => t.status === 'closed').length;
    const high   = tickets.filter(t => t.priority === 'high').length;
    const med    = tickets.filter(t => t.priority === 'medium').length;
    const low    = tickets.filter(t => t.priority === 'low').length;

    /* subtitle */
    const sub = document.getElementById('chartSubtitleText');
    if (sub) {
      let periodLabel = getPeriodLabel();
      sub.textContent = `${tickets.length} ticket${tickets.length !== 1 ? 's' : ''} — ${periodLabel}`;
    }

    const showOpen   = filterStatus === 'all' || filterStatus === 'open';
    const showClosed = filterStatus === 'all' || filterStatus === 'closed';
    const showProg   = filterStatus === 'all' || filterStatus === 'in_progress';
    const showHigh   = filterPriority === 'all' || filterPriority === 'high';
    const showMed    = filterPriority === 'all' || filterPriority === 'medium';
    const showLow    = filterPriority === 'all' || filterPriority === 'low';

    const labels = [], values = [], colors = [];
    const push = (label, val, color, show) => {
      if (show) { labels.push(label); values.push(val); colors.push(color); }
    };
push('Open',        open,   '#DC2626', showOpen);
push('Closed',      closed, '#16A34A', showClosed);
push('In Progress', prog,   '#3503aa', showProg);
    push('High',        high,   '#e64545', showHigh);
    push('Medium',      med,    '#e48a36', showMed);
    push('Low',         low,    '#93c5fd', showLow);

    if (chartInstance) chartInstance.destroy();
    chartInstance = new Chart(ctx.getContext('2d'), {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          data: values,
          backgroundColor: colors,
          borderRadius: 10,
          borderSkipped: false,
          barThickness: 72,
          hoverBackgroundColor: colors.map(c => c + 'cc'),
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(15,23,42,.95)',
            titleColor: '#fff',
            bodyColor: 'rgba(255,255,255,.8)',
            padding: 12,
            cornerRadius: 10,
            displayColors: true,
            callbacks: {
              title: items => items[0].label,
              label: item => `  ${item.parsed.y} ticket${item.parsed.y !== 1 ? 's' : ''}`,
            }
          }
        },
        scales: {
          x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 13, weight: '600' }, color: '#475569' } },
          y: { beginAtZero: true, grid: { color: 'rgba(226,232,240,.7)' }, border: { display: false }, ticks: { stepSize: 1, precision: 0, font: { size: 11 }, color: '#94a3b8' } }
        }
      }
    });

    setEl('ckpi-total',  tickets.length);
    setEl('ckpi-open',   open);
    setEl('ckpi-prog',   prog);
    setEl('ckpi-closed', closed);
    setEl('ckpi-high',   high);
  }

  function getPeriodLabel() {
    const periodLabels = {
      '7':   'Last 7 days', '30': 'Last 30 days', '60': 'Last 60 days',
      '90':  'Last 90 days', '180': 'Last 6 months', '365': 'Last year',
      'all': 'All time',
    };
    if (filterPeriod === 'custom') {
      const f = customDateFrom ? customDateFrom.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '?';
      const t = customDateTo   ? customDateTo.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : 'now';
      return `${f} – ${t}`;
    }
    return periodLabels[filterPeriod] || filterPeriod;
  }

  function setEl(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }

  /* ══════════════════════════════════════════════
     UPDATE SUMMARY STRIP  (below table)
  ══════════════════════════════════════════════ */
  function updateSummaryStrip() {
    const rows = activeRows;
    const total   = rows.length;
    const open    = rows.filter(r => r.dataset.status === 'open').length;
    const prog    = rows.filter(r => r.dataset.status === 'in_progress').length;
    const closed  = rows.filter(r => r.dataset.status === 'closed').length;
    const breach  = rows.filter(r => r.dataset.sla === 'Breached').length;
    setEl('ss-total',  total);
    setEl('ss-open',   open);
    setEl('ss-prog',   prog);
    setEl('ss-closed', closed);
    setEl('ss-breach', breach);
  }

  /* ══════════════════════════════════════════════
     TABLE
  ══════════════════════════════════════════════ */
  function getAllRows() { return Array.from(document.querySelectorAll('#ticketTableBody tr[data-id]')); }

  function renderPage() {
    const rows  = activeRows;
    const total = rows.length;
    const pages = Math.max(1, Math.ceil(total / PER_PAGE));
    currentPage = Math.min(currentPage, pages);
    const s = (currentPage - 1) * PER_PAGE, e = s + PER_PAGE;

    // Hide all, show only current page of filtered
    getAllRows().forEach(r => r.style.display = 'none');
    rows.forEach((r, i) => { r.style.display = (i >= s && i < e) ? '' : 'none'; });

    const pi = document.getElementById('pageInfo');
    if (pi) pi.textContent = total ? `Showing ${s + 1}–${Math.min(e, total)} of ${total} tickets` : 'No tickets match your filters';

    const pb = document.getElementById('pageBtns');
    if (!pb) return;
    pb.innerHTML = '';
    pb.appendChild(mkBtn('‹', currentPage === 1, () => { currentPage--; renderPage(); }));
    let sp = Math.max(1, currentPage - 2), ep = Math.min(pages, sp + 4);
    if (ep - sp < 4) sp = Math.max(1, ep - 4);
    for (let p = sp; p <= ep; p++) {
      const b = mkBtn(p, false, () => { currentPage = p; renderPage(); });
      if (p === currentPage) b.classList.add('active');
      pb.appendChild(b);
    }
    pb.appendChild(mkBtn('›', currentPage === pages, () => { currentPage++; renderPage(); }));
  }

  function mkBtn(label, disabled, fn) {
    const b = document.createElement('button');
    b.className = 'page-btn'; b.textContent = label; b.disabled = disabled;
    b.addEventListener('click', fn); return b;
  }

  window.sortTable = function (col) {
    const keys = ['id', 'category', 'status', 'priority', 'assigned', 'complaintby', 'firstresponse-raw', 'respondtime-raw', 'sla'];
    const key  = keys[col];
    sortDir[key] = !sortDir[key];

    // Sort activeRows
    activeRows.sort((a, b) => {
      const aV = a.dataset[key] || '';
      const bV = b.dataset[key] || '';
      const numA = parseFloat(aV), numB = parseFloat(bV);
      if (!isNaN(numA) && !isNaN(numB))
        return sortDir[key] ? numA - numB : numB - numA;
      return sortDir[key]
        ? aV.localeCompare(bV, undefined, { numeric: true })
        : bV.localeCompare(aV, undefined, { numeric: true });
    });

    // Also reorder DOM
    const tbody = document.getElementById('ticketTableBody');
    activeRows.forEach(r => tbody.appendChild(r));

    document.querySelectorAll('#ticketTable thead th').forEach((th, i) => {
      th.classList.toggle('sorted', i === col);
      const ic = th.querySelector('.sort-icon');
      if (ic) ic.textContent = i === col ? (sortDir[key] ? '↑' : '↓') : '⇅';
    });

    currentPage = 1;
    renderPage();
  };

  /* ══════════════════════════════════════════════
     DOWNLOAD — uses activeRows (filtered)
  ══════════════════════════════════════════════ */
window.downloadCSV = function () {
    const rows  = activeRows.length ? activeRows : getAllRows();
    const lines = [['Ticket ID', 'Category', 'Status', 'Priority', 'Closed By', 'Complaint By', 'Resolution Time', 'Respond Time', 'SLA'].join(',')];
    rows.forEach(r => {
      const d = r.dataset;
      lines.push([
        d.id,
        `"${(d.category || '').replace(/"/g, '""')}"`,
        d.status,
        d.priority,
        `"${(d.assigned || '—').replace(/"/g, '""')}"`,
        `"${(d.complaintby || '—').replace(/"/g, '""')}"`,
        d.firstresponse,
        d.respondtime,
        d.sla
      ].join(','));
    });
    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    Object.assign(document.createElement('a'), {
      href: URL.createObjectURL(blob),
      download: `ticket-report-${getPeriodLabel().replace(/\s+/g, '-').toLowerCase()}.csv`
    }).click();
  };

 window.downloadPDF = async function () {
    const { jsPDF } = window.jspdf;
    // ── CHANGED: landscape to match Staff Activity tab ───────────
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

    // ── Header bar (A4 landscape width = 297mm) ──────────────────
    doc.setFillColor(87, 68, 118);
    doc.rect(0, 0, 297, 22, 'F');

    // ── Logo ────────────────────────────────────────────────────
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

    // ── Header text ─────────────────────────────────────────────
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(12);
    doc.text('UniKL Help Desk — IT Department Ticket Report', 36, 10);
    doc.setFontSize(8);
    doc.setFont('helvetica', 'normal');
    doc.text('Generated: ' + new Date().toLocaleString(), 36, 16);

    // ── Filter info line ─────────────────────────────────────────
    doc.setTextColor(87, 68, 118);
    doc.setFontSize(8);
    doc.setFont('helvetica', 'bold');
    const filterInfo = [
      `Period: ${getPeriodLabel()}`,
      filterStatus   !== 'all' ? `Status: ${filterStatus.replace('_', ' ')}` : null,
      filterPriority !== 'all' ? `Priority: ${filterPriority}` : null,
      filterStaff    !== 'all' ? `Staff: ${filterStaff}` : null,
      searchQuery ? `Search: "${searchQuery}"` : null,
    ].filter(Boolean).join('   |   ');
    doc.text(filterInfo, 14, 28);

    // ── KPI summary line ─────────────────────────────────────────
    const rows    = activeRows;
    const total   = rows.length;
    const open    = rows.filter(r => r.dataset.status === 'open').length;
    const prog    = rows.filter(r => r.dataset.status === 'in_progress').length;
    const closed  = rows.filter(r => r.dataset.status === 'closed').length;
    const breach  = rows.filter(r => r.dataset.sla === 'Breached').length;
    doc.setTextColor(30, 41, 59);
    doc.setFontSize(8.5);
    doc.setFont('helvetica', 'normal');
    doc.text(
      `Total: ${total}  |  Open: ${open}  |  In Progress: ${prog}  |  Closed: ${closed}  |  SLA Breached: ${breach}`,
      14, 36
    );

    // ── Table ────────────────────────────────────────────────────
    const body = rows.map(r => [
      r.dataset.id,
      r.dataset.category,
      r.dataset.status,
      r.dataset.priority,
      r.dataset.assigned || '—',
      r.dataset.complaintby || '—',
      r.dataset.firstresponse,
      r.dataset.respondtime,
      r.dataset.sla
    ]);

    doc.autoTable({
      startY: 42,
      margin: { left: 14, right: 14 },
      tableWidth: 'auto',
      head: [['Ticket ID', 'Category', 'Status', 'Priority', 'Closed By', 'Complaint By', 'Resolution Time', 'Respond Time', 'SLA']],
      body,
      styles: { fontSize: 7.5, cellPadding: 2.5, overflow: 'linebreak' },
      headStyles: { fillColor: [87, 68, 118], textColor: 255, fontStyle: 'bold', fontSize: 8 },
      alternateRowStyles: { fillColor: [248, 247, 252] },
      columnStyles: {
        0: { cellWidth: 32 },  // Ticket ID
        1: { cellWidth: 30 },  // Category
        2: { cellWidth: 20 },  // Status
        3: { cellWidth: 18 },  // Priority
        4: { cellWidth: 34 },  // Closed By
        5: { cellWidth: 34 },  // Complaint By
        6: { cellWidth: 22 },  // Resolution Time
        7: { cellWidth: 20 },  // Respond Time
        8: { cellWidth: 16 },  // SLA
      },
      didParseCell: data => {
        if (data.section === 'body') {
          if (data.column.index === 8 && data.cell.raw === 'Breached') {
            data.cell.styles.textColor = [220, 38, 38];
            data.cell.styles.fontStyle = 'bold';
          }
          if (data.column.index === 2) {
            const c = { open: [220, 38, 38], in_progress: [37, 99, 235], closed: [22, 163, 74] };
            data.cell.styles.textColor = c[data.cell.raw] || [51, 65, 85];
          }
          if (data.column.index === 3) {
            const p = { high: [220, 38, 38], medium: [234, 138, 54], low: [99, 102, 241] };
            data.cell.styles.textColor = p[data.cell.raw] || [51, 65, 85];
          }
        }
      }
    });

    // ── Page numbers ─────────────────────────────────────────────
    const pc = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pc; i++) {
      doc.setPage(i);
      doc.setFontSize(7);
      doc.setTextColor(148, 163, 184);
      // A4 landscape height = 210mm
      doc.text(`Page ${i} of ${pc} — UniKL Help Desk IT Dept`, 14, 200);
    }

    doc.save(`ticket-report-${getPeriodLabel().replace(/\s+/g, '-').toLowerCase()}.pdf`);
  };

})();