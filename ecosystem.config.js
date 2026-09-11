const path = require("path");
const os = require("os");

const appRoot = __dirname;
const host = process.env.MIKHMON_HOST || "0.0.0.0";
const port = process.env.MIKHMON_PORT || "80";
const cpuCount = typeof os.availableParallelism === "function"
  ? os.availableParallelism()
  : os.cpus().length;
const workerCount = process.env.MIKHMON_WORKERS
  || String(Math.max(2, Math.min(cpuCount, 8)));
const phpMemoryLimit = process.env.MIKHMON_PHP_MEMORY_LIMIT || "256M";

const phpOptions = [
  "-d opcache.enable_cli=1",
  "-d opcache.memory_consumption=128",
  "-d opcache.interned_strings_buffer=16",
  "-d opcache.max_accelerated_files=10000",
  "-d opcache.validate_timestamps=1",
  "-d opcache.revalidate_freq=2",
  "-d realpath_cache_size=4096K",
  "-d realpath_cache_ttl=600",
  `-d memory_limit=${phpMemoryLimit}`,
];

module.exports = {
  apps: [
    {
      name: "mikhmon",
      cwd: appRoot,
      script: path.join(appRoot, "server.php"),
      interpreter: "php",
      interpreter_args: `${phpOptions.join(" ")} -S ${host}:${port}`,
      exec_mode: "fork",
      instances: 1,
      autorestart: true,
      watch: false,
      restart_delay: 3000,
      max_memory_restart: "256M",
      kill_timeout: 5000,
      time: false,
      env: {
        PHP_CLI_SERVER_WORKERS: workerCount,
      },
      env_production: {
        APP_ENV: "production",
      },
    },
  ],
};
