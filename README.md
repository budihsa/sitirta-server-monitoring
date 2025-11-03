# sitirta-server-monitoring
Sitirta Monitoring System

Requirement:
- Web server: Nginx atau Apache.
- PHP 5.6 (atau kompatibel) dengan ext-curl aktif.
- Akses HTTP ke tiap server/agent: http://IP/sitmon_agent.php?token=...
- Internet outbound (untuk CDN Bootstrap, Bootstrap Icons, Chart.js), atau siapkan mirror lokal bila lingkungan offline.

Konfigurasi:
/var/www/servermon/
└── index.php   ← file dashboard

Edit bagian ini pada index.php:
$SERVERS = array(
    array('name' => 'Nama Server 1',  'url' => 'http://isi_dengan_ip_server/server_agent.php',  'token' => 'IsiDenganTokenRahasia'),
    ...
);
