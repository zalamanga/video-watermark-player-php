param([Parameter(Mandatory=$true)][string]$PhpPath)
$ErrorActionPreference = 'Stop'
if (-not $env:VIDEO_APP_ORIGIN -or -not $env:VIDEO_PRIVATE_DIR) { throw 'Atur VIDEO_APP_ORIGIN dan VIDEO_PRIVATE_DIR di sesi terminal ini.' }
$videoUsername = Read-Host 'Nama operator baru'
$videoSecret = Read-Host 'Kata sandi baru (16..72 byte, tidak ditampilkan)' -AsSecureString
$videoPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($videoSecret)
$videoProcess = $null
try {
    $videoPlain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($videoPointer)
    $videoPayload = @{username=$videoUsername;password=$videoPlain} | ConvertTo-Json -Compress
    $videoInfo = [Diagnostics.ProcessStartInfo]::new()
    $videoInfo.FileName = (Resolve-Path -LiteralPath $PhpPath).Path
    $videoInfo.UseShellExecute = $false
    $videoInfo.CreateNoWindow = $true
    $videoInfo.RedirectStandardInput = $true
    $videoInfo.WorkingDirectory = Split-Path -Parent $PSScriptRoot
    $videoConsole = Join-Path $PSScriptRoot 'console.php'
    $videoInfo.Arguments = '"' + $videoConsole + '" init "--confirm=' + $env:VIDEO_PRIVATE_DIR + '"'
    $videoInfo.StandardInputEncoding = [Text.UTF8Encoding]::new($false)
    $videoProcess = [Diagnostics.Process]::Start($videoInfo)
    $videoProcess.StandardInput.Write($videoPayload)
    $videoProcess.StandardInput.Close()
    $videoProcess.WaitForExit()
    if ($videoProcess.ExitCode -ne 0) { throw 'Initializer belum selesai. Jangan overwrite direktori parsial; periksa output CLI.' }
} finally {
    if ($videoProcess) { $videoProcess.Dispose() }
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($videoPointer)
    $videoPlain = $null; $videoPayload = $null; $videoSecret.Dispose()
}
