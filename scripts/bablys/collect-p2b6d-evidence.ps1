param(
    [Parameter(Mandatory=$true)]
    [string]$ProjectPath,
    [string]$OutputDirectory = '',
    [switch]$WhatIf
)

$ErrorActionPreference = 'Stop'

function Stop-Collection([string]$Message) {
    throw "Bablys P2B.6D evidence collection stopped: $Message"
}

$project = [System.IO.Path]::GetFullPath($ProjectPath)
$required = @(
    'artisan',
    'composer.json',
    'app/Domain/Cms/Access/CmsAccess.php',
    'app/Domain/Cms/Audit/CmsAuditRecorder.php',
    'app/Domain/Cms/Audit/CmsAuditArchiveService.php'
)

foreach ($relative in $required) {
    if (-not (Test-Path -LiteralPath (Join-Path $project $relative) -PathType Leaf)) {
        Stop-Collection "Required Bablys file missing: $relative. Check ProjectPath."
    }
}

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Stop-Collection 'PHP was not found on PATH.'
}

$stamp = [System.DateTime]::UtcNow.ToString('yyyyMMdd_HHmmss')
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $out = Join-Path $project "storage/app/private/bablys-p2b6d-evidence/$stamp"
} else {
    $out = [System.IO.Path]::GetFullPath($OutputDirectory)
}

$public = [System.IO.Path]::GetFullPath((Join-Path $project 'public'))
$publicWithSeparator = $public.TrimEnd('\','/') + [System.IO.Path]::DirectorySeparatorChar
$outFull = [System.IO.Path]::GetFullPath($out)
if ($outFull.Equals($public, [System.StringComparison]::OrdinalIgnoreCase) -or
    $outFull.StartsWith($publicWithSeparator, [System.StringComparison]::OrdinalIgnoreCase)) {
    Stop-Collection 'Evidence output cannot be placed in a publicly served directory.'
}

Write-Host 'Bablys P2B.6D — read-only release evidence collection'
Write-Host "Project: $project"
Write-Host "Evidence folder: $outFull"
Write-Host 'Commands: PHP/Laravel version, admin routes, migration status, audit readiness, optional Git revision.'
Write-Host 'NO tests, database mutations, .env reads, credentials, DB exports or archive commands.'

if ($WhatIf) {
    Write-Host 'Dry run complete. No files or application data changed.'
    exit 0
}

New-Item -ItemType Directory -Path $outFull -Force | Out-Null
$checks = New-Object 'System.Collections.Generic.List[object]'

function Invoke-ReadOnlyCommand([string]$Name, [string]$Program, [string[]]$Arguments) {
    $log = Join-Path $outFull ($Name + '.txt')
    Push-Location $project
    try {
        $ErrorActionPreference = 'Continue'
        & $Program @Arguments 2>&1 | Out-File -LiteralPath $log -Encoding UTF8
        $code = [int]$LASTEXITCODE
    } catch {
        $_.ToString() | Out-File -LiteralPath $log -Encoding UTF8 -Append
        $code = 999
    } finally {
        $ErrorActionPreference = 'Stop'
        Pop-Location
    }
    $checks.Add([pscustomobject]@{
        check = $Name
        exit_code = $code
        result = $(if ($code -eq 0) { 'reported_success' } else { 'requires_review' })
        output_file = [System.IO.Path]::GetFileName($log)
    })
    Write-Host "$Name => exit code $code"
}

Invoke-ReadOnlyCommand 'php-version' 'php' @('-v')
Invoke-ReadOnlyCommand 'laravel-version' 'php' @('artisan', '--version')
Invoke-ReadOnlyCommand 'admin-route-inventory' 'php' @('artisan', 'route:list', '--path=admin', '-v')
Invoke-ReadOnlyCommand 'migration-status' 'php' @('artisan', 'migrate:status')
Invoke-ReadOnlyCommand 'audit-readiness' 'php' @('artisan', 'bablys:audit:readiness')

if (Get-Command git -ErrorAction SilentlyContinue) {
    Invoke-ReadOnlyCommand 'git-revision' 'git' @('rev-parse', 'HEAD')
    Invoke-ReadOnlyCommand 'git-working-tree' 'git' @('status', '--short')
}

$summary = [ordered]@{
    project = 'Bablys Tours & Travels'
    milestone = 'P2B.6D'
    captured_utc = [System.DateTime]::UtcNow.ToString('o')
    evidence_type = 'read_only_local_environment_snapshot'
    executed_full_test_suite = $false
    independent_production_signoff = $false
    production_launch_approved = $false
    checks = @($checks.ToArray())
    note = 'User reported full P2B.6C gates and browser QA green. This collector does not re-run tests, certify production backups, inspect secrets, or perform a restore drill.'
}

$summary | ConvertTo-Json -Depth 6 |
    Set-Content -LiteralPath (Join-Path $outFull 'evidence-summary.json') -Encoding UTF8

Write-Host "Evidence saved in $outFull"
Write-Host 'Review output files before sharing externally. Production operational approval remains pending.'
