/* =============================================================
   feedback-report.js  —  Afsmd Dept Admin · Feedback Tab
   + Period selector + Custom date range (matches Tickets tab)
   ============================================================= */
 
(function () {
  'use strict';

  const {
    pos = 0, neu = 0, neg = 0, totF = 0,
    fiveS = 0, fourS = 0, threeS = 0, twoS = 0, oneS = 0,
  } = window.FEEDBACK_DATA || {};

  const RATING_LABEL = {
    5: 'Very Satisfied', 4: 'Satisfied', 3: 'Neutral',
    2: 'Dissatisfied',   1: 'Very Dissatisfied',
  };

  /* ── Filter state ── */
  let filterPeriod    = 'all';   // 'all' | '7' | '30' … | 'custom'
  let customDateFrom  = null;    // Date object | null
  let customDateTo    = null;    // Date object | null
  let filterRating    = 'all';
  let filterSentiment = 'all';
  let filterDept      = 'all';
  let filterType      = 'all';
  let searchQuery     = '';
  let fbSortDir       = {};

  /* ── Pagination ── */
  const PER_PAGE  = 10;
  let currentPage = 1;
  let activeRows  = [];

  /* ── Chart ── */
  let chartInst = null;

  const FACE_COLORS = { 5:'#16A34A', 4:'#22C55E', 3:'#EAB308', 2:'#F97316', 1:'#EF4444' };
  const FACE_BORDER = { 5:'#15803D', 4:'#16A34A', 3:'#B45309', 2:'#EA580C', 1:'#B91C1C' };

  /* ══════════════════════════════════════════════
     PERIOD HELPERS
  ══════════════════════════════════════════════ */
  function toggleFbCustomRange(show) {
    const from = document.getElementById('fbCustomRangeFrom');
    const to   = document.getElementById('fbCustomRangeTo');
    if (from) from.style.display = show ? '' : 'none';
    if (to)   to.style.display   = show ? '' : 'none';
    if (!show) {
      customDateFrom = null;
      customDateTo   = null;
      const f = document.getElementById('fbCustomDateFrom');
      const t = document.getElementById('fbCustomDateTo');
      if (f) f.value = '';
      if (t) t.value = '';
    }
  }

  function tsInFbPeriod(tsSeconds) {
    if (filterPeriod === 'all') return true;
    if (filterPeriod === 'custom') {
      const t = tsSeconds * 1000;
      if (customDateFrom && t < customDateFrom.getTime()) return false;
      if (customDateTo   && t > customDateTo.getTime())   return false;
      return true;
    }
    const days   = parseInt(filterPeriod, 10);
    const cutoff = Date.now() - days * 24 * 60 * 60 * 1000;
    return tsSeconds * 1000 >= cutoff;
  }

  function getFbPeriodLabel() {
    const map = {
      '7':'Last 7 days','30':'Last 30 days','60':'Last 60 days',
      '90':'Last 90 days','180':'Last 6 months','365':'Last year','all':'All time'
    };
    if (filterPeriod === 'custom') {
      const fmt = d => d ? d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '?';
      return `${fmt(customDateFrom)} to ${fmt(customDateTo)}`;
    }
    return map[filterPeriod] || filterPeriod;
  }

  /* ══════════════════════════════════════════════
     CHART
  ══════════════════════════════════════════════ */
  function initChart() {
    const ctx = document.getElementById('fbRatingChart');
    if (!ctx) return;

    const counts = [fiveS, fourS, threeS, twoS, oneS];
    const labels = ['5 — Very Satisfied','4 — Satisfied','3 — Neutral','2 — Dissatisfied','1 — Very Dissatisfied'];
    const colors = [FACE_COLORS[5], FACE_COLORS[4], FACE_COLORS[3], FACE_COLORS[2], FACE_COLORS[1]];
    const bords  = [FACE_BORDER[5], FACE_BORDER[4], FACE_BORDER[3], FACE_BORDER[2], FACE_BORDER[1]];

    if (chartInst) chartInst.destroy();
    chartInst = new Chart(ctx.getContext('2d'), {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Responses',
          data: counts,
          backgroundColor: colors,
          borderColor: bords,
          borderWidth: 1.5,
          borderRadius: 5,
          borderSkipped: false,
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#1e293b', padding: 10, cornerRadius: 8,
            callbacks: {
              label: item => {
                const v   = item.parsed.x;
                const pct = totF > 0 ? Math.round(v / totF * 100) : 0;
                return `  ${v} response${v !== 1 ? 's' : ''} (${pct}%)`;
              }
            }
          }
        },
        scales: {
          x: {
            beginAtZero: true,
            ticks: { stepSize:1, precision:0, color:'#94a3b8', font:{size:11} },
            grid: { color:'rgba(0,0,0,.05)' }, border: { display:false }
          },
          y: {
            ticks: { color:'#475569', font:{size:12, weight:'600'} },
            grid: { display:false }, border: { display:false }
          }
        },
        layout: { padding:{ right:16 } }
      }
    });
  }

  /* ══════════════════════════════════════════════
     INIT
  ══════════════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', () => {
    initChart();
    initFilters();
    activeRows = getFilteredRows();
    renderPage();
    updateSummary();
    updateFilterPills();
  });

  /* ══════════════════════════════════════════════
     FILTERS INIT
  ══════════════════════════════════════════════ */
  function initFilters() {
    /* Period dropdown */
    const periodSel = document.getElementById('fbFilterPeriod');
    if (periodSel) {
      periodSel.addEventListener('change', () => {
        filterPeriod = periodSel.value;
        toggleFbCustomRange(filterPeriod === 'custom');
        applyAll();
      });
    }

    /* Custom date — From */
    document.getElementById('fbCustomDateFrom')?.addEventListener('change', e => {
      customDateFrom = e.target.value ? new Date(e.target.value + 'T00:00:00') : null;
      applyAll();
    });

    /* Custom date — To */
    document.getElementById('fbCustomDateTo')?.addEventListener('change', e => {
      customDateTo = e.target.value ? new Date(e.target.value + 'T23:59:59') : null;
      applyAll();
    });

    const bind = (id, setter) => {
      document.getElementById(id)?.addEventListener('change', e => {
        setter(e.target.value);
        applyAll();
      });
    };
    bind('fbFilterRating',    v => { filterRating    = v; });
    bind('fbFilterSentiment', v => { filterSentiment = v; });
    bind('fbFilterDept',      v => { filterDept      = v; });
    bind('fbFilterType',      v => { filterType      = v; });

    document.getElementById('fbSearch')?.addEventListener('input', e => {
      searchQuery = e.target.value.toLowerCase().trim();
      applyAll();
    });

    document.getElementById('fbFilterResetBtn')?.addEventListener('click', () => {
      filterPeriod = filterRating = filterSentiment = filterDept = filterType = 'all';
      searchQuery  = '';
      customDateFrom = customDateTo = null;
      ['fbFilterPeriod','fbFilterRating','fbFilterSentiment','fbFilterDept','fbFilterType'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = 'all';
      });
      const s = document.getElementById('fbSearch');
      if (s) s.value = '';
      toggleFbCustomRange(false);
      applyAll();
    });
  }

  function applyAll() {
    currentPage = 1;
    activeRows  = getFilteredRows();
    renderPage();
    updateSummary();
    updateFilterPills();
  }

  /* ══════════════════════════════════════════════
     ROW FILTERING
  ══════════════════════════════════════════════ */
  function getAllRows() {
    return Array.from(document.querySelectorAll('#fbTableBody tr[data-ticket-id]'));
  }

  function getFilteredRows() {
    return getAllRows().filter(r => {
      /* Period / date filter */
      if (filterPeriod !== 'all') {
        const ts = parseInt(r.dataset.dateTs || r.dataset['date-ts'] || 0, 10);
        if (!tsInFbPeriod(ts)) return false;
      }
      if (filterRating    !== 'all' && r.dataset.rating    !== filterRating)    return false;
      if (filterSentiment !== 'all' && r.dataset.sentiment !== filterSentiment) return false;
      if (filterType      !== 'all' && r.dataset.type      !== filterType)       return false;
      if (filterDept !== 'all') {
        const deptVal = (r.dataset.submittedBy || '').trim().toLowerCase();
        if (deptVal !== filterDept.toLowerCase()) return false;
      }
      if (searchQuery) {
        const hay = [
          r.dataset.ticketId, r.dataset.title, r.dataset.submittedBy,
          r.dataset.sentiment, r.dataset.comment, r.dataset.type,
          r.dataset.rating, r.dataset.ratingLabel
        ].join(' ').toLowerCase();
        if (!hay.includes(searchQuery)) return false;
      }
      return true;
    });
  }

  /* ══════════════════════════════════════════════
     FILTER PILLS
  ══════════════════════════════════════════════ */
  function updateFilterPills() {
    const el = document.getElementById('fbActiveFilters');
    if (!el) return;
    const parts = [];
    parts.push(`📅 ${getFbPeriodLabel()}`);
    if (filterRating    !== 'all') parts.push(`Rating: <strong>${filterRating} stars - ${RATING_LABEL[parseInt(filterRating,10)]}</strong>`);
    if (filterSentiment !== 'all') parts.push(`Sentiment: <strong>${filterSentiment}</strong>`);
    if (filterDept      !== 'all') parts.push(`Dept/Faculty: <strong>${filterDept}</strong>`);
    if (filterType      !== 'all') parts.push(`Type: <strong>${filterType}</strong>`);
    if (searchQuery)                parts.push(`Search: <strong>"${searchQuery}"</strong>`);
    el.innerHTML = parts.map(p => `<span class="filter-pill">${p}</span>`).join('');
    el.style.display = 'flex';
  }

  /* ══════════════════════════════════════════════
     PAGINATION
  ══════════════════════════════════════════════ */
  function renderPage() {
    const total = activeRows.length;
    const pages = Math.max(1, Math.ceil(total / PER_PAGE));
    currentPage = Math.min(currentPage, pages);
    const s = (currentPage - 1) * PER_PAGE;
    const e = s + PER_PAGE;

    getAllRows().forEach(r => r.style.display = 'none');
    activeRows.forEach((r, i) => { r.style.display = (i >= s && i < e) ? '' : 'none'; });

    const pi = document.getElementById('fbPageInfo');
    if (pi) pi.textContent = total
      ? `Showing ${s + 1}–${Math.min(e, total)} of ${total} records`
      : 'No records match your filters';

    const pb = document.getElementById('fbPageBtns');
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

  function updateSummary() {
    const rows   = activeRows;
    const total  = rows.length;
    const p      = rows.filter(r => r.dataset.sentiment === 'Positive').length;
    const n      = rows.filter(r => r.dataset.sentiment === 'Neutral').length;
    const g      = rows.filter(r => r.dataset.sentiment === 'Negative').length;
    const manual = rows.filter(r => r.dataset.type === 'Manual').length;
    const auto   = rows.filter(r => r.dataset.type === 'Auto').length;
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('fbss-total',  total);
    set('fbss-pos',    p);
    set('fbss-neu',    n);
    set('fbss-neg',    g);
    set('fbss-manual', manual);
    set('fbss-auto',   auto);
  }

  /* ══════════════════════════════════════════════
     SORT
  ══════════════════════════════════════════════ */
  const COL_KEYS = ['ticketId','assigned','category','submittedBy','rating','sentiment','comment','dateTs','type'];

  window.fbSortTable = function (col) {
    const key = COL_KEYS[col];
    fbSortDir[key] = !fbSortDir[key];
    activeRows.sort((a, b) => {
      const aV = a.dataset[key] || '';
      const bV = b.dataset[key] || '';
      const numA = parseFloat(aV), numB = parseFloat(bV);
      if (!isNaN(numA) && !isNaN(numB))
        return fbSortDir[key] ? numA - numB : numB - numA;
      return fbSortDir[key]
        ? aV.localeCompare(bV, undefined, { numeric:true })
        : bV.localeCompare(aV, undefined, { numeric:true });
    });
    const tbody = document.getElementById('fbTableBody');
    activeRows.forEach(r => tbody.appendChild(r));
    document.querySelectorAll('#fbTable thead th').forEach((th, i) => {
      th.classList.toggle('sorted', i === col);
      const ic = th.querySelector('.sort-icon');
      if (ic) ic.textContent = i === col ? (fbSortDir[key] ? '↑' : '↓') : '⇅';
    });
    currentPage = 1;
    renderPage();
  };

  /* ══════════════════════════════════════════════
     CSV
  ══════════════════════════════════════════════ */
  window.fbDownloadCSV = function () {
    const rows = activeRows.length ? activeRows : getAllRows();
    const slug = getFbPeriodLabel().replace(/[\s→]+/g, '-').toLowerCase();
    const lines = [['Ticket ID','Assigned Staff','Category','Submitted By','Rating','Rating Label','Sentiment','Comment','Date','Type'].join(',')];
    rows.forEach(r => {
      lines.push([
        r.dataset.ticketId,
        `"${(r.dataset.assigned    || '').replace(/"/g,'""')}"`,
        `"${(r.dataset.category    || '').replace(/"/g,'""')}"`,
        `"${(r.dataset.submittedBy || '').replace(/"/g,'""')}"`,
        r.dataset.rating,
        `"${RATING_LABEL[parseInt(r.dataset.rating,10)] || ''}"`,
        r.dataset.sentiment,
        `"${(r.dataset.comment     || '').replace(/"/g,'""')}"`,
        r.dataset.date,
        r.dataset.type
      ].join(','));
    });
    const blob = new Blob([lines.join('\n')], { type:'text/csv' });
    Object.assign(document.createElement('a'), {
      href: URL.createObjectURL(blob),
      download: `feedback-report-${slug}.csv`
    }).click();
  };

  /* ══════════════════════════════════════════════
     PDF
  ══════════════════════════════════════════════ */
  window.fbDownloadPDF = async function () {
    const { jsPDF } = window.jspdf;
    const doc   = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const label = getFbPeriodLabel();
    const slug  = label.replace(/[\s→]+/g, '-').toLowerCase();

    // ── Header bar (A4 landscape = 297mm wide) ──
    doc.setFillColor(87, 68, 118);
    doc.rect(0, 0, 297, 22, 'F');

    // ── Logo (same as tickets + staff PDF) ──
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

    // ── Header text ──
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(12);
    doc.text('UniKL Help Desk — Administration & Facilities Management Department | Feedback Report', 36, 10);
    doc.setFontSize(8);
    doc.setFont('helvetica', 'normal');
    doc.text('Generated: ' + new Date().toLocaleString(), 36, 16);

    // ── Filter info line ──
    doc.setTextColor(87, 68, 118);
    doc.setFontSize(8);
    doc.setFont('helvetica', 'bold');
    const filters = [
      `Period: ${label}`,
      filterRating    !== 'all' ? `Rating: ${filterRating} stars - ${RATING_LABEL[parseInt(filterRating, 10)]}` : null,
      filterSentiment !== 'all' ? `Sentiment: ${filterSentiment}` : null,
      filterDept      !== 'all' ? `Dept: ${filterDept}` : null,
      filterType      !== 'all' ? `Type: ${filterType}` : null,
      searchQuery     ? `Search: "${searchQuery}"` : null,
    ].filter(Boolean).join('   |   ');
    doc.text(filters, 14, 28);

    // ── KPI summary line ──
    const rows   = activeRows;
    const total  = rows.length;
    const p      = rows.filter(r => r.dataset.sentiment === 'Positive').length;
    const n      = rows.filter(r => r.dataset.sentiment === 'Neutral').length;
    const g      = rows.filter(r => r.dataset.sentiment === 'Negative').length;
    const manual = rows.filter(r => r.dataset.type === 'Manual').length;
    const auto   = rows.filter(r => r.dataset.type === 'Auto').length;
    const satP   = total > 0 ? Math.round(p / total * 100) : 0;
    const avgR   = total > 0
      ? (rows.reduce((s, r) => s + parseInt(r.dataset.rating, 10), 0) / total).toFixed(2) : '—';

    doc.setTextColor(30, 41, 59);
    doc.setFontSize(8.5);
    doc.setFont('helvetica', 'normal');
    doc.text(
      `Total: ${total}   |   Positive (4-5 stars): ${p}   |   Neutral (3 stars): ${n}   |   Negative (1-2 stars): ${g}   |   Avg Rating: ${avgR}/5   |   Manual: ${manual}   |   Auto: ${auto}`,
      14, 34
    );

    // ── Legend note line ──
    doc.setTextColor(148, 163, 184);
    doc.setFontSize(7);
    doc.text(
      'Positive = Very Satisfied (5) + Satisfied (4)   |   Neutral = Neutral (3)   |   Negative = Dissatisfied (2) + Very Dissatisfied (1)',
      14, 40
    );

    // ── Table ──
    doc.autoTable({
      startY: 45,
      margin: { left: 14, right: 14 },
      tableWidth: 'auto',
      head: [['Ticket ID', 'Assigned Staff', 'Category', 'Submitted By', 'Rating', 'Rating Label', 'Sentiment', 'Comment', 'Date', 'Type']],
      body: rows.map(r => [
        r.dataset.ticketId,
        r.dataset.assigned,
        r.dataset.category,
        r.dataset.submittedBy || '—',
        r.dataset.rating + ' / 5',
        RATING_LABEL[parseInt(r.dataset.rating, 10)] || '',
        r.dataset.sentiment,
        (r.dataset.comment || '—').substring(0, 55) + ((r.dataset.comment || '').length > 55 ? '…' : ''),
        r.dataset.date,
        r.dataset.type
      ]),
      styles: { fontSize: 7.5, cellPadding: 2.5, overflow: 'linebreak' },
      headStyles: { fillColor: [87, 68, 118], textColor: 255, fontStyle: 'bold', fontSize: 8 },
      alternateRowStyles: { fillColor: [248, 247, 252] },
      columnStyles: {
        0: { cellWidth: 32 },  // Ticket ID
        1: { cellWidth: 28 },  // Assigned Staff
        2: { cellWidth: 28 },  // Category
        3: { cellWidth: 25 },  // Submitted By
        4: { cellWidth: 13 },  // Rating
        5: { cellWidth: 24 },  // Rating Label
        6: { cellWidth: 18 },  // Sentiment
        7: { cellWidth: 42 },  // Comment
        8: { cellWidth: 18 },  // Date
        9: { cellWidth: 12 },  // Type
      },
      didParseCell: data => {
        if (data.section !== 'body') return;
        if (data.column.index === 6) {
          const v = data.cell.raw;
          const c = v === 'Positive' ? [22, 163, 74] : v === 'Neutral' ? [217, 119, 6] : [220, 38, 38];
          data.cell.styles.textColor = c;
          data.cell.styles.fontStyle = 'bold';
        }
        if (data.column.index === 4) {
          const v = parseInt(data.cell.raw, 10);
          data.cell.styles.textColor = v >= 4 ? [22, 163, 74] : v === 3 ? [217, 119, 6] : [220, 38, 38];
          data.cell.styles.fontStyle = 'bold';
        }
      }
    });

    // ── Page numbers ──
    const pc = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pc; i++) {
      doc.setPage(i);
      doc.setFontSize(7);
      doc.setTextColor(148, 163, 184);
      doc.text(`Page ${i} of ${pc}  —  UniKL Help Desk — AFSMD  —  Feedback Report`, 14, 203);
    }

    doc.save(`feedback-report-${slug}.pdf`);
  };

})();