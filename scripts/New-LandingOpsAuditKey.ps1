[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$OutputDirectory,

    [string]$KeyName = 'landingops-audit-ed25519',

    [switch]$AddToAgent
)

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot)).TrimEnd('\')
$outputRoot = [IO.Path]::GetFullPath($OutputDirectory).TrimEnd('\')
if (-not (Test-Path -LiteralPath $outputRoot -PathType Container)) {
    throw 'OutputDirectory must be a pre-existing private directory outside the repository.'
}
if ($outputRoot -eq [IO.Path]::GetPathRoot($outputRoot)) { throw 'A filesystem root cannot hold the audit key.' }
if ($outputRoot -eq $projectRoot -or $outputRoot.StartsWith($projectRoot + '\', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'The audit key must not be created inside the LandingOps repository.'
}
if ($KeyName -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$') { throw 'KeyName is invalid.' }

$cursor = Get-Item -LiteralPath $outputRoot -Force
while ($null -ne $cursor) {
    if (($cursor.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw 'OutputDirectory or one of its existing ancestors is a reparse point.'
    }
    $cursor = $cursor.Parent
}

$privateKey = Join-Path $outputRoot $KeyName
$publicKey = $privateKey + '.pub'
if (Test-Path -LiteralPath $privateKey -PathType Any) { throw 'The private key target already exists.' }
if (Test-Path -LiteralPath $publicKey -PathType Any) { throw 'The public key target already exists.' }

$sshKeygen = (Get-Command ssh-keygen.exe -ErrorAction Stop).Source
Write-Host 'ssh-keygen will request a passphrase interactively. Input is not echoed by ssh-keygen.'
& $sshKeygen -q -t ed25519 -a 100 -o -f $privateKey -C 'landingops-audit@landingops'
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $privateKey -PathType Leaf) -or -not (Test-Path -LiteralPath $publicKey -PathType Leaf)) {
    throw 'ssh-keygen did not create a complete key pair.'
}

$sidLine = (& whoami.exe /user /fo csv /nh) -join ''
if ($sidLine -notmatch '"(S-1-[0-9-]+)"') { throw 'Unable to determine the current Windows user SID.' }
$sid = $Matches[1]
& icacls.exe $privateKey /inheritance:r /grant:r "${sid}:(F)" | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Unable to restrict the private key ACL.' }

if ($AddToAgent) {
    $sshAdd = (Get-Command ssh-add.exe -ErrorAction Stop).Source
    & $sshAdd $privateKey
    if ($LASTEXITCODE -ne 0) { throw 'ssh-add failed; the key files remain protected on disk.' }
}

$fingerprint = (& $sshKeygen -lf $publicKey -E sha256) -join [Environment]::NewLine
if ($LASTEXITCODE -ne 0) { throw 'Unable to calculate the public key fingerprint.' }
Write-Host 'Key pair created. Keep the private key outside the repository and back it up securely.'
Write-Output $fingerprint
