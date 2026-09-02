<?php
include_once(__DIR__ . '/../include/systemlog.php');
$isSystemLogPage = isset($report) && $report === 'systemlog';
$systemLogs = mikhmonReadSystemLogs($isSystemLogPage ? 200 : 20);
$systemLogMaxHeight = $isSystemLogPage ? '75vh' : '320px';
$systemLogFormatTime = function ($systemLog) {
  if ((int) ($systemLog['timestamp'] ?? 0) <= 0) return '-';
  try {
    return (new DateTime('@' . (int) $systemLog['timestamp']))
      ->setTimezone(new DateTimeZone($systemLog['timezone'] ?? mikhmonSystemLogTimezone()))
      ->format('Y-m-d H:i:s');
  } catch (Exception $exception) {
    return date('Y-m-d H:i:s', (int) $systemLog['timestamp']);
  }
};
$systemLogLevelClasses = array(
  'success' => 'text-success',
  'warning' => 'text-warning',
  'error' => 'text-danger',
  'info' => 'text-info',
);
?>
<div class="card">
  <div class="card-header">
    <h3><i class=" fa fa-align-justify"></i> System Logs Aplikasi &nbsp; | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer" title="Reload data"></i></h3>
  </div>
  <div class="card-body">
    <div style="padding: 5px; max-height: <?= $systemLogMaxHeight; ?>;" class="mr-t-10 overflow">
      <table class="table table-sm table-bordered table-hover" style="font-size: 12px;" id="dataTable">
        <thead>
          <tr><th>Waktu</th><th>Level</th><th>Aktivitas</th><th>Pengguna</th></tr>
        </thead>
        <tbody>
        <?php if (!$systemLogs): ?>
          <tr><td colspan="4" class="text-center">Belum ada aktivitas aplikasi yang tercatat.</td></tr>
        <?php else: foreach ($systemLogs as $systemLog): ?>
          <?php $levelClass = $systemLogLevelClasses[$systemLog['level']] ?? 'text-info'; ?>
          <tr>
            <td class="text-nowrap"><?= htmlspecialchars($systemLogFormatTime($systemLog), ENT_QUOTES); ?></td>
            <td class="<?= $levelClass; ?>"><?= strtoupper(htmlspecialchars($systemLog['level'], ENT_QUOTES)); ?></td>
            <td><b><?= htmlspecialchars($systemLog['category'], ENT_QUOTES); ?></b><br><?= htmlspecialchars($systemLog['message'], ENT_QUOTES); ?><?php if ($systemLog['session'] !== ''): ?><br><small>Router: <?= htmlspecialchars($systemLog['session'], ENT_QUOTES); ?></small><?php elseif ($systemLog['role'] === 'admin'): ?><br><small>Router: Semua Router</small><?php endif; ?></td>
            <td><?= htmlspecialchars($systemLog['user'], ENT_QUOTES); ?><br><small><?= strtoupper(htmlspecialchars($systemLog['role'], ENT_QUOTES)); ?><?= $systemLog['ip'] !== '' ? ' &middot; ' . htmlspecialchars($systemLog['ip'], ENT_QUOTES) : ''; ?></small></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
