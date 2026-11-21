# P3H.5: safe test runner. Does not run the real MySQL race suite.
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$ProjectPath,
    [string]$TestDatabase = 'bablys_suite_clean_test',
    [switch]$FullSuite,
    [switch]$QualityGates
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if ($TestDatabase -cnotmatch '^[A-Za-z][A-Za-z0-9_]*_test$' -or $TestDatabase.EndsWith('race_test')) {
    throw 'Specify a dedicated non-race MySQL *_test database.'
}
$project = (Resolve-Path -LiteralPath $ProjectPath).ProviderPath
if (-not (Test-Path -LiteralPath (Join-Path $project 'artisan') -PathType Leaf)) {
    throw 'ProjectPath must be the Bablys Laravel application root.'
}
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { throw 'PHP executable not found on PATH.' }
Push-Location $project
try {
    $env:APP_ENV = 'testing'
    $env:DB_CONNECTION = 'mysql'
    $env:DB_DATABASE = $TestDatabase
    if (-not $env:DB_HOST) { $env:DB_HOST = '127.0.0.1' }
    if (-not $env:DB_PORT) { $env:DB_PORT = '3306' }
    $env:BABLYS_REAL_MYSQL_RACE = ''

    # Laravel must use the same test DB that the runner was asked to check.
    # Note: phpunit.xml may specify a test environment different from Tinker;
    # each behavioral test independently checks its environment before setup.
    $probeExpression = 'echo app()->environment()." | ".DB::connection()->getDriverName()." | ".DB::connection()->getDatabaseName();'
    $probe = (& php artisan tinker "--execute=$probeExpression" 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0) { throw "Unable to verify Laravel database environment: $probe" }
    $expected = 'testing | mysql | ' + $TestDatabase
    if ($probe -notlike ('*' + $expected + '*')) { throw "Unsafe Laravel environment: $probe; expected $expected" }
    Write-Host "Verified isolated Laravel environment: $expected"
    Write-Host 'WARNING: The configured test database is disposable and may be migrated/reset by tests.'

    foreach ($filter in @(
        'BookingCustomerAdminReleaseJourneyTest',
        'BookingReleaseSecurityPrivacyTest',
        'BookingReleaseSourceContractTest',
        'CustomerTravelAccountTest',
        'AdminBookingActionControlsTest',
        'AdminBookingDashboardReadonlyTest',
        'PublicTourSeatAvailabilityTest',
        'PublicBookingEnquiryJourneyTest'
    )) {
        Write-Host "Running focused test: $filter"
        & php artisan test "--filter=$filter" --stop-on-failure
        if ($LASTEXITCODE -ne 0) { throw "Focused test suite failed: $filter" }
    }
    if ($FullSuite) {
        Write-Host 'Running full Laravel suite (real race mode disabled).'
        & php artisan test
        if ($LASTEXITCODE -ne 0) { throw 'Full Laravel suite failed.' }
    }
    if ($QualityGates) {
        & php vendor/bin/phpstan analyse
        if ($LASTEXITCODE -ne 0) { throw 'PHPStan failed.' }
        if (-not (Get-Command npm.cmd -ErrorAction SilentlyContinue)) { throw 'npm.cmd not found on PATH.' }
        & npm.cmd run types:check
        if ($LASTEXITCODE -ne 0) { throw 'TypeScript typecheck failed.' }
        & npm.cmd run build
        if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' }
    }
    Write-Host 'P3H.5 selected gates PASS. Complete manual browser/security acceptance separately.'
} finally {
    Pop-Location
}
