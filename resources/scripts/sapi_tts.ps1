param(
    [Parameter(Mandatory = $true)][string]$Out,
    [Parameter(Mandatory = $true)][string]$TextFile,
    [string]$Voice = "",
    [int]$Rate = 1,
    [string]$WordsFile = ""
)

Add-Type -AssemblyName System.Speech

if (Test-Path $Out) { Remove-Item -LiteralPath $Out -Force }

$text = [System.IO.File]::ReadAllText($TextFile, [System.Text.Encoding]::UTF8)
if ([string]::IsNullOrWhiteSpace($text)) {
    Write-Error "Text file is empty"
    exit 2
}

$synth = New-Object System.Speech.Synthesis.SpeechSynthesizer

if ($Voice -ne "") {
    try {
        $synth.SelectVoice($Voice)
    } catch {
        Write-Error ("Voice not available: " + $Voice)
        exit 3
    }
}

$synth.Rate = $Rate

$words = New-Object System.Collections.ArrayList

if ($WordsFile -ne "") {
    $handler = {
        param($sender, $eventArgs)
        [void]$words.Add([pscustomobject]@{
            position = [math]::Round($eventArgs.AudioPosition.TotalSeconds, 3)
            text = $eventArgs.Text
        })
    }
    $synth.add_SpeakProgress($handler)
}

$synth.SetOutputToWaveFile($Out)
$synth.Speak($text)
$synth.Dispose()

if ($WordsFile -ne "") {
    $words | ConvertTo-Json -Depth 4 | Set-Content -Path $WordsFile -Encoding UTF8
}

Write-Output ("ok " + (Get-Item $Out).Length)
