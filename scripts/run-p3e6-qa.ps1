# P3E.6: explicit safe QA launcher for the ordinary ISOLATED test DB.
# Never launches concurrency test or migrations, and does not change env variables.
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string] $ProjectPath,
    [Parameter(Mandatory=$true)][string] $ExpectedDatabase,
    [switch] $FullSuite,
    [switch] $QualityGates
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath (Join-Path $ProjectPath 'artisan') -PathType Leaf)) {
    throw 'Laravel artisan not found at ProjectPath.'
}
if ($env:APP_ENV -ne 'testing') { throw 'Refusing QA: APP_ENV must be testing.' }
if ($env:DB_DATABASE -ne $ExpectedDatabase) { throw 'Refusing QA: DB_DATABASE does not match ExpectedDatabase.' }
if ($ExpectedDatabase -notmatch '^[A-Za-z0-9_]+_test$' -or $ExpectedDatabase -match 'race_test$') {
    throw 'Refusing QA: use a dedicated, non-race test database ending in _test.'
}
if ($env:BABLYS_REAL_MYSQL_RACE -eq '1') { throw 'Refusing QA: disable race-test opt-in first.' }

Push-Location -LiteralPath $ProjectPath
try {
    # Tinker reports the effective normal Laravel connection, not necessarily phpunit.xml overrides.
    $actual = (& php artisan tinker '--execute=echo DB::connection()->getDatabaseName();' 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $actual -ne $ExpectedDatabase) {
        throw "Refusing QA: effective Laravel database is '$actual', expected '$ExpectedDatabase'."
    }
    Write-Host "Verified APP_ENV=testing; database=$actual; race opt-in disabled."
    Write-Host 'Also verify phpunit.xml/.env.testing do not override the test database.'

    foreach ($name in @('BookingFinalSecurityRegressionTest', 'BookingFinalLifecycleIntegrationTest', 'BookingFinalHttpContractTest')) {
        Write-Host "Running focused suite: $name"
        & php artisan test "--filter=$name" --stop-on-failure
        if ($LASTEXITCODE -ne 0) { throw "Focused suite failed: $name" }
    }
    if ($FullSuite) {
        Write-Host 'Running full Laravel regression suite (the opt-in race test should SKIP here).'
        & php artisan test
        if ($LASTEXITCODE -ne 0) { throw 'Full Laravel suite failed.' }
    }
    if ($QualityGates) {
        & php 'vendor/bin/phpstan' analyse
        if ($LASTEXITCODE -ne 0) { throw 'PHPStan failed.' }
        & npm.cmd run types:check
        if ($LASTEXITCODE -ne 0) { throw 'TypeScript type check failed.' }
        & npm.cmd run build
        if ($LASTEXITCODE -ne 0) { throw 'Production frontend build failed.' }
    }
    Write-Host 'Requested automated gates passed. Browser/a11y/privacy manual checklist still required.'
} finally {
    Pop-Location
}
