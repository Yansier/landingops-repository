[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$SshHost,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$SshUser,

    [ValidateRange(1, 65535)]
    [int]$SshPort = 22,

    [ValidateRange(1024, 65535)]
    [int]$LocalPort = 13306,

    [string]$IdentityFile
)

$ssh = Get-Command ssh -ErrorAction Stop
$projectRoot = Split-Path -Parent $PSScriptRoot
$knownHostsDirectory = Join-Path $projectRoot '.tools\ssh'
$knownHostsFile = Join-Path $knownHostsDirectory 'landingops_test_known_hosts'
if (-not (Test-Path -LiteralPath $knownHostsDirectory -PathType Container)) {
    New-Item -ItemType Directory -Path $knownHostsDirectory -Force | Out-Null
}
$arguments = @(
    '-N',
    '-o', 'KexAlgorithms=curve25519-sha256',
    '-o', 'ExitOnForwardFailure=yes',
    '-o', 'ServerAliveInterval=30',
    '-o', 'ServerAliveCountMax=3',
    '-o', 'StrictHostKeyChecking=accept-new',
    '-o', "UserKnownHostsFile=${knownHostsFile}",
    '-L', "127.0.0.1:${LocalPort}:127.0.0.1:3306",
    '-p', [string]$SshPort
)
if ($IdentityFile) {
    $resolvedIdentity = (Resolve-Path -LiteralPath $IdentityFile -ErrorAction Stop).Path
    $arguments += @('-i', $resolvedIdentity)
}
$arguments += "${SshUser}@${SshHost}"

Write-Host "Opening SSH tunnel 127.0.0.1:${LocalPort} -> server 127.0.0.1:3306"
Write-Host 'Keep this window open while the MySQL integration test runs. Press Ctrl+C to close the tunnel.'
& $ssh.Source @arguments
exit $LASTEXITCODE
