[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('doctor', 'plan', 'build', 'init', 'update', 'rollback')]
    [string]$Command,

    [Parameter(Mandatory = $true)]
    [string]$Config,

    [string]$Facts,
    [string]$Operation,
    [string]$Version,
    [string]$SourceCommit,
    [string]$Output,
    [string]$ReleaseDirectory,
    [string]$SimulateRoot,
    [switch]$PromptSecrets
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$php = Join-Path $projectRoot '.tools\php\php.exe'
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    $phpCommand = Get-Command php -ErrorAction Stop
    $php = $phpCommand.Source
}
$configPath = (Resolve-Path -LiteralPath $Config).Path
$temporaryVariables = [System.Collections.Generic.List[string]]::new()

function Set-HiddenProcessEnvironment([string]$Name) {
    $secure = Read-Host "Value for $Name" -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try {
        $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
        [Environment]::SetEnvironmentVariable($Name, $plain, 'Process')
        $temporaryVariables.Add($Name)
        $plain = $null
    }
    finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
        $secure.Dispose()
    }
}

try {
    if ($PromptSecrets) {
        $configuration = Get-Content -Raw -LiteralPath $configPath | ConvertFrom-Json
        foreach ($property in $configuration.credentials.PSObject.Properties) {
            $environmentName = [string]$property.Value
            if ([string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($environmentName, 'Process'))) {
                Set-HiddenProcessEnvironment $environmentName
            }
        }
    }

    $arguments = @((Join-Path $projectRoot 'bin\landingops-deploy'), $Command, '--config', $configPath)
    if ($Facts) { $arguments += @('--facts', (Resolve-Path -LiteralPath $Facts).Path) }
    if ($Operation) { $arguments += @('--operation', $Operation) }
    if ($Version) { $arguments += @('--version', $Version) }
    if ($SourceCommit) { $arguments += @('--source-commit', $SourceCommit) }
    if ($Output) { $arguments += @('--output', $Output) }
    if ($ReleaseDirectory) { $arguments += @('--release-dir', $ReleaseDirectory) }
    if ($SimulateRoot) { $arguments += @('--simulate-root', $SimulateRoot) }
    & $php @arguments
    exit $LASTEXITCODE
}
finally {
    foreach ($name in $temporaryVariables) {
        [Environment]::SetEnvironmentVariable($name, $null, 'Process')
    }
}
