const path = require("path");

const appRoot = __dirname;
const host = process.env.MIKHMON_HOST || "0.0.0.0";
const port = process.env.MIKHMON_PORT || "80";

module.exports = {
  apps: [
    {
      name: "mikhmon",
      cwd: appRoot,
      script: path.join(appRoot, "server.php"),
      interpreter: "php",
      interpreter_args: `-S ${host}:${port}`,
      exec_mode: "fork",
      instances: 1,
      autorestart: true,
      watch: false,
      restart_delay: 3000,
      max_memory_restart: "256M",
      kill_timeout: 5000,
      time: true,
      env_production: {
        APP_ENV: "production",
        PHP_CLI_SERVER_WORKERS: "4",
      },
    },
  ],
};
