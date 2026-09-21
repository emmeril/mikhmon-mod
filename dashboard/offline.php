<?php
$offlineSession = isset($session) ? (string) $session : '';
$offlineRetryUrl = './?session=' . rawurlencode($offlineSession);
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><h3><i class="fa fa-exclamation-triangle text-warning"></i> Router MikroTik tidak terhubung</h3></div>
      <div class="card-body">
        <p>Aplikasi tetap aktif, tetapi halaman yang membutuhkan data langsung dari router dihentikan sementara agar server tidak ikut lambat.</p>
        <p>Periksa koneksi jaringan/VPN, alamat IP, layanan API RouterOS, username, dan password. Percobaan koneksi berikutnya tersedia otomatis setelah sekitar 30 detik.</p>
        <a class="btn bg-primary" href="<?= htmlspecialchars($offlineRetryUrl, ENT_QUOTES); ?>"><i class="fa fa-refresh"></i> Coba Hubungkan Lagi</a>
        <?php if (function_exists('mikhmonIsAdmin') && mikhmonIsAdmin()): ?>
          <a class="btn bg-grey" href="./?admin=routers&amp;session=<?= rawurlencode($offlineSession); ?>"><i class="fa fa-cog"></i> Pengaturan Router</a>
          <a class="btn bg-grey" href="./?report=systemlog&amp;session=<?= rawurlencode($offlineSession); ?>"><i class="fa fa-list"></i> Log Aplikasi</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
