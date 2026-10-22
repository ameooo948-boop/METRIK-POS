# Composer platform check troubleshooting

The release validation script runs `composer check-platform-reqs --no-dev --no-interaction -vvv`.

If that check fails, look for a package requirement that reports `missing` or an installed version that does not satisfy a requirement. Common examples include a missing PHP extension or a CLI PHP version that differs from the version used by the web server. Do not use `--ignore-platform-reqs` to make a release check pass.

If PowerShell stops after Composer prints only its progress heading, the script disables PowerShell's optional native-command stderr promotion (when that setting exists) and relies on Composer's exit code instead. To diagnose the environment separately, run from the project root:

```powershell
php -v
composer check-platform-reqs --no-dev -vvv
composer diagnose
```

Share the command output if the requirement failure remains; do not share `.env` contents, passwords, API keys, or other secrets.
