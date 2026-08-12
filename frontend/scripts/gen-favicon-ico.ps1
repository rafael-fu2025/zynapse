# Regenerates frontend/public/favicon.ico from the new maroon SYNAPSE logo
# (synapse-maroon.png), embedding 32x32 + 16x16 PNG entries (Vista+ ICO).
Add-Type -AssemblyName System.Drawing

$src = "C:\Users\udtoh_lmtzs7k\zynapse\frontend\public\synapse-maroon.png"
$out = "C:\Users\udtoh_lmtzs7k\zynapse\frontend\public\favicon.ico"
$srcBmp = [System.Drawing.Bitmap]::FromFile($src)

# Downscale preserving alpha.
function Resize-To([System.Drawing.Bitmap]$bmp, [int]$size) {
    $dst = New-Object System.Drawing.Bitmap($size, $size)
    $g = [System.Drawing.Graphics]::FromImage($dst)
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.Clear([System.Drawing.Color]::Transparent)
    $g.DrawImage($bmp, 0, 0, $size, $size)
    $g.Dispose()
    return $dst
}

$sizes = @(32, 16)
$pngs = @()
foreach ($s in $sizes) {
    $resized = Resize-To $srcBmp $s
    $ms = New-Object System.IO.MemoryStream
    $resized.Save($ms, [System.Drawing.Imaging.ImageFormat]::Png)
    $pngs += , @($s, $ms.ToArray())
    $resized.Dispose()
    $ms.Dispose()
}
$srcBmp.Dispose()

# Assemble ICO: ICONDIR + ICONDIRENTRY x N + image data.
$fs = New-Object System.IO.FileStream($out, [System.IO.FileMode]::Create)
$bw = New-Object System.IO.BinaryWriter($fs)
$count = $pngs.Count
$bw.Write([UInt16]0)            # reserved
$bw.Write([UInt16]1)            # type = icon
$bw.Write([UInt16]$count)       # image count

$offset = 6 + (16 * $count)
$blobs = @()
foreach ($entry in $pngs) {
    $size = $entry[0]
    $data = $entry[1]
    $blobs += , $data
    $bw.Write([Byte]($size % 256))   # width (0 => 256)
    $bw.Write([Byte]($size % 256))   # height
    $bw.Write([Byte]0)               # color count
    $bw.Write([Byte]0)               # reserved
    $bw.Write([UInt16]1)             # color planes
    $bw.Write([UInt16]32)            # bits per pixel
    $bw.Write([UInt32]$data.Length)  # bytes in resource
    $bw.Write([UInt32]$offset)       # image offset
    $offset += $data.Length
}
foreach ($data in $blobs) {
    $bw.Write($data)
}
$bw.Flush()
$bw.Close()
$fs.Close()

Write-Output "Generated $out ($((Get-Item $out).Length) bytes, $count sizes)"
