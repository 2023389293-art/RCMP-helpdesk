/* =============================================================
   category-report.js  —  IT Dept Admin · Category Tab 
   + Custom date range filter (pure JS, no page reload)
   The category data injected by PHP now always covers ALL time;
   JS handles date filtering client-side.
   ============================================================= */
 
(function () {
  'use strict';

  const RAW = window.CATEGORY_DATA || {};
  const labels     = RAW.labels     || [];
  const openCounts = RAW.open       || [];
  const inProg     = RAW.inProgress || [];
  const closedC    = RAW.closed     || [];

  /* Build full category objects */
  const ALL_CATS = labels.map((lbl, i) => ({
    label:      lbl,
    open:       openCounts[i]  || 0,
    inProgress: inProg[i]      || 0,
    closed:     closedC[i]     || 0,
    total:      (openCounts[i] || 0) + (inProg[i] || 0) + (closedC[i] || 0),
  }));

  /* ── Filter state ── */
  let filterPeriod   = 'all';   // 'all' | '7' | '30' … | 'custom'
  let customDateFrom = null;    // Date | null
  let customDateTo   = null;    // Date | null
  let filterStatus   = 'all';
  let catSearchQuery = '';
  let catSortDir     = {};
  let catActiveRows  = [];
  let chartInst      = null;
  const CAT_PER_PAGE = 10;
  let catCurrentPage = 1;

  const periodLabels = {
    '7':'Last 7 days','30':'Last 30 days','60':'Last 60 days',
    '90':'Last 90 days','180':'Last 6 months','365':'Last year','all':'All time'
  };

  /* ══════════════════════════════════════════════
     PERIOD HELPERS
  ══════════════════════════════════════════════ */
  function toggleCatCustomRange(show) {
    const from = document.getElementById('catCustomRangeFrom');
    const to   = document.getElementById('catCustomRangeTo');
    if (from) from.style.display = show ? '' : 'none';
    if (to)   to.style.display   = show ? '' : 'none';
    if (!show) {
      customDateFrom = null;
      customDateTo   = null;
      const f = document.getElementById('catCustomDateFrom');
      const t = document.getElementById('catCustomDateTo');
      if (f) f.value = '';
      if (t) t.value = '';
    }
  }

  function tsInCatPeriod(tsSeconds) {
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

  function getCatPeriodLabel() {
    if (filterPeriod === 'custom') {
      const fmt = d => d ? d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '?';
      return `${fmt(customDateFrom)} → ${fmt(customDateTo)}`;
    }
    return periodLabels[filterPeriod] || filterPeriod;
  }

  /* ══════════════════════════════════════════════
     INIT
  ══════════════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', () => {
    initCatFilters();
    requestAnimationFrame(() => {
      renderCatChart(ALL_CATS);
      updateCatKPIs(ALL_CATS);
      catActiveRows = getCatRows();
      renderCatPage();
      updateCatSummary();
      updateCatFilterPill();
      updateResolutionCalc();
    });
  });

  /* ══════════════════════════════════════════════
     FILTERS
  ══════════════════════════════════════════════ */
  function initCatFilters() {

    /* Period dropdown — removed onchange page-reload, pure JS now */
    const periodSel = document.getElementById('catPeriodSelect');
    if (periodSel) {
      /* Set initial value based on PHP-rendered selected attr */
      const pre = periodSel.querySelector('option[selected]');
      if (pre) filterPeriod = pre.value;

      periodSel.addEventListener('change', () => {
        filterPeriod = periodSel.value;
        toggleCatCustomRange(filterPeriod === 'custom');
        applyAll();
      });
    }

    /* Custom date — From */
    document.getElementById('catCustomDateFrom')?.addEventListener('change', e => {
      customDateFrom = e.target.value ? new Date(e.target.value + 'T00:00:00') : null;
      applyAll();
    });

    /* Custom date — To */
    document.getElementById('catCustomDateTo')?.addEventListener('change', e => {
      customDateTo = e.target.value ? new Date(e.target.value + 'T23:59:59') : null;
      applyAll();
    });

    const statusSel = document.getElementById('catFilterStatus');
    if (statusSel) {
      statusSel.addEventListener('change', () => {
        filterStatus = statusSel.value;
        applyAll();
      });
    }

    const searchEl = document.getElementById('catSearch');
    if (searchEl) {
      searchEl.addEventListener('input', e => {
        catSearchQuery = e.target.value.toLowerCase().trim();
        catCurrentPage = 1;
        catActiveRows  = getCatRows();
        renderCatPage();
        updateCatSummary();
        updateResolutionCalc();
      });
    }

    const resetBtn = document.getElementById('catFilterResetBtn');
    if (resetBtn) {
      resetBtn.addEventListener('click', () => {
        filterPeriod   = 'all';
        filterStatus   = 'all';
        catSearchQuery = '';
        customDateFrom = null;
        customDateTo   = null;
        if (periodSel) periodSel.value = 'all';
        if (statusSel) statusSel.value = 'all';
        if (searchEl)  searchEl.value  = '';
        toggleCatCustomRange(false);
        applyAll();
      });
    }
  }

  function applyAll() {
    catCurrentPage = 1;
    const filtered = getFilteredCats();
    renderCatChart(filtered);
    updateCatKPIs(filtered);
    catActiveRows = getCatRows();
    renderCatPage();
    updateCatSummary();
    updateCatFilterPill();
    updateResolutionCalc();
  }

  /* ══════════════════════════════════════════════
     DATA FILTERING
     Note: For category stats, the "period" filter works on the
     DOM rows' data-created-ts attribute (earliest ticket date
     in that category). If you want strict per-period filtering
     the PHP must pass per-ticket timestamps; without that we
     filter the DOM rows using their data-created-ts.
     Category JS-array (ALL_CATS) doesn't carry timestamps so
     the chart always shows full data — only the table is gated.
  ══════════════════════════════════════════════ */
  function getFilteredCats() {
    /* For chart we filter by status only (no ts in JS array) */
    return ALL_CATS.filter(c => {
      if (filterStatus === 'open'        && c.open       === 0) return false;
      if (filterStatus === 'in_progress' && c.inProgress === 0) return false;
      if (filterStatus === 'closed'      && c.closed     === 0) return false;
      return true;
    });
  }

  function getAllCatRows() {
    return Array.from(document.querySelectorAll('#catTableBody tr[data-category]'));
  }

  function getCatRows() {
    return getAllCatRows().filter(r => {
      /* Period / date filter — uses data-created-ts if available */
      if (filterPeriod !== 'all') {
        const ts = parseInt(r.dataset.createdTs || r.dataset['created-ts'] || 0, 10);
        if (ts && !tsInCatPeriod(ts)) return false;
        /* if no ts present, include row (safe fallback) */
      }
      if (filterStatus === 'open'        && parseInt(r.dataset.open       || 0) === 0) return false;
      if (filterStatus === 'in_progress' && parseInt(r.dataset.inprogress || 0) === 0) return false;
      if (filterStatus === 'closed'      && parseInt(r.dataset.closed     || 0) === 0) return false;
      if (catSearchQuery) {
        const hay = (r.dataset.category || '').toLowerCase();
        if (!hay.includes(catSearchQuery)) return false;
      }
      return true;
    });
  }

  /* ══════════════════════════════════════════════
     FILTER PILL
  ══════════════════════════════════════════════ */
  function updateCatFilterPill() {
    const el = document.getElementById('catActiveFilterSummary');
    if (!el) return;
    const parts = [];
    parts.push(`📅 ${getCatPeriodLabel()}`);
    if (filterStatus !== 'all') parts.push(`Status: <strong>${filterStatus.replace('_',' ')}</strong>`);
    if (catSearchQuery)          parts.push(`Search: <strong>"${catSearchQuery}"</strong>`);
    el.innerHTML = parts.map(p => `<span class="filter-pill">${p}</span>`).join('');
    el.style.display = 'flex';
  }

  /* ══════════════════════════════════════════════
     KPI ROW
  ══════════════════════════════════════════════ */
  function updateCatKPIs(cats) {
    const total   = cats.reduce((s, c) => s + c.total, 0);
    const openT   = cats.reduce((s, c) => s + c.open, 0);
    const progT   = cats.reduce((s, c) => s + c.inProgress, 0);
    const closedT = cats.reduce((s, c) => s + c.closed, 0);

    const rateByTickets = total > 0 ? Math.round(closedT / total * 1000) / 10 : 0;
    const catRates = cats.map(c => c.total > 0 ? (c.closed / c.total * 100) : 0);    const rateByCategory = catRates.length > 0
      ? Math.round(catRates.reduce((s, v) => s + v, 0) / catRates.length * 10) / 10 : 0;

    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('catkpi-cats', cats.length);    set('catkpi-total',         total);
    set('catkpi-open',          openT);
    set('catkpi-inprog',        progT);
    set('catkpi-closed',        closedT);
    set('catkpi-rate-tickets',  rateByTickets   + '%');
    set('catkpi-rate-category', rateByCategory  + '%');

    const elT = document.getElementById('catkpi-rate-tickets');
    const elC = document.getElementById('catkpi-rate-category');
    if (elT) elT.style.color = rateByTickets  >= 70 ? '#16A34A' : (rateByTickets  >= 40 ? '#F97316' : '#DC2626');
    if (elC) elC.style.color = rateByCategory >= 70 ? '#16A34A' : (rateByCategory >= 40 ? '#F97316' : '#DC2626');
  }

  /* ══════════════════════════════════════════════
     RESOLUTION RATE CALC PANEL
  ══════════════════════════════════════════════ */
  function updateResolutionCalc() {
    const rows    = catActiveRows;
    const totalT  = rows.reduce((s, r) => s + parseInt(r.dataset.total  || 0, 10), 0);
    const closedT = rows.reduce((s, r) => s + parseInt(r.dataset.closed || 0, 10), 0);
    const rate    = totalT > 0 ? Math.round(closedT / totalT * 1000) / 10 : 0;

    const rateEl   = document.getElementById('catcalc-rate');
    const totalEl  = document.getElementById('catcalc-total');
    const closedEl = document.getElementById('catcalc-closed');
    if (totalEl)  totalEl.textContent  = totalT;
    if (closedEl) closedEl.textContent = closedT;
    if (rateEl) {
      rateEl.textContent = rate + '%';
      rateEl.style.color = rate >= 70 ? '#16A34A' : (rate >= 40 ? '#F97316' : '#DC2626');
    }
  }

  /* ══════════════════════════════════════════════
     CHART
  ══════════════════════════════════════════════ */
  function renderCatChart(cats) {
    const ctx = document.getElementById('categoryChart');
    if (!ctx) return;

    const lbls       = cats.map(c => c.label);
    const showOpen   = filterStatus === 'all' || filterStatus === 'open';
    const showProg   = filterStatus === 'all' || filterStatus === 'in_progress';
    const showClosed = filterStatus === 'all' || filterStatus === 'closed';

    const datasets = [];
    if (showOpen)   datasets.push({ label:'Open',        data: cats.map(c=>c.open),       backgroundColor:'rgba(99,102,241,.80)',  borderRadius:4, borderSkipped:false, stack:'cat' });
    if (showProg)   datasets.push({ label:'In Progress', data: cats.map(c=>c.inProgress), backgroundColor:'rgba(245,158,11,.80)',  borderRadius:4, borderSkipped:false, stack:'cat' });
    if (showClosed) datasets.push({ label:'Closed',      data: cats.map(c=>c.closed),     backgroundColor:'rgba(22,163,74,.80)',   borderRadius:4, borderSkipped:false, stack:'cat' });

    const wrapEl = document.getElementById('catChartWrap');
    if (wrapEl) {
      const needed = Math.max(200, lbls.length * 52 + 80);
      wrapEl.style.minHeight = needed + 'px';
    }

    if (chartInst) chartInst.destroy();

    chartInst = new Chart(ctx.getContext('2d'), {
      type: 'bar',
      data: { labels: lbls, datasets },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'top', align: 'end',
            labels: { font: { size: 11, weight: '600' }, padding: 18, usePointStyle: true, pointStyle: 'rectRounded' }
          },
          tooltip: {
            backgroundColor: '#1e293b', padding: 12, cornerRadius: 10,
            mode: 'index', intersect: false,
            callbacks: { label: item => `  ${item.dataset.label}: ${item.parsed.x}` }
          }
        },
        scales: {
          x: {
            stacked: true, beginAtZero: true,
            grid: { color: 'rgba(226,232,240,.55)' }, border: { display: false },
            ticks: { stepSize: 1, precision: 0, font: { size: 11 }, color: '#94a3b8' }
          },
          y: {
            stacked: true, grid: { display: false }, border: { display: false },
            ticks: { font: { size: 12, weight: '600' }, color: '#334155', padding: 10 }
          }
        },
        layout: { padding: { right: 20 } }
      }
    });

    const sub = document.getElementById('catChartSubtitle');
    if (sub) {
      const total = cats.reduce((s, c) => s + c.total, 0);
      sub.textContent = `${cats.length} categor${cats.length !== 1 ? 'ies' : 'y'} · ${total} ticket${total !== 1 ? 's' : ''} · ${getCatPeriodLabel()}`;
    }
  }

  /* ══════════════════════════════════════════════
     PAGINATION
  ══════════════════════════════════════════════ */
  function renderCatPage() {
    const rows  = catActiveRows;
    const total = rows.length;
    const pages = Math.max(1, Math.ceil(total / CAT_PER_PAGE));
    catCurrentPage = Math.min(catCurrentPage, pages);
    const s = (catCurrentPage - 1) * CAT_PER_PAGE;
    const e = s + CAT_PER_PAGE;

    getAllCatRows().forEach(r => r.style.display = 'none');
    rows.forEach((r, i) => { r.style.display = (i >= s && i < e) ? '' : 'none'; });

    const pi = document.getElementById('catPageInfo');
    if (pi) pi.textContent = total
      ? `Showing ${s + 1}–${Math.min(e, total)} of ${total} categories`
      : 'No categories match your filters';

    const pb = document.getElementById('catPageBtns');
    if (!pb) return;
    pb.innerHTML = '';
    pb.appendChild(mkBtn('‹', catCurrentPage === 1, () => { catCurrentPage--; renderCatPage(); }));
    let sp = Math.max(1, catCurrentPage - 2), ep = Math.min(pages, sp + 4);
    if (ep - sp < 4) sp = Math.max(1, ep - 4);
    for (let p = sp; p <= ep; p++) {
      const b = mkBtn(p, false, () => { catCurrentPage = p; renderCatPage(); });
      if (p === catCurrentPage) b.classList.add('active');
      pb.appendChild(b);
    }
    pb.appendChild(mkBtn('›', catCurrentPage === pages, () => { catCurrentPage++; renderCatPage(); }));
  }

  function mkBtn(label, disabled, fn) {
    const b = document.createElement('button');
    b.className = 'page-btn'; b.textContent = label; b.disabled = disabled;
    b.addEventListener('click', fn); return b;
  }

  function updateCatSummary() {
    const rows      = catActiveRows;
    const totalT    = rows.reduce((s, r) => s + parseInt(r.dataset.total      || 0, 10), 0);
    const openT     = rows.reduce((s, r) => s + parseInt(r.dataset.open       || 0, 10), 0);
    const inProgT   = rows.reduce((s, r) => s + parseInt(r.dataset.inprogress || 0, 10), 0);
    const closedT   = rows.reduce((s, r) => s + parseInt(r.dataset.closed     || 0, 10), 0);

    const rateByTickets = totalT > 0 ? Math.round(closedT / totalT * 1000) / 10 : 0;
    const catRates = rows.map(r => {
  const t = parseInt(r.dataset.total  || 0, 10);
  const c = parseInt(r.dataset.closed || 0, 10);
  return t > 0 ? (c / t * 100) : 0;  // ← 0 instead of null
});
    const rateByCategory = catRates.length > 0
      ? Math.round(catRates.reduce((s, v) => s + v, 0) / catRates.length * 10) / 10 : 0;

    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('catss-cats',          rows.length);
    set('catss-total',         totalT);
    set('catss-open',          openT);
    set('catss-inprog',        inProgT);
    set('catss-closed',        closedT);
    set('catss-rate-tickets',  rateByTickets  + '%');
    set('catss-rate-category', rateByCategory + '%');

    const elT = document.getElementById('catss-rate-tickets');
    const elC = document.getElementById('catss-rate-category');
    if (elT) elT.style.color = rateByTickets  >= 70 ? '#16A34A' : (rateByTickets  >= 40 ? '#F97316' : '#DC2626');
    if (elC) elC.style.color = rateByCategory >= 70 ? '#16A34A' : (rateByCategory >= 40 ? '#F97316' : '#DC2626');
  }

  /* ══════════════════════════════════════════════
     SORT
  ══════════════════════════════════════════════ */
  const CAT_COL_KEYS = ['category','total','open','inprogress','closed','high','rate'];

  window.sortCatTable = function (col) {
    const key = CAT_COL_KEYS[col];
    catSortDir[key] = !catSortDir[key];
    catActiveRows.sort((a, b) => {
      const aV = a.dataset[key] || '';
      const bV = b.dataset[key] || '';
      const numA = parseFloat(aV), numB = parseFloat(bV);
      if (!isNaN(numA) && !isNaN(numB))
        return catSortDir[key] ? numA - numB : numB - numA;
      return catSortDir[key]
        ? aV.localeCompare(bV, undefined, { numeric: true })
        : bV.localeCompare(aV, undefined, { numeric: true });
    });
    const tbody = document.getElementById('catTableBody');
    catActiveRows.forEach(r => tbody.appendChild(r));
    document.querySelectorAll('#catTable thead th').forEach((th, i) => {
      th.classList.toggle('sorted', i === col);
      const ic = th.querySelector('.sort-icon');
      if (ic) ic.textContent = i === col ? (catSortDir[key] ? '↑' : '↓') : '⇅';
    });
    catCurrentPage = 1;
    renderCatPage();
  };

  /* ══════════════════════════════════════════════
     DOWNLOAD CSV
  ══════════════════════════════════════════════ */
  window.catDownloadCSV = function () {
    const rows = catActiveRows.length ? catActiveRows : getAllCatRows();
    const lines = [['Category','Total','Open','In Progress','Closed','High Priority','Resolution Rate'].join(',')];
    rows.forEach(r => {
      lines.push([
        `"${(r.dataset.category || '').replace(/"/g,'""')}"`,
        r.dataset.total      || 0,
        r.dataset.open       || 0,
        r.dataset.inprogress || 0,
        r.dataset.closed     || 0,
        r.dataset.high       || 0,
        (r.dataset.rate      || '0') + '%'
      ].join(','));
    });
    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    Object.assign(document.createElement('a'), {
      href: URL.createObjectURL(blob),
      download: `category-report-${getCatPeriodLabel().replace(/[\s→]+/g,'-').toLowerCase()}.csv`
    }).click();
  };

  /* ══════════════════════════════════════════════
     DOWNLOAD PDF
  ══════════════════════════════════════════════ */
  window.catDownloadPDF = async function () {
    const { jsPDF } = window.jspdf;
    const doc   = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const label = getCatPeriodLabel();

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
    } catch(e) {}

    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(12);
    doc.text('UniKL Help Desk — IT Department | Category Report', 36, 10);
    doc.setFontSize(8);
    doc.setFont('helvetica', 'normal');
    doc.text('Generated: ' + new Date().toLocaleString(), 36, 16);

    doc.setTextColor(87,68,118); doc.setFontSize(8); doc.setFont('helvetica','bold');
    const filterLine = [
      `Period: ${label}`,
      filterStatus !== 'all' ? `Status: ${filterStatus.replace('_',' ')}` : null,
      catSearchQuery ? `Search: "${catSearchQuery}"` : null,
    ].filter(Boolean).join('   |   ') || 'All categories';
    doc.text(filterLine, 14, 30);

    const rows      = catActiveRows;
    const totalT    = rows.reduce((s,r) => s + parseInt(r.dataset.total      || 0,10), 0);
    const inProgT   = rows.reduce((s,r) => s + parseInt(r.dataset.inprogress || 0,10), 0);
    const closedT   = rows.reduce((s,r) => s + parseInt(r.dataset.closed     || 0,10), 0);
    const rateByTickets  = totalT > 0 ? Math.round(closedT/totalT*1000)/10 : 0;
    const catRatesArr = rows.map(r => {
  const t = parseInt(r.dataset.total||0,10), c = parseInt(r.dataset.closed||0,10);
  return t > 0 ? (c/t*100) : 0;
});
    const rateByCategory = catRatesArr.length > 0
      ? Math.round(catRatesArr.reduce((s,v)=>s+v,0)/catRatesArr.length*10)/10 : 0;

    doc.setTextColor(30,41,59); doc.setFontSize(8.5); doc.setFont('helvetica','normal');
    doc.text(
      `Categories: ${rows.length}  |  Total: ${totalT}  |  In Progress: ${inProgT}  |  Closed: ${closedT}  |  Rate (By Tickets): ${rateByTickets}%  |  Rate (By Category Avg): ${rateByCategory}%`,
      14, 37
    );

    doc.setTextColor(148,163,184); doc.setFontSize(7);
    doc.text('Rate (By Tickets) = Closed ÷ Total Tickets × 100    |    Rate (By Category Avg) = Average of each category\'s resolution rate', 14, 43);

    doc.autoTable({
      startY: 48,
      head: [['Category','Total','Open','In Progress','Closed','High Priority','Resolution Rate']],
      body: rows.map(r => {
        const rT = parseFloat(r.dataset.rate || 0);
        return [
          r.dataset.category   || '',
          parseInt(r.dataset.total      || 0, 10),
          r.dataset.open       || 0,
          r.dataset.inprogress || 0,
          parseInt(r.dataset.closed     || 0, 10),
          r.dataset.high       || 0,
          rT + '%'
        ];
      }),
      styles: { fontSize:8, cellPadding:2.5 },
      headStyles: { fillColor:[87,68,118], textColor:255, fontStyle:'bold', fontSize:8 },
      alternateRowStyles: { fillColor:[248,247,252] },
      columnStyles: {
        0:{cellWidth:72},1:{cellWidth:20},2:{cellWidth:20},
        3:{cellWidth:28},4:{cellWidth:20},5:{cellWidth:33},6:{cellWidth:44}
      },
      didParseCell: data => {
        if (data.section !== 'body') return;
        if (data.column.index === 2) data.cell.styles.textColor = [99,102,241];
        if (data.column.index === 3) data.cell.styles.textColor = [245,158,11];
        if (data.column.index === 4) data.cell.styles.textColor = [22,163,74];
        if (data.column.index === 6) {
          const v = parseFloat(data.cell.raw);
          data.cell.styles.textColor = v>=70?[22,163,74]:v>=40?[249,115,22]:[220,38,38];
          data.cell.styles.fontStyle = 'bold';
        }
      }
    });

    const pc = doc.internal.getNumberOfPages();
    for (let i=1;i<=pc;i++) {
      doc.setPage(i); doc.setFontSize(7); doc.setTextColor(148,163,184);
      doc.text(`Page ${i} of ${pc} — UniKL Help Desk IT Dept`, 14, 208);
    }
    doc.save(`category-report-${label.replace(/[\s→]+/g,'-').toLowerCase()}.pdf`);
  };

})();