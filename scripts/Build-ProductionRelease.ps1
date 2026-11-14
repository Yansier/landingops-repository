[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Config,
    [Parameter(Mandatory = $true)][string]$Version,
    [Parameter(Mandatory = $true)][string]$OutputDirectory,
    [string]$SourceCommit
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$php = Join-Path $projectRoot '.tools\php\php.exe'
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { $php = (Get-Command php -ErrorAction Stop).Source }
$composer = Join-Path $projectRoot '.tools\composer.phar'
if (-not (Test-Path -LiteralPath $composer -PathType Leaf)) { throw 'composer.phar not found; install Composer outside the Web Root.' }
$npm = if ($env:LANDINGOPS_NPM_BIN) { $env:LANDINGOPS_NPM_BIN } else { (Get-Command npm.cmd -ErrorAction Stop).Source }
$node = if ($env:LANDINGOPS_NODE_BIN) { $env:LANDINGOPS_NODE_BIN } else { (Get-Command node.exe -ErrorAction Stop).Source }
if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { throw 'Node.js executable not found.' }
$originalPath = $env:Path
$env:Path = (Split-Path -Parent $node) + [IO.Path]::PathSeparator + $env:Path

Push-Location $projectRoot
$buildExitCode = 0
try {
    $resolvedCommit = (& git rev-parse HEAD).Trim()
    if ($LASTEXITCODE -ne 0 -or $resolvedCommit -notmatch '^[0-9a-f]{40}$') { throw 'Unable to resolve source commit.' }
    if ($SourceCommit -and $SourceCommit -ne $resolvedCommit) { throw 'SourceCommit does not match the checked out commit.' }
    $SourceCommit = $resolvedCommit
    $dirtySource = @(& git status --porcelain --untracked-files=all)
    if ($LASTEXITCODE -ne 0) { throw 'Unable to inspect source tree state.' }
    if ($dirtySource.Count -gt 0) { throw 'Production release requires a clean Git working tree.' }

    & $php $composer validate --strict
    if ($LASTEXITCODE -ne 0) { throw 'Composer validation failed.' }
    & $php $composer install --no-dev --classmap-authoritative --no-interaction --no-progress
    if ($LASTEXITCODE -ne 0) { throw 'Composer production install failed.' }
    & $php tests\run.php
    if ($LASTEXITCODE -ne 0) { throw 'PHP tests failed.' }

    Push-Location (Join-Path $projectRoot 'frontend')
    try {
        & $npm ci --ignore-scripts
        if ($LASTEXITCODE -ne 0) { throw 'Node dependency installation failed.' }
        & $npm test -- --run
        if ($LASTEXITCODE -ne 0) { throw 'Vue tests failed.' }
        & $npm run typecheck
        if ($LASTEXITCODE -ne 0) { throw 'Vue TypeScript check failed.' }
        & $npm run build
        if ($LASTEXITCODE -ne 0) { throw 'Vite production build failed.' }
    }
    finally { Pop-Location }

    & $php bin\landingops-deploy build --config $Config --version $Version --source-commit $SourceCommit --output $OutputDirectory
    if ($LASTEXITCODE -ne 0) { throw 'Release packaging failed.' }
}
catch {
    $buildExitCode = 1
    Write-Error $_
}
finally {
    $env:Path = $originalPath
    Pop-Location
}
exit $buildExitCode
