param(
    [string] $TaskName = 'Tornedon Laravel Scheduler',
    [string] $PhpPath = 'php'
)

$projectPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$artisanPath = Join-Path $projectPath 'artisan'

if (-not (Test-Path $artisanPath)) {
    throw "Arquivo artisan não encontrado em: $artisanPath"
}

if ($PhpPath -eq 'php') {
    $phpCommand = (Get-Command php -ErrorAction Stop).Source
} else {
    $phpCommand = (Resolve-Path $PhpPath -ErrorAction Stop).Path
}

$taskCommand = "`"$phpCommand`" `"$artisanPath`" schedule:run"

$arguments = @(
    '/Create',
    '/F',
    '/SC', 'MINUTE',
    '/MO', '1',
    '/TN', $TaskName,
    '/TR', $taskCommand
)

$process = Start-Process -FilePath 'schtasks.exe' -ArgumentList $arguments -NoNewWindow -Wait -PassThru

if ($process.ExitCode -ne 0) {
    throw "Falha ao registrar a tarefa agendada [$TaskName]."
}

Write-Host "Tarefa [$TaskName] criada com sucesso."
Write-Host "Projeto: $projectPath"
Write-Host "PHP: $phpCommand"
