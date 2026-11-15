# Copies the source tree into a development install so a browser audit or screenshots see exactly the tree's state.
# usage: powershell -File tests/browser/sync-install.ps1 -Target <install folder> [-Php <php.exe>]
# Never touches the install's .env, storage/ or bootstrap/cache; vendor/ is mirrored (a removed package disappears there too).
param([Parameter(Mandatory)][string]$Target, [string]$Php = 'php')
$src = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$skip = @('.git', 'node_modules', 'storage', 'cache', 'vendor')
robocopy $src $Target /E /XD $skip /XF .env '*.log' .phpunit.result.cache /NFL /NDL /NJH /NJS /NP | Out-Null
robocopy (Join-Path $src 'vendor') (Join-Path $Target 'vendor') /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
Push-Location $Target
& $Php artisan view:clear | Out-Null
& $Php artisan optimize:clear | Out-Null
Pop-Location
"synced $src -> $Target"
