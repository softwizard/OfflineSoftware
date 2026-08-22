<#
.SYNOPSIS
    S6-07 restore — load a backup-mysql.ps1 dump into a target database.

.DESCRIPTION
    Creates TargetDatabase if it doesn't exist, then replays the dump into
    it. The dump file itself contains its own `CREATE DATABASE` /
    `USE <original-name>` statements (from --databases in
    backup-mysql.ps1), so this only needs to hand the file to the mysql
    client — it does not need to pre-select a database. Point
    -TargetDatabase at a *different* name than production when rehearsing
    a restore (see docs/backup-restore-procedure.md) so a bad dump can
    never overwrite a live database.

.PARAMETER DumpFile
    A .sql or .sql.zip produced by backup-mysql.ps1.

.EXAMPLE
    pwsh scripts/restore-mysql.ps1 -DumpFile backups/softv_off_online_cloud_2026-08-24_020000.sql.zip -TargetDatabase softv_off_online_cloud_restore_test
#>
param(
    [Parameter(Mandatory = $true)][string]$DumpFile,
    [Parameter(Mandatory = $true)][string]$TargetDatabase,
    [string]$MysqlBinDir = 'c:/xampp/mysql/bin',
    [string]$User = 'root',
    [string]$Password = ''
)

$ErrorActionPreference = 'Stop'

$mysql = Join-Path $MysqlBinDir 'mysql.exe'
$passArg = if ($Password) { "-p$Password" } else { $null }

$sqlPath = $DumpFile
$extractedDir = $null

if ($DumpFile -like '*.zip') {
    $extractedDir = Join-Path ([System.IO.Path]::GetTempPath()) ([System.Guid]::NewGuid().ToString())
    Expand-Archive -Path $DumpFile -DestinationPath $extractedDir -Force
    $sqlPath = (Get-ChildItem -Path $extractedDir -Filter '*.sql' | Select-Object -First 1).FullName
}

try {
    $createArgs = @('-u', $User)
    if ($passArg) { $createArgs += $passArg }
    $createArgs += @('-e', "CREATE DATABASE IF NOT EXISTS ``$TargetDatabase``")
    & $mysql @createArgs
    if ($LASTEXITCODE -ne 0) { throw "Failed to create database [$TargetDatabase]" }

    # The dump's own `USE <original-name>` line, not this arg, decides
    # which schema the statements actually run against when the dump was
    # produced with --databases — so restoring into a *different*
    # TargetDatabase name means editing that line, not just naming the
    # target here. backup-restore-procedure.md's rehearsal step does this
    # with a plain text substitution before replay.
    $restoreArgs = @('-u', $User)
    if ($passArg) { $restoreArgs += $passArg }
    $restoreArgs += $TargetDatabase

    Write-Host "Restoring $sqlPath into [$TargetDatabase] ..."
    Get-Content $sqlPath -Raw | & $mysql @restoreArgs
    if ($LASTEXITCODE -ne 0) { throw "mysql restore exited with code $LASTEXITCODE" }

    Write-Host "Restore complete into [$TargetDatabase]."
} finally {
    if ($extractedDir -and (Test-Path $extractedDir)) {
        Remove-Item $extractedDir -Recurse -Force
    }
}
