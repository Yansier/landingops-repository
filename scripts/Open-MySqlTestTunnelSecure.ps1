[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$SshHost,
    [Parameter(Mandatory = $true)]
    [string]$SshUser,
    [ValidateRange(1, 65535)]
    [int]$SshPort = 22,
    [ValidateRange(1024, 65535)]
    [int]$LocalPort = 13306
)

$projectRoot = Split-Path -Parent $PSScriptRoot
$askPass = Join-Path $projectRoot '.tools\ssh\LandingOpsAskPass.exe'
$knownHosts = Join-Path $projectRoot '.tools\ssh\landingops_test_known_hosts'
$statusFile = Join-Path $projectRoot '.tools\ssh\tunnel-status.json'
if (-not (Test-Path -LiteralPath $askPass -PathType Leaf)) {
    throw "SSH askpass helper was not built: $askPass"
}

$credential = Get-Credential -UserName $SshUser -Message "SSH password for ${SshUser}@${SshHost}; verified ED25519 host only"
if ($null -eq $credential) {
    throw 'SSH credential input was cancelled.'
}
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($credential.Password)
try {
    $env:LANDINGOPS_SSH_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $env:SSH_ASKPASS = $askPass
    $env:SSH_ASKPASS_REQUIRE = 'force'
    $env:DISPLAY = 'landingops-local'
    $arguments = @(
        '-N',
        '-o', 'KexAlgorithms=curve25519-sha256',
        '-o', 'ExitOnForwardFailure=yes',
        '-o', 'ServerAliveInterval=30',
        '-o', 'ServerAliveCountMax=3',
        '-o', 'StrictHostKeyChecking=accept-new',
        '-o', "UserKnownHostsFile=${knownHosts}",
        '-L', "127.0.0.1:${LocalPort}:127.0.0.1:3306",
        '-p', [string]$SshPort,
        "${SshUser}@${SshHost}"
    )
    [IO.File]::WriteAllText($statusFile, '{"state":"starting"}')
    & (Get-Command ssh -ErrorAction Stop).Source @arguments
    $exitCode = $LASTEXITCODE
    [IO.File]::WriteAllText($statusFile, ('{"state":"exited","exit_code":' + $exitCode + '}'))
    exit $exitCode
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    Remove-Item Env:LANDINGOPS_SSH_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:SSH_ASKPASS -ErrorAction SilentlyContinue
    Remove-Item Env:SSH_ASKPASS_REQUIRE -ErrorAction SilentlyContinue
    Remove-Item Env:DISPLAY -ErrorAction SilentlyContinue
}
