<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Document Parser
 * Safely extracts text content from TXT, PDF, and DOCX files.
 */

class DocumentParser {
    /**
     * Extract readable text from uploaded file based on its mime type / extension
     */
    public static function extractText(string $filePath, string $extension): string {
        if (!file_exists($filePath)) {
            return '';
        }

        $extension = strtolower($extension);

        return match ($extension) {
            'txt' => self::parseTxt($filePath),
            'docx' => self::parseDocx($filePath),
            'pdf' => self::parsePdf($filePath),
            default => '',
        };
    }

    private static function parseTxt(string $filePath): string {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return '';
        }
        // Normalize encoding to UTF-8
        $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-1'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        return trim($content);
    }

    private static function parseDocx(string $filePath): string {
        $content = '';

        // 1. Попытка через стандартный ZipArchive, если расширение загружено
        if (class_exists('ZipArchive')) {
            try {
                $zip = new ZipArchive();
                if ($zip->open($filePath) === true) {
                    $xmlIndex = $zip->locateName('word/document.xml');
                    if ($xmlIndex !== false) {
                        $xmlData = $zip->getFromIndex($xmlIndex);
                        if ($xmlData) {
                            $xml = new DOMDocument();
                            @$xml->loadXML($xmlData, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING);
                            $content = $xml->textContent ?? '';
                        }
                    }
                    $zip->close();
                }
            } catch (\Throwable $e) {}
        }

        // 2. Фоллбэк: чистый PHP парсер ZIP-структуры без необходимости расширения ZipArchive
        if (empty($content)) {
            $content = self::extractDocxPurePhp($filePath);
        }

        return trim(preg_replace('/\s+/', ' ', $content));
    }

    /**
     * Извлечение текста из word/document.xml внутри DOCX без расширения ZipArchive
     */
    private static function extractDocxPurePhp(string $filePath): string {
        $data = @file_get_contents($filePath);
        if (!$data || strlen($data) < 30) {
            return '';
        }

        // Поиск файла word/document.xml по локальным заголовкам ZIP (PK\x03\x04)
        $offset = 0;
        $targetFilename = 'word/document.xml';
        while (($pos = strpos($data, "PK\x03\x04", $offset)) !== false) {
            if ($pos + 30 > strlen($data)) break;
            $header = substr($data, $pos, 30);
            $compMethod = unpack('v', substr($header, 8, 2))[1] ?? 0;
            $compSize   = unpack('V', substr($header, 18, 4))[1] ?? 0;
            $uncompSize = unpack('V', substr($header, 22, 4))[1] ?? 0;
            $nameLen    = unpack('v', substr($header, 26, 2))[1] ?? 0;
            $extraLen   = unpack('v', substr($header, 28, 2))[1] ?? 0;

            $filename = substr($data, $pos + 30, $nameLen);
            $fileDataPos = $pos + 30 + $nameLen + $extraLen;

            if ($filename === $targetFilename) {
                if ($fileDataPos + $compSize <= strlen($data)) {
                    $compressedData = substr($data, $fileDataPos, $compSize);
                    $xml = '';
                    if ($compMethod === 8 && function_exists('gzinflate')) {
                        $xml = @gzinflate($compressedData);
                    } elseif ($compMethod === 0) {
                        $xml = $compressedData;
                    }
                    if ($xml) {
                        return strip_tags(str_replace(['<w:p>', '</w:p>', '<w:tab/>', '<w:br/>'], ["\n", "\n", "\t", "\n"], $xml));
                    }
                }
                break;
            }
            $offset = $pos + 4;
        }

        // 3. Последний фоллбэк: грубое извлечение тегов <w:t> из сырого содержимого (если распакован или без сжатия)
        if (preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/is', $data, $matches)) {
            return implode(' ', $matches[1]);
        }

        return '';
    }

    private static function parsePdf(string $filePath): string {
        // Pure PHP PDF text extraction stream parser for text-based PDFs
        $content = file_get_contents($filePath);
        if ($content === false) {
            return '';
        }

        $text = '';
        // Look for stream ... endstream blocks
        if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $content, $matches)) {
            foreach ($matches[1] as $stream) {
                $decompressed = @gzuncompress($stream);
                $target = ($decompressed !== false) ? $decompressed : $stream;

                // Extract text objects inside parenthesis (BT ... ET)
                if (preg_match_all('/\((.*?)\)\s*T[jJ]/s', $target, $textMatches)) {
                    foreach ($textMatches[1] as $tm) {
                        $text .= $tm . ' ';
                    }
                } elseif (preg_match_all('/\[(.*?)\]\s*TJ/s', $target, $arrayMatches)) {
                    foreach ($arrayMatches[1] as $am) {
                        if (preg_match_all('/\((.*?)\)/s', $am, $bracketMatches)) {
                            $text .= implode('', $bracketMatches[1]) . ' ';
                        }
                    }
                }
            }
        }

        // If compressed text extraction yielded little text, try plain string search
        if (mb_strlen(trim($text)) < 20) {
            // Strip binary characters and extract ASCII/UTF-8 words
            preg_match_all('/[a-zA-Zа-яА-ЯёЁ0-9\s.,;:\-\(\)\"\'«»]{4,}/u', $content, $cleanMatches);
            if (!empty($cleanMatches[0])) {
                $text = implode(' ', $cleanMatches[0]);
            }
        }

        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
