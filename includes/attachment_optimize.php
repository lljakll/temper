<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/**
 * Local optimize-on-upload for new ledger attachments.
 * Each new PDF runs Ghostscript ebook (about 150 dpi) and screen (about 100 dpi).
 * The smaller output that beats the original is the one returned. JPEG and PNG
 * are resized and recompressed with GD. Images stay images.
 * No external compression service and no user choice of profile.
 */

function ledgerAttachmentOptimizableExtensions(): array {
    return ['pdf', 'jpg', 'jpeg', 'png'];
}

function ledgerFormatStoredKilobytes(int $bytes): string {
    if ($bytes <= 0) {
        return '0 KB';
    }
    $kb = $bytes / 1024;
    if ($kb < 10) {
        $rounded = round($kb, 1);
        $text = number_format($rounded, 1, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');
        if ($text === '' || $text === '0') {
            $text = '0.1';
        }
        return $text . ' KB';
    }
    return (string)max(1, (int)round($kb)) . ' KB';
}

function ledgerGhostscriptBinary(): ?string {
    static $cached = false;
    static $path = null;
    if ($cached) {
        return $path;
    }
    $cached = true;
    $dirs = [];
    $env = getenv('PATH');
    if (is_string($env) && $env !== '') {
        foreach (explode(PATH_SEPARATOR, $env) as $dir) {
            $dir = trim($dir);
            if ($dir !== '') {
                $dirs[] = $dir;
            }
        }
    }
    $dirs[] = '/usr/bin';
    $dirs[] = '/usr/local/bin';
    $dirs[] = '/bin';
    $seen = [];
    foreach ($dirs as $dir) {
        $candidate = rtrim($dir, '/') . '/gs';
        if (isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;
        if (is_executable($candidate)) {
            $path = $candidate;
            return $path;
        }
    }
    return null;
}

function ledgerImageOptimizeAvailable(): bool {
    return function_exists('imagecreatefromjpeg')
        && function_exists('imagecreatefrompng')
        && function_exists('imagejpeg')
        && function_exists('imagepng')
        && function_exists('imagecreatetruecolor')
        && function_exists('imagecopyresampled');
}

/**
 * JPEG/PNG resize used for every new image upload.
 *
 * @return array{max_edge:int,jpeg_quality:int}
 */
function ledgerAttachmentOptimizeProfile(): array {
    return [
        'max_edge' => 2000,
        'jpeg_quality' => 82,
    ];
}

/**
 * Percent change of the stored file versus the upload. Saved files are never larger.
 */
function ledgerAttachmentSizeChangePhrase(int $originalBytes, int $savedBytes): string {
    if ($originalBytes <= 0 || $savedBytes >= $originalBytes) {
        return '0% change';
    }
    $pct = (int)round((($originalBytes - $savedBytes) / $originalBytes) * 100);
    if ($pct < 1) {
        return 'under 1% smaller';
    }
    return $pct . '% smaller';
}

/**
 * @return array{
 *   attempted:bool,
 *   kept:string,
 *   reason:string,
 *   input_bytes:int,
 *   output_bytes:?int,
 *   output_path:?string,
 *   gs_available:bool,
 *   image_tool_available:bool
 * }
 */
function ledgerOptimizeResult(
    bool $attempted,
    string $kept,
    string $reason,
    int $inputBytes,
    ?int $outputBytes,
    ?string $outputPath,
    bool $gsAvailable,
    bool $imageToolAvailable
): array {
    return [
        'attempted' => $attempted,
        'kept' => $kept,
        'reason' => $reason,
        'input_bytes' => $inputBytes,
        'output_bytes' => $outputBytes,
        'output_path' => $outputPath,
        'gs_available' => $gsAvailable,
        'image_tool_available' => $imageToolAvailable,
    ];
}

/**
 * Attempt a local optimize. Always runs for PDF and JPEG/PNG, including small files.
 * Returns output_path only when that file is strictly smaller than the source.
 * The source file is left in place. A larger or failed output is deleted here.
 *
 * PDF always runs both profiles. Images always use the 2000px / quality 82 pass.
 * $pass is accepted so older callers keep working and is ignored.
 */
function ledgerOptimizeAttachmentFile(string $srcPath, string $extension, string $pass = 'initial'): array {
    unset($pass);
    // Two Ghostscript runs, each capped near 40 seconds.
    @set_time_limit(120);
    $ext = strtolower($extension);
    $gsAvailable = ledgerGhostscriptBinary() !== null;
    $imageTool = ledgerImageOptimizeAvailable();
    $inputBytes = is_file($srcPath) ? (int)filesize($srcPath) : 0;
    if (!in_array($ext, ledgerAttachmentOptimizableExtensions(), true)) {
        return ledgerOptimizeResult(false, 'skipped', 'unsupported', $inputBytes, null, null, $gsAvailable, $imageTool);
    }
    if ($inputBytes <= 0 || !is_readable($srcPath)) {
        return ledgerOptimizeResult(true, 'original', 'failed', $inputBytes, null, null, $gsAvailable, $imageTool);
    }
    if ($ext === 'pdf') {
        return ledgerOptimizePdfFile($srcPath, $inputBytes, $gsAvailable, $imageTool);
    }
    return ledgerOptimizeImageFile($srcPath, $ext, $inputBytes, $gsAvailable, $imageTool);
}

/**
 * @param array<int, string> $failures
 * @return array<string, mixed>
 */
function ledgerPdfOptimizeMeta(array $result, array $failures, ?string $chosen): array {
    $names = [];
    foreach ($failures as $name) {
        if ($name === 'ebook' || $name === 'screen') {
            $names[] = $name;
        }
    }
    $result['profile_failures'] = $names;
    $result['chosen_profile'] = ($chosen === 'ebook' || $chosen === 'screen') ? $chosen : null;
    return $result;
}

/**
 * Write one Ghostscript profile to a new temp file. Does not read or replace $work.
 *
 * @return array{ok:bool,path:?string,bytes:int,exit:int,timed_out:bool,stderr:string}
 */
function ledgerWritePdfProfile(string $gs, string $work, string $setting, int $timeoutSeconds): array {
    $empty = ['ok' => false, 'path' => null, 'bytes' => 0, 'exit' => 1, 'timed_out' => false, 'stderr' => ''];
    $out = tempnam(sys_get_temp_dir(), 'temper-opt-');
    if ($out === false) {
        $empty['stderr'] = 'Could not create a temp PDF.';
        return $empty;
    }
    // Some Ghostscript builds will not overwrite an existing empty file.
    @unlink($out);

    $screen = $setting === 'screen';
    $pdfSetting = $screen ? '/screen' : '/ebook';
    $dpi = $screen ? 100 : 150;
    $cmd = [
        $gs,
        '-sDEVICE=pdfwrite',
        '-dCompatibilityLevel=1.4',
        '-dPDFSETTINGS=' . $pdfSetting,
        '-dDownsampleColorImages=true',
        '-dDownsampleGrayImages=true',
        '-dColorImageDownsampleType=/Bicubic',
        '-dGrayImageDownsampleType=/Bicubic',
        '-dColorImageResolution=' . $dpi,
        '-dGrayImageResolution=' . $dpi,
        '-dColorImageDownsampleThreshold=1.0',
        '-dGrayImageDownsampleThreshold=1.0',
        '-dNOPAUSE',
        '-dQUIET',
        '-dBATCH',
        '-dSAFER',
        '-sOutputFile=' . $out,
        $work,
    ];
    $ran = ledgerRunLocalCommand($cmd, $timeoutSeconds);
    $bytes = is_file($out) ? (int)filesize($out) : 0;
    $valid = $bytes > 0 && ledgerFileStartsWith($out, '%PDF-');
    $ok = !$ran['timed_out'] && (int)$ran['exit'] === 0 && $valid;
    return [
        'ok' => $ok,
        'path' => $out,
        'bytes' => $bytes,
        'exit' => (int)$ran['exit'],
        'timed_out' => (bool)$ran['timed_out'],
        'stderr' => (string)$ran['stderr'],
    ];
}

/**
 * @return array<string, mixed>
 */
function ledgerOptimizePdfFile(
    string $srcPath,
    int $inputBytes,
    bool $gsAvailable,
    bool $imageTool
): array {
    $bothFailed = ['ebook', 'screen'];
    $gs = ledgerGhostscriptBinary();
    if ($gs === null) {
        return ledgerPdfOptimizeMeta(
            ledgerOptimizeResult(true, 'original', 'gs_missing', $inputBytes, null, null, false, $imageTool),
            [],
            null
        );
    }
    if (!function_exists('proc_open')) {
        return ledgerPdfOptimizeMeta(
            ledgerOptimizeResult(true, 'original', 'failed', $inputBytes, null, null, true, $imageTool),
            $bothFailed,
            null
        );
    }

    $work = null;
    $scratch = [];
    $keep = null;
    try {
        // Ghostscript is denied read access under the storage tree. A temp copy
        // is in a directory gs can read. The copy is removed before return.
        // The upload itself is not replaced here.
        $work = tempnam(sys_get_temp_dir(), 'temper-opt-in-');
        if ($work === false || !@copy($srcPath, $work)) {
            return ledgerPdfOptimizeMeta(
                ledgerOptimizeResult(true, 'original', 'failed', $inputBytes, null, null, true, $imageTool),
                $bothFailed,
                null
            );
        }

        $failures = [];
        $candidates = [];
        foreach (['ebook', 'screen'] as $setting) {
            $one = ledgerWritePdfProfile($gs, $work, $setting, 40);
            if (is_string($one['path']) && $one['path'] !== '') {
                $scratch[] = $one['path'];
            }
            if (empty($one['ok'])) {
                $failures[] = $setting;
                $snippet = trim(preg_replace('/\s+/', ' ', (string)$one['stderr']) ?? '');
                if (strlen($snippet) > 300) {
                    $snippet = substr($snippet, 0, 300);
                }
                error_log(
                    'temper attachment optimize: pdf ' . $setting . ' '
                    . (!empty($one['timed_out']) ? 'timeout' : 'failed')
                    . ' exit=' . (int)$one['exit']
                    . ' bytes_in=' . $inputBytes
                    . ' bytes_out=' . (int)$one['bytes']
                    . ($snippet !== '' ? ' gs=' . $snippet : '')
                );
                continue;
            }
            $bytes = (int)$one['bytes'];
            if ($bytes <= 0 || $bytes >= $inputBytes || !is_string($one['path'])) {
                continue;
            }
            $candidates[$setting] = ['path' => $one['path'], 'bytes' => $bytes];
        }

        $chosen = null;
        if (isset($candidates['ebook'], $candidates['screen'])) {
            if ($candidates['screen']['bytes'] < $candidates['ebook']['bytes']) {
                $chosen = 'screen';
            } else {
                $chosen = 'ebook';
            }
        } elseif (isset($candidates['ebook'])) {
            $chosen = 'ebook';
        } elseif (isset($candidates['screen'])) {
            $chosen = 'screen';
        }

        if ($chosen === null) {
            $reason = $failures === [] ? 'not_smaller' : 'failed';
            return ledgerPdfOptimizeMeta(
                ledgerOptimizeResult(true, 'original', $reason, $inputBytes, null, null, true, $imageTool),
                $failures,
                null
            );
        }

        $keep = $candidates[$chosen]['path'];
        return ledgerPdfOptimizeMeta(
            ledgerOptimizeResult(
                true,
                'optimized',
                'shrunk',
                $inputBytes,
                $candidates[$chosen]['bytes'],
                $keep,
                true,
                $imageTool
            ),
            $failures,
            $chosen
        );
    } catch (Throwable $e) {
        $keep = null;
        error_log('temper attachment optimize: pdf exception ' . $e->getMessage());
        return ledgerPdfOptimizeMeta(
            ledgerOptimizeResult(true, 'original', 'failed', $inputBytes, null, null, true, $imageTool),
            $bothFailed,
            null
        );
    } finally {
        if (is_string($work) && $work !== '' && is_file($work)) {
            @unlink($work);
        }
        foreach ($scratch as $path) {
            if (!is_string($path) || $path === '' || !is_file($path)) {
                continue;
            }
            if ($keep !== null && $path === $keep) {
                continue;
            }
            @unlink($path);
        }
    }
}

/**
 * @return array<string, mixed>
 */
function ledgerOptimizeImageFile(
    string $srcPath,
    string $ext,
    int $inputBytes,
    bool $gsAvailable,
    bool $imageTool
): array {
    if (!ledgerImageOptimizeAvailable()) {
        return ledgerOptimizeResult(true, 'original', 'image_tool_missing', $inputBytes, null, null, $gsAvailable, false);
    }

    $info = @getimagesize($srcPath);
    if (!is_array($info)) {
        return ledgerOptimizeResult(true, 'original', 'failed', $inputBytes, null, null, $gsAvailable, true);
    }
    $w = (int)($info[0] ?? 0);
    $h = (int)($info[1] ?? 0);
    $type = (int)($info[2] ?? 0);
    $expect = ($ext === 'png') ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
    if ($w < 1 || $h < 1 || $type !== $expect) {
        return ledgerOptimizeResult(true, 'original', 'failed', $inputBytes, null, null, $gsAvailable, true);
    }
    // Decoded bitmap plus a resized copy must fit the process. Over the cap, keep the original.
    if (($w * $h) > 40000000) {
        error_log('temper attachment optimize: image too large to decode ' . $w . 'x' . $h);
        return ledgerOptimizeResult(true, 'original', 'failed', $inputBytes, null, null, $gsAvailable, true);
    }

    $previousMemory = ini_get('memory_limit');
    @ini_set('memory_limit', '512M');
    $profile = ledgerAttachmentOptimizeProfile();
    $out = null;
    $keep = false;
    $image = null;
    try {
        $image = $ext === 'png' ? @imagecreatefrompng($srcPath) : @imagecreatefromjpeg($srcPath);
        if ($image === false) {
            return ledgerOptimizeResult(true, 'original', 'failed', $inputBytes, null, null, $gsAvailable, true);
        }
        if ($ext !== 'png') {
            $orientation = 1;
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($srcPath);
                if (is_array($exif) && !empty($exif['Orientation'])) {
                    $orientation = (int)$exif['Orientation'];
                }
            }
            $image = ledgerGdApplyExifOrientation($image, $orientation);
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $long = max($w, $h);
        $maxEdge = (int)$profile['max_edge'];
        if ($long > $maxEdge && $long > 0) {
            $scale = $maxEdge / $long;
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $scaled = ledgerGdScaleImage($image, $nw, $nh, $ext === 'png');
            if ($scaled === false) {
                return ledgerOptimizeResult(true, 'original', 'failed', $inputBytes, null, null, $gsAvailable, true);
            }
            $image = $scaled;
        }

        $out = tempnam(sys_get_temp_dir(), 'temper-opt-');
        if ($out === false) {
            return ledgerOptimizeResult(true, 'original', 'failed', $inputBytes, null, null, $gsAvailable, true);
        }
        @unlink($out);
        $wrote = $ext === 'png'
            ? ledgerGdSavePng($image, $out)
            : imagejpeg($image, $out, (int)$profile['jpeg_quality']);
        $outputBytes = ($wrote && is_file($out)) ? (int)filesize($out) : 0;
        $valid = $outputBytes > 0 && ledgerSavedImageMatches($out, $ext);
        if (!$valid || $outputBytes >= $inputBytes) {
            return ledgerOptimizeResult(
                true,
                'original',
                $valid ? 'not_smaller' : 'failed',
                $inputBytes,
                $valid ? $outputBytes : null,
                null,
                $gsAvailable,
                true
            );
        }
        $keep = true;
        return ledgerOptimizeResult(true, 'optimized', 'shrunk', $inputBytes, $outputBytes, $out, $gsAvailable, true);
    } catch (Throwable $e) {
        error_log('temper attachment optimize: image exception ' . $e->getMessage());
        return ledgerOptimizeResult(true, 'original', 'failed', $inputBytes, null, null, $gsAvailable, true);
    } finally {
        if (!$keep && is_string($out) && $out !== '' && is_file($out)) {
            @unlink($out);
        }
        if (is_string($previousMemory) && $previousMemory !== '') {
            @ini_set('memory_limit', $previousMemory);
        }
    }
}

/**
 * @param array<string, mixed> $opt
 * @return array<string, mixed>
 */
function ledgerAttachmentOptimizePayload(
    string $originalName,
    string $extension,
    int $storedBytes,
    array $opt
): array {
    $ext = strtolower($extension);
    $optimizable = in_array($ext, ledgerAttachmentOptimizableExtensions(), true);
    $kept = (string)($opt['kept'] ?? 'skipped');
    $reason = (string)($opt['reason'] ?? 'unsupported');
    $originalBytes = (int)($opt['input_bytes'] ?? 0);
    if ($originalBytes <= 0) {
        $originalBytes = $storedBytes > 0 ? $storedBytes : 0;
    }
    $savedBytes = $storedBytes > 0 ? $storedBytes : $originalBytes;
    $savedLabel = ledgerFormatStoredKilobytes($savedBytes);
    $name = basename(str_replace(["\r", "\n", "\0"], '', $originalName));
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'This file';
    }
    if (function_exists('mb_strlen') && mb_strlen($name) > 80) {
        $name = mb_substr($name, 0, 77) . '...';
    }

    $failures = [];
    if (isset($opt['profile_failures']) && is_array($opt['profile_failures'])) {
        foreach ($opt['profile_failures'] as $failure) {
            if ($failure === 'ebook' || $failure === 'screen') {
                $failures[] = $failure;
            }
        }
    }
    $gsMissing = $reason === 'gs_missing';
    $imageMissing = $reason === 'image_tool_missing';
    $prefix = '';
    $warning = false;
    if ($gsMissing) {
        $prefix = 'Ghostscript is not installed. ';
        $warning = true;
    } elseif ($ext === 'pdf' && count($failures) >= 2) {
        $prefix = 'The ebook and screen profiles failed. ';
        $warning = true;
    } elseif ($ext === 'pdf' && count($failures) === 1) {
        $prefix = 'The ' . $failures[0] . ' profile failed. ';
        $warning = true;
    } elseif ($ext === 'pdf' && $reason === 'failed' && $kept !== 'optimized') {
        $prefix = 'The ebook and screen profiles failed. ';
        $warning = true;
    } elseif ($imageMissing) {
        $prefix = 'Image optimization is not available on this server. ';
        $warning = true;
    } elseif ($optimizable && $ext !== 'pdf' && $reason === 'failed' && $kept !== 'optimized') {
        $prefix = 'Image optimization failed. ';
        $warning = true;
    }

    $message = '';
    if ($optimizable) {
        $message = $prefix . $name . ': original ' . ledgerFormatStoredKilobytes($originalBytes)
            . ', saved ' . $savedLabel
            . ' (' . ledgerAttachmentSizeChangePhrase($originalBytes, $savedBytes) . ').';
    }

    $chosen = $opt['chosen_profile'] ?? null;
    if ($chosen !== 'ebook' && $chosen !== 'screen') {
        $chosen = null;
    }

    return [
        'notice' => $optimizable,
        'retryable' => false,
        'warning' => $warning,
        'kept' => $kept,
        'reason' => $reason,
        'stored_bytes' => $savedBytes,
        'stored_label' => $savedLabel,
        'original_bytes' => $originalBytes,
        'original_label' => ledgerFormatStoredKilobytes($originalBytes),
        'chosen_profile' => $chosen,
        'gs_missing' => $gsMissing,
        'message' => $message,
    ];
}

function ledgerFileStartsWith(string $path, string $prefix): bool {
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    $got = (string)fread($fh, strlen($prefix));
    fclose($fh);
    return $got === $prefix;
}

function ledgerSavedImageMatches(string $path, string $ext): bool {
    $info = @getimagesize($path);
    if (!is_array($info)) {
        return false;
    }
    $type = (int)($info[2] ?? 0);
    if ($ext === 'png') {
        return $type === IMAGETYPE_PNG;
    }
    return $type === IMAGETYPE_JPEG;
}

/**
 * @return array{exit:int,stdout:string,stderr:string,timed_out:bool}
 */
function ledgerRunLocalCommand(array $command, int $timeoutSeconds): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $proc = @proc_open($command, $descriptors, $pipes, sys_get_temp_dir());
    if (!is_resource($proc)) {
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'Could not start the local optimizer.', 'timed_out' => false];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $start = time();
    $timedOut = false;
    $exit = 1;
    while (true) {
        $status = proc_get_status($proc);
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        if (empty($status['running'])) {
            break;
        }
        if ((time() - $start) >= $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($proc, 15);
            usleep(200000);
            $status = proc_get_status($proc);
            if (!empty($status['running'])) {
                proc_terminate($proc, 9);
            }
            break;
        }
        usleep(100000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($proc);
    if (!$timedOut) {
        // proc_get_status() keeps exitcode at -1 after it was polled while running.
        $exit = (int)$closed;
    }
    if (strlen($stdout) > 2000) {
        $stdout = substr($stdout, 0, 2000);
    }
    if (strlen($stderr) > 2000) {
        $stderr = substr($stderr, 0, 2000);
    }
    return [
        'exit' => $timedOut ? 124 : $exit,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timed_out' => $timedOut,
    ];
}

function ledgerGdApplyExifOrientation($image, int $orientation) {
    switch ($orientation) {
        case 2:
            if (function_exists('imageflip')) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
            return $image;
        case 3:
            return ledgerGdRotateImage($image, 180);
        case 4:
            if (function_exists('imageflip')) {
                imageflip($image, IMG_FLIP_VERTICAL);
            }
            return $image;
        case 5:
            if (function_exists('imageflip')) {
                imageflip($image, IMG_FLIP_VERTICAL);
            }
            return ledgerGdRotateImage($image, -90);
        case 6:
            return ledgerGdRotateImage($image, -90);
        case 7:
            if (function_exists('imageflip')) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
            return ledgerGdRotateImage($image, -90);
        case 8:
            return ledgerGdRotateImage($image, 90);
        default:
            return $image;
    }
}

function ledgerGdRotateImage($image, float $angle) {
    $bg = imagecolorallocate($image, 255, 255, 255);
    if ($bg === false) {
        $bg = 0;
    }
    $rotated = imagerotate($image, $angle, $bg);
    if ($rotated === false) {
        return $image;
    }
    return $rotated;
}

function ledgerGdScaleImage($image, int $nw, int $nh, bool $png) {
    $dst = imagecreatetruecolor($nw, $nh);
    if ($dst === false) {
        return false;
    }
    if ($png) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $clear = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        if ($clear !== false) {
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $clear);
        }
        imagealphablending($dst, true);
    } else {
        $white = imagecolorallocate($dst, 255, 255, 255);
        if ($white !== false) {
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        }
    }
    imagecopyresampled($dst, $image, 0, 0, 0, 0, $nw, $nh, imagesx($image), imagesy($image));
    if ($png) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    return $dst;
}

function ledgerGdSavePng($image, string $path): bool {
    imagesavealpha($image, true);
    return imagepng($image, $path, 9);
}
