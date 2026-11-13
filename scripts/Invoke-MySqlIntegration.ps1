[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$DatabaseUser,

    [ValidateRange(1024, 65535)]
    [int]$LocalPort = 13306
)

$projectRoot = Split-Path -Parent $PSScriptRoot
$php = Join-Path $projectRoot '.tools\php\php.exe'
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    throw "Project-local PHP was not found at $php"
}

$securePassword = Read-Host 'landingops_test database password' -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    $env:LANDINGOPS_TEST_MYSQL_DSN = "mysql:host=127.0.0.1;port=${LocalPort};dbname=landingops_test;charset=utf8mb4"
    $env:LANDINGOPS_TEST_MYSQL_USER = $DatabaseUser
    $env:LANDINGOPS_TEST_MYSQL_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    & $php (Join-Path $projectRoot 'tests\mysql-integration.php')
    exit $LASTEXITCODE
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    Remove-Item Env:LANDINGOPS_TEST_MYSQL_DSN -ErrorAction SilentlyContinue
    Remove-Item Env:LANDINGOPS_TEST_MYSQL_USER -ErrorAction SilentlyContinue
    Remove-Item Env:LANDINGOPS_TEST_MYSQL_PASSWORD -ErrorAction SilentlyContinue
}
