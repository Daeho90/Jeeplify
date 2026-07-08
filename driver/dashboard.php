<?php
session_start();
if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    header('Location: ../index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Jeeplify — Driver</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="../fav.png"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
:root{
  --bg:     #0d1321;
  --panel:  rgba(13,20,38,.96);
  --accent: #0ea5e9;
  --green:  #22c55e;
  --red:    #ef4444;
  --yellow: #f59e0b;
  --orange: #f97316;
  --text:   #e8edf5;
  --muted:  rgba(232,237,245,.50);
  --border: rgba(255,255,255,.08);
  --card:   rgba(255,255,255,.06);
  --r:      14px;
  --peek:   200px;
  --pulse-color: #22c55e;
}

*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
html,body{width:100%;height:100%;overflow:hidden;font-family:'Montserrat',sans-serif;background:var(--bg);color:var(--text);}

/* ── MAP ── */
#map{position:fixed;inset:0;z-index:0;}
.leaflet-container{background:#0d1321 !important;}

/* ── TOP BAR ── */
.top-bar{
  position:fixed;top:0;left:0;right:0;z-index:200;
  display:flex;align-items:center;justify-content:space-between;
  padding:calc(env(safe-area-inset-top, 0px) + 10px) 14px 10px;
  background:linear-gradient(to bottom,rgba(13,20,38,.85) 0%,transparent 100%);
  pointer-events:none;
}
.top-bar>*{pointer-events:all;}

.brand-pill{
  display:flex;align-items:center;gap:8px;
  background:rgba(13,20,38,.82);backdrop-filter:blur(14px);
  border:1px solid var(--border);border-radius:50px;
  padding:6px 14px 6px 8px;
}
.brand-pill .bicon{
  width:28px;height:28px;border-radius:8px;
  background:linear-gradient(135deg,#0284c7,#0ea5e9);
  display:flex;align-items:center;justify-content:center;font-size:13px;
}
.brand-pill span{font-size:11px;font-weight:700;}

.gps-pill{
  display:flex;align-items:center;gap:6px;
  background:rgba(13,20,38,.82);backdrop-filter:blur(14px);
  border:1px solid var(--border);border-radius:50px;
  padding:6px 12px;font-size:11px;font-weight:600;color:var(--muted);
}
.pip{
  width:7px;height:7px;border-radius:50%;
  background:var(--muted);flex-shrink:0;transition:background .4s;
}
.pip.on {background:var(--green);animation:blink 1.4s ease-in-out infinite;}
.pip.err{background:var(--red);}

/* ── BOTTOM SHEET ── */
.sheet{
  position:fixed;left:0;right:0;
  bottom:0;
  z-index:200;
  background:var(--panel);
  backdrop-filter:blur(26px);-webkit-backdrop-filter:blur(26px);
  border-top:1px solid var(--border);
  border-radius:22px 22px 0 0;
  transform:translateY(calc(100% - var(--peek)));
  transition:transform .38s cubic-bezier(.32,0,.67,0);
  max-height:calc(100dvh - 70px);
  overflow-y:auto;-webkit-overflow-scrolling:touch;
  padding-bottom:env(safe-area-inset-bottom, 0px);
}
.sheet.open{
  transform:translateY(0);
  transition:transform .42s cubic-bezier(.22,1,.36,1);
}

.handle{
  width:36px;height:4px;border-radius:2px;
  background:rgba(255,255,255,.15);
  margin:10px auto 0;
}

/* ── PROFILE CARD ── */
.profile-card{
  margin:10px 14px 0;
  background:var(--card);border:1px solid var(--border);border-radius:var(--r);
  padding:12px 14px;
  cursor:pointer;
  display:flex;align-items:center;justify-content:space-between;gap:10px;
}
.profile-left{display:flex;align-items:center;gap:10px;}
.avatar{
  width:38px;height:38px;border-radius:11px;flex-shrink:0;
  background:linear-gradient(135deg,rgba(14,165,233,.25),rgba(14,165,233,.10));
  border:1px solid rgba(14,165,233,.22);
  display:flex;align-items:center;justify-content:center;font-size:17px;
}
.driver-name{font-size:13px;font-weight:700;line-height:1.2;}
.driver-sub{font-size:10px;color:var(--muted);margin-top:2px;}
.chevron{
  color:var(--muted);flex-shrink:0;
  transition:transform .35s cubic-bezier(.22,1,.36,1);
}
.sheet.open .chevron{transform:rotate(180deg);}
.chevron svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;}

/* GPS toggle button */
.gps-toggle{
  display:flex;align-items:center;gap:5px;
  padding:5px 10px;border-radius:20px;
  background:rgba(14,165,233,.10);border:1px solid rgba(14,165,233,.20);
  color:var(--accent);font-size:10px;font-weight:700;
  cursor:pointer;flex-shrink:0;white-space:nowrap;
  transition:background .2s,color .2s,border-color .2s;
}
.gps-toggle.live{
  background:rgba(34,197,94,.10);border-color:rgba(34,197,94,.22);color:var(--green);
}
.gps-toggle.err{
  background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.22);color:var(--red);
}
.gps-toggle svg{width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;}

/* ── TRIP STATUS PANEL ── */
.status-panel{
  margin:10px 14px 0;
}
.status-panel-label{
  font-size:9px;font-weight:700;text-transform:uppercase;
  color:var(--muted);letter-spacing:.6px;margin-bottom:7px;
}
.status-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:7px;
}
.status-btn{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:5px;padding:10px 8px;
  border-radius:12px;
  border:1.5px solid var(--border);
  background:var(--card);
  cursor:pointer;
  transition:background .18s,border-color .18s,transform .12s;
  -webkit-tap-highlight-color:transparent;
  position:relative;overflow:hidden;
}
.status-btn:active{transform:scale(.96);}
.status-btn .sico{font-size:18px;line-height:1;}
.status-btn .stxt{
  font-size:9px;font-weight:800;text-transform:uppercase;
  letter-spacing:.5px;color:var(--muted);
  transition:color .18s;text-align:center;
}

.status-btn[data-status="on_route"].active,
.status-btn[data-status="on_route"]:hover{background:rgba(14,165,233,.12);border-color:rgba(14,165,233,.35);}
.status-btn[data-status="on_route"].active .stxt,
.status-btn[data-status="on_route"]:hover .stxt{color:var(--accent);}

.status-btn[data-status="traffic"].active,
.status-btn[data-status="traffic"]:hover{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35);}
.status-btn[data-status="traffic"].active .stxt,
.status-btn[data-status="traffic"]:hover .stxt{color:var(--yellow);}

.status-btn[data-status="maintenance"].active,
.status-btn[data-status="maintenance"]:hover{background:rgba(249,115,22,.12);border-color:rgba(249,115,22,.35);}
.status-btn[data-status="maintenance"].active .stxt,
.status-btn[data-status="maintenance"]:hover .stxt{color:var(--orange);}

.status-btn[data-status="complete"].active,
.status-btn[data-status="complete"]:hover{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.35);}
.status-btn[data-status="complete"].active .stxt,
.status-btn[data-status="complete"]:hover .stxt{color:var(--green);}

.status-btn.active::after{
  content:'';position:absolute;inset:-1px;border-radius:inherit;
  border:1.5px solid currentColor;opacity:.3;pointer-events:none;
}

/* ── SHEET BODY ── */
.sheet-body{padding:0 14px 12px;}

.slabel{
  font-size:9px;font-weight:700;text-transform:uppercase;
  color:var(--muted);letter-spacing:.6px;margin:16px 0 6px;
}

.icard{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--r);padding:12px 14px;
}
.srow{display:flex;justify-content:space-between;align-items:center;font-size:12px;padding:5px 0;}
.srow .lbl{color:var(--muted);}
.srow .val{font-weight:700;}
.sep{height:1px;background:var(--border);margin:2px 0;}

.status-badge{
  display:inline-flex;align-items:center;gap:5px;
  font-size:10px;font-weight:700;text-transform:uppercase;
  padding:4px 10px;border-radius:20px;
  background:rgba(14,165,233,.12);color:var(--accent);border:1px solid rgba(14,165,233,.20);
}
.status-badge.active{
  background:rgba(34,197,94,.12);color:var(--green);border-color:rgba(34,197,94,.20);
}
.dot{width:6px;height:6px;border-radius:50%;background:currentColor;}
.dot.pulse{animation:blink 1.4s ease-in-out infinite;}

/* ── LOGOUT BUTTON ── */
.logout-btn{
  width:100%;height:40px;margin-top:16px;
  border-radius:10px;border:1px solid rgba(239,68,68,.22);
  background:rgba(239,68,68,.07);
  color:rgba(239,68,68,.80);
  font-family:'Montserrat',sans-serif;font-size:12px;font-weight:700;
  cursor:pointer;letter-spacing:.3px;
  transition:background .2s,color .2s;
  display:flex;align-items:center;justify-content:center;gap:7px;
}
.logout-btn:active{background:rgba(239,68,68,.15);color:var(--red);}
.logout-btn svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}

/* ── TOAST ── */
.toast{
  position:fixed;bottom:calc(var(--peek) + 12px);left:50%;
  transform:translateX(-50%) translateY(16px);
  color:#fff;font-size:12px;font-weight:600;
  padding:9px 18px;border-radius:50px;
  transition:transform .35s cubic-bezier(.34,1.56,.64,1),opacity .3s;
  opacity:0;z-index:9999;pointer-events:none;
  max-width:calc(100vw - 32px);text-align:center;
}
.toast.error  {background:rgba(239,68,68,.96);box-shadow:0 4px 20px rgba(239,68,68,.35);}
.toast.success{background:rgba(34,197,94,.96);box-shadow:0 4px 20px rgba(34,197,94,.35);}
.toast.info   {background:rgba(14,165,233,.96);box-shadow:0 4px 20px rgba(14,165,233,.35);}
.toast.show   {transform:translateX(-50%) translateY(0);opacity:1;}

/* ── SKELETONS ── */
.skel{
  display:inline-block;height:12px;border-radius:6px;
  background:linear-gradient(90deg,var(--card) 25%,rgba(255,255,255,.1) 50%,var(--card) 75%);
  background-size:200% 100%;animation:shimmer 1.4s infinite;
}
.skel.w40{width:40%;}.skel.w60{width:60%;}.skel.w80{width:80%;}
@keyframes shimmer{from{background-position:200% 0}to{background-position:-200% 0}}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}

/* ── HEARTBEAT PULSE MARKER ── */
.hb-wrap{
  position:absolute;
  width:60px;height:60px;
  top:calc(-30px + 24px);
  left:calc(-30px + 24px);
  pointer-events:none;
}
.hb-ring{
  position:absolute;inset:0;
  border-radius:50%;
  border:2px solid var(--pulse-color);
  opacity:0;
  animation:hb-beat 2s ease-out infinite;
}
.hb-ring:nth-child(2){animation-delay:.22s;}
@keyframes hb-beat{
  0%       { transform:scale(.3); opacity:.9; }
  40%,100% { transform:scale(1);  opacity:0;  }
}

/* ── ETA CARD ── */
.eta-card{
  margin:10px 14px 0;
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--r);padding:11px 14px;
  display:flex;align-items:center;justify-content:space-between;gap:10px;
}
.eta-left{ display:flex;flex-direction:column;gap:2px; }
.eta-label{
  font-size:9px;font-weight:700;text-transform:uppercase;
  letter-spacing:.6px;color:var(--muted);
}
.eta-value{
  font-size:20px;font-weight:800;color:var(--text);line-height:1.1;
  transition:color .3s;
}
.eta-value.unavailable{ font-size:13px;color:var(--muted);font-weight:600; }
.eta-sub{font-size:10px;color:var(--muted);margin-top:1px;}
.eta-right{
  display:flex;flex-direction:column;align-items:flex-end;gap:3px;
  flex-shrink:0;
}
.eta-dist{font-size:11px;font-weight:700;color:var(--accent);}
.eta-dir{font-size:9px;color:var(--muted);text-align:right;max-width:90px;line-height:1.3;}
.dir-toggle{
  display:flex;align-items:center;gap:5px;
  padding:4px 10px;border-radius:20px;
  background:rgba(14,165,233,.10);border:1px solid rgba(14,165,233,.20);
  color:var(--accent);font-size:10px;font-weight:700;
  cursor:pointer;white-space:nowrap;margin-top:4px;
  transition:background .2s;
}
.dir-toggle:active{ background:rgba(14,165,233,.2); }

/* ── LOCATION MODAL ── */
.overlay{
  position:fixed;inset:0;z-index:9000;
  display:flex;align-items:flex-end;justify-content:center;
  background:rgba(0,0,0,.55);backdrop-filter:blur(8px);
  opacity:0;pointer-events:none;transition:opacity .3s;
}
.overlay.active{opacity:1;pointer-events:all;}
.loc-card{
  width:100%;max-width:480px;
  background:var(--panel);border:1px solid var(--border);
  border-radius:24px 24px 0 0;
  padding:24px 22px calc(24px + env(safe-area-inset-bottom, 0px));
  text-align:center;
  transform:translateY(100%);transition:.4s cubic-bezier(.22,1,.36,1);
}
.overlay.active .loc-card{transform:translateY(0);}
.loc-icon{
  width:52px;height:52px;border-radius:15px;
  background:linear-gradient(135deg,#163b8f,#0ea5e9);
  display:flex;align-items:center;justify-content:center;
  font-size:22px;margin:0 auto 16px;
  box-shadow:0 0 28px rgba(14,165,233,.3);
}
.loc-card h2{font-size:22px;font-weight:800;margin-bottom:8px;}
.loc-card p{font-size:13px;line-height:1.7;color:var(--muted);margin-bottom:22px;}
.allow-btn{
  width:100%;height:48px;border:none;border-radius:12px;
  background:linear-gradient(90deg,#2563eb,#0ea5e9);
  color:#fff;font-size:14px;font-weight:700;
  font-family:'Montserrat',sans-serif;cursor:pointer;
}
.later-btn{
  width:100%;height:44px;margin-top:8px;
  border-radius:12px;border:1px solid var(--border);
  background:transparent;color:var(--muted);
  font-size:13px;font-weight:600;
  font-family:'Montserrat',sans-serif;cursor:pointer;
}

/* ── CONFIRM MODAL ── */
.confirm-overlay{
  position:fixed;inset:0;z-index:9100;
  display:flex;align-items:flex-end;justify-content:center;
  background:rgba(0,0,0,.6);backdrop-filter:blur(10px);
  opacity:0;pointer-events:none;transition:opacity .25s;
}
.confirm-overlay.active{opacity:1;pointer-events:all;}
.confirm-card{
  width:100%;max-width:480px;
  background:var(--panel);border:1px solid var(--border);
  border-radius:24px 24px 0 0;
  padding:24px 22px calc(24px + env(safe-area-inset-bottom, 0px));
  text-align:center;
  transform:translateY(100%);transition:.38s cubic-bezier(.22,1,.36,1);
}
.confirm-overlay.active .confirm-card{transform:translateY(0);}
.confirm-icon{font-size:36px;margin-bottom:12px;}
.confirm-card h3{font-size:18px;font-weight:800;margin-bottom:8px;}
.confirm-card p{font-size:13px;color:var(--muted);line-height:1.6;margin-bottom:22px;}
.confirm-yes{
  width:100%;height:46px;border:none;border-radius:12px;
  background:linear-gradient(90deg,#16a34a,#22c55e);
  color:#fff;font-size:13px;font-weight:700;
  font-family:'Montserrat',sans-serif;cursor:pointer;margin-bottom:8px;
}
.confirm-no{
  width:100%;height:42px;border-radius:12px;
  border:1px solid var(--border);background:transparent;
  color:var(--muted);font-size:13px;font-weight:600;
  font-family:'Montserrat',sans-serif;cursor:pointer;
}

/* ── DESKTOP SIDEBAR ── */
@media(min-width:768px){
  .top-bar,.sheet{display:none;}
  #map{left:300px;}

  .sidebar{
    display:flex !important;
    position:fixed;top:0;left:0;bottom:0;width:300px;
    z-index:200;background:var(--panel);
    backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);
    border-right:1px solid var(--border);
    flex-direction:column;overflow-y:auto;
  }
  .sb-head{
    padding:18px 16px 14px;border-bottom:1px solid var(--border);flex-shrink:0;
  }
  .sb-brand{display:flex;align-items:center;gap:10px;margin-bottom:14px;}
  .sb-brand .bicon{
    width:38px;height:38px;border-radius:11px;
    background:linear-gradient(135deg,#0284c7,#0ea5e9);
    display:flex;align-items:center;justify-content:center;font-size:16px;
  }
  .sb-brand h2{font-size:13px;font-weight:700;}
  .sb-brand small{font-size:10px;color:var(--muted);display:block;margin-top:1px;}

  .sb-profile{
    background:var(--card);border:1px solid var(--border);
    border-radius:10px;padding:10px 12px;
    display:flex;align-items:center;gap:9px;
  }
  .sb-avatar{
    width:34px;height:34px;border-radius:9px;flex-shrink:0;
    background:linear-gradient(135deg,rgba(14,165,233,.25),rgba(14,165,233,.10));
    border:1px solid rgba(14,165,233,.22);
    display:flex;align-items:center;justify-content:center;font-size:15px;
  }
  .sb-profile-name{font-size:12px;font-weight:700;color:var(--text);}
  .sb-profile-sub{font-size:10px;color:var(--muted);margin-top:1px;}

  .sb-body{padding:12px 14px;flex:1;overflow-y:auto;}
  .sb-label{
    font-size:9px;font-weight:700;text-transform:uppercase;
    color:var(--muted);letter-spacing:.5px;margin:14px 0 5px;
  }
  .sb-card{
    background:var(--card);border:1px solid var(--border);
    border-radius:10px;padding:11px 13px;font-size:12px;
  }
  .sb-row{display:flex;justify-content:space-between;font-size:11.5px;padding:4px 0;}
  .sb-row .val{font-weight:700;color:var(--text);}
  .sb-sep{height:1px;background:var(--border);margin:2px 0;}

  .sb-status-grid{
    display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:5px;
  }

  .sb-footer{
    border-top:1px solid var(--border);padding:12px 14px;flex-shrink:0;
    display:flex;flex-direction:column;gap:8px;
  }
  .sb-gps-row{
    display:flex;align-items:center;gap:8px;
    font-size:11px;color:var(--muted);
  }
  .sb-logout{
    height:34px;border-radius:8px;
    border:1px solid rgba(239,68,68,.22);
    background:rgba(239,68,68,.07);
    color:rgba(239,68,68,.80);
    font-family:'Montserrat',sans-serif;font-size:11px;font-weight:700;
    cursor:pointer;
    transition:background .2s,color .2s;
    display:flex;align-items:center;justify-content:center;gap:6px;
  }
  .sb-logout:hover{background:rgba(239,68,68,.15);color:var(--red);}
  .sb-logout svg{width:13px;height:13px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}

  .overlay,.confirm-overlay{align-items:center;}
  .loc-card,.confirm-card{border-radius:22px;max-width:360px;margin:auto;padding:28px 26px;}
}
</style>
</head>
<body>

<div id="map"></div>

<!-- TOP BAR (mobile) -->
<div class="top-bar">
  <div class="brand-pill">
    <div class="bicon">🚌</div>
    <span>Jeeplify Driver</span>
  </div>
  <div class="gps-pill">
    <div class="pip" id="pipMob"></div>
    <span id="gpsLblMob">No GPS</span>
  </div>
</div>

<!-- BOTTOM SHEET (mobile) -->
<div class="sheet" id="sheet">
  <div class="handle"></div>

  <div class="profile-card" id="profileCard">
    <div class="profile-left">
      <div class="avatar">👤</div>
      <div>
        <div class="driver-name" id="peekName"><span class="skel w60"></span></div>
        <div class="driver-sub">Driver Portal</div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <button class="gps-toggle" id="gpsBtnMob" onclick="toggleGps(event)">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8"/></svg>
        <span id="gpsBtnLbl">GPS Off</span>
      </button>
      <div class="chevron">
        <svg viewBox="0 0 24 24"><polyline points="18 15 12 9 6 15"/></svg>
      </div>
    </div>
  </div>

  <div class="status-panel">
    <div class="status-panel-label">Trip Status</div>
    <div class="status-grid">
      <button class="status-btn" data-status="on_route"    onclick="setTripStatus('on_route',event)">
        <span class="sico">🟢</span><span class="stxt">On Route</span>
      </button>
      <button class="status-btn" data-status="traffic"     onclick="setTripStatus('traffic',event)">
        <span class="sico">🚦</span><span class="stxt">Traffic</span>
      </button>
      <button class="status-btn" data-status="maintenance" onclick="setTripStatus('maintenance',event)">
        <span class="sico">⚠️</span><span class="stxt">Maintenance</span>
      </button>
      <button class="status-btn" data-status="complete"    onclick="setTripStatus('complete',event)">
        <span class="sico">☑️</span><span class="stxt">Complete Trip</span>
      </button>
    </div>
  </div>

  <!-- ETA CARD (mobile) -->
  <div class="eta-card" id="etaCard">
    <div class="eta-left">
      <div class="eta-label">ETA to Terminal</div>
      <div class="eta-value" id="etaValue">—</div>
      <div class="eta-sub" id="etaSub">Waiting for GPS…</div>
    </div>
    <div class="eta-right">
      <div class="eta-dist" id="etaDist">—</div>
      <div class="eta-dir"  id="etaDir">—</div>
      <button class="dir-toggle" onclick="toggleDirection(event)">⇄ Flip</button>
    </div>
  </div>

  <div class="sheet-body">
    <div class="slabel">Assigned Jeepney</div>
    <div class="icard" id="jeepCard"><span class="skel w40"></span></div>

    <div class="slabel">Next / Active Trip</div>
    <div class="icard" id="tripCard">
      <span class="skel w80"></span><br>
      <span class="skel w60" style="margin-top:8px;"></span>
    </div>

    <button class="logout-btn" onclick="handleLogout()">
      <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </button>
  </div>
</div>

<!-- SIDEBAR (desktop) -->
<aside class="sidebar" style="display:none;">
  <div class="sb-head">
    <div class="sb-brand">
      <div class="bicon">🚌</div>
      <div>
        <h2>Bacolod Jeepney Tracker</h2>
        <small>Driver Portal</small>
      </div>
    </div>
    <div class="sb-profile">
      <div class="sb-avatar">👤</div>
      <div>
        <div class="sb-profile-name" id="sbName"><span class="skel w60"></span></div>
        <div class="sb-profile-sub">Driver</div>
      </div>
    </div>
  </div>

  <div class="sb-body">
    <div class="sb-label">Trip Status</div>
    <div class="sb-status-grid">
      <button class="status-btn" data-status="on_route"    onclick="setTripStatus('on_route',event)">
        <span class="sico">🟢</span><span class="stxt">On Route</span>
      </button>
      <button class="status-btn" data-status="traffic"     onclick="setTripStatus('traffic',event)">
        <span class="sico">🚦</span><span class="stxt">Traffic</span>
      </button>
      <button class="status-btn" data-status="maintenance" onclick="setTripStatus('maintenance',event)">
        <span class="sico">⚠️</span><span class="stxt">Maintenance</span>
      </button>
      <button class="status-btn" data-status="complete"    onclick="setTripStatus('complete',event)">
        <span class="sico">☑️</span><span class="stxt">Complete Trip</span>
      </button>
    </div>

    <!-- ETA CARD (desktop) -->
    <div class="sb-label">ETA</div>
    <div class="sb-card" id="sbEtaCard">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
        <span style="font-size:22px;font-weight:800;" id="sbEtaValue">—</span>
        <span style="font-size:11px;font-weight:700;color:var(--accent);" id="sbEtaDist">—</span>
      </div>
      <div style="font-size:10px;color:var(--muted);margin-bottom:6px;" id="sbEtaSub">Waiting for GPS…</div>
      <div style="font-size:10px;color:var(--muted);margin-bottom:8px;" id="sbEtaDir">—</div>
      <button class="dir-toggle" style="width:100%;justify-content:center;" onclick="toggleDirection(event)">⇄ Flip Direction</button>
    </div>

    <div class="sb-label">Assigned Jeepney</div>
    <div class="sb-card" id="sbJeepCard"><span class="skel w40"></span></div>

    <div class="sb-label">Next / Active Trip</div>
    <div class="sb-card" id="sbTripCard">
      <span class="skel w80"></span>
      <span class="skel w60" style="display:block;margin-top:8px;"></span>
    </div>
  </div><!-- /.sb-body -->

  <div class="sb-footer">
    <div class="sb-gps-row">
      <div class="pip" id="pipDesk"></div>
      <span id="gpsLblDesk">Location not shared</span>
    </div>
    <button class="gps-toggle" id="gpsBtnDesk" onclick="toggleGps(event)" style="width:100%;justify-content:center;">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8"/></svg>
      <span id="gpsBtnDeskLbl">GPS Off</span>
    </button>
    <button class="sb-logout" onclick="handleLogout()">
      <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </button>
  </div>
</aside>

<!-- LOCATION MODAL -->
<div class="overlay" id="locOverlay">
  <div class="loc-card">
    <div class="loc-icon">📍</div>
    <h2>Share Your Location</h2>
    <p>Commuters see your jeepney live on the map. Tap <strong>Allow</strong> to start broadcasting your GPS.</p>
    <button class="allow-btn" id="allowBtn">Allow Location Access</button>
    <button class="later-btn" id="skipBtn">Skip for now</button>
  </div>
</div>

<!-- COMPLETE TRIP CONFIRM MODAL -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-card">
    <div class="confirm-icon">✅</div>
    <h3>Complete this trip?</h3>
    <p>This will mark the current trip as completed and stop your GPS broadcast.</p>
    <button class="confirm-yes" id="confirmYes">Yes, Complete Trip</button>
    <button class="confirm-no"  id="confirmNo">Cancel</button>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
/* ─────────────────────────────────────────────────────────
   CONSTANTS
───────────────────────────────────────────────────────── */
const DEFAULT  = [10.6765, 122.9509];   // number literals — never strings
const GPS_TICK = 10_000;

/* ─────────────────────────────────────────────────────────
   MAP SETUP
───────────────────────────────────────────────────────── */
const map = L.map('map', { zoomControl: false }).setView(DEFAULT, 15);
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
  attribution: '© OpenStreetMap © CARTO',
  subdomains: 'abcd',
  maxZoom: 19
}).addTo(map);
L.control.zoom({ position: 'bottomright' }).addTo(map);

/* ── Driver marker with heartbeat rings ── */
const driverIcon = L.divIcon({
  html: `<div style="position:relative;width:35px;height:35px;">
           <div class="hb-wrap">
             <div class="hb-ring"></div>
             <div class="hb-ring"></div>
           </div>
           <img src="Modern.png" style="width:35px;height:35px;display:block;">
         </div>`,
  className:  '',
  iconSize:   [35, 35],
  iconAnchor: [24, 24]
});
let driverMarker = L.marker(DEFAULT, { icon: driverIcon }).addTo(map);

/* ─────────────────────────────────────────────────────────
   SMOOTH MOVEMENT
   BUG FIX: always coerce lat/lng to float so rAF math
   never string-concatenates instead of adding.
───────────────────────────────────────────────────────── */
let _animFrame  = null;
let _currentPos = [DEFAULT[0], DEFAULT[1]];   // always numbers

function smoothMoveTo(rawLat, rawLng) {
  const lat = parseFloat(rawLat);
  const lng = parseFloat(rawLng);
  if (!isFinite(lat) || !isFinite(lng)) {
    console.warn('smoothMoveTo: invalid coords', rawLat, rawLng);
    return;
  }

  const start     = [_currentPos[0], _currentPos[1]];
  const end       = [lat, lng];
  const duration  = 1000;
  const startTime = performance.now();

  if (_animFrame) cancelAnimationFrame(_animFrame);

  function step(now) {
    const t    = Math.min((now - startTime) / duration, 1);
    const ease = t < .5 ? 2*t*t : -1+(4-2*t)*t;
    const curLat = start[0] + (end[0] - start[0]) * ease;
    const curLng = start[1] + (end[1] - start[1]) * ease;
    driverMarker.setLatLng([curLat, curLng]);
    if (t < 1) {
      _animFrame = requestAnimationFrame(step);
    } else {
      _currentPos = [lat, lng];
      _animFrame  = null;
    }
  }
  _animFrame = requestAnimationFrame(step);
}

/* ── Pulse colour by status ── */
function updatePulse(status) {
  const colours = {
    on_route:    '#22c55e',
    traffic:     '#f97316',
    maintenance: '#ef4444',
    complete:    '#0ea5e9'
  };
  document.documentElement.style.setProperty(
    '--pulse-color',
    colours[status] || colours.on_route
  );
}

/* ─────────────────────────────────────────────────────────
   ROUTE DATA  ([lat, lng])
───────────────────────────────────────────────────────── */
const ROUTE_COORDS = [
  [10.66691,122.94646],[10.6664,122.94625],[10.66562,122.94593],[10.66557,122.94591],
  [10.66423,122.94534],[10.66385,122.94614],[10.66365,122.94654],[10.66321,122.94753],
  [10.66311,122.94774],[10.66304,122.94787],[10.66274,122.9485],[10.66266,122.9487],
  [10.66257,122.94888],[10.66239,122.94926],[10.66224,122.94961],[10.66202,122.95003],
  [10.66183,122.95042],[10.66166,122.9508],[10.66145,122.95116],[10.66125,122.95154],
  [10.66105,122.95192],[10.66101,122.95201],[10.661,122.95202],[10.66099,122.95203],
  [10.66098,122.95205],[10.66096,122.95206],[10.66095,122.95208],[10.66093,122.9521],
  [10.66097,122.95236],[10.66061,122.95219],[10.65989,122.95179],[10.65987,122.95184],
  [10.65967,122.95239],[10.65955,122.95276],[10.65951,122.9529],[10.65947,122.95302],
  [10.65944,122.9531],[10.65941,122.95316],[10.65936,122.95326],[10.65932,122.95332],
  [10.6593,122.95336],[10.65887,122.95373],[10.65864,122.95393],[10.65816,122.95435],
  [10.65751,122.95487],[10.65734,122.95501],[10.65729,122.95505],[10.65714,122.95518],
  [10.65688,122.95541],[10.65661,122.95562],[10.65539,122.95665],[10.65394,122.95787],
  [10.65291,122.95871],[10.65255,122.959],[10.65229,122.95923],[10.65187,122.95959],
  [10.65161,122.95982],[10.65106,122.96029],[10.65096,122.96037],[10.65088,122.96044],
  [10.65004,122.96115],[10.64961,122.96151],[10.64897,122.96204],[10.64889,122.96211],
  [10.64875,122.96223],[10.64859,122.96237],[10.64856,122.96239],[10.64847,122.96247],
  [10.64769,122.96311],[10.64744,122.96333],[10.64703,122.96366],[10.64695,122.96373],
  [10.64691,122.96376],[10.64617,122.96441],[10.6458,122.96472],[10.64467,122.96565],
  [10.6436,122.96654],[10.64331,122.96679],[10.64317,122.96691],[10.64308,122.96699],
  [10.6423,122.96766],[10.64195,122.96795],[10.64174,122.96813],[10.64074,122.96898],
  [10.64059,122.96912],[10.64016,122.96964],[10.63964,122.97026],[10.63897,122.97108],
  [10.63885,122.97123],[10.63851,122.97163],[10.63757,122.97279],[10.63745,122.97291],
  [10.63731,122.97305],[10.63722,122.97312],[10.63713,122.97319],[10.63708,122.97321],
  [10.63706,122.97323],[10.63696,122.97328],[10.6369,122.97332],[10.63679,122.97337],
  [10.63673,122.9734],[10.6366,122.97343],[10.63644,122.97346],[10.6361,122.9735],
  [10.63549,122.9736],[10.63538,122.97361],[10.63486,122.97369],[10.63458,122.97373],
  [10.63323,122.97391],[10.63305,122.97394],[10.63189,122.97411],[10.63136,122.9742],
  [10.63132,122.97421],[10.63129,122.97436],[10.63127,122.97455],[10.63125,122.97468],
  [10.63118,122.97514],[10.63113,122.97548],[10.63112,122.97554],[10.63104,122.97609],
  [10.63071,122.97602],[10.6298,122.97581],[10.62962,122.97576],[10.6296,122.97576],
  [10.6292,122.97567],[10.62876,122.97557],[10.62872,122.97556],[10.62829,122.97546],
  [10.62813,122.97542],[10.62802,122.9754],[10.62782,122.97536],[10.62741,122.97535],
  [10.62738,122.97535],[10.62715,122.97533],[10.62704,122.97532],[10.62686,122.9753],
  [10.62656,122.97523],[10.62651,122.97523],[10.62643,122.97521],[10.62633,122.97517],
  [10.62622,122.97512],[10.6261,122.97508],[10.62604,122.97506],[10.62589,122.97501],
  [10.62577,122.97497],[10.62542,122.97484],[10.6247,122.97455],[10.6246,122.97452],
  [10.62457,122.97461],[10.62451,122.9748]
];

const TERMINAL_A  = 'Arguelles';
const TERMINAL_B  = 'Mansilingan';
let   routeForward = true;

const SPEED_KMH = {
  on_route:    25,
  traffic:     10,
  maintenance: 0,
  complete:    0
};

function haversine(a, b) {
  const R  = 6371;
  const d1 = (b[0] - a[0]) * Math.PI / 180;
  const d2 = (b[1] - a[1]) * Math.PI / 180;
  const x  = Math.sin(d1/2)**2 +
              Math.cos(a[0]*Math.PI/180) * Math.cos(b[0]*Math.PI/180) * Math.sin(d2/2)**2;
  return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1-x));
}

function nearestRouteIndex(coords, lat, lng) {
  let best = 0, bestDist = Infinity;
  coords.forEach((pt, i) => {
    const d = haversine(pt, [lat, lng]);
    if (d < bestDist) { bestDist = d; best = i; }
  });
  return best;
}

function remainingDistance(coords, fromIndex) {
  let dist = 0;
  for (let i = fromIndex; i < coords.length - 1; i++) {
    dist += haversine(coords[i], coords[i+1]);
  }
  return dist;
}

function formatETA(minutes) {
  if (minutes < 1)  return '< 1 min';
  if (minutes < 60) return `${Math.round(minutes)} min`;
  const h = Math.floor(minutes / 60);
  const m = Math.round(minutes % 60);
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

function calcETA(lat, lng) {
  const status = currentTripStatus;
  if (status === 'maintenance') { renderETA(null, null, 'unavailable'); return; }
  if (status === 'complete')    { renderETA(null, null, 'arrived');     return; }

  const coords = routeForward ? ROUTE_COORDS : [...ROUTE_COORDS].reverse();
  const dest   = routeForward ? TERMINAL_B   : TERMINAL_A;
  const origin = routeForward ? TERMINAL_A   : TERMINAL_B;
  const idx    = nearestRouteIndex(coords, lat, lng);
  const distKm = remainingDistance(coords, idx);
  const speed  = SPEED_KMH[status] || 25;
  const mins   = (distKm / speed) * 60;
  renderETA(formatETA(mins), distKm, 'ok', origin, dest);
}

function renderETA(timeStr, distKm, state, origin, dest) {
  const val  = document.getElementById('etaValue');
  const sub  = document.getElementById('etaSub');
  const dist = document.getElementById('etaDist');
  const dir  = document.getElementById('etaDir');
  const lbl  = document.getElementById('etaCard')?.querySelector('.eta-label');
  const sbVal  = document.getElementById('sbEtaValue');
  const sbSub  = document.getElementById('sbEtaSub');
  const sbDist = document.getElementById('sbEtaDist');
  const sbDir  = document.getElementById('sbEtaDir');

  if (state === 'unavailable') {
    const msg = 'Under maintenance';
    if (val)  { val.textContent = '—'; val.className = 'eta-value unavailable'; }
    if (sub)  sub.textContent   = msg;
    if (dist) dist.textContent  = '—';
    if (dir)  dir.textContent   = '—';
    if (sbVal)  { sbVal.textContent = '—'; sbVal.style.fontSize = '14px'; }
    if (sbSub)  sbSub.textContent  = msg;
    if (sbDist) sbDist.textContent = '—';
    if (sbDir)  sbDir.textContent  = '—';
    return;
  }
  if (state === 'arrived') {
    const msg = 'Trip completed';
    if (val)  { val.textContent = '✓'; val.className = 'eta-value'; }
    if (sub)  sub.textContent   = msg;
    if (dist) dist.textContent  = '0 km';
    if (dir)  dir.textContent   = '—';
    if (sbVal)  { sbVal.textContent = '✓'; sbVal.style.fontSize = '22px'; }
    if (sbSub)  sbSub.textContent  = msg;
    if (sbDist) sbDist.textContent = '0 km';
    if (sbDir)  sbDir.textContent  = '—';
    return;
  }

  const distStr = distKm >= 1
    ? `${distKm.toFixed(1)} km`
    : `${Math.round(distKm * 1000)} m`;
  const dirStr  = `${origin} → ${dest}`;

  if (val)  { val.textContent = timeStr; val.className = 'eta-value'; }
  if (sub)  sub.textContent   = 'to ' + dest;
  if (dist) dist.textContent  = distStr;
  if (dir)  dir.textContent   = dirStr;
  if (lbl)  lbl.textContent   = 'ETA to ' + dest;
  if (sbVal)  { sbVal.textContent = timeStr; sbVal.style.fontSize = '22px'; }
  if (sbSub)  sbSub.textContent  = 'to ' + dest;
  if (sbDist) sbDist.textContent = distStr;
  if (sbDir)  sbDir.textContent  = dirStr;
}

function toggleDirection(e) {
  if (e) e.stopPropagation();
  routeForward = !routeForward;
  if (pending && gpsOn && currentTripId) calcETA(pending[0], pending[1]);
  else resetETA();
  showToast(routeForward ? '→ Arguelles to Mansilingan' : '← Mansilingan to Arguelles', 'info');
}

/* ─────────────────────────────────────────────────────────
   TOAST
───────────────────────────────────────────────────────── */
let _tt;
function showToast(msg, type = 'error') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = `toast ${type}`;
  void el.offsetWidth;
  el.classList.add('show');
  clearTimeout(_tt);
  _tt = setTimeout(() => el.classList.remove('show'), 3500);
}

/* ─────────────────────────────────────────────────────────
   GPS STATE
───────────────────────────────────────────────────────── */
let watchId  = null;
let gpsTimer = null;
let pending  = null;   // [lat, lng] as numbers
let gpsOn    = false;

function setGpsUI(state) {
  ['pipMob', 'pipDesk'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.className = 'pip' + (state === 'on' ? ' on' : state === 'err' ? ' err' : '');
  });

  const full  = state === 'on'  ? 'Broadcasting'      : state === 'err' ? 'GPS Error'           : 'Location not shared';
  const short = state === 'on'  ? 'Live'              : state === 'err' ? 'Error'               : 'No GPS';

  const lm = document.getElementById('gpsLblMob');  if (lm) lm.textContent = short;
  const ld = document.getElementById('gpsLblDesk'); if (ld) ld.textContent = full;

  // Mobile button
  const btn  = document.getElementById('gpsBtnMob');
  const lbl  = document.getElementById('gpsBtnLbl');
  if (btn) btn.className = 'gps-toggle' + (state === 'on' ? ' live' : state === 'err' ? ' err' : '');
  if (lbl) lbl.textContent = state === 'on' ? 'Live' : state === 'err' ? 'Error' : 'GPS Off';

  // Desktop button
  const dbtn = document.getElementById('gpsBtnDesk');
  const dlbl = document.getElementById('gpsBtnDeskLbl');
  if (dbtn) dbtn.className = 'gps-toggle' + (state === 'on' ? ' live' : state === 'err' ? ' err' : '');
  if (dlbl) dlbl.textContent = state === 'on' ? 'GPS Live' : state === 'err' ? 'GPS Error' : 'Enable GPS';
}

async function uploadCoords(lat, lng) {
  try {
    const coords  = routeForward ? ROUTE_COORDS : [...ROUTE_COORDS].reverse();
    const idx     = nearestRouteIndex(coords, lat, lng);
    const distKm  = remainingDistance(coords, idx);
    const speed   = SPEED_KMH[currentTripStatus] || 25;
    const etaMins = speed > 0 ? Math.round((distKm / speed) * 60) : null;

    await fetch('update_location.php', {
      method: 'POST',
      body: new URLSearchParams({
        lat,
        lng,
        eta_minutes: etaMins ?? '',
        eta_dist_km: distKm.toFixed(2),
        direction:   routeForward ? 'forward' : 'reverse',
        status:      currentTripStatus || 'on_route'
      })
    });
  } catch { /* silent */ }
}

function startGps() {
  if (!navigator.geolocation) { setGpsUI('err'); showToast('Geolocation not supported.'); return; }
  gpsOn = true;
  watchId = navigator.geolocation.watchPosition(
    pos => {
      // BUG FIX: always store as parsed floats
      const lat = parseFloat(pos.coords.latitude);
      const lng = parseFloat(pos.coords.longitude);
      if (!isFinite(lat) || !isFinite(lng)) return;
      pending = [lat, lng];
      smoothMoveTo(lat, lng);
      map.panTo(pending, { animate: true });
      setGpsUI('on');
      if (gpsOn && currentTripId) calcETA(lat, lng);
    },
    err => { console.warn('GPS error:', err); setGpsUI('err'); },
    { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 }
  );
  gpsTimer = setInterval(() => {
    if (pending) uploadCoords(pending[0], pending[1]);
  }, GPS_TICK);
}

function stopGps() {
  if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
  clearInterval(gpsTimer);
  gpsTimer = null;
  pending  = null;
  gpsOn    = false;
  setGpsUI('off');
  resetETA();
}

function resetETA() {
  ['etaValue','sbEtaValue'].forEach(id => {
    const el = document.getElementById(id);
    if (el) { el.textContent = '—'; el.className = 'eta-value'; if (id === 'sbEtaValue') el.style.fontSize = '22px'; }
  });
  const subMsg = currentTripId ? 'GPS not active' : 'No trip assigned';
  ['etaSub','sbEtaSub'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = subMsg; });
  ['etaDist','sbEtaDist'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = '—'; });
  ['etaDir','sbEtaDir'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = '—'; });
  const lbl = document.getElementById('etaCard')?.querySelector('.eta-label');
  if (lbl) lbl.textContent = 'ETA to Terminal';
}

function toggleGps(e) {
  e.stopPropagation();
  if (gpsOn) stopGps();
  else document.getElementById('locOverlay').classList.add('active');
}

/* ─────────────────────────────────────────────────────────
   TRIP STATUS
───────────────────────────────────────────────────────── */
let currentTripStatus = null;
let currentTripId     = null;

const STATUS_LABELS = {
  on_route:    'On Route',
  traffic:     'In Traffic',
  maintenance: 'Maintenance',
  complete:    'Trip Completed',
};

function highlightStatusBtn(status) {
  document.querySelectorAll('.status-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.status === status);
  });
}

async function pushTripStatus(status) {
  if (!currentTripId) return;
  try {
    const res  = await fetch('update_trip_status.php', {
      method: 'POST',
      body: new URLSearchParams({ trip_id: currentTripId, status })
    });
    const data = await res.json();
    if (!data.ok) showToast(data.message || 'Failed to update status.');
  } catch {
    showToast('Connection error updating status.');
  }
}

function setTripStatus(status, e) {
  if (e) e.stopPropagation();
  if (status === 'complete') {
    document.getElementById('confirmOverlay').classList.add('active');
    return;
  }
  if (currentTripStatus === status) {
    currentTripStatus = null;
    highlightStatusBtn(null);
    return;
  }
  currentTripStatus = status;
  highlightStatusBtn(status);
  updatePulse(status);
  pushTripStatus(status);
  if (pending && currentTripId) calcETA(pending[0], pending[1]);
  showToast('Status: ' + STATUS_LABELS[status], 'info');
}

function completeTrip() {
  currentTripStatus = 'complete';
  highlightStatusBtn('complete');
  updatePulse('complete');
  pushTripStatus('complete');
  stopGps();
  showToast('Trip marked as completed.', 'success');
  setTimeout(loadDriverData, 1500);
}

document.getElementById('confirmYes').onclick = () => {
  document.getElementById('confirmOverlay').classList.remove('active');
  completeTrip();
};
document.getElementById('confirmNo').onclick = () => {
  document.getElementById('confirmOverlay').classList.remove('active');
};

/* ─────────────────────────────────────────────────────────
   RENDER HELPERS
───────────────────────────────────────────────────────── */
function renderDriver(d) {
  const name = d.name || '—';
  document.getElementById('peekName').textContent = name;
  const sbn = document.getElementById('sbName'); if (sbn) sbn.textContent = name;

  const jeepHTML = d.jeep_id ? `
    <div class="srow"><span class="lbl">Plate No.</span><span class="val">${d.jeep_id}</span></div>
    ${d.model ? `<div class="sep"></div><div class="srow"><span class="lbl">Model</span><span class="val">${d.model}</span></div>` : ''}
  ` : `<span style="color:var(--muted);font-size:12px;font-style:italic;">No jeepney assigned</span>`;

  const sbJeepHTML = d.jeep_id ? `
    <div class="sb-row"><span style="color:var(--muted)">Plate No.</span><span class="val">${d.jeep_id}</span></div>
    ${d.model ? `<div class="sb-sep"></div><div class="sb-row"><span style="color:var(--muted)">Model</span><span class="val">${d.model}</span></div>` : ''}
  ` : `<span style="color:var(--muted);font-size:11px;font-style:italic;">No jeepney assigned</span>`;

  document.getElementById('jeepCard').innerHTML = jeepHTML;
  const sj = document.getElementById('sbJeepCard'); if (sj) sj.innerHTML = sbJeepHTML;
}

function renderTrip(t) {
  const empty   = `<span style="color:var(--muted);font-size:12px;font-style:italic;">No trip assigned</span>`;
  const sbEmpty = `<span style="color:var(--muted);font-size:11px;font-style:italic;">No trip assigned</span>`;

  if (!t) {
    currentTripId = null;
    document.getElementById('tripCard').innerHTML = empty;
    const st = document.getElementById('sbTripCard'); if (st) st.innerHTML = sbEmpty;
    resetETA();
    return;
  }

  currentTripId = t.id;

  if (t.status === 'active' && !currentTripStatus) {
    currentTripStatus = 'on_route';
    highlightStatusBtn('on_route');
    updatePulse('on_route');
  } else if (t.status === 'completed') {
    currentTripStatus = 'complete';
    highlightStatusBtn('complete');
    updatePulse('complete');
  }

  const isActive = t.status === 'active';
  const badge = `<span class="status-badge ${isActive ? 'active' : ''}">
    <span class="dot ${isActive ? 'pulse' : ''}"></span>${t.status}
  </span>`;

  document.getElementById('tripCard').innerHTML = `
    ${badge}
    <div class="sep" style="margin-top:10px;"></div>
    <div class="srow"><span class="lbl">Route</span><span class="val">${t.route || '—'}</span></div>
    <div class="sep"></div>
    <div class="srow"><span class="lbl">Departure</span><span class="val">${t.departure || '—'}</span></div>
    <div class="sep"></div>
    <div class="srow"><span class="lbl">Vehicle</span><span class="val">${t.vehicle || '—'}</span></div>
  `;

  const st = document.getElementById('sbTripCard');
  if (st) st.innerHTML = `
    <div style="margin-bottom:8px;">${badge}</div>
    <div class="sb-row"><span style="color:var(--muted)">Route</span><span class="val">${t.route || '—'}</span></div>
    <div class="sb-sep"></div>
    <div class="sb-row"><span style="color:var(--muted)">Departure</span><span class="val">${t.departure || '—'}</span></div>
    <div class="sb-sep"></div>
    <div class="sb-row"><span style="color:var(--muted)">Vehicle</span><span class="val">${t.vehicle || '—'}</span></div>
  `;
}

/* ─────────────────────────────────────────────────────────
   LOAD DATA
───────────────────────────────────────────────────────── */
async function loadDriverData() {
  try {
    const res = await fetch('get_driver_data.php');
    if (res.status === 401) { window.location.href = '../index.php'; return; }
    const data = await res.json();
    if (!data.ok) { showToast(data.message || 'Failed to load driver data.'); return; }
    renderDriver(data.driver);
    renderTrip(data.trip);
    if (data.last_location) {
      // BUG FIX: parse server values as floats before any use
      const lat = parseFloat(data.last_location.lat);
      const lng = parseFloat(data.last_location.lng);
      if (isFinite(lat) && isFinite(lng)) {
        _currentPos = [lat, lng];
        driverMarker.setLatLng([lat, lng]);
        map.setView([lat, lng], 16);
      }
    }
  } catch (e) {
    showToast('Connection error. Could not load driver data.');
    console.error(e);
  }
}

/* ─────────────────────────────────────────────────────────
   SHEET TOGGLE
───────────────────────────────────────────────────────── */
document.getElementById('profileCard').addEventListener('click', () => {
  document.getElementById('sheet').classList.toggle('open');
});

/* ─────────────────────────────────────────────────────────
   LOGOUT
───────────────────────────────────────────────────────── */
function handleLogout() { stopGps(); window.location.href = 'logout.php'; }

/* ─────────────────────────────────────────────────────────
   LOCATION MODAL
───────────────────────────────────────────────────────── */
const overlay = document.getElementById('locOverlay');
document.getElementById('allowBtn').onclick = () => { overlay.classList.remove('active'); startGps(); };
document.getElementById('skipBtn').onclick  = () => { overlay.classList.remove('active'); };

/* ─────────────────────────────────────────────────────────
   BOOT
───────────────────────────────────────────────────────── */
window.addEventListener('load', () => {
  loadDriverData();
  resetETA();
  setTimeout(() => overlay.classList.add('active'), 700);
});
window.addEventListener('beforeunload', () => {
  stopGps();
  // Best-effort: mark jeepney offline immediately rather than waiting for
  // the 10-minute staleness timeout in driver_locations.
  navigator.sendBeacon('update_location.php', new URLSearchParams({
    lat: 0, lng: 0, status: 'offline', _offline: '1'
  }));
});
</script>
</body>
</html>