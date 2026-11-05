<?php
declare(strict_types=1);

namespace MyPHP\Platform;

use MyPHP\FileFilter;

/**
 * What a file dialog's filters ask for, as the mobile pickers take it (Android\Dialogs, iOS\Dialogs):
 * the MIME type of an extension, and whether every type is a picture or a film, which the photo
 * picker (PHPicker, Android's photo picker) shows instead of the document picker.
 */
final class FileTypes
{
    private const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp', 'tif', 'tiff', 'avif', 'dng'];
    private const VIDEOS = ['mp4', 'm4v', 'mov', 'webm', 'mkv', '3gp'];

    private const MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'heic' => 'image/heic', 'heif' => 'image/heif', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'avif' => 'image/avif', 'dng' => 'image/x-adobe-dng', 'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4', 'm4v' => 'video/x-m4v', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
        'mkv' => 'video/x-matroska', '3gp' => 'video/3gpp',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'flac' => 'audio/flac',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv', 'json' => 'application/json',
        'xml' => 'text/xml', 'html' => 'text/html', 'zip' => 'application/zip', 'gz' => 'application/gzip',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'apk' => 'application/vnd.android.package-archive', 'ipa' => 'application/octet-stream',
    ];

    /** The extensions of $filters, lower case, without dots and duplicates; empty for any file ("*"). @param list<FileFilter> $filters @return list<string> */
    public static function extensions(array $filters): array
    {
        $out = [];
        foreach ($filters as $f) {
            foreach ($f->extensions as $e) {
                $e = strtolower(ltrim($e, '.'));
                if ($e === '*' || $e === '') {
                    return [];
                }
                $out[$e] = true;
            }
        }
        return array_keys($out);
    }

    /** Whether $extensions (of extensions()) name only pictures and films. @param list<string> $extensions */
    public static function mediaOnly(array $extensions): bool
    {
        if ($extensions === []) {
            return false;
        }
        foreach ($extensions as $e) {
            if (!in_array($e, self::IMAGES, true) && !in_array($e, self::VIDEOS, true)) {
                return false;
            }
        }
        return true;
    }

    /** The MIME types of $extensions, one each, "application/octet-stream" for one not known. @param list<string> $extensions @return list<string> */
    public static function mimeTypes(array $extensions): array
    {
        $out = [];
        foreach ($extensions as $e) {
            $out[self::MIME[$e] ?? 'application/octet-stream'] = true;
        }
        return array_keys($out);
    }

    /** The photo picker's types for $extensions: "image/*", "video/*", or both. @param list<string> $extensions @return list<string> */
    public static function mediaKinds(array $extensions): array
    {
        $out = [];
        foreach ($extensions as $e) {
            $out[in_array($e, self::IMAGES, true) ? 'image/*' : 'video/*'] = true;
        }
        return array_keys($out);
    }
}
