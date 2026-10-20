# Builds ForeverTracker.exe (the launcher) at the root of ForeverTracker from tools\launcher\ForeverTracker.cs, with the
# C# compiler of the .NET Framework 4 that Windows ships: no SDK to install. Also draws tools\launcher\ForeverTracker.ico
# (the logo of public/assets/img/logo.svg) at 16, 32, 48 and 256 pixels.
#   powershell -ExecutionPolicy Bypass -File tools\launcher\build-exe.ps1
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$Here = $PSScriptRoot
$Root = Split-Path -Parent (Split-Path -Parent $Here)

function New-LogoBitmap([int]$size) {
    $bmp = New-Object Drawing.Bitmap($size, $size, [Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $g = [Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = 'AntiAlias'; $g.PixelOffsetMode = 'HighQuality'
    $g.Clear([Drawing.Color]::Transparent)
    $s = $size / 32.0
    $p = { param($x, $y) New-Object Drawing.PointF(([single]($x * $s)), ([single]($y * $s))) }
    # rounded square
    $r = 7 * $s; $w = 32 * $s
    $box = New-Object Drawing.Drawing2D.GraphicsPath
    $box.AddArc(0, 0, 2 * $r, 2 * $r, 180, 90); $box.AddArc($w - 2 * $r, 0, 2 * $r, 2 * $r, 270, 90)
    $box.AddArc($w - 2 * $r, $w - 2 * $r, 2 * $r, 2 * $r, 0, 90); $box.AddArc(0, $w - 2 * $r, 2 * $r, 2 * $r, 90, 90); $box.CloseFigure()
    $g.FillPath((New-Object Drawing.SolidBrush([Drawing.Color]::FromArgb(27, 29, 35))), $box)
    # the eye: the two Bézier curves of the SVG path, and the pupil
    $gold = [Drawing.Color]::FromArgb(214, 162, 62)
    $eye = New-Object Drawing.Drawing2D.GraphicsPath
    $eye.AddBezier((& $p 5.5 16), (& $p 8.4 11.3), (& $p 12 9), (& $p 16 9))
    $eye.AddBezier((& $p 16 9), (& $p 20 9), (& $p 23.6 11.3), (& $p 26.5 16))
    $eye.AddBezier((& $p 26.5 16), (& $p 23.6 20.7), (& $p 20 23), (& $p 16 23))
    $eye.AddBezier((& $p 16 23), (& $p 12 23), (& $p 8.4 20.7), (& $p 5.5 16))
    $eye.CloseFigure()
    $pen = New-Object Drawing.Pen($gold, [single]([Math]::Max(1.2, 2 * $s))); $pen.LineJoin = 'Round'
    $g.DrawPath($pen, $eye)
    $g.FillEllipse((New-Object Drawing.SolidBrush($gold)), [single]((16 - 3.6) * $s), [single]((16 - 3.6) * $s), [single](7.2 * $s), [single](7.2 * $s))
    $g.Dispose()
    return $bmp
}

# .ico: classic 32-bit bitmaps for the small sizes (read by everything), PNG for 256 pixels
function ConvertTo-IconDib($bmp) {
    $size = $bmp.Width
    $ms = New-Object IO.MemoryStream
    $w = New-Object IO.BinaryWriter($ms)
    $mask = [int]([Math]::Ceiling($size / 32.0) * 4) * $size
    $w.Write([uint32]40); $w.Write([int32]$size); $w.Write([int32]($size * 2)); $w.Write([uint16]1); $w.Write([uint16]32)
    $w.Write([uint32]0); $w.Write([uint32]($size * $size * 4 + $mask)); $w.Write([int32]0); $w.Write([int32]0); $w.Write([uint32]0); $w.Write([uint32]0)
    for ($y = $size - 1; $y -ge 0; $y--) {          # bottom-up rows, BGRA
        for ($x = 0; $x -lt $size; $x++) { $c = $bmp.GetPixel($x, $y); $w.Write([byte]$c.B); $w.Write([byte]$c.G); $w.Write([byte]$c.R); $w.Write([byte]$c.A) }
    }
    $w.Write((New-Object byte[] $mask))              # AND mask: unused, the alpha channel decides
    $w.Flush()
    return , $ms.ToArray()
}
$sizes = 16, 32, 48, 256
$images = foreach ($size in $sizes) {
    $bmp = New-LogoBitmap $size
    if ($size -ge 256) {
        $ms = New-Object IO.MemoryStream
        $bmp.Save($ms, [Drawing.Imaging.ImageFormat]::Png)
        $data = $ms.ToArray()
    } else { $data = ConvertTo-IconDib $bmp }
    $bmp.Dispose()
    , $data
}
$ico = New-Object IO.MemoryStream
$w = New-Object IO.BinaryWriter($ico)
$w.Write([uint16]0); $w.Write([uint16]1); $w.Write([uint16]$sizes.Count)
$offset = 6 + 16 * $sizes.Count
for ($i = 0; $i -lt $sizes.Count; $i++) {
    $dim = if ($sizes[$i] -ge 256) { 0 } else { $sizes[$i] }
    $w.Write([byte]$dim); $w.Write([byte]$dim); $w.Write([byte]0); $w.Write([byte]0)
    $w.Write([uint16]1); $w.Write([uint16]32); $w.Write([uint32]$images[$i].Length); $w.Write([uint32]$offset)
    $offset += $images[$i].Length
}
foreach ($data in $images) { $w.Write($data) }
$w.Flush()
$icon = Join-Path $Here 'ForeverTracker.ico'
[IO.File]::WriteAllBytes($icon, $ico.ToArray())

# compile
$csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (-not (Test-Path $csc)) { $csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe' }
if (-not (Test-Path $csc)) { throw '.NET Framework 4 C# compiler not found.' }
$out = Join-Path $Root 'ForeverTracker.exe'
# a running launcher locks its file, but Windows lets it be renamed: the new one is written beside it
if (Test-Path $out) {
    try { [IO.File]::Open($out, 'Open', 'ReadWrite', 'None').Close() }
    catch {
        $old = Join-Path $Root 'ForeverTracker.old.exe'
        if (Test-Path $old) { Remove-Item $old -Force -ErrorAction SilentlyContinue }
        Rename-Item $out 'ForeverTracker.old.exe'
        Write-Host 'ForeverTracker.exe is running: moved to ForeverTracker.old.exe (delete it once closed).'
    }
}
& $csc /nologo /codepage:65001 /target:winexe /optimize+ /platform:anycpu "/win32icon:$icon" "/out:$out" /r:System.Windows.Forms.dll /r:System.Drawing.dll /r:System.Web.Extensions.dll (Join-Path $Here 'ForeverTracker.cs')
if ($LASTEXITCODE -ne 0) { throw 'Compilation failed.' }
Get-Item $out | Select-Object FullName, Length, LastWriteTime
