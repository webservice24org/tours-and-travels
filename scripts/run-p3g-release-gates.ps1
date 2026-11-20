# P3G — guarded local release QA runner. PowerShell 5.1+. Never runs migrations directly.
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$ProjectPath,
    [string]$TestDatabase = 'bablys_suite_clean_test',
    [switch]$FullSuite,
    [switch]$QualityGates
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if ($TestDatabase -notmatch '^[a-zA-Z][a-zA-Z0-9_]*_test$' -or $TestDatabase -match 'race_test$') {
    throw 'Use an explicitly dedicated non-race MySQL test DB ending in _test.'
}
$project = [System.IO.Path]::GetFullPath($ProjectPath)
if (-not (Test-Path -LiteralPath (Join-Path $project 'artisan') -PathType Leaf)) {
    throw 'Laravel artisan not found in ProjectPath.'
}
# Inherited only by this PowerShell process and its child processes; does not alter .env.
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = $TestDatabase
$env:BABLYS_REAL_MYSQL_RACE = ''
if (-not $env:DB_HOST) { $env:DB_HOST = '127.0.0.1' }
if (-not $env:DB_PORT) { $env:DB_PORT = '3306' }
if (-not $env:DB_USERNAME) { throw 'Set DB_USERNAME in this PowerShell process before running.' }
Push-Location -LiteralPath $project
try {
    # Fails closed when cached CLI config points to development DB. PHPUnit may
    # independently override it: review phpunit.xml / .env.testing before use.
    $connection = (& php artisan tinker '--execute=echo DB::connection()->getDatabaseName();' 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $connection -ne $TestDatabase) {
        throw "Refusing QA: effective CLI connection '$connection', expected $TestDatabase."
    }
    Write-Host "Verified ordinary test database $TestDatabase (not the race database)."
    Write-Host 'IMPORTANT: check phpunit.xml and .env.testing for database overrides before continuing.'
    foreach ($name in @('PublicTourReleaseJourneyTest', 'PublicTourReleaseSecurityBoundaryTest', 'PublicTourReleaseSourceContractTest')) {
        Write-Host "Running $name"
        & php artisan test "--filter=$name" --stop-on-failure
        if ($LASTEXITCODE -ne 0) { throw "QA failed: $name" }
    }
    if ($FullSuite) {
        & php artisan test
        if ($LASTEXITCODE -ne 0) { throw 'Full Laravel suite failed.' }
    }
    if ($QualityGates) {
        & php 'vendor/bin/phpstan' analyse
        if ($LASTEXITCODE -ne 0) { throw 'PHPStan failed.' }
        & npm.cmd run types:check
        if ($LASTEXITCODE -ne 0) { throw 'TypeScript type check failed.' }
        & npm.cmd run build
        if ($LASTEXITCODE -ne 0) { throw 'Vite production build failed.' }
    }
    Write-Host 'Requested P3G automated gates passed. Manual browser/privacy/SEO acceptance is still required.'
} finally {
    Pop-Location
}
