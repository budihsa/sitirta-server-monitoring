<?php
date_default_timezone_set('Asia/Jakarta');

/* ---------- DAFTAR SERVER (AGENT JSON) ---------- */
$SERVERS = array(
    array('name' => 'Server 1',  'url' => 'http://isi_dengan_ip_server/sitmon_agent.php',  'token' => 'IsiDenganTokenRahasia'),
    array('name' => 'Server 2',  'url' => 'http://isi_dengan_ip_server/sitmon_agent.php',  'token' => 'IsiDenganTokenRahasia'),
    array('name' => 'Server 3',  'url' => 'http://isi_dengan_ip_server/sitmon_agent.php',  'token' => 'IsiDenganTokenRahasia'),
);

/* ---------- FETCHER (cURL multi) ---------- */
function fetch_all($servers, $timeout_sec) {
    $mh = curl_multi_init(); $chs = array();
    foreach ($servers as $idx => $sv) {
        $url = rtrim($sv['url'], '?&');
        if (!empty($sv['token'])) {
            $q = (strpos($url, '?') !== false ? '&' : '?') . 'token=' . urlencode($sv['token']);
            $url = $url . $q;
        }
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout_sec);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout_sec);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_multi_add_handle($mh, $ch);
        $chs[$idx] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 1.0); } while ($running > 0);

    $results = array();
    foreach ($servers as $idx => $sv) {
        $body = curl_multi_getcontent($chs[$idx]);
        $err  = curl_error($chs[$idx]);
        $http = curl_getinfo($chs[$idx], CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $chs[$idx]);
        curl_close($chs[$idx]);

        $ok = (!$err && $http == 200 && $body);
        $data = $ok ? json_decode($body, true) : null;
        if (!is_array($data)) $ok = false;

        $results[] = array(
            'name'   => $sv['name'],
            'url'    => $sv['url'],
            'status' => $ok ? 'ok' : 'down',
            'http'   => $http,
            'error'  => $ok ? null : ($err ? $err : 'HTTP '.$http),
            'data'   => $ok ? $data : null
        );
    }
    curl_multi_close($mh);
    return $results;
}

/* ---------- API JSON ---------- */
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['action'] === 'data') {
        $servers = fetch_all($SERVERS, 4);
        echo json_encode(array(
            'generated_at' => date('Y-m-d H:i:s'),
            'servers'      => $servers
        ));
        exit;
    }
    echo json_encode(array('error'=>'Unknown action'));
    exit;
}
?>
<!doctype html>
<html lang="id" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SITIRTA – Server Monitoring Perumda Tirta Mulia Pemalang</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root{
            --bg:#0b1220; --card:#111a2b; --muted:#7e8ba3;
            --ok:#2ecc71; --warn:#f1c40f; --crit:#e74c3c; --info:#3498db; --soft:#BEE5EB;
            --fs-xxs: clamp(10px,.75vw,12px);
            --fs-xs:  clamp(11px,.85vw,13px);
            --fs-sm:  clamp(12px,1vw,14px);
            --fs-md:  clamp(13px,1.1vw,15px);
            --fs-lg:  clamp(14px,1.25vw,16px);
        }
        html,body{height:100%;}
        body{background:var(--bg);font-size:var(--fs-md);}
        .card{background:var(--card);border:1px solid rgba(255,255,255,.06);}
        .badge-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:.35rem;}
        .badge-up{font-size:var(--fs-sm);color:#d1d9e6;}
        .small{font-size:var(--fs-sm);color:var(--muted);}
        .card-title{text-transform:uppercase;font-size:clamp(14px,1.4vw,18px);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .kv{display:flex;justify-content:space-between;align-items:center;margin-bottom:.35rem;}
        .kv b{letter-spacing:.02em;color:#e6eefc;}
        .progress{height:10px;background:rgba(255,255,255,.07);}
        .progress-bar.ok{background-color:var(--ok);}
        .progress-bar.warn{background-color:var(--warn);color:#111;}
        .progress-bar.crit{background-color:var(--crit);}
        .state-pill{display:inline-flex;align-items:center;gap:.4rem;padding:.15rem .5rem;border-radius:999px;font-size:var(--fs-xs);}
        .state-ok{background:rgba(46,204,113,.15);color:#aef5c9;border:1px solid rgba(46,204,113,.35);}
        .state-warn{background:rgba(241,196,15,.15);color:#f7e7a1;border:1px solid rgba(241,196,15,.35);}
        .state-crit{background:rgba(231,76,60,.15);color:#ffb3aa;border:1px solid rgba(231,76,60,.35);}
        .state-info{background:rgba(52,152,219,.15);color:#b6dcfb;border:1px solid rgba(52,152,219,.35);}
        .header-stick{position:sticky;top:0;z-index:99;background:linear-gradient(180deg,#0b1220 70%, rgba(11,18,32,0));}

        /* GRID KARTU – 5x2 desktop (≥1400px) */
        #grid{display:grid;grid-gap:12px;}
        @media (max-width: 575.98px){#grid{grid-template-columns:repeat(1,minmax(0,1fr));}}
        @media (min-width:576px) and (max-width:991.98px){#grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
        @media (min-width:992px) and (max-width:1399.98px){#grid{grid-template-columns:repeat(4,minmax(0,1fr));}}
        @media (min-width:1400px){#grid{grid-template-columns:repeat(5,minmax(0,1fr));}}
        .server-card{display:flex;flex-direction:column;min-height:180px;}
        .server-card .card-body{display:flex;flex-direction:column;gap:.35rem;overflow:hidden;}
        .server-card .subnote{margin-top:.15rem;font-weight:600;}

        /* TABEL (fit-to-screen) */
        #tableSection{display:none;}
        #tableWrap{overflow:hidden;position:relative;background:var(--card);border:1px solid rgba(255,255,255,.06);border-radius:.5rem;padding:.5rem;}
        #tableFitBox{transform-origin:top left;display:inline-block;}
        .table-compact thead th,.table-compact tbody td{padding:12px 18px;font-size:var(--fs-sm);line-height:1.2;white-space:nowrap;}
        .table-sticky thead th{position:sticky;top:0;z-index:2;background:var(--card);}
        .table-sticky thead tr:nth-child(1) th{position: sticky; top: 0; z-index: 3; background: var(--card);}
        .table-sticky thead tr:nth-child(2) th{position: sticky; top: 38px; z-index: 2; background: var(--card);}
        #tableWrap.responsive{overflow:auto;}
        #tableWrap.responsive #tableFitBox{transform:none!important;width:max-content;}
        .dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:.35rem;}
        .dot.ok{background:var(--ok)} .dot.warn{background:var(--warn)} .dot.crit{background:var(--crit)}
        .table-compact td.hl-ok{background:rgba(46,204,113,.18)!important;color:#c8f5d8;font-weight:600;}
        .table-compact td.hl-warn{background:rgba(241,196,15,.22)!important;color:#fff0b3;font-weight:600;}
        .table-compact td.hl-crit{background:rgba(231,76,60,.22)!important;color:#ffd0ca;font-weight:600;}
        .table-compact td.hl-core{background: rgba(52,152,219,.22) !important;color: #d6eaff;font-weight: 600;}
        #tableWrap{overflow-y: auto;overflow-x: hidden;}
        #tableFitBox{transform: none !important;width: 100%;}
        .table-compact{table-layout: fixed;width: 100%;}
        .table-compact thead th,.table-compact tbody td{padding: 10px 12px;font-size: var(--fs-sm);line-height: 1.25;white-space: normal;word-break: break-word;}
        .td-num{ white-space: nowrap; text-align: left; text-transform: uppercase; }

        body.mode-table{overflow:hidden;}
        body.mode-table #grid{display:none!important;}
        body.mode-table #tableSection{display:block!important;}
        body.mode-table .mt-4{display:none;}
        .legend .badge-dot{width:8px;height:8px;}
        .legend{font-size:var(--fs-sm);}

        /* Override jumlah kolom via atribut body[data-cols] */
        @media (min-width: 992px){
            body[data-cols="3"] #grid{ grid-template-columns: repeat(3, minmax(0,1fr)); }
            body[data-cols="4"] #grid{ grid-template-columns: repeat(4, minmax(0,1fr)); }
            body[data-cols="5"] #grid{ grid-template-columns: repeat(5, minmax(0,1fr)); }
        }

        /* ====== GRAFIK ====== */
        #chartsSection{display:none;}
        body.mode-charts #grid{display:none!important;}
        body.mode-charts #tableSection{display:none!important;}
        body.mode-charts #chartsSection{display:block!important;}
        .chart-card{background:var(--card);border:1px solid rgba(255,255,255,.06);border-radius:.5rem;padding:12px;}
        .chart-title{font-size:clamp(14px,1.4vw,18px);margin:0 0 .25rem 0;}
        .chart-note{font-size:var(--fs-sm);color:var(--muted);}
        .charts-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px;}
        .charts-grid .col-12{grid-column:span 12;}
        .chart-box{position:relative;height:300px;}
        @media (min-width:1400px){ .chart-box{height:340px;} }

        /* Tabs per server (scrollable) + toolbar */
        .nav-pills.server-tabs{gap:6px; white-space:nowrap; overflow:auto; flex-wrap:nowrap;}
        .nav-pills.server-tabs .nav-link{border:1px solid rgba(255,255,255,.12);}
        .server-tab-pane{display:none;}
        .server-tab-pane.active{display:block;}
        .charts-toolbar{display:flex;gap:8px;align-items:center;}
        .charts-toolbar .form-select, .charts-toolbar .btn{height:32px;padding:.15rem .5rem;font-size:var(--fs-sm);}
    </style>
</head>
<body>
<div class="container-fluid py-3">
    <div class="header-stick py-2 mb-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h1 class="h4 m-0"><i class="bi bi-activity me-2"></i>SITIRTA – Server Monitoring</h1>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="small">Auto-refresh: <span id="autointv">1s</span> • Terakhir update: <span id="lastUpdate">—</span></div>

                <div class="btn-group btn-group-sm" role="group" aria-label="Kolom">
                    <span class="btn btn-outline-secondary disabled">Kolom:</span>
                    <input type="radio" class="btn-check" name="cols" id="cols_3" autocomplete="off">
                    <label class="btn btn-outline-light" for="cols_3">3</label>
                    <input type="radio" class="btn-check" name="cols" id="cols_4" autocomplete="off">
                    <label class="btn btn-outline-light" for="cols_4">4</label>
                    <input type="radio" class="btn-check" name="cols" id="cols_5" autocomplete="off" checked>
                    <label class="btn btn-outline-light" for="cols_5">5</label>
                </div>

                <div class="btn-group btn-group-sm" role="group" aria-label="Tampilan">
                    <input type="radio" class="btn-check" name="viewmode" id="view_cards" autocomplete="off">
                    <label class="btn btn-outline-light" for="view_cards"><i class="bi bi-grid-3x3-gap"></i> Kartu</label>
                    <input type="radio" class="btn-check" name="viewmode" id="view_table" autocomplete="off">
                    <label class="btn btn-outline-light" for="view_table"><i class="bi bi-table"></i> Tabel</label>
                    <input type="radio" class="btn-check" name="viewmode" id="view_charts" autocomplete="off">
                    <label class="btn btn-outline-light" for="view_charts"><i class="bi bi-graph-up"></i> Grafik</label>
                </div>
            </div>
        </div>
    </div>

    <!-- GRID KARTU -->
    <div id="grid"></div>

    <!-- TABEL -->
    <div id="tableSection" class="mt-3">
        <div id="tableWrap">
            <div id="tableFitBox"></div>
        </div>
    </div>

    <!-- GRAFIK -->
    <div id="chartsSection" class="mt-3">
        <div class="charts-grid">
            <div class="col-12">
                <div class="chart-card">
                    <div class="d-flex justify-content-between align-items-end">
                        <h3 class="chart-title"><i class="bi bi-bar-chart-line me-2"></i>CPU / RAM / HDD per Server</h3>
                        <div class="chart-note">Snapshot saat ini per server</div>
                    </div>
                    <div class="chart-box"><canvas id="barServer"></canvas></div>
                </div>
            </div>

            <!-- Tren per Server (Tabs + Toolbar Riwayat & Export) -->
            <div class="col-12">
                <div class="chart-card">
                    <div class="d-flex justify-content-between align-items-end mb-2 flex-wrap gap-2">
                        <h3 class="chart-title m-0"><i class="bi bi-graph-up-arrow me-2"></i>Tren per Server</h3>
                        <div class="charts-toolbar">
                            <label for="histlen" class="small text-secondary me-1">Riwayat</label>
                            <select id="histlen" class="form-select form-select-sm">
                                <option value="60">60 detik</option>
                                <option value="120">120 detik</option>
                                <option value="300">300 detik</option>
                            </select>
                            <button id="btnExport" class="btn btn-outline-light btn-sm" type="button" title="Export PNG chart server aktif">
                                <i class="bi bi-download"></i>
                            </button>
                        </div>
                    </div>
                    <ul class="nav nav-pills server-tabs mb-2" id="serverTabs" role="tablist" aria-label="Server Timeseries Tabs"></ul>
                    <div id="serverCharts" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3 legend">
        <h2 class="h6 text-uppercase text-secondary mb-2">Keterangan</h2>
        <div class="d-flex gap-3 small">
            <div><span class="badge-dot" style="background:var(--ok)"></span>OK (&le;60%)</div>
            <div><span class="badge-dot" style="background:var(--warn)"></span>Waspada (61–80%)</div>
            <div><span class="badge-dot" style="background:var(--crit)"></span>Kritis (&gt;80%)</div>
        </div>
    </div>
</div>

<script>
    /* --------- Konstanta Refresh --------- */
    const REFRESH_MS = 1000;        // servers
    document.getElementById('autointv').textContent = (REFRESH_MS/1000) + 's';

    /* Element refs */
    const grid         = document.getElementById('grid');
    const lastUpdate   = document.getElementById('lastUpdate');
    const tableSection = document.getElementById('tableSection');
    const tableWrap    = document.getElementById('tableWrap');
    const tableFitBox  = document.getElementById('tableFitBox');
    const chartsSection= document.getElementById('chartsSection');

    let latestPayload = null;

    /* ====== Util ====== */
    function level(p){ if(p===null||p===undefined) return 'ok'; if(p>80) return 'crit'; if(p>60) return 'warn'; return 'ok'; }
    function pct(v){ return (v===null||v===undefined) ? '—' : (typeof v==='number' ? v.toFixed(0) : v) + '%'; }
    function text(x){ return (x===null||x===undefined||x==='') ? '—' : x; }
    function makeId(name){ return 'card_'+name.replace(/[^a-zA-Z0-9]+/g,'_'); }
    function sanitizeId(name){ return name.replace(/[^a-zA-Z0-9]+/g,'_'); }
    function nowStamp(){
        const d=new Date();
        const z=n=>String(n).padStart(2,'0');
        return `${d.getFullYear()}${z(d.getMonth()+1)}${z(d.getDate())}_${z(d.getHours())}${z(d.getMinutes())}${z(d.getSeconds())}`;
    }

    function humanBytes(bytes){
        if (typeof bytes!=='number' || isNaN(bytes) || bytes<=0) return null;
        const u = ['B','KB','MB','GB','TB','PB']; let i=0, b=bytes;
        while(b>=1024 && i<u.length-1){ b/=1024; i++; }
        return { value: b.toFixed(b>=100?0:(b>=10?1:2)), unit: u[i] };
    }
    function fromHumanObj(h){ if (!h || typeof h.value==='undefined' || !h.unit) return null; return (h.value + ' ' + h.unit); }

    function summarizeDisks(disks){
        if (!Array.isArray(disks) || disks.length===0) return null;
        let used=0, total=0, haveBytes=false;
        disks.forEach(d=>{
            if (typeof d.total_bytes==='number' && typeof d.used_bytes==='number'){ total+=d.total_bytes; used+=d.used_bytes; haveBytes=true; }
            else if (typeof d.total==='number' && typeof d.used==='number'){ total+=d.total; used+=d.used; haveBytes=true; }
        });
        if (haveBytes){
            const free = Math.max(total-used,0);
            const hUsed = humanBytes(used), hFree = humanBytes(free), hTot = humanBytes(total);
            const percent = total>0 ? Math.round((used/total)*100) : 0;
            return { used_h: hUsed?(hUsed.value+' '+hUsed.unit):'—', free_h: hFree?(hFree.value+' '+hFree.unit):'—', total_h: hTot?(hTot.value+' '+hTot.unit):'—', percent: percent };
        }
        const worst = disks.slice().sort((a,b)=>(b.percent||0)-(a.percent||0))[0];
        if (!worst) return null;
        return {
            used_h: fromHumanObj(worst.h_used) || '—',
            free_h: worst.h_total && worst.h_used ? ((worst.h_total.value - worst.h_used.value) + ' ' + worst.h_total.unit) : '—',
            total_h: fromHumanObj(worst.h_total) || '—',
            percent: typeof worst.percent==='number' ? Math.round(worst.percent) : null
        };
    }

    /* ====== RENDER KARTU ====== */
    function renderCards(payload){
        grid.innerHTML = '';

        (payload.servers||[]).forEach(sv=>{
            const s   = sv.data || {};
            const cpu = s.cpu || {};
            const mem = s.mem || {};
            const disks = s.disks || [];
            const dsum = summarizeDisks(disks);
            const cpuLvl = level(cpu.percent);
            const memLvl = level(mem.percent);
            const dskLvl = level(dsum ? dsum.percent : 0);

            const col = document.createElement('div');
            col.className = 'card server-card h-100';
            col.id = makeId(sv.name);
            col.innerHTML = `
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between">
          <div>
            <div class="badge-up">
              <span class="badge-dot" style="background:${sv.status==='ok' ? 'var(--ok)':'var(--crit)'}"></span>
              ${sv.status==='ok' ? 'Online' : 'Down'}
            </div>
            <h3 class="card-title h5 mt-1 mb-0">${sv.name}</h3>
            <div class="small">Uptime: ${text(s.uptime)}</div>
          </div>
          <div class="text-end small text-secondary"><i class="bi bi-clock me-1"></i>${text(s.ts)}</div>
        </div>

        <hr class="my-2">

        <div class="kv"><b>CPU</b><span>${pct(cpu.percent)}</span></div>
        <div class="progress"><div class="progress-bar ${cpuLvl}" role="progressbar" style="width:${cpu.percent||0}%"></div></div>
        <div class="small subnote">Core: ${text(cpu.cores)}</div>

        <div class="mt-2 kv"><b>RAM</b><span>${pct(mem.percent)}</span></div>
        <div class="progress"><div class="progress-bar ${memLvl}" role="progressbar" style="width:${mem.percent||0}%"></div></div>
        <div class="small subnote">
          ${fromHumanObj(mem.h_used) || (mem.used_h || '—')}
          / ${fromHumanObj(mem.h_free) || (mem.free_h || '—')}
          <span class="text-secondaryx"> • Total: ${fromHumanObj(mem.h_total) || (mem.total_h || '—')}</span>
        </div>

        <div class="mt-2 kv"><b>HDD</b><span>${dsum ? (dsum.percent + '%') : '—'}</span></div>
        <div class="progress"><div class="progress-bar ${dskLvl}" role="progressbar" style="width:${dsum ? dsum.percent : 0}%"></div></div>
        <div class="small subnote">
          ${dsum ? (''+ dsum.used_h +' / '+ dsum.free_h +' <span class="text-secondaryx"> • Total: '+ dsum.total_h +'</span>') : '—'}
        </div>
      </div>`;
            grid.appendChild(col);
        });

        fitCardsToViewport(); // 5x2 desktop
    }

    /* ====== AUTO-FIT KARTU KE VIEWPORT ====== */
    function getComputedColumns(){
        const styles = window.getComputedStyle(grid);
        const template = styles.getPropertyValue('grid-template-columns');
        return template ? template.split(' ').length : 1;
    }

    function fitCardsToViewport(){
        if (!grid || !grid.children.length) return;

        Array.from(grid.querySelectorAll('.server-card')).forEach(c=>{ c.style.height=''; });

        const desktopWide = window.matchMedia('(min-width: 1400px)').matches;
        if (!desktopWide){ return; }

        const cols = getComputedColumns(); // seharusnya 5
        const cards = Array.from(grid.querySelectorAll('.server-card'));
        const rowsDesired = 2;

        const vpH = window.innerHeight;
        const gridTop = grid.getBoundingClientRect().top;
        const legend = document.querySelector('.legend');
        const legendH = legend ? legend.getBoundingClientRect().height : 0;

        const available = Math.max(220, vpH - gridTop - legendH - 12);
        const styles = window.getComputedStyle(grid);
        const gap = parseFloat(styles.getPropertyValue('grid-row-gap')) || 12;

        const totalCards = cards.length;
        const maxCardsFit = cols * rowsDesired;

        if (totalCards <= maxCardsFit){
            const cardH = (available - gap*(rowsDesired-1)) / rowsDesired;
            cards.forEach(c=>{ c.style.height = Math.floor(cardH) + 'px'; });
        }
    }

    /* ====== TABEL ====== */
    function pctClass(v){ if(v==null||isNaN(v))return ''; if(v>80)return 'hl-crit'; if(v>60)return 'hl-warn'; return 'hl-ok'; }
    function renderTable(payload){
        if (!tableFitBox) return;
        const svs = payload.servers || [];

        let html = `
  <table class="table table-dark table-bordered table-compact table-sticky w-100 m-0">
    <colgroup>
      <col style="width:40px">
      <col style="width:22%">
      <col style="width:9%">
      <col style="width:11%">
      <col style="width:8%">
      <col style="width:7%">
      <col style="width:8%">
      <col style="width:10%">
      <col style="width:10%">
      <col style="width:10%">
      <col style="width:8%">
      <col style="width:10%">
      <col style="width:10%">
      <col style="width:10%">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2">#</th>
        <th rowspan="2">Server</th>
        <th rowspan="2">Status</th>
        <th rowspan="2">OS</th>
        <th colspan="2" class="text-center">CPU</th>
        <th colspan="4" class="text-center">RAM</th>
        <th colspan="4" class="text-center">HDD</th>
      </tr>
      <tr>
        <th class="text-center">CPU Load (%)</th><th class="text-center">Core</th>
        <th class="text-center">RAM Load (%)</th><th class="text-center">RAM Terpakai</th><th class="text-center">RAM Tersedia</th><th>RAM Total</th>
        <th class="text-center">HDD Load (%)</th><th class="text-center">HDD Terpakai</th><th class="text-center">HDD Tersedia</th><th class="text-center">HDD Total</th>
      </tr>
    </thead><tbody>`;

        svs.forEach((sv,i)=>{
            const s    = sv.data || {};
            const cpu  = s.cpu || {};
            const mem  = s.mem || {};
            const dsum = summarizeDisks(s.disks || []);

            const cpuPct = (cpu.percent!=null ? Math.round(cpu.percent) : null);
            const ramPct = (mem.percent!=null ? Math.round(mem.percent) : null);
            const dskPct = (dsum ? dsum.percent : null);

            html += `
      <tr>
        <td class="td-num">${i+1}</td>

        <td class="td-num">
          ${sv.name}
          <div class="text-secondary small">${text(s.ip)}</div>
        </td>

        <td align="right" class="td-num">
          <span class="dot ${sv.status==='ok'?'ok':'crit'}"></span>${sv.status==='ok'?'Online':'Down'}
        </td>

        <td class="td-numx">${text(s.os)}</td>

        <td align="right" class="td-num ${pctClass(cpuPct)}">${cpuPct!=null?cpuPct:'—'} %</td>
        <td align="right" class="td-num hl-core">${text(cpu.cores)}</td>

        <td align="right" class="td-num ${pctClass(ramPct)}">${ramPct!=null?ramPct:'—'} %</td>
        <td align="right" class="td-num">${fromHumanObj(mem.h_used) || (mem.used_h || '—')}</td>
        <td align="right" class="td-num">${fromHumanObj(mem.h_free) || (mem.free_h || '—')}</td>
        <td align="right" class="td-num">${fromHumanObj(mem.h_total) || (mem.total_h || '—')}</td>

        <td align="right" class="td-num ${pctClass(dskPct)}">${dskPct!=null?dskPct:'—'} %</td>
        <td align="right" class="td-num">${dsum? dsum.used_h : '—'}</td>
        <td align="right" class="td-num">${dsum? dsum.free_h : '—'}</td>
        <td align="right" class="td-num">${dsum? dsum.total_h : '—'}</td>
      </tr>`;
        });

        html += `</tbody></table>`;
        tableFitBox.innerHTML = html;

        if (!isTableHidden()) { resetTableScale(); sizeTableWrap(); fitTable(); }
    }

    function isTableHidden(){
        const cs = getComputedStyle(tableSection);
        return cs.display==='none' || cs.visibility==='hidden';
    }
    function resetTableScale(){
        if (tableFitBox) tableFitBox.style.transform = 'none';
    }
    function sizeTableWrap(){
        if (!tableWrap) return;
        const top = tableWrap.getBoundingClientRect().top;
        const h   = window.innerHeight - top - 16;
        tableWrap.style.height = (h>120 ? h : 120) + 'px';
        tableWrap.style.overflowY = 'auto';
        tableWrap.style.overflowX = 'hidden';
    }

    function fitTable(){
        if (!tableWrap || !tableFitBox) return;
        tableWrap.classList.remove('responsive');
        tableFitBox.style.transform = 'none';
    }
    function fitTableLater(){ setTimeout(()=>{ if(!isTableHidden()){ sizeTableWrap(); fitTable(); }}, 80); }

    /* ====== GRAFIK ====== */
    let CHART_barServer=null;
    function ensureCharts(){
        if (CHART_barServer) return;

        const ctxBar = document.getElementById('barServer').getContext('2d');
        CHART_barServer = new Chart(ctxBar, {
            type: 'bar',
            data: { labels: [], datasets: [
                    { label: 'CPU %', data: [], borderWidth: 1 },
                    { label: 'RAM %', data: [], borderWidth: 1 },
                    { label: 'HDD %', data: [], borderWidth: 1 },
                ]},
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { ticks: { color: '#cfd6e4' }, grid: { display:false } },
                    y: { min:0, max:100, ticks:{ stepSize:20, color:'#cfd6e4' } }
                },
                plugins: {
                    legend: { labels:{ color:'#e6eefc' } },
                    tooltip: { mode:'index', intersect:false }
                }
            }
        });
    }

    function fillBarServer(payload){
        if (!CHART_barServer) return;
        const svs = payload.servers || [];
        const labels = [];
        const cpu = [], ram = [], hdd = [];
        svs.forEach(sv=>{
            const s = sv.data || {};
            const dsum = summarizeDisks(s.disks || []);
            labels.push(sv.name);
            cpu.push( (s.cpu && typeof s.cpu.percent==='number') ? Math.max(0,Math.min(100,Math.round(s.cpu.percent))) : null );
            ram.push( (s.mem && typeof s.mem.percent==='number') ? Math.max(0,Math.min(100,Math.round(s.mem.percent))) : null );
            hdd.push( (dsum && typeof dsum.percent==='number') ? Math.max(0,Math.min(100,Math.round(dsum.percent))) : null );
        });
        CHART_barServer.data.labels = labels;
        CHART_barServer.data.datasets[0].data = cpu;
        CHART_barServer.data.datasets[1].data = ram;
        CHART_barServer.data.datasets[2].data = hdd;
        CHART_barServer.update('none');
    }

    /* ====== PER-SERVER TIMESERIES (TABS) ====== */
    const SERVER_HISTORY = {}; // name -> {labels:[], cpu:[], ram:[], hdd:[]}
    const SERVER_CHARTS  = {}; // name -> Chart instance
    let   SERVER_LIST    = []; // cached order
    let   ACTIVE_SERVER  = null;

    // Durasi riwayat dinamis (default 60 detik), simpan preferensi
    let HISTORY_POINTS = parseInt(localStorage.getItem('sitmon_history_points') || '60', 10);
    function setHistoryPoints(n){
        HISTORY_POINTS = Math.max(10, parseInt(n||60,10));
        try{ localStorage.setItem('sitmon_history_points', HISTORY_POINTS); }catch(e){}
        // pangkas masing-masing history agar sesuai batas baru
        Object.keys(SERVER_HISTORY).forEach(name=>{
            const H = SERVER_HISTORY[name];
            while (H.labels.length > HISTORY_POINTS){
                H.labels.shift(); H.cpu.shift(); H.ram.shift(); H.hdd.shift();
            }
        });
        // refresh chart aktif
        updateAllServerCharts();
        // set label dropdown
        const sel = document.getElementById('histlen');
        if (sel) sel.value = String(HISTORY_POINTS);
    }

    function ensureServerTabsAndCanvases(payload){
        const svs = payload.servers || [];
        const names = svs.map(sv=>sv.name);
        if (JSON.stringify(names) === JSON.stringify(SERVER_LIST)) return;

        SERVER_LIST = names.slice();
        const tabs = document.getElementById('serverTabs');
        const panes= document.getElementById('serverCharts');
        if (!tabs || !panes) return;

        tabs.innerHTML = '';
        panes.innerHTML = '';
        names.forEach((name)=>{
            const id = sanitizeId(name);
            // tab
            const li = document.createElement('li');
            li.className = 'nav-item';
            const btn = document.createElement('button');
            btn.className = 'nav-link btn btn-sm btn-outline-light';
            btn.setAttribute('type','button');
            btn.dataset.serverName = name;
            btn.textContent = name;
            btn.addEventListener('click', ()=> setActiveServer(name));
            li.appendChild(btn);
            tabs.appendChild(li);
            // pane
            const pane = document.createElement('div');
            pane.className = 'server-tab-pane';
            pane.id = 'pane_'+id;
            pane.innerHTML = `<div class="chart-box"><canvas id="svChart_${id}"></canvas></div>`;
            panes.appendChild(pane);

            // init history
            if (!SERVER_HISTORY[name]){
                SERVER_HISTORY[name] = { labels:[], cpu:[], ram:[], hdd:[] };
            }
        });

        // default active first tab
        setActiveServer(names[0] || null);

        // create charts for all canvases
        names.forEach(name=> ensureServerChart(name));
    }

    function setActiveServer(name){
        ACTIVE_SERVER = name;
        const tabs = document.querySelectorAll('#serverTabs .nav-link');
        tabs.forEach(el=> el.classList.toggle('active', el.dataset.serverName===name));
        const names = SERVER_LIST;
        names.forEach(n=>{
            const pane = document.getElementById('pane_'+sanitizeId(n));
            if (pane) pane.classList.toggle('active', n===name);
        });
        if (name && SERVER_CHARTS[name]) {
            SERVER_CHARTS[name].resize();
            SERVER_CHARTS[name].update('none');
        }
        // toggle tombol export
        const btn = document.getElementById('btnExport');
        if (btn) btn.disabled = !name;
    }

    function ensureServerChart(name){
        if (SERVER_CHARTS[name]) return;
        const id = sanitizeId(name);
        const ctx = document.getElementById('svChart_'+id);
        if (!ctx) return;
        SERVER_CHARTS[name] = new Chart(ctx.getContext('2d'), {
            type:'line',
            data: {
                labels: [],
                datasets: [
                    { label:'CPU %', data: [], tension:.3, fill:false, borderWidth:2 },
                    { label:'RAM %', data: [], tension:.3, fill:false, borderWidth:2 },
                    { label:'HDD %', data: [], tension:.3, fill:false, borderWidth:2 },
                ]
            },
            options: {
                responsive:true, maintainAspectRatio:false,
                scales:{
                    x:{ ticks:{ color:'#cfd6e4', maxRotation:0 }, grid:{ display:false } },
                    y:{ min:0, max:100, ticks:{ stepSize:20, color:'#cfd6e4' } }
                },
                plugins:{ legend:{ labels:{ color:'#e6eefc' } }, tooltip:{ mode:'index', intersect:false } }
            }
        });
        applyServerHistoryToChart(name);
    }

    function pushServerHistoryPoint(payload){
        const ts = payload.generated_at || new Date().toLocaleTimeString();
        (payload.servers||[]).forEach(sv=>{
            const name = sv.name;
            const s = sv.data || {};
            const cpu = (s.cpu && typeof s.cpu.percent==='number') ? Math.round(s.cpu.percent) : null;
            const ram = (s.mem && typeof s.mem.percent==='number') ? Math.round(s.mem.percent) : null;
            const dsum = summarizeDisks(s.disks || []);
            const hdd = (dsum && typeof dsum.percent==='number') ? Math.round(dsum.percent) : null;

            if (!SERVER_HISTORY[name]) SERVER_HISTORY[name] = { labels:[], cpu:[], ram:[], hdd:[] };
            const H = SERVER_HISTORY[name];

            H.labels.push(ts);
            H.cpu.push(cpu);
            H.ram.push(ram);
            H.hdd.push(hdd);
            while (H.labels.length > HISTORY_POINTS){
                H.labels.shift(); H.cpu.shift(); H.ram.shift(); H.hdd.shift();
            }
        });
    }

    function applyServerHistoryToChart(name){
        const chart = SERVER_CHARTS[name];
        const H = SERVER_HISTORY[name];
        if (!chart || !H) return;
        chart.data.labels = H.labels.slice();
        chart.data.datasets[0].data = H.cpu.slice();
        chart.data.datasets[1].data = H.ram.slice();
        chart.data.datasets[2].data = H.hdd.slice();
        chart.update('none');
    }

    function updateAllServerCharts(){
        SERVER_LIST.forEach(name=>{
            ensureServerChart(name);
            applyServerHistoryToChart(name);
        });
    }

    function renderCharts(payload){
        ensureCharts();
        ensureServerTabsAndCanvases(payload);
        fillBarServer(payload);
        pushServerHistoryPoint(payload);
        updateAllServerCharts();
    }

    /* ====== Export PNG untuk chart per-server ====== */
    function exportActiveServerPNG(){
        if (!ACTIVE_SERVER) return;
        const chart = SERVER_CHARTS[ACTIVE_SERVER];
        if (!chart) return;
        const link = document.createElement('a');
        const id = sanitizeId(ACTIVE_SERVER);
        link.download = `sitmon_${id}_${nowStamp()}.png`;
        link.href = chart.canvas.toDataURL('image/png');
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    /* ====== Refresh ====== */
    function refreshNow(){
        fetch(location.pathname + '?action=data', {cache:'no-store'})
            .then(r=>r.json())
            .then(j=>{
                latestPayload = j;
                lastUpdate.textContent = j.generated_at || '—';
                const vm = getViewMode();
                if      (vm==='table')  renderTable(j);
                else if (vm==='charts') renderCharts(j);
                else                    renderCards(j);
            })
            .catch(_=>{ lastUpdate.textContent = 'gagal mengambil data'; });
    }

    /* ====== View Toggle ====== */
    function getViewMode(){
        const saved = localStorage.getItem('sitmon_view');
        if (saved === 'table' || saved === 'cards' || saved==='charts') return saved || 'cards';
        const rTable = document.getElementById('view_table');
        const rCharts= document.getElementById('view_charts');
        if (rTable && rTable.checked) return 'table';
        if (rCharts && rCharts.checked) return 'charts';
        return 'cards';
    }
    function showView(mode){
        localStorage.setItem('sitmon_view', mode);
        const isTable  = (mode === 'table');
        const isCharts = (mode === 'charts');

        grid.style.display          = (!isTable && !isCharts) ? 'grid'  : 'none';
        tableSection.style.display  = isTable  ? 'block' : 'none';
        chartsSection.style.display = isCharts ? 'block' : 'none';

        document.body.classList.toggle('mode-table',  isTable);
        document.body.classList.toggle('mode-charts', isCharts);

        if (isTable){
            resetTableScale(); if (latestPayload) renderTable(latestPayload); fitTableLater();
        } else if (isCharts){
            ensureCharts(); if (latestPayload) renderCharts(latestPayload);
        } else {
            if (latestPayload) renderCards(latestPayload);
            setTimeout(fitCardsToViewport, 50);
        }
    }
    function initViewToggle(){
        const rCards  = document.getElementById('view_cards');
        const rTable  = document.getElementById('view_table');
        const rCharts = document.getElementById('view_charts');
        const saved   = localStorage.getItem('sitmon_view') || 'cards';

        if (rCards)  rCards.checked  = (saved === 'cards');
        if (rTable)  rTable.checked  = (saved === 'table');
        if (rCharts) rCharts.checked = (saved === 'charts');

        showView(saved);

        if (rCards)  rCards.addEventListener('change', ()=> rCards.checked  && showView('cards'));
        if (rTable)  rTable.addEventListener('change', ()=> rTable.checked  && showView('table'));
        if (rCharts) rCharts.addEventListener('change',()=> rCharts.checked && showView('charts'));
    }

    /* ====== Init ====== */
    initViewToggle();
    initCols();

    // Set riwayat sesuai preferensi tersimpan
    setHistoryPoints(HISTORY_POINTS);
    const selHist = document.getElementById('histlen');
    if (selHist){ selHist.value = String(HISTORY_POINTS); selHist.addEventListener('change', e=> setHistoryPoints(e.target.value)); }

    // Tombol export PNG per-server
    const btnExport = document.getElementById('btnExport');
    if (btnExport){ btnExport.addEventListener('click', exportActiveServerPNG); btnExport.disabled = true; }

    refreshNow();
    setInterval(refreshNow, REFRESH_MS);

    // Refit saat resize/orientasi/visibilitas
    window.addEventListener('resize', ()=>{ fitCardsToViewport(); fitTableLater(); if (ACTIVE_SERVER && SERVER_CHARTS[ACTIVE_SERVER]) SERVER_CHARTS[ACTIVE_SERVER].resize(); });
    window.addEventListener('orientationchange', ()=>{ fitCardsToViewport(); fitTableLater(); if (ACTIVE_SERVER && SERVER_CHARTS[ACTIVE_SERVER]) SERVER_CHARTS[ACTIVE_SERVER].resize(); });
    document.addEventListener('visibilitychange', ()=>{ if(!document.hidden){ fitCardsToViewport(); fitTableLater(); if (ACTIVE_SERVER && SERVER_CHARTS[ACTIVE_SERVER]) SERVER_CHARTS[ACTIVE_SERVER].resize(); }});
    document.fonts && document.fonts.ready && document.fonts.ready.then(()=>{ fitCardsToViewport(); fitTableLater(); if (ACTIVE_SERVER && SERVER_CHARTS[ACTIVE_SERVER]) SERVER_CHARTS[ACTIVE_SERVER].resize(); });

    /* ====== Kolom (3/4/5) ====== */
    function applyCols(cols){
        document.body.setAttribute('data-cols', cols);
        try{ localStorage.setItem('sitmon_cols', cols); }catch(e){}
        fitCardsToViewport();
        if (getViewMode()==='table'){ fitTableLater(); }
    }
    function initCols(){
        const saved = (localStorage.getItem('sitmon_cols') || '5');
        applyCols(saved);
        const r3 = document.getElementById('cols_3');
        const r4 = document.getElementById('cols_4');
        const r5 = document.getElementById('cols_5');
        if (r3) r3.checked = (saved === '3');
        if (r4) r4.checked = (saved === '4');
        if (r5) r5.checked = (saved === '5');
        if (r3) r3.addEventListener('change', ()=> r3.checked && applyCols('3'));
        if (r4) r4.addEventListener('change', ()=> r4.checked && applyCols('4'));
        if (r5) r5.addEventListener('change', ()=> r5.checked && applyCols('5'));
    }
</script>
</body>
</html>
