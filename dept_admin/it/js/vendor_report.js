/* =============================================================
   vendor_report.js  —  IT Dept Admin · Vendor Activity Tab
   ============================================================= */

(function () {
  'use strict';

  const ALL_VENDORS = window.VENDOR_DATA?.vendors || [];
  const PERIOD_INIT  = window.VENDOR_DATA?.period  || '30';

  /* ── State ── */
  let vendorSearchQuery  = '';
  let vendorFilterByName = 'all';
  let vendorSortDir      = {};
  let vendorActiveRows   = [];
  let vendorChartInst    = null;
  const VENDOR_PER_PAGE  = 10;
  let vendorCurrentPage  = 1;
  let vendorFilterPeriod = PERIOD_INIT;
  let vendorCustomFrom   = null;
  let vendorCustomTo     = null;

  const periodLabels = {
    '7':   'Last 7 days',  '30':  'Last 30 days',
    '60':  'Last 60 days', '90':  'Last 90 days',
    '180': 'Last 6 months','365': 'Last year',
    'all': 'All time'
  };

  /* ══════════════════════════════════════════════
     PERIOD HELPERS
  ══════════════════════════════════════════════ */
  function toggleVendorCustomRange(show) {
    const from = document.getElementById('vendorCustomRangeFrom');
    const to   = document.getElementById('vendorCustomRangeTo');
    if (from) from.style.display = show ? '' : 'none';
    if (to)   to.style.display   = show ? '' : 'none';
    if (!show) {
      vendorCustomFrom = null;
      vendorCustomTo   = null;
      const f = document.getElementById('vendorCustomDateFrom');
      const t = document.getElementById('vendorCustomDateTo');
      if (f) f.value = '';
      if (t) t.value = '';
    }
  }

  function getVendorPeriodLabel() {
    if (vendorFilterPeriod === 'custom') {
      const fmt = d => d ? d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '?';
      return `${fmt(vendorCustomFrom)} → ${fmt(vendorCustomTo)}`;
    }
    return periodLabels[vendorFilterPeriod] || vendorFilterPeriod;
  }

  /* ══════════════════════════════════════════════
     INIT
  ══════════════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', () => {

    const periodSel = document.getElementById('vendorFilterPeriod');
    if (periodSel) {
      periodSel.value = vendorFilterPeriod;
      periodSel.addEventListener('change', () => {
        vendorFilterPeriod = periodSel.value;
        toggleVendorCustomRange(vendorFilterPeriod === 'custom');
        applyVendorFilters();
      });
    }

    document.getElementById('vendorCustomDateFrom')?.addEventListener('change', e => {
      vendorCustomFrom = e.target.value ? new Date(e.target.value + 'T00:00:00') : null;
      applyVendorFilters();
    });

    document.getElementById('vendorCustomDateTo')?.addEventListener('change', e => {
      vendorCustomTo = e.target.value ? new Date(e.target.value + 'T23:59:59') : null;
      applyVendorFilters();
    });

    const nameSel = document.getElementById('vendorFilterName');
    if (nameSel) {
      nameSel.addEventListener('change', () => {
        vendorFilterByName = nameSel.value;
        applyVendorFilters();
      });
    }

    document.getElementById('vendorFilterResetBtn')?.addEventListener('click', () => {
      vendorFilterPeriod = '30';
      vendorFilterByName = 'all';
      vendorSearchQuery  = '';
      vendorCustomFrom   = null;
      vendorCustomTo     = null;
      if (periodSel) periodSel.value = '30';
      if (nameSel)   nameSel.value   = 'all';
      const s  = document.getElementById('vendorSearch');
      const sf = document.getElementById('vendorCustomDateFrom');
      const st = document.getElementById('vendorCustomDateTo');
      if (s)  s.value  = '';
      if (sf) sf.value = '';
      if (st) st.value = '';
      toggleVendorCustomRange(false);
      applyVendorFilters();
    });

    document.getElementById('vendorSearch')?.addEventListener('input', e => {
      vendorSearchQuery = e.target.value.toLowerCase().trim();
      vendorCurrentPage = 1;
      applyVendorFilters();
    });

    requestAnimationFrame(() => {
      applyVendorFilters();
    });
  });

  /* ══════════════════════════════════════════════
     APPLY ALL FILTERS
  ══════════════════════════════════════════════ */
  function applyVendorFilters() {
    vendorCurrentPage = 1;
    vendorActiveRows  = getVendorRows();
    renderVendorChart();
    renderVendorPage();
    updateVendorSummary();
    updateVendorFilterPill();
    renderVendorChartSubtitle();
  }

  function updateVendorFilterPill() {
    const el = document.getElementById('vendorActiveFilterSummary');
    if (!el) return;
    const parts = [];
    parts.push(`📅 ${getVendorPeriodLabel()}`);
    if (vendorFilterByName !== 'all') parts.push(`Vendor: <strong>${vendorFilterByName}</strong>`);
    if (vendorSearchQuery)             parts.push(`Search: <strong>"${vendorSearchQuery}"</strong>`);
    el.innerHTML = parts.map(p => `<span class="filter-pill">${p}</span>`).join('');
    el.style.display = 'flex';
  }

  function renderVendorChartSubtitle() {
    const el = document.getElementById('vendorChartSubtitle');
    if (!el) return;
    const filtered = vendorFilterByName !== 'all'
      ? ALL_VENDORS.filter(v => v.company_name === vendorFilterByName)
      : ALL_VENDORS;
    el.textContent = `${filtered.length} vendor${filtered.length !== 1 ? 's' : ''} — ${getVendorPeriodLabel()}`;
  }

  /* ══════════════════════════════════════════════
     ROW FILTERING
  ══════════════════════════════════════════════ */
  function getAllVendorRows() {
    return Array.from(document.querySelectorAll('#vendorTableBody tr[data-name]'));
  }

  function getVendorRows() {
    return getAllVendorRows().filter(r => {
      if (vendorFilterByName !== 'all' && r.dataset.name !== vendorFilterByName) return false;

      if (vendorSearchQuery) {
        const hay = [r.dataset.name, r.dataset.pic].join(' ').toLowerCase();
        if (!hay.includes(vendorSearchQuery)) return false;
      }
      return true;
    });
  }

  /* ══════════════════════════════════════════════
     CHART — line, mirrors staff chart
  ══════════════════════════════════════════════ */
  function renderVendorChart() {
    const ctx = document.getElementById('vendorChart');
    if (!ctx) return;

    const data = vendorFilterByName !== 'all'
      ? ALL_VENDORS.filter(v => v.company_name === vendorFilterByName)
      : ALL_VENDORS;

    if (!data.length) {
      if (vendorChartInst) vendorChartInst.destroy();
      vendorChartInst = null;
      return;
    }

    const names    = data.map(v => v.company_name);
    const resolved = data.map(v => v.resolved);

    if (vendorChartInst) vendorChartInst.destroy();

    vendorChartInst = new Chart(ctx.getContext('2d'), {
      type: 'bar',
      data: {
        labels: names,
        datasets: [
          {
            label: 'Resolved (Closed)',
            data: resolved,
            backgroundColor: 'rgba(22,163,74,0.80)',
            borderColor: 'rgba(22,163,74,1)',
            borderWidth: 1.5,
            borderRadius: 6,
            borderSkipped: false,
            maxBarThickness: 80,
            barThickness: data.length === 1 ? 80 : undefined,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 16, bottom: 8, left: 8, right: 16 } },
        plugins: {
          legend: {
            position: 'top', align: 'end',
            labels: { font: { size: 12, weight: '600' }, padding: 20, usePointStyle: true, pointStyle: 'circle' }
          },
          tooltip: {
            backgroundColor: 'rgba(15,23,42,.96)',
            titleColor: '#fff', bodyColor: 'rgba(255,255,255,.85)',
            padding: 14, cornerRadius: 12, displayColors: true, boxPadding: 6,
            callbacks: {
              title: items => data[items[0].dataIndex]?.company_name || items[0].label,
              label: item  => {
                const d = data[item.dataIndex];
                if (!d) return `  Resolved: ${item.parsed.y}`;
                const rate = d.tickets_handled > 0
                  ? Math.round(d.resolved / d.tickets_handled * 100) : 0;
                let rr = '—';
                if (d.avg_respond_h !== null && d.avg_respond_h !== undefined) {
                  const h = Math.floor(d.avg_respond_h);
                  const m = Math.round((d.avg_respond_h - h) * 60);
                  rr = d.avg_respond_h < 1
                    ? (Math.round(d.avg_respond_h * 60) === 0 ? '< 1m' : Math.round(d.avg_respond_h * 60) + 'm')
                    : (m > 0 ? h + 'h ' + m + 'm' : h + 'h');
                }
                return [
                  `  Resolved: ${item.parsed.y}`,
                  `  Tickets Assigned: ${d.tickets_handled}`,
                  `  Resolution Rate: ${rate}%`,
                  `  Avg Respond Time: ${rr}`
                ];
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            border: { display: false },
            ticks: { font: { size: 11, weight: '600' }, color: '#334155', autoSkip: false, maxRotation: 35, minRotation: 0 }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(226,232,240,.55)', drawBorder: false },
            border: { display: false },
            ticks: { precision: 0, stepSize: 1, font: { size: 11 }, color: '#94a3b8', padding: 6 }
          }
        }
      }
    });

    const totalResolved = data.reduce((s, d) => s + d.resolved, 0);
    const totalHandled  = data.reduce((s, d) => s + d.tickets_handled, 0);
    const setK = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    setK('vkpi-vendors',  data.length);
    setK('vkpi-resolved', totalResolved);
    setK('vkpi-actions',  totalHandled);

    const totalRespondMins    = data.reduce((s, d) => s + ((d.avg_respond_h || 0) * (d.responded_count || 0)), 0);
    const totalRespondedCount = data.reduce((s, d) => s + (d.responded_count || 0), 0);
    const avgRespondH = totalRespondedCount > 0
      ? Math.round(totalRespondMins / totalRespondedCount * 10) / 10
      : null;
    let avgRespondFmt = '—';
    if (avgRespondH !== null) {
      if (avgRespondH < 1) {
        const mins = Math.round(avgRespondH * 60);
        avgRespondFmt = mins === 0 ? '< 1m' : mins + 'm';
      } else {
        const h = Math.floor(avgRespondH);
        const m = Math.round((avgRespondH - h) * 60);
        avgRespondFmt = m > 0 ? h + 'h ' + m + 'm' : h + 'h';
      }
    }
    setK('vkpi-avg-respond', avgRespondFmt);
  }

  /* ══════════════════════════════════════════════
     PAGINATION
  ══════════════════════════════════════════════ */
  function renderVendorPage() {
    const rows  = vendorActiveRows;
    const total = rows.length;
    const pages = Math.max(1, Math.ceil(total / VENDOR_PER_PAGE));
    vendorCurrentPage = Math.min(vendorCurrentPage, pages);
    const s = (vendorCurrentPage - 1) * VENDOR_PER_PAGE;
    const e = s + VENDOR_PER_PAGE;

    getAllVendorRows().forEach(r => r.style.display = 'none');
    rows.forEach((r, i) => { r.style.display = (i >= s && i < e) ? '' : 'none'; });

    const pi = document.getElementById('vendorPageInfo');
    if (pi) pi.textContent = total
      ? `Showing ${s + 1}–${Math.min(e, total)} of ${total} vendors`
      : 'No vendors match your filters';

    const pb = document.getElementById('vendorPageBtns');
    if (!pb) return;
    pb.innerHTML = '';
    pb.appendChild(mkBtn('‹', vendorCurrentPage === 1, () => { vendorCurrentPage--; renderVendorPage(); }));
    let sp = Math.max(1, vendorCurrentPage - 2), ep = Math.min(pages, sp + 4);
    if (ep - sp < 4) sp = Math.max(1, ep - 4);
    for (let p = sp; p <= ep; p++) {
      const b = mkBtn(p, false, () => { vendorCurrentPage = p; renderVendorPage(); });
      if (p === vendorCurrentPage) b.classList.add('active');
      pb.appendChild(b);
    }
    pb.appendChild(mkBtn('›', vendorCurrentPage === pages, () => { vendorCurrentPage++; renderVendorPage(); }));
  }

  function mkBtn(label, disabled, fn) {
    const b = document.createElement('button');
    b.className = 'page-btn'; b.textContent = label; b.disabled = disabled;
    b.addEventListener('click', fn); return b;
  }

  function updateVendorSummary() {
    const rows = vendorActiveRows.length ? vendorActiveRows : getAllVendorRows();
    let totalHandled = 0, totalResolved = 0, totalInProg = 0, totalOpen = 0;
    rows.forEach(r => {
      totalHandled  += parseInt(r.dataset.handled  || 0, 10);
      totalResolved += parseInt(r.dataset.resolved || 0, 10);
      totalInProg   += parseInt(r.dataset.inprog   || 0, 10);
      totalOpen     += parseInt(r.dataset.open     || 0, 10);
    });
    const el = id => document.getElementById(id);
    if (el('ss-vendor-total'))    el('ss-vendor-total').textContent    = rows.length;
    if (el('ss-vendor-actions'))  el('ss-vendor-actions').textContent  = totalHandled;
    if (el('ss-vendor-resolved')) el('ss-vendor-resolved').textContent = totalResolved;
    if (el('ss-vendor-inprog'))   el('ss-vendor-inprog').textContent   = totalInProg;
    if (el('ss-vendor-open'))     el('ss-vendor-open').textContent     = totalOpen;
  }

  /* ══════════════════════════════════════════════
     SORT
  ══════════════════════════════════════════════ */
  window.sortVendorTable = function (col) {
    const keys = ['rank', 'name', 'pic', 'status', 'handled', 'resolved', 'inprog', 'avgrespond'];
    const key  = keys[col];
    vendorSortDir[key] = !vendorSortDir[key];

    vendorActiveRows.sort((a, b) => {
      const aV = a.dataset[key] || '';
      const bV = b.dataset[key] || '';
      const numA = parseFloat(aV), numB = parseFloat(bV);
      if (!isNaN(numA) && !isNaN(numB))
        return vendorSortDir[key] ? numA - numB : numB - numA;
      return vendorSortDir[key]
        ? aV.localeCompare(bV, undefined, { numeric: true })
        : bV.localeCompare(aV, undefined, { numeric: true });
    });

    const tbody = document.getElementById('vendorTableBody');
    vendorActiveRows.forEach(r => tbody.appendChild(r));

    document.querySelectorAll('#vendorTable thead th').forEach((th, i) => {
      th.classList.toggle('sorted', i === col);
      const ic = th.querySelector('.sort-icon');
      if (ic) ic.textContent = i === col ? (vendorSortDir[key] ? '↑' : '↓') : '⇅';
    });

    vendorCurrentPage = 1;
    renderVendorPage();
  };

  /* ══════════════════════════════════════════════
     DOWNLOAD CSV
  ══════════════════════════════════════════════ */
  window.vendorDownloadCSV = function () {
    const rows  = vendorActiveRows.length ? vendorActiveRows : getAllVendorRows();
    const label = getVendorPeriodLabel().replace(/\s+/g, '-').replace(/[→]/g, 'to').toLowerCase();
    const lines = [['No','Company Name','PIC Name','Account Status','Tickets Assigned','Closed','In Progress','Open','Avg Respond Time'].join(',')];
    rows.forEach(r => {
      lines.push([
        r.dataset.rank,
        `"${(r.dataset.name || '').replace(/"/g,'""')}"`,
        `"${(r.dataset.pic  || '').replace(/"/g,'""')}"`,
        r.dataset.status || '—',
        r.dataset.handled,
        r.dataset.resolved,
        r.dataset.inprog,
        r.dataset.open,
        r.dataset.avgrespondFmt || '—',
      ].join(','));
    });
    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    Object.assign(document.createElement('a'), {
      href: URL.createObjectURL(blob),
      download: `vendor-activity-${label}.csv`
    }).click();
  };

  /* ══════════════════════════════════════════════
     DOWNLOAD PDF
  ══════════════════════════════════════════════ */
  window.vendorDownloadPDF = async function () {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const label = getVendorPeriodLabel();

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
    doc.text('UniKL Help Desk — IT Department Vendor Activity Report', 36, 10);
    doc.setFontSize(8); doc.setFont('helvetica', 'normal');
    doc.text('Generated: ' + new Date().toLocaleString(), 36, 16);

    doc.setTextColor(87, 68, 118); doc.setFontSize(8); doc.setFont('helvetica', 'bold');
    const filterLine = [
      `Period: ${label}`,
      vendorFilterByName !== 'all' ? `Vendor: ${vendorFilterByName}` : null,
      vendorSearchQuery  ? `Search: "${vendorSearchQuery}"` : null,
    ].filter(Boolean).join('   |   ');
    doc.text(filterLine, 14, 28);

    const rows = vendorActiveRows;
    let totalHandled = 0, totalResolved = 0, totalInProg = 0, totalOpen = 0;
    rows.forEach(r => {
      totalHandled  += parseInt(r.dataset.handled  || 0, 10);
      totalResolved += parseInt(r.dataset.resolved || 0, 10);
      totalInProg   += parseInt(r.dataset.inprog   || 0, 10);
      totalOpen     += parseInt(r.dataset.open     || 0, 10);
    });
    doc.setTextColor(30, 41, 59); doc.setFontSize(8.5);
    doc.text(
      `Vendors: ${rows.length}  |  Assigned: ${totalHandled}  |  Closed: ${totalResolved}  |  In Progress: ${totalInProg}  |  Open: ${totalOpen}`,
      14, 36
    );

    doc.autoTable({
      startY: 42,
      margin: { left: 14, right: 14 },
      tableWidth: 'auto',
      head: [['No','Company Name','PIC Name','Account Status','Assigned','Closed','In Progress','Open','Avg Respond Time']],
      body: rows.map(r => [
        r.dataset.rank, r.dataset.name, r.dataset.pic,
        r.dataset.status || '—',
        r.dataset.handled, r.dataset.resolved, r.dataset.inprog, r.dataset.open,
        r.dataset.avgrespondFmt || '—'
      ]),
      styles: { fontSize: 7.5, cellPadding: 2.5, overflow: 'linebreak' },
      headStyles: { fillColor: [87, 68, 118], textColor: 255, fontStyle: 'bold', fontSize: 8 },
      alternateRowStyles: { fillColor: [248, 247, 252] },
      columnStyles: {
        0: { cellWidth: 10 },
        1: { cellWidth: 55 },
        2: { cellWidth: 40 },
        3: { cellWidth: 25 },
        4: { cellWidth: 22 },
        5: { cellWidth: 22 },
        6: { cellWidth: 25 },
        7: { cellWidth: 18 },
        8: { cellWidth: 38 },
      },
      didParseCell: data => {
        if (data.section === 'body') {
          if (data.column.index === 4) { data.cell.styles.textColor = [99,102,241];  data.cell.styles.fontStyle = 'bold'; }
          if (data.column.index === 5) { data.cell.styles.textColor = [22,163,74];   data.cell.styles.fontStyle = 'bold'; }
          if (data.column.index === 6) { data.cell.styles.textColor = [99,102,241]; }
          if (data.column.index === 7) { data.cell.styles.textColor = [245,158,11]; }
          
          if (data.column.index === 8) {
            const h = parseFloat(data.cell.raw);
            if (!isNaN(h)) {
              data.cell.styles.textColor = h <= 2 ? [22,163,74] : (h <= 6 ? [249,115,22] : [220,38,38]);
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
      doc.text(`Page ${i} of ${pc} — UniKL Help Desk IT Dept`, 14, 200);
    }

    doc.save(`vendor-activity-${label.replace(/[\s→]+/g, '-').toLowerCase()}.pdf`);
  };

})();