<!-- dept_admin/ccu/_head_assets.php -->  
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --blue:        #6B5A9E;
      --blue-dark:   #5A4A8A;
      --blue-light:  #EDE9FE;
      --blue-mid:    #C8B8E8;
      --gold:        #D4A017;
      --white:       #FFFFFF;
      --off-white:   #F7F9FC;
      --gray-100:    #EEF1F7;
      --gray-200:    #DDE2EE;
      --gray-300:    #CDD3DF;
      --gray-500:    #7A8499;
      --gray-700:    #4A3B6B;
      --gray-900:    #271F40;;
      --text:        #111827;
      --error:       #C0392B;
      --error-bg:    #FDECEA;
      --sidebar-w:   240px;
    }
    html { scroll-behavior: smooth; }
    body { font-family: 'DM Sans', sans-serif; color: var(--text); background: var(--off-white); display: flex; min-height: 100vh; }

    /* ── SIDEBAR ──────────────────────────────────────────────────── */
    .sidebar {
      position: fixed; top: 0; left: 0; bottom: 0; width: var(--sidebar-w);
      background: linear-gradient(180deg, #4A3B6B 0%, #362B55 50%, #271F40 100%);
      z-index: 50; overflow-y: auto;
      display: flex;
      flex-direction: column;
    }
    .sidebar-brand {
      padding: 26px 20px 22px;
      border-bottom: 1px solid rgba(255,255,255,.07);
      display: flex; align-items: center; gap: 12px;
      text-decoration: none;
    }
    .brand-logo {
      background: #6B5A9E;
      width: 36px; height: 36px; background: var(--blue); border-radius: 7px;
      display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 700; color: white; letter-spacing: -.5px; flex-shrink: 0;
    }
    .brand-logo em { color: #F5C44B; font-style: normal; }
    .brand-text .t1 { font-size: 10px; color: rgba(255,255,255,.4); letter-spacing: .06em; text-transform: uppercase; }
    .brand-text .t2 { font-size: 12px; font-weight: 600; color: rgba(255,255,255,.85); }

    .sidebar-dept {
      padding: 14px 20px; border-bottom: 1px solid rgba(255,255,255,.07);
    }
    .dept-chip {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(107,90,158,.3); border-color: rgba(200,190,230,.2); color: #C8B8E8;
      padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600;
      color: var(--blue-mid); letter-spacing: .04em; text-transform: uppercase;
    }

    .sidebar-nav { flex: 1; padding: 12px 10px; display: flex; flex-direction: column; gap: 2px; }
    .nav-section-label {
      font-size: 9px; font-weight: 600; color: rgba(255,255,255,.25);
      letter-spacing: .1em; text-transform: uppercase; padding: 10px 10px 4px;
    }
    .nav-item {
      display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px;
      font-size: 13px; font-weight: 500; color: rgba(255,255,255,.55);
      text-decoration: none; transition: background .15s, color .15s; position: relative;
    }
    .nav-item:hover { background: rgba(255,255,255,.06); color: rgba(255,255,255,.85); }
    .nav-item.active { background: rgba(107,90,158,.4); }
    .nav-icon svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.8; display: block; flex-shrink: 0; }
    .nav-pip { position: absolute; right: 10px; width: 6px; height: 6px; border-radius: 50%; background: #B8A8D8; }

    .sidebar-footer {
      padding: 14px 16px; border-top: 1px solid rgba(255,255,255,.07);
    }
    .user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .user-av {
      width: 32px; height: 32px; border-radius: 50%; background: rgba(107,90,158,.45); color: #C8B8E8;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700; color: var(--blue-mid); flex-shrink: 0;
    }
    .user-n  { font-size: 12px; font-weight: 600; color: rgba(255,255,255,.85); }
    .user-role { font-size: 10px; color: rgba(255,255,255,.35); }
    .btn-logout {
      display: flex; align-items: center; gap: 6px; width: 100%;
      padding: 8px 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,.1);
      background: transparent; color: rgba(255,255,255,.4); font-size: 12px; font-weight: 500;
      cursor: pointer; text-decoration: none; transition: all .15s; font-family: inherit;
    }
    .btn-logout:hover { background: rgba(255,0,0,.1); color: #ff8080; border-color: rgba(255,100,100,.2); }
    .btn-logout svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; }

    /* ── MAIN ──────────────────────────────────────────────────────── */
    .main-content { margin-left: var(--sidebar-w); flex: 1; padding: 32px 36px; max-width: calc(100vw - var(--sidebar-w)); }

    /* ── PAGE HEADER ──────────────────────────────────────────────── */
    .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
    .page-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--blue); margin-bottom: 4px; }
    .page-title { font-family: 'DM Serif Display', serif; font-size: 30px; color: var(--gray-900); display: flex; align-items: center; gap: 10px; }
    .title-count { font-family: 'DM Sans', sans-serif; font-size: 16px; font-weight: 600; background: var(--blue-light); color: var(--blue); padding: 2px 10px; border-radius: 100px; }

    .header-actions { display: flex; gap: 8px; align-items: center; }
    .view-only-badge {
      display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 100px;
      background: var(--blue-light); color: var(--blue); font-size: 12px; font-weight: 600;
      border: 1px solid rgba(0,59,142,.15);
    }
    .view-only-badge svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; }

    /* ── STAT CARDS ───────────────────────────────────────────────── */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
    .stat-card { background: white; border: 1px solid var(--gray-200); border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 14px; }
    .stat-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .stat-icon svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; }
    .stat-icon.blue   { background: #EFF6FF; color: #1D4ED8; }
    .stat-icon.amber  { background: #FFFBEB; color: #D97706; }
    .stat-icon.green  { background: #ECFDF5; color: #059669; }
    .stat-icon.red    { background: #FEF2F2; color: #DC2626; }
    .stat-icon.teal   { background: #F0FDFA; color: #0D9488; }
    .stat-icon.indigo { background: #EEF2FF; color: #4338CA; }
    .stat-val   { font-size: 24px; font-weight: 700; color: var(--gray-900); line-height: 1; }
    .stat-label { font-size: 11px; color: var(--gray-500); margin-top: 3px; font-weight: 500; }

    /* ── CARDS ────────────────────────────────────────────────────── */
    .card { background: white; border: 1px solid var(--gray-200); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
    .card.no-pad { padding: 0; overflow: hidden; }
    .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .card-title { font-size: 15px; font-weight: 600; color: var(--gray-900); }
    .card-link { font-size: 12px; color: var(--blue); text-decoration: none; font-weight: 500; }
    .card-link:hover { text-decoration: underline; }

    .dash-row { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
    .flex-1 { flex: 1; }
    .flex-2 { flex: 2; }

    /* ── TABLE ────────────────────────────────────────────────────── */
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .data-table.full { width: 100%; }
    .data-table thead th { padding: 10px 16px; background: var(--gray-100); border-bottom: 1px solid var(--gray-200); font-size: 11px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--gray-500); text-align: left; white-space: nowrap; }
    .data-table tbody td { padding: 12px 16px; border-bottom: 1px solid var(--gray-100); vertical-align: middle; }
    .data-table tbody tr:last-child td { border-bottom: none; }
    .data-table tbody tr:hover td { background: var(--gray-100); }
    .ticket-row { cursor: default; }
    .empty-row { text-align: center; color: var(--gray-500); padding: 32px !important; }
    .ticket-id { font-size: 11px; font-weight: 600; font-family: monospace; background: var(--gray-100); padding: 2px 7px; border-radius: 4px; color: var(--gray-700); }
    .td-title { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
    .td-cat   { color: var(--gray-500); font-size: 12px; }
    .td-date  { color: var(--gray-500); font-size: 12px; white-space: nowrap; }
    .td-email { color: var(--gray-500); font-size: 12px; }
    .mono     { font-family: monospace; font-size: 12px; }

    /* ── BADGES ───────────────────────────────────────────────────── */
    .badge { display: inline-block; font-size: 10px; font-weight: 700; letter-spacing: .04em; padding: 3px 9px; border-radius: 100px; text-transform: uppercase; }
    .priority-high   { background: #FEF2F2; color: #DC2626; border: 1px solid rgba(220,38,38,.2); }
    .priority-medium { background: #FFFBEB; color: #D97706; border: 1px solid rgba(217,119,6,.2); }
    .priority-low    { background: #ECFDF5; color: #059669; border: 1px solid rgba(5,150,105,.2); }
    .status-open        { background: #EFF6FF; color: #1D4ED8; border: 1px solid rgba(29,78,216,.2); }
    .status-in_progress { background: #FFF7ED; color: #C2410C; border: 1px solid rgba(194,65,12,.2); }
    .status-closed      { background: #F0FDF4; color: #15803D; border: 1px solid rgba(21,128,61,.2); }

    .submitter-badge {
      display: inline-block; font-size: 9px; font-weight: 700;
      letter-spacing: .04em; padding: 2px 6px; border-radius: 3px;
      text-transform: uppercase; flex-shrink: 0;
    }
    .submitter-badge.student { background: #EFF6FF; color: #1D4ED8; }
    .submitter-badge.staff   { background: #F5F3FF; color: #6D28D9; }

    /* ── FILTER BAR ───────────────────────────────────────────────── */
    .filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
    .search-wrap { position: relative; flex: 1; min-width: 200px; }
    .search-wrap svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; fill: none; stroke: var(--gray-500); stroke-width: 2; pointer-events: none; }
    .search-wrap input { width: 100%; padding: 9px 12px 9px 34px; border: 1.5px solid var(--gray-300); border-radius: 8px; font-size: 13px; font-family: inherit; background: white; outline: none; }
    .search-wrap input:focus { border-color: var(--blue); }
    .filter-bar select { padding: 9px 12px; border: 1.5px solid var(--gray-300); border-radius: 8px; font-size: 13px; font-family: inherit; background: white; outline: none; cursor: pointer; }
    .filter-bar select:focus { border-color: var(--blue); }

    /* ── BUTTONS ──────────────────────────────────────────────────── */
    .btn-primary-sm { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; background: var(--blue); color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; font-family: inherit; transition: background .15s; }
    .btn-primary-sm:hover { background: var(--blue-dark); }
    .btn-primary-sm svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; }
    .btn-outline-sm { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border: 1.5px solid var(--gray-300); background: white; color: var(--gray-700); border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; font-family: inherit; transition: border-color .15s; }
    .btn-outline-sm:hover { border-color: var(--blue); color: var(--blue); }
    .btn-outline-sm svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; }
    .btn-ghost-sm { display: inline-flex; align-items: center; padding: 9px 14px; background: transparent; color: var(--gray-500); border: 1.5px solid var(--gray-200); border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; font-family: inherit; }
    .btn-ghost-sm:hover { color: var(--gray-700); border-color: var(--gray-300); }

    /* ── ACTION BUTTONS ───────────────────────────────────────────── */
    .actions-cell { white-space: nowrap; }
    .action-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 6px; border: 1px solid transparent; background: var(--gray-100); cursor: pointer; font-size: 14px; transition: background .15s; margin-right: 3px; }
    .action-btn:hover { filter: brightness(.9); }
    .action-btn.amber { background: #FFFBEB; border-color: rgba(217,119,6,.2); }
    .action-btn.green { background: #ECFDF5; border-color: rgba(5,150,105,.2); }
    .action-btn.blue  { background: #EFF6FF; border-color: rgba(29,78,216,.2); }
    .action-btn.red   { background: #FEF2F2; border-color: rgba(220,38,38,.2); }

    /* ── USER TABLE CELLS ─────────────────────────────────────────── */
    .user-cell { display: flex; align-items: center; gap: 10px; }
    .user-avatar { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
    .user-avatar.staff { background: #EFF6FF; color: #1D4ED8; }
    .user-avatar.admin { background: #FEF9C3; color: #92400E; }
    .user-name { font-weight: 500; font-size: 13px; }
    .user-you  { font-size: 10px; color: var(--blue); font-weight: 600; }
    .role-pill { display: inline-block; font-size: 10px; font-weight: 700; letter-spacing: .04em; padding: 3px 9px; border-radius: 100px; text-transform: uppercase; }
    .role-pill.staff { background: #EFF6FF; color: #1D4ED8; border: 1px solid rgba(29,78,216,.2); }
    .role-pill.admin { background: #FEF9C3; color: #92400E; border: 1px solid rgba(146,64,14,.2); }
    .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; }
    .status-dot.active   { background: #10B981; }
    .status-dot.inactive { background: #9CA3AF; }

    /* ── CAT LIST ─────────────────────────────────────────────────── */
    .cat-list { display: flex; flex-direction: column; gap: 12px; }
    .cat-item {}
    .cat-top { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 5px; }
    .cat-name { font-weight: 500; color: var(--gray-700); }
    .cat-count { color: var(--gray-500); }
    .cat-bar { height: 6px; background: var(--gray-100); border-radius: 3px; overflow: hidden; }
    .cat-fill { height: 100%; border-radius: 3px; transition: width .6s ease; }

    /* ── RATING CARD ──────────────────────────────────────────────── */
    .rating-card { display: flex; align-items: center; gap: 16px; padding: 20px 24px; }
    .rating-icon { font-size: 32px; }
    .rating-val  { font-size: 28px; font-weight: 700; color: var(--gray-900); }
    .rating-label { font-size: 13px; color: var(--gray-500); }

    /* ── PAGINATION ───────────────────────────────────────────────── */
    .pagination { display: flex; align-items: center; gap: 12px; justify-content: center; margin-top: 16px; }
    .pg-btn { padding: 8px 16px; border: 1.5px solid var(--gray-300); border-radius: 7px; font-size: 13px; text-decoration: none; color: var(--gray-700); background: white; }
    .pg-btn:hover { border-color: var(--blue); color: var(--blue); }
    .pg-info { font-size: 13px; color: var(--gray-500); }

    /* ── ALERTS ───────────────────────────────────────────────────── */
    .alert { display: flex; align-items: flex-start; gap: 10px; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
    .alert-error   { background: var(--error-bg); color: var(--error); border: 1px solid rgba(192,57,43,.2); }
    .alert-success { background: #ECFDF5; color: #065F46; border: 1px solid rgba(6,95,70,.2); }

    /* ── MODAL ────────────────────────────────────────────────────── */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 200; align-items: center; justify-content: center; }
    .modal-overlay.open { display: flex; }
    .modal { background: white; border-radius: 14px; width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.2); animation: fadeUp .2s ease; }
    @keyframes fadeUp { from { opacity:0; transform: translateY(12px); } to { opacity:1; transform: translateY(0); } }
    .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px 16px; border-bottom: 1px solid var(--gray-200); }
    .modal-header h3 { font-size: 16px; font-weight: 600; }
    .modal-close { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--gray-500); line-height: 1; padding: 2px 6px; }
    .modal-form { padding: 20px 24px; }
    .modal-footer { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; border-top: 1px solid var(--gray-100); padding-top: 16px; }

    /* ── FORM FIELDS (modal) ──────────────────────────────────────── */
    .field { margin-bottom: 16px; }
    .field label { display: block; font-size: 13px; font-weight: 500; color: var(--gray-700); margin-bottom: 6px; }
    .field input, .field select { width: 100%; padding: 10px 12px; border: 1.5px solid var(--gray-300); border-radius: 8px; font-family: inherit; font-size: 13px; outline: none; }
    .field input:focus, .field select:focus { border-color: var(--blue); }
    .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .req { color: #DC2626; }
    .empty-text { color: var(--gray-500); font-size: 13px; text-align: center; padding: 20px 0; }

    /* ── REPORTS ──────────────────────────────────────────────────── */
    .range-tabs { display: flex; gap: 4px; }
    .range-tab { padding: 7px 14px; border-radius: 7px; font-size: 12px; font-weight: 500; text-decoration: none; color: var(--gray-500); background: white; border: 1.5px solid var(--gray-200); transition: all .15s; }
    .range-tab.active, .range-tab:hover { background: var(--blue); color: white; border-color: var(--blue); }
    .progress-wrap { display: flex; align-items: center; gap: 8px; }
    .progress-bar { height: 6px; background: var(--blue); border-radius: 3px; min-width: 4px; }
    .progress-label { font-size: 12px; color: var(--gray-500); white-space: nowrap; }
    .legend-list { display: flex; flex-direction: column; gap: 6px; margin-top: 14px; }
    .legend-item { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--gray-700); }
    .legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .feedback-summary { display: flex; align-items: center; gap: 24px; }
    .feedback-big { font-size: 48px; font-weight: 700; color: var(--gray-900); line-height: 1; }
    .feedback-big span { font-size: 20px; color: var(--gray-500); font-weight: 400; }
    .feedback-stats { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--gray-700); }

   @media (max-width: 900px) {
    .sidebar { transform: translateX(-100%); transition: transform .25s ease; }
    .sidebar.open { transform: translateX(0); }
    .main-content { margin-left: 0; padding: 60px 12px 20px; max-width: 100vw; }
    .page-header { flex-direction: column; align-items: flex-start; gap: 8px; }
    .page-title  { font-size: 20px; }
    .filter-bar { flex-direction: column; align-items: stretch; }
    .filter-bar select,
    .filter-bar .search-wrap,
    .filter-bar .btn-primary-sm,
    .filter-bar .btn-ghost-sm { width: 100%; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .dash-row { grid-template-columns: 1fr; }
    .card.no-pad { overflow-x: auto; }
    .data-table { min-width: 400px; }
    .data-table thead th:nth-child(3),
    .data-table thead th:nth-child(4),
    .data-table thead th:nth-child(7),
    .data-table tbody td:nth-child(3),
    .data-table tbody td:nth-child(4),
    .data-table tbody td:nth-child(7) { display: none; }
    .data-table thead th:nth-child(4),
    .data-table tbody td:nth-child(4) { display: none; }
    .ticket-id { max-width: 70px; font-size: 10px; }
    .td-title  { max-width: 100px; }
    .modal { max-width: 95vw; margin: 10px; }
    .mob-toggle {
      display: flex; position: fixed; top: 14px; left: 14px; z-index: 100;
      width: 40px; height: 40px; border-radius: 9px; background: #4A3B6B;
      border: none; cursor: pointer; align-items: center; justify-content: center;
      box-shadow: 0 2px 8px rgba(0,0,0,.25);
    }
    .mob-toggle svg { width: 20px; height: 20px; stroke: white; fill: none; stroke-width: 2; stroke-linecap: round; }
    .mob-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 49; }
    .mob-overlay.open { display: block; }
    .section-tabs { gap: .2rem; padding: .35rem; }
    .section-tab  { padding: .4rem .65rem; font-size: .74rem; }
    .filter-panel-row { flex-direction: column; }
    .filter-group     { width: 100%; }
    .filter-select,
    .filter-date-input { width: 100%; }
    .chart-kpi-row { grid-template-columns: repeat(2, 1fr) !important; }
    .chart-card,
    .fb2-chart-card { overflow-x: auto; }
    .chart-canvas-wrap { min-width: 320px; }
    .table-card-header,
    .fb2-table-header { flex-direction: column; align-items: flex-start; gap: 8px; }
    .table-actions,
    .fb2-table-actions { flex-direction: column; width: 100%; }
    .tbl-search,
    .dl-btn { width: 100%; }
    .summary-strip,
    .fb2-summary-strip { flex-wrap: wrap; gap: 6px; }
  }

  @media (min-width: 901px) {
    .mob-toggle  { display: none; }
    .mob-overlay { display: none; }
  }
  </style>