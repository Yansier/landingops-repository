[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('collect', 'verify', 'plan')]
    [string]$Command,

    [Parameter(Mandatory = $true)]
    [string]$Config,

    [string]$Evidence,
    [string]$Output
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$php = Join-Path $projectRoot '.tools\php\php.exe'
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    $php = (Get-Command php -ErrorAction Stop).Source
}
$configPath = (Resolve-Path -LiteralPath $Config).Path
$secureKey = Read-Host 'Temporary evidence signing key (minimum 32 characters, input hidden)' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureKey)
$exitCode = 1
try {
    $plainKey = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    if ($plainKey.Length -lt 32) { throw 'Evidence signing key must contain at least 32 characters.' }
    [Environment]::SetEnvironmentVariable('LANDINGOPS_REMOTE_EVIDENCE_KEY', $plainKey, 'Process')
    $plainKey = $null

    $arguments = @((Join-Path $projectRoot 'bin\landingops-remote-doctor'), $Command, '--config', $configPath)
    if ($Command -ne 'collect') {
        if (-not $Evidence) { throw '-Evidence is required for verify and plan.' }
        $arguments += @('--evidence', (Resolve-Path -LiteralPath $Evidence).Path)
    }
    $lines = @(& $php @arguments)
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) { throw "Read-only diagnostic exited with code $exitCode." }
    $json = $lines -join [Environment]::NewLine
    if ($Output) {
        $outputPath = [IO.Path]::GetFullPath($Output)
        $parent = Split-Path -Parent $outputPath
        if (-not (Test-Path -LiteralPath $parent -PathType Container)) { throw 'Output parent directory must already exist.' }
        [IO.File]::WriteAllText($outputPath, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
        Write-Host "Sanitized result written to $outputPath"
    } else {
        $json
    }
}
finally {
    [Environment]::SetEnvironmentVariable('LANDINGOPS_REMOTE_EVIDENCE_KEY', $null, 'Process')
    if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    $secureKey.Dispose()
}
exit $exitCode
