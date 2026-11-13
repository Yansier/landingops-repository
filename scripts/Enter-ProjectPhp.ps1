[CmdletBinding()]
param()

$projectRoot = Split-Path -Parent $PSScriptRoot
$phpDirectory = Join-Path $projectRoot '.tools\php'
$phpExecutable = Join-Path $phpDirectory 'php.exe'

if (-not (Test-Path -LiteralPath $phpExecutable -PathType Leaf)) {
    throw "Project-local PHP was not found at $phpExecutable"
}

if (($env:Path -split ';') -notcontains $phpDirectory) {
    $env:Path = "$phpDirectory;$env:Path"
}

$modules = & $phpExecutable -m
foreach ($required in @('json', 'PDO', 'pdo_mysql', 'zip')) {
    if ($modules -cnotcontains $required) {
        throw "Required PHP extension is not loaded: $required"
    }
}

& $phpExecutable -v
Write-Host 'Project PHP is active in this PowerShell session; required extensions are loaded.'
