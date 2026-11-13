[CmdletBinding()]
param(
    [ValidateRange(1024, 65535)]
    [int]$LocalPort = 13306
)

$projectRoot = Split-Path -Parent $PSScriptRoot
$php = Join-Path $projectRoot '.tools\php\php.exe'
$test = Join-Path $projectRoot 'tests\mysql-integration.php'
$resultFile = Join-Path $projectRoot '.tools\mysql-integration-result.log'
$credential = Get-Credential -Message 'Dedicated landingops_test MySQL username and password'
if ($null -eq $credential) {
    throw 'MySQL credential input was cancelled.'
}
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($credential.Password)
try {
    $env:LANDINGOPS_TEST_MYSQL_DSN = "mysql:host=127.0.0.1;port=${LocalPort};dbname=landingops_test;charset=utf8mb4"
    $env:LANDINGOPS_TEST_MYSQL_USER = $credential.UserName
    $env:LANDINGOPS_TEST_MYSQL_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $output = @(& $php $test 2>&1)
    $exitCode = $LASTEXITCODE
    [IO.File]::WriteAllLines($resultFile, [string[]]$output)
    $output | Out-Host
    exit $exitCode
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    Remove-Item Env:LANDINGOPS_TEST_MYSQL_DSN -ErrorAction SilentlyContinue
    Remove-Item Env:LANDINGOPS_TEST_MYSQL_USER -ErrorAction SilentlyContinue
    Remove-Item Env:LANDINGOPS_TEST_MYSQL_PASSWORD -ErrorAction SilentlyContinue
}
