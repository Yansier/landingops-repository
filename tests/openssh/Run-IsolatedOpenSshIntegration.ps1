[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$dockerCommand = Get-Command docker.exe -ErrorAction SilentlyContinue
if ($null -eq $dockerCommand) {
    Write-Output 'SKIP: isolated_linux_openssh_unavailable (docker.exe not installed)'
    exit 77
}
$docker = $dockerCommand.Source
& $docker version | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-Output 'SKIP: isolated_linux_openssh_unavailable (Docker daemon unavailable)'
    exit 77
}
$ssh = (Get-Command ssh.exe -ErrorAction Stop).Source
$sshKeygen = (Get-Command ssh-keygen.exe -ErrorAction Stop).Source
$sshKeyscan = (Get-Command ssh-keyscan.exe -ErrorAction Stop).Source
$scp = (Get-Command scp.exe -ErrorAction Stop).Source
$sftp = (Get-Command sftp.exe -ErrorAction Stop).Source
$identity = [Guid]::NewGuid().ToString('N')
$image = 'landingops-openssh-test:' + $identity
$container = 'landingops-openssh-test-' + $identity
$network = 'landingops-openssh-test-' + $identity
$tempRoot = Join-Path ([IO.Path]::GetTempPath()) ('landingops-openssh-' + $identity)
$privateKey = Join-Path $tempRoot 'audit-key'
$publicKey = $privateKey + '.pub'
$knownHosts = Join-Path $tempRoot 'known_hosts'
$payload = Join-Path $tempRoot 'scp-payload.txt'
$containerStarted = $false
$networkCreated = $false

function Invoke-ExpectFailure([scriptblock]$Action, [string]$Label) {
    & $Action
    if ($LASTEXITCODE -eq 0) { throw "$Label unexpectedly succeeded." }
}

New-Item -ItemType Directory -Path $tempRoot | Out-Null
try {
    & $sshKeygen -q -t ed25519 -N '' -f $privateKey -C 'landingops-audit@isolated-test'
    if ($LASTEXITCODE -ne 0) { throw 'Unable to generate ephemeral integration key.' }
    [IO.File]::WriteAllText($payload, "integration-only`n", [Text.UTF8Encoding]::new($false))

    & $docker build --pull=false -f (Join-Path $PSScriptRoot 'Dockerfile') -t $image $projectRoot
    if ($LASTEXITCODE -ne 0) { throw 'Isolated OpenSSH image build failed.' }
    & $docker network create --internal $network | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Unable to create isolated Docker network.' }
    $networkCreated = $true
    $publicMount = ([IO.Path]::GetFullPath($publicKey) -replace '\\','/') + ':/run/landingops-test/audit.pub:ro'
    $privateMount = ([IO.Path]::GetFullPath($privateKey) -replace '\\','/') + ':/run/landingops-test/audit-key:ro'
    $containerId = (& $docker run -d --rm --name $container --network $network -p '127.0.0.1::2222' -v $publicMount -v $privateMount $image).Trim()
    if ($LASTEXITCODE -ne 0 -or -not $containerId) { throw 'Isolated OpenSSH container failed to start.' }
    $containerStarted = $true

    Start-Sleep -Seconds 2
    $portLine = (& $docker port $container '2222/tcp').Trim()
    if ($portLine -notmatch ':(\d+)$') { throw 'Unable to resolve isolated SSH port.' }
    $port = $Matches[1]
    $scan = & $sshKeyscan -T 5 -p $port 127.0.0.1 2>$null
    if ($LASTEXITCODE -ne 0 -or -not $scan) { throw 'Unable to scan isolated host key.' }
    [IO.File]::WriteAllLines($knownHosts, $scan, [Text.UTF8Encoding]::new($false))
    $options = @('-F','NUL','-T','-p',$port,'-i',$privateKey,'-o','IdentitiesOnly=yes','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o',('UserKnownHostsFile=' + $knownHosts),'-o','GlobalKnownHostsFile=NUL','-o','ClearAllForwardings=yes','landingops-audit@127.0.0.1')

    $good = @(& $ssh @options 'landingops-readonly collect v2')
    if ($LASTEXITCODE -ne 0 -or (($good -join "`n") -notmatch 'LO1\tprotocol_version\t0\t')) { throw 'Fixed command handshake failed.' }
    Invoke-ExpectFailure { & $ssh @options 'landingops-readonly collect v2; id' | Out-Null } 'Command injection'
    Invoke-ExpectFailure { & $ssh @options | Out-Null } 'Interactive shell'
    'touch /tmp/landingops-stdin-executed' | & $ssh @options 'landingops-readonly collect v2' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Fixed command failed while discarding stdin.' }
    & $docker exec $container test '!' -e /tmp/landingops-stdin-executed
    if ($LASTEXITCODE -ne 0) { throw 'Malicious stdin was executed.' }

    Invoke-ExpectFailure { & $scp -F NUL -P $port -i $privateKey -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o ('UserKnownHostsFile=' + $knownHosts) $payload 'landingops-audit@127.0.0.1:/tmp/scp-payload' | Out-Null } 'SCP'
    $batch = Join-Path $tempRoot 'sftp-batch.txt'; [IO.File]::WriteAllText($batch, "quit`n", [Text.UTF8Encoding]::new($false))
    Invoke-ExpectFailure { & $sftp -F NUL -b $batch -P $port -i $privateKey -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o ('UserKnownHostsFile=' + $knownHosts) 'landingops-audit@127.0.0.1' | Out-Null } 'SFTP'
    Invoke-ExpectFailure { & $ssh -F NUL -T -p $port -o BatchMode=yes -o PubkeyAuthentication=no -o PreferredAuthentications=password -o StrictHostKeyChecking=yes -o ('UserKnownHostsFile=' + $knownHosts) 'landingops-audit@127.0.0.1' | Out-Null } 'Password authentication'

    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    $listener.Start(); $forwardPort = ([Net.IPEndPoint]$listener.LocalEndpoint).Port; $listener.Stop()
    $forwardArguments = @('-F','NUL','-N','-p',$port,'-i',$privateKey,'-o','IdentitiesOnly=yes','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o',('UserKnownHostsFile=' + $knownHosts),'-o','ExitOnForwardFailure=yes','-L',("${forwardPort}:127.0.0.1:22"),'landingops-audit@127.0.0.1')
    $forwardProcess = Start-Process -FilePath $ssh -ArgumentList $forwardArguments -NoNewWindow -PassThru
    if (-not $forwardProcess.WaitForExit(10000)) { $forwardProcess.Kill(); throw 'Port forwarding unexpectedly remained active.' }
    if ($forwardProcess.ExitCode -eq 0) { throw 'Port forwarding unexpectedly succeeded.' }

    $agentCheck = 'cp /run/landingops-test/audit-key /tmp/landingops-agent-key; chmod 600 /tmp/landingops-agent-key; eval "$(ssh-agent -s)" >/dev/null; ssh-add /tmp/landingops-agent-key >/dev/null; ssh -A -F /dev/null -T -p 2222 -i /tmp/landingops-agent-key -o IdentitiesOnly=yes -o StrictHostKeyChecking=no landingops-audit@127.0.0.1 "landingops-readonly collect v2" >/dev/null; result=$?; ssh-agent -k >/dev/null; rm -f /tmp/landingops-agent-key; exit $result'
    & $docker exec $container /bin/sh -c $agentCheck
    if ($LASTEXITCODE -ne 0) { throw 'Agent-forwarding restriction or fixed-command execution failed.' }

    & $docker exec -u landingops-audit $container test '!' -w /usr/local/libexec/landingops-readonly-wrapper
    if ($LASTEXITCODE -ne 0) { throw 'Audit user can write wrapper.' }
    & $docker exec -u landingops-audit $container test '!' -w /usr/local/libexec/landingops-readonly-probe-v2
    if ($LASTEXITCODE -ne 0) { throw 'Audit user can write probe.' }
    & $docker exec -u landingops-audit $container test '!' -w /var/lib/landingops-audit/.ssh/authorized_keys
    if ($LASTEXITCODE -ne 0) { throw 'Audit user can write authorized_keys.' }

    Write-Output 'PASS: isolated real OpenSSH forced-command, forwarding and permission behavior verified; production resources were not contacted.'
}
finally {
    if ($containerStarted) { & $docker rm -f $container | Out-Null }
    if ($networkCreated) { & $docker network rm $network | Out-Null }
    & $docker image rm -f $image 2>$null | Out-Null
    if (Test-Path -LiteralPath $tempRoot) { Remove-Item -LiteralPath $tempRoot -Recurse -Force }
}
