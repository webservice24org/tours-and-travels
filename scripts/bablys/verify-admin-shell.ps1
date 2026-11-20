[CmdletBinding()]
param([Parameter(Mandatory = $true)][string] $ProjectPath)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path -LiteralPath $ProjectPath).Path
$Pages = @(
  'resources/js/pages/admin/index.tsx',
  'resources/js/pages/admin/staff-users/index.tsx',
  'resources/js/pages/admin/roles/index.tsx',
  'resources/js/pages/admin/audit-logs/index.tsx',
  'resources/js/pages/admin/tour-categories/index.tsx',
  'resources/js/pages/admin/destinations/index.tsx'
)
foreach ($Relative in $Pages) {
    $Path = Join-Path $Root ($Relative.Replace('/', '\'))
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Missing: $Relative" }
    $Content = [System.IO.File]::ReadAllText($Path)
    if (-not $Content.Contains('<BablysAdminLayout>') -or
        -not $Content.Contains('</BablysAdminLayout>') -or
        $Content.Contains('<CmsAdminNavigation />')) {
        throw "Shared admin layout not correctly applied: $Relative"
    }
    Write-Host "OK: $Relative" -ForegroundColor Green
}
foreach ($Relative in @('resources/js/layouts/bablys-admin-layout.tsx', 'resources/js/components/admin/navigation/CmsSidebarMenu.tsx')) {
    if (-not (Test-Path -LiteralPath (Join-Path $Root ($Relative.Replace('/', '\'))) -PathType Leaf)) { throw "Missing: $Relative" }
}
Write-Host 'Bablys P3B.7 structural verification passed. Check mobile/desktop behavior in browser.' -ForegroundColor Cyan
