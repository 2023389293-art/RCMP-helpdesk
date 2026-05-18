<?php
// index.php - UniKL Complaint Management System - Public Homepage
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>UniKL RCMP | RUSH — RCMP User Helpdesk</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;1,700&family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --navy:        #1E3A5F;
      --navy-dark:   #142845;
      --navy-mid:    #2B4F7E;
      --navy-light:  #E8EEF6;
      --olive:       #5C6B35;
      --olive-light: #7A8E4A;
      --olive-bg:    #F0F3E8;
      --gold:        #B8860B;
      --gold-light:  #D4A017;
      --gold-bg:     #FBF5E6;
      --page-bg:     #F4F2ED;
      --surface:     #FFFFFF;
      --text-dark:   #1A2332;
      --text-mid:    #3D4F63;
      --text-muted:  #7A8899;
      --border:      #D8D3C8;
      --border-soft: #E5E1D8;
    }

    html, body {
      min-height: 100vh;
      font-family: 'Source Sans 3', sans-serif;
      background: var(--page-bg);
      color: var(--text-dark);
    }

    /* ── GOV BANNER ── */
    .gov-banner {
      background: var(--navy-dark);
      padding: 6px 48px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 2px solid var(--gold);
    }
    .gov-banner-left {
      display: flex; align-items: center; gap: 10px;
      font-size: 11px; color: rgba(255,255,255,0.75);
      letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;
    }
    .gov-banner-left span { color: var(--gold-light); }
    .gov-banner-right { font-size: 10.5px; color: rgba(255,255,255,0.5); letter-spacing: 0.04em; }

    /* ── NAV ── */
    nav {
      background: var(--surface);
      border-bottom: 3px solid var(--navy);
      box-shadow: 0 2px 16px rgba(0,0,0,0.08);
      padding: 0 48px;
      display: flex; align-items: center; justify-content: space-between;
      height: 76px;
      position: sticky; top: 0; z-index: 100;
    }
    .nav-brand { display: flex; align-items: center; gap: 16px; text-decoration: none; }
    .nav-logo img { width: 82px; height: 82px; object-fit: contain; }
    .nav-divider { width: 1px; height: 36px; background: var(--border); }
    .nav-text-group { display: flex; flex-direction: column; }
    .nav-org { font-size: 13px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--olive); }
    .nav-title { font-size: 15px; font-weight: 700; color: var(--navy-dark); letter-spacing: 0.01em; }
    .nav-actions { display: flex; align-items: center; gap: 10px; }

    .btn-ghost {
      font-size: 12.5px; font-weight: 600; letter-spacing: 0.04em;
      padding: 9px 22px; border-radius: 5px;
      border: 1.5px solid var(--border);
      background: transparent; color: var(--text-mid);
      cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 7px;
      transition: all 0.2s;
    }
    .btn-ghost:hover { border-color: var(--navy); color: var(--navy); background: var(--navy-light); }
    .btn-ghost svg { width: 14px; height: 14px; }

    .btn-navy-solid {
      font-size: 12.5px; font-weight: 700; letter-spacing: 0.05em;
      padding: 9px 22px; border-radius: 5px;
      border: none; background: var(--navy); color: #fff;
      cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 7px;
      transition: background 0.2s, transform 0.15s;
      box-shadow: 0 2px 8px rgba(30,58,95,0.3);
    }
    .btn-navy-solid:hover { background: var(--navy-dark); transform: translateY(-1px); }
    .btn-navy-solid svg { width: 14px; height: 14px; }

    /* ── HERO — full-bleed, everything inside ── */
    .hero {
  background: #d8deea;
  position: relative; overflow: hidden;
  padding: 28px 48px 28px;
  min-height: calc(100vh - 76px - 38px);
  display: flex; flex-direction: column; justify-content: center;
}
    .hero::before {
      content: '';
      position: absolute; inset: 0;
      background-image:
        linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
      background-size: 40px 40px;
    }
    .hero::after {
      content: '';
      position: absolute; right: -80px; top: -80px;
      width: 420px; height: 420px; border-radius: 50%;
      background: radial-gradient(circle, rgba(184,134,11,0.13) 0%, transparent 70%);
      pointer-events: none;
    }

    .hero-inner { position: relative; z-index: 2; }

    /* top row: badge + heading + sub + CTAs */
    .hero-top {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 32px 56px;
      align-items: center;
      margin-bottom: 36px;
    }

    .hero-left {}

    .hero-badge {
  display: inline-flex; align-items: center; gap: 8px;
  border: none;
  border-radius: 4px;
  padding: 5px 14px;
  font-size: 13px; font-weight: 700; letter-spacing: 0.12em;
  margin-bottom: 18px;
}
    .hero-badge-dot {
      width: 6px; height: 6px; border-radius: 50%; background: var(--gold-light);
      animation: pulse 2s infinite;
    }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

    .hero-heading {
      font-family: 'Playfair Display', serif;
      font-size: clamp(32px, 4vw, 52px);
      font-weight: 700; color: var(--navy-dark);
      line-height: 1.08; margin-bottom: 14px; letter-spacing: -0.01em;
    }
    .hero-heading .accent { color: var(--gold-light); font-style: italic; }

    .hero-sub {
      font-size: 14.5px; color: var(--text-mid);
      line-height: 1.75; font-weight: 300; max-width: 500px; margin-bottom: 28px;
    }

    .hero-cta-row { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }

    .btn-gold {
      font-size: 13px; font-weight: 700; letter-spacing: 0.06em;
      padding: 12px 28px; border-radius: 5px;
      border: none; background: var(--gold); color: #fff;
      cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
      transition: background 0.2s, transform 0.15s;
      box-shadow: 0 4px 16px rgba(184,134,11,0.4);
    }
    .btn-gold:hover { background: var(--gold-light); transform: translateY(-2px); }
    .btn-gold svg { width: 15px; height: 15px; }

.btn-outline-white {
  font-size: 12.5px; font-weight: 600; letter-spacing: 0.04em;
  padding: 11px 24px; border-radius: 5px;
  border: 1.5px solid var(--navy);
  color: var(--navy);
  background: transparent;
  cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 7px;
  transition: all 0.2s;
}

    .btn-outline-white:hover { border-color: var(--navy-dark); background: var(--navy-light); }
    .btn-outline-white svg { width: 14px; height: 14px; }

    /* hero-qref removed */



    /* ── DIVIDER STRIP inside hero ── */
    .hero-divider {
      height: 1px; background: rgba(30,58,95,0.1);
      margin: 0 0 28px;
    }

    /* ── BOTTOM: notice + departments, side by side ── */
    .hero-bottom {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 28px;
    }

    /* Notice box */
    .notice-box {
  border: none;
  border-radius: 8px;
  padding: 14px 16px;
  display: flex; align-items: flex-start; gap: 12px;
}
    .notice-icon-wrap {
      width: 26px; height: 26px; flex-shrink: 0; border-radius: 50%;
      background: var(--gold);
      display: flex; align-items: center; justify-content: center;
    }
    .notice-icon-wrap svg { width: 12px; height: 12px; fill: white; }
    .notice-text { font-size: 12.5px; color: var(--text-mid); line-height: 1.6; }
    .notice-text strong { color: var(--gold); }
    .notice-text a { color: var(--navy); font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
    .notice-ref { margin-top: 6px; font-size: 10.5px; color: var(--text-muted); letter-spacing: 0.04em; }

    /* Stats row */
    .hero-stats {
      display: flex; align-items: center; gap: 0;
      background: rgba(255,255,255,0.7);
      border: 1px solid rgba(30,58,95,0.12);
      border-radius: 8px; overflow: hidden;
    }
    .hero-stat {
      flex: 1; padding: 14px 16px;
      display: flex; align-items: center; gap: 10px;
      border-right: 1px solid rgba(30,58,95,0.08);
    }
    .hero-stat:last-child { border-right: none; }
    .hero-stat-icon {
      width: 30px; height: 30px; border-radius: 7px;
      background: rgba(30,58,95,0.08);
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .hero-stat-icon svg { width: 13px; height: 13px; stroke: var(--navy-mid); fill: none; stroke-width: 2; }
    .hero-stat-label { font-size: 10px; color: var(--text-muted); font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; }
    .hero-stat-value { color: var(--text-dark); font-weight: 500; }

    /* ── DEPT CARDS (animated) ── */
.dept-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.dept-card {
  background: transparent;
  border: 1.5px solid rgba(30,58,95,0.35);
  border-radius: 12px;
  padding: 14px 16px;
  display: flex; flex-direction: row; align-items: center; gap: 14px;
  transition: border-color 0.25s, box-shadow 0.25s, transform 0.25s;
  animation: fadeUp 0.5s ease both;
  box-shadow: 0 1px 5px rgba(30,58,95,0.05);
}
.dept-card:hover {
  border-color: rgba(43,79,126,0.28);
  box-shadow: 0 5px 24px rgba(30,58,95,0.10);
  transform: translateY(-2px);
}
.dept-card:nth-child(1){animation-delay:.30s}
.dept-card:nth-child(2){animation-delay:.37s}
.dept-card:nth-child(3){animation-delay:.44s}
.dept-card:nth-child(4){animation-delay:.51s}
.dept-card:nth-child(5){animation-delay:.58s}
.dept-card.wide {
  grid-column: 1 / -1;
  flex-direction: row; align-items: center; gap: 14px; padding: 14px 18px;
}
.dept-card.wide .dept-text { flex: 1; }
.dept-icon {
  width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
}
.dept-card.it    .dept-icon { background: rgba(43,79,126,0.08); }
.dept-card.hc    .dept-icon { background: rgba(27,110,73,0.08); }
.dept-card.af    .dept-icon { background: rgba(184,134,11,0.08); }
.dept-card.cc    .dept-icon { background: rgba(80,44,120,0.08); }
.dept-card.maint .dept-icon { background: rgba(175,55,35,0.08); }
.dept-icon svg { width: 24px; height: 24px; overflow: visible; }
.dept-name { font-size: 13px; font-weight: 700; color: var(--navy-dark); line-height: 1.3; }
.dept-tag  { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* ── ICON ANIMATIONS ── */
.ico-it .frame { stroke-dasharray:72; stroke-dashoffset:72; animation:draw-path 1s ease forwards 0.3s; }
.ico-it .screen-line  { stroke-dasharray:14; stroke-dashoffset:14; animation:draw-path 0.8s ease forwards 1.1s; }
.ico-it .screen-line2 { stroke-dasharray:9;  stroke-dashoffset:9;  animation:draw-path 0.6s ease forwards 1.7s; }
.ico-it .cursor { opacity:0; animation:cursor-blink 1.05s step-start infinite 2.2s; }
.ico-it .stand  { stroke-dasharray:24; stroke-dashoffset:24; animation:draw-path 0.6s ease forwards 0.8s; }
@keyframes cursor-blink { 0%,49%{opacity:1} 50%,100%{opacity:0} }

.ico-hc .body-path { stroke-dasharray:56; stroke-dashoffset:56; animation:draw-path 1s ease forwards 0.4s; }
.ico-hc .head      { stroke-dasharray:28; stroke-dashoffset:28; animation:draw-path 0.7s ease forwards 0.3s; }
.ico-hc .orbit-ring { stroke-dasharray:5 6; transform-origin:12px 10px; animation:spin-ring 8s linear infinite 1.2s; opacity:0; animation-fill-mode:forwards; }
.ico-hc .orbit-dot  { transform-origin:12px 10px; animation:orbit-dot-spin 8s linear infinite 1.2s; opacity:0; animation-fill-mode:forwards; }
@keyframes spin-ring      { 0%{opacity:0;transform:rotate(0deg)} 5%{opacity:1} 95%{opacity:1} 100%{opacity:0;transform:rotate(360deg)} }
@keyframes orbit-dot-spin { 0%{opacity:0;transform:rotate(0deg)} 5%{opacity:1} 100%{opacity:1;transform:rotate(360deg)} }

.ico-af .walls { stroke-dasharray:90; stroke-dashoffset:90; animation:draw-path 1.2s ease forwards 0.2s; }
.ico-af .w1    { animation:win-on 0s ease forwards 1.4s; opacity:0; }
.ico-af .w2    { animation:win-on 0s ease forwards 1.8s; opacity:0; }
.ico-af .door  { animation:win-on 0s ease forwards 2.2s; opacity:0; }
.ico-af .w1-pulse { animation:win-on 0s forwards 1.4s, win-pulse 3s ease-in-out infinite 1.6s; opacity:0; }
.ico-af .w2-pulse { animation:win-on 0s forwards 1.8s, win-pulse 3s ease-in-out infinite 2.2s; opacity:0; }
@keyframes win-on    { to{opacity:1} }
@keyframes win-pulse { 0%,100%{opacity:1} 50%{opacity:0.25} }

.ico-cc .bubble { stroke-dasharray:68; stroke-dashoffset:68; animation:draw-path 1s ease forwards 0.3s; }
.ico-cc .d1 { animation:type-dot 1.6s ease-in-out infinite 1.2s;  opacity:0; }
.ico-cc .d2 { animation:type-dot 1.6s ease-in-out infinite 1.45s; opacity:0; }
.ico-cc .d3 { animation:type-dot 1.6s ease-in-out infinite 1.7s;  opacity:0; }
@keyframes type-dot { 0%{opacity:0;transform:translateY(0)} 15%{opacity:1;transform:translateY(-2.5px)} 30%{opacity:.5;transform:translateY(0)} 100%{opacity:0;transform:translateY(0)} }

.ico-maint .gear-group  { transform-origin:16.5px 7.5px; animation:gear-spin 10s linear infinite 0.8s; }
.ico-maint .wrench-path { stroke-dasharray:52; stroke-dashoffset:52; animation:draw-path 1s ease forwards 0.2s; }
.ico-maint .wrench-group { transform-origin:9px 15px; animation:wrench-rock 5s ease-in-out infinite 1.3s; }
@keyframes gear-spin   { to{transform:rotate(360deg)} }
@keyframes wrench-rock { 0%,100%{transform:rotate(0deg)} 25%{transform:rotate(-14deg)} 75%{transform:rotate(10deg)} }

@keyframes draw-path { to{stroke-dashoffset:0} }

    /* ── FOOTER ── */
    footer {
      background: var(--navy-dark);
      padding: 0 48px; height: 52px;
      display: flex; align-items: center; justify-content: space-between;
      border-top: 2px solid var(--gold);
    }
    .footer-chips { display: flex; gap: 6px; }
    .fchip {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 12px; border-radius: 100px;
      background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.10);
      font-size: 11px; color: rgba(255,255,255,0.55);
    }
    .fchip strong { color: rgba(255,255,255,0.9); }
    .footer-right { font-size: 10.5px; color: rgba(255,255,255,0.28); letter-spacing: 0.04em; }

    /* ── ANIMATIONS ── */
    @keyframes fadeUp {
      from { opacity:0; transform:translateY(16px); }
      to   { opacity:1; transform:translateY(0); }
    }
    .fade-up { animation: fadeUp 0.55s ease both; }
    .d1{animation-delay:.05s} .d2{animation-delay:.12s}
    .d3{animation-delay:.20s} .d4{animation-delay:.28s}
    .d5{animation-delay:.36s} .d6{animation-delay:.44s}

    /* ── RESPONSIVE ── */
    @media (max-width: 1100px) {
      .dept-cards { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 860px) {
.gov-banner { display: none; }
nav { padding: 0 14px; height: 60px; }
.nav-logo img { width: 44px; height: 44px; }
.nav-org { font-size: 10px; }
.nav-title { font-size: 12px; }
.nav-divider { display: none; }
      .btn-ghost { 
  font-size: 11px; 
  padding: 7px 12px; 
  gap: 5px;
}
.btn-ghost svg { width: 12px; height: 12px; }
      .btn-navy-solid { font-size: 11px; padding: 7px 10px; gap: 5px; }
.btn-navy-solid svg { width: 12px; height: 12px; }
.nav-actions { gap: 6px; }
      .hero {
        padding: 28px 16px 32px;
        min-height: auto;
        justify-content: flex-start;
      }
      .hero-top { grid-template-columns: 1fr; gap: 24px; margin-bottom: 24px; }
      .hero-top .fade-up.d2 { padding-top: 0 !important; }
      .hero-bottom { grid-template-columns: 1fr; gap: 16px; }
.hero-heading { font-size: clamp(26px, 7vw, 34px); }
.hero-sub { font-size: 13px; margin-bottom: 18px; }
.hero-badge { font-size: 10px; padding: 4px 10px; }
      .dept-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
      .dept-card { padding: 10px 12px; gap: 10px; }
      .dept-icon { width: 38px; height: 38px; border-radius: 10px; }
      .dept-name { font-size: 12px; }
      .dept-tag { font-size: 10px; }
      .hero-stats { flex-direction: column; }
      .hero-stat { border-right: none; border-bottom: 1px solid rgba(30,58,95,0.08); }
      .hero-stat:last-child { border-bottom: none; }
      footer { padding: 0 16px; height: auto; min-height: 52px; flex-direction: column; gap: 8px; padding-top: 10px; padding-bottom: 10px; }
      .footer-chips { flex-wrap: wrap; gap: 4px; }
      .footer-right { font-size: 10px; }
    }
    @media (max-width: 480px) {
      .dept-grid { grid-template-columns: 1fr; }
      .dept-card.wide { grid-column: auto; }
      .hero-badge { font-size: 11px; }
      .btn-gold { font-size: 12px; padding: 10px 20px; }
    }
    .hero-eyebrow-label {
  font-size: 11px; font-weight: 600;
  letter-spacing: 0.15em; text-transform: uppercase;
  color: var(--gold); margin-bottom: 16px;
  display: flex; align-items: center; gap: 10px;
}
.hero-eyebrow-label::before {
  content: ''; display: block; width: 24px; height: 1px;
  background: var(--gold); opacity: 0.5;
}

.hero-wordmark {
  display: flex;
  align-items: center;
  gap: 0px;
  margin-bottom: 6px;
}

.rush-logo-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
  height: clamp(72px, 10vw, 110px);
}

.rush-logo-wrap img {
  height: clamp(72px, 10vw, 110px);
  width: auto;
  object-fit: contain;
  filter: sepia(1) saturate(3) hue-rotate(5deg) brightness(0.7);
  margin-right: -18px;
  transform-origin: center bottom;
  animation: runBob 0.4s ease-in-out infinite alternate,
             runLean 0.8s ease-in-out infinite alternate;
}

@keyframes runBob {
  from { transform: translateY(0px); }
  to   { transform: translateY(-6px); }
}

@keyframes runLean {
  from { transform: rotate(-5deg); }
  to   { transform: rotate(0deg); }
}

.rush-speed-lines { display: none; }

.ush-text {
  font-family: 'Playfair Display', serif;
  font-size: clamp(64px, 10vw, 100px);
  font-weight: 700; line-height: 1;
  color: var(--navy-dark);
  font-style: italic;
  letter-spacing: -0.02em;
}

.hero-sub-title {
  font-size: clamp(11px, 1.5vw, 13px);
  font-weight: 400; color: var(--text-muted);
  letter-spacing: 0.28em; text-transform: uppercase;
  margin-bottom: 12px;
}


  </style>
</head>
<body>

  <!-- ── GOV BANNER ── -->
  <div class="gov-banner">
    <div class="gov-banner-left">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      <span>UniKL Royal College of Medicine Perak</span>
      <span style="color:rgba(255,255,255,0.25)">·</span>
      <span>Official Internal Portal</span>
    </div>
    <div class="gov-banner-right">Doc Ref: UniKL/RCMP/CD/ITD-01-01</div>
  </div>

  <!-- ── NAV ── -->
  <nav>
    <a class="nav-brand" href="index.php">
      <div class="nav-logo"><img src="img/RCMP.png" alt="UniKL RCMP Logo" /></div>
      <div class="nav-divider"></div>
      <div class="nav-text-group">
<span class="nav-org">UniKL RCMP</span>
<span class="nav-title">RUSH — RCMP User Helpdesk</span>
      </div>
    </a>
    <div class="nav-actions">
      <a href="login.php" class="btn-ghost">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Login / Submit Ticket
      </a>
      <a href="staff_login.php" class="btn-navy-solid">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Admin Login
      </a>
    </div>
  </nav>

  <!-- ── HERO (single unified panel) ── -->
  <div class="hero">
    <div class="hero-inner">

      <!-- Top: heading/sub/CTAs  |  Quick Reference -->
      <div class="hero-top">
        <div class="hero-left fade-up d1">
          
<div class="hero-eyebrow-label">Official Helpdesk Portal</div>

          <div class="hero-wordmark">
            <div class="rush-logo-wrap">
              <div class="rush-speed-lines">
                <span></span><span></span><span></span>
              </div>
              <img src="img/Rush.png" alt="R" />
            </div>
            <span class="ush-text">USH</span>
          </div>

          <p class="hero-sub-title">RCMP &mdash; User Helpdesk</p>

          <p class="hero-sub">
            RUSH is UniKL RCMP's official helpdesk portal. Submit, track, and manage service requests across all departments — every ticket is logged, assigned, and resolved with full transparency.
          </p>
          <div class="hero-cta-row">
            <a href="login.php" class="btn-gold">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
              Submit a Ticket
            </a>
          </div>
        </div>

        <!-- Departments panel (right side) — animated cards -->
        <div class="fade-up d2" style="padding-top: 60px;">
          <div class="dept-grid">

            <!-- IT -->
            <div class="dept-card it">
              <div class="dept-icon">
                <svg class="ico-it" viewBox="0 0 24 24" fill="none" stroke="#2B4F7E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <rect class="frame" x="2" y="3" width="20" height="14" rx="2" fill="rgba(43,79,126,0.05)"/>
                  <line class="stand" x1="8" y1="21" x2="16" y2="21"/>
                  <line class="stand" x1="12" y1="17" x2="12" y2="21"/>
                  <line class="screen-line"  x1="5"  y1="9"  x2="16" y2="9"/>
                  <line class="screen-line2" x1="5"  y1="12" x2="12" y2="12"/>
                  <line class="cursor" x1="13" y1="12" x2="14.5" y2="12" stroke-width="2.2"/>
                </svg>
              </div>
              <div>
                <div class="dept-name">Information Technology</div>
                <div class="dept-tag">IT Support &amp; Systems</div>
              </div>
            </div>

            <!-- HC -->
            <div class="dept-card hc">
              <div class="dept-icon">
                <svg class="ico-hc" viewBox="0 0 24 24" fill="none" stroke="#1B6E49" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <circle class="head" cx="12" cy="8" r="3.5"/>
                  <path class="body-path" d="M6 21v-2a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v2"/>
                  <ellipse class="orbit-ring" cx="12" cy="8" rx="6.5" ry="2.5" stroke="rgba(27,110,73,0.35)" stroke-width="1" fill="none"/>
                  <g class="orbit-dot"><circle cx="18.5" cy="8" r="1.4" fill="#1B6E49" stroke="none"/></g>
                </svg>
              </div>
              <div>
                <div class="dept-name">Human Capital</div>
                <div class="dept-tag">HR &amp; Personnel</div>
              </div>
            </div>

            <!-- AF -->
            <div class="dept-card af">
              <div class="dept-icon">
                <svg class="ico-af" viewBox="0 0 24 24" fill="none" stroke="#B8860B" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <path class="walls" d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="rgba(184,134,11,0.06)"/>
                  <rect class="w1 w1-pulse" x="6.5" y="11.5" width="3.5" height="3" rx="0.4" fill="rgba(184,134,11,0.55)" stroke="none"/>
                  <rect class="w2 w2-pulse" x="14" y="11.5" width="3.5" height="3" rx="0.4" fill="rgba(184,134,11,0.55)" stroke="none"/>
                  <rect class="door" x="10" y="15.5" width="4" height="5.5" rx="0.4" fill="rgba(184,134,11,0.2)" stroke="none"/>
                </svg>
              </div>
              <div>
                <div class="dept-name">Administration &amp; Facilities</div>
                <div class="dept-tag">Admin &amp; Infrastructure</div>
              </div>
            </div>

            <!-- CC -->
            <div class="dept-card cc">
              <div class="dept-icon">
                <svg class="ico-cc" viewBox="0 0 24 24" fill="none" stroke="#502C78" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <path class="bubble" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" fill="rgba(80,44,120,0.06)"/>
                  <circle class="d1" cx="8.5"  cy="11" r="1.3" fill="#502C78" stroke="none"/>
                  <circle class="d2" cx="12"   cy="11" r="1.3" fill="#502C78" stroke="none"/>
                  <circle class="d3" cx="15.5" cy="11" r="1.3" fill="#502C78" stroke="none"/>
                </svg>
              </div>
              <div>
                <div class="dept-name">Corporate Communication</div>
                <div class="dept-tag">Comms &amp; Media</div>
              </div>
            </div>

            <!-- MAINT — wide -->
            <div class="dept-card maint wide">
              <div class="dept-icon">
                <svg class="ico-maint" viewBox="0 0 24 24" fill="none" stroke="#AF3723" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <g class="gear-group">
                    <circle cx="16.5" cy="7.5" r="2.6" fill="rgba(175,55,35,0.07)"/>
                    <line x1="16.5" y1="4.2"  x2="16.5" y2="5.2"  stroke-width="1.6"/>
                    <line x1="16.5" y1="9.8"  x2="16.5" y2="10.8" stroke-width="1.6"/>
                    <line x1="13.2" y1="7.5"  x2="14.2" y2="7.5"  stroke-width="1.6"/>
                    <line x1="18.8" y1="7.5"  x2="19.8" y2="7.5"  stroke-width="1.6"/>
                    <line x1="14.17" y1="5.17" x2="14.88" y2="5.88" stroke-width="1.6"/>
                    <line x1="18.12" y1="9.12" x2="18.83" y2="9.83" stroke-width="1.6"/>
                    <line x1="14.17" y1="9.83" x2="14.88" y2="9.12" stroke-width="1.6"/>
                    <line x1="18.12" y1="5.88" x2="18.83" y2="5.17" stroke-width="1.6"/>
                  </g>
                  <g class="wrench-group">
                    <path class="wrench-path" d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                  </g>
                </svg>
              </div>
              <div class="dept-text">
                <div class="dept-name">Maintenance</div>
                <div class="dept-tag">Building &amp; Equipment Maintenance</div>
              </div>
            </div>

          </div>
        </div>
      </div><!-- /hero-top -->

      

    </div><!-- /hero-inner -->
  </div><!-- /hero -->

  <!-- ── FOOTER ── -->
  <footer>
    <div class="footer-chips">
      <div class="fchip">
        <svg viewBox="0 0 24 24" fill="none" stroke="rgba(212,160,23,0.8)" stroke-width="2" width="11" height="11"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <strong>Mon – Fri</strong>&nbsp;8 am – 5 pm
      </div>
      <div class="fchip">
        <svg viewBox="0 0 24 24" fill="none" stroke="rgba(212,160,23,0.8)" stroke-width="2" width="11" height="11"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.37 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.4a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        ITD&nbsp;<strong>Ext. 142 / 140</strong>
      </div>
      <div class="fchip">
        <svg viewBox="0 0 24 24" fill="none" stroke="rgba(212,160,23,0.8)" stroke-width="2" width="11" height="11"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Real-time ticket tracking
      </div>
      <div class="fchip" style="color:rgba(212,160,23,0.75);">
        <svg viewBox="0 0 24 24" fill="none" stroke="rgba(212,160,23,0.75)" stroke-width="2" width="11" height="11"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Room booking → <a href="#" style="color:rgba(212,160,23,0.9);text-decoration:underline;text-underline-offset:2px;margin-left:3px;">Vequip</a>
      </div>
    </div>
    <div class="footer-right">© <?php echo date('Y'); ?> UniKL RCMP · RUSH — RCMP User Helpdesk</div>
  </footer>

</body>
</html>