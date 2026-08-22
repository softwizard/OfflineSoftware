<#
.SYNOPSIS
    S6-07 nightly backup — mysqldump a syncable-server database to a
    timestamped, compressed file and prune anything past retention.

.DESCRIPTION
    --single-transaction takes a consistent InnoDB snapshot without
    locking tables — the server keeps accepting sync traffic (push/pull)
    while this runs. See docs/backup-restore-procedure.md for the
    restore side, RPO/RTO, and how to schedule this.

.PARAMETER Database
    Defaults to this project's cloud database.

.PARAMETER OutputDir
    Where dump files land. Defaults to a `backups` folder next to this
    script (outside the repo's git tree — see .gitignore).

.PARAMETER RetentionDays
    Dumps older than this are deleted after a successful new dump.

.EXAMPLE
    pwsh scripts/backup-mysql.ps1
    pwsh scripts/backup-mysql.ps1 -Database softv_off_online_cloud -RetentionDays 14
#>
param(
    [string]$Database = 'softv_off_online_cloud',
    [string]$MysqlBinDir = 'c:/xampp/mysql/bin',
    [string]$OutputDir = (Join-Path $PSScriptRoot 'backups'),
    [string]$User = 'root',
    [string]$Password = '',
    [int]$RetentionDays = 14
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

$timestamp = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$dumpFile = Join-Path $OutputDir "$Database`_$timestamp.sql"

$mysqldump = Join-Path $MysqlBinDir 'mysqldump.exe'
$passArg = if ($Password) { "-p$Password" } else { $null }

$args = @('-u', $User)
if ($passArg) { $args += $passArg }
$args += @('--single-transaction', '--routines', '--triggers', '--databases', $Database)

Write-Host "Dumping [$Database] to $dumpFile ..."
& $mysqldump @args | Out-File -FilePath $dumpFile -Encoding utf8

if ($LASTEXITCODE -ne 0) {
    throw "mysqldump exited with code $LASTEXITCODE"
}

Compress-Archive -Path $dumpFile -DestinationPath "$dumpFile.zip" -Force
Remove-Item $dumpFile

Write-Host "Backup complete: $dumpFile.zip"

Get-ChildItem -Path $OutputDir -Filter "$Database`_*.sql.zip" |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$RetentionDays) } |
    ForEach-Object {
        Write-Host "Pruning old backup: $($_.Name)"
        Remove-Item $_.FullName -Force
    }
