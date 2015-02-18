<?php

namespace Mxm\Api;

use Mxm\Api;

/**
 * MXM JSON API Client
 *
 * @category   Mxm
 * @package    Api
 * @copyright  Copyright (c) 2007-2015 Emailcenter UK. (http://www.emailcenteruk.com)
 * @license    Commercial
 */
class Helper
{
    /**
     * @var Api
     */
    protected $api;

    /**
     * @param Api $api
     */
    public function __construct(Api $api)
    {
        $this->api = $api;
    }

    /**
     * Download file by type
     *
     * @param string $type
     * @param string|int $primaryId
     * @param array $options {
     *     @var bool   $extract Whether to extract a compressed download, default true
     *     @var string $dir     Directory to use for downloaded file(s), default sys_temp_dir
     * }
     * @return string filename
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function download($type, $primaryId, array $options = [])
    {
        $typePrimary = array(
            'file'       => 'key',
            'listexport' => 'id',
            'dataexport' => 'id',
        );

        if (!isset($typePrimary[$type])) {
            throw new \InvalidArgumentException("Invalid download type specified");
        }

        // Create target file
        $filename = tempnam(
            (isset($options['dir']) ? $options['dir'] : sys_get_temp_dir()),
            "mxm-{$type}-{$primaryId}-"
        );

        // Build URL
        $config = $this->api->getConfig();
        $url = ($config['useSsl'] ? 'https' : 'http') .
            "://{$config['host']}" .
            "/download/{$type}/" .
            "{$typePrimary[$type]}/{$primaryId}";

        // Set up stream
        $opts = array(
            'http' => array(
                'header' => sprintf("Authorization: Basic %s\r\n", base64_encode($config['user'] . ':' . $config['pass']))
            )
        );
        $context = stream_context_create($opts);

        // Get file
        $this->api->getLogger()->debug("Download file {$type} {$primaryId}", [
            'url'  => $url,
            'user' => $config['user']
        ]);
        $local = fopen($filename, 'w');
        $remote = fopen($url, 'r', false, $context);
        while ($content = fread($remote, 101400)) {
            fwrite($local, $content);
        }
        fclose($local);
        fclose($remote);

        // Get MIME
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($filename);
        if ($mime === false) {
            throw new \RuntimeException("MIME type could not be determined");
        }

        // Add extension to filename, optionally extract zip
        switch (true) {
            case (strpos($mime, 'zip')) :
                if (!isset($options['extract']) || $options['extract'] == true) {
                    // Maxemail only compresses CSV files, and only contains one file in a zip
                    $zip = new \ZipArchive();
                    $zip->open($filename);
                    $filenameExtract = $zip->getNameIndex(0);
                    $targetDir = rtrim(dirname($filename), '/');
                    $zip->extractTo($targetDir . '/');
                    $zip->close();
                    rename($targetDir . '/' . $filenameExtract, $filename . '.csv');
                    unlink($filename);
                    $filename = $filename . '.csv';
                } else {
                    rename($filename, $filename . '.zip');
                    $filename = $filename . '.zip';
                }

                break;

            case (strpos($mime, 'pdf')) :
                rename($filename, $filename . '.pdf');
                $filename = $filename . '.pdf';
                break;

            case (strpos($mime, 'csv')) :
                // no break
            case ($mime == 'text/plain') :
                rename($filename, $filename . '.csv');
                $filename = $filename . '.csv';
                break;
        }

        return $filename;
    }
}