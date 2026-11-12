<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Pure PHP SSL IMAP Client
 * Не требует устаревшего расширения ext-imap, работает через OpenSSL сокеты.
 * Поддерживает Gmail, Mail.ru, Yandex, Rambler и любые другие IMAP-серверы.
 */

class ImapClient {
    private $socket = null;
    private int $tagCount = 0;
    private ?string $lastError = null;
    private bool $connected = false;

    public function __construct() {}

    public function __destruct() {
        $this->close();
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    /**
     * Подключение к IMAP-серверу по SSL/TLS
     */
    public function connect(string $host, int $port = 993, int $timeout = 10): bool {
        $this->close();
        $this->lastError = null;

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
                'crypto_method'    => STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            ]
        ]);

        $errno = 0;
        $errstr = '';
        $remote = "ssl://{$host}:{$port}";

        $this->socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->socket) {
            $this->lastError = "Не удалось подключиться к {$host}:{$port} ($errno $errstr)";
            return false;
        }

        stream_set_timeout($this->socket, $timeout);
        $greeting = fgets($this->socket);
        if (!$greeting || !str_starts_with(trim($greeting), '* OK')) {
            $this->lastError = "Неожиданный ответ сервера: " . ($greeting ?: 'пустой ответ');
            $this->close();
            return false;
        }

        $this->connected = true;
        return true;
    }

    /**
     * Авторизация (LOGIN)
     */
    public function login(string $username, string $password): bool {
        if (!$this->connected || !$this->socket) {
            $this->lastError = "Соединение не установлено";
            return false;
        }

        // Экранирование спецсимволов для IMAP кавычек
        $safeUser = addcslashes($username, "\\\"");
        $safePass = addcslashes($password, "\\\"");

        $tag = $this->nextTag();
        $cmd = "{$tag} LOGIN \"{$safeUser}\" \"{$safePass}\"\r\n";
        fwrite($this->socket, $cmd);

        $response = $this->readUntilTag($tag);
        $lastLine = end($response) ?: '';

        if (str_contains($lastLine, "{$tag} OK")) {
            return true;
        }

        $this->lastError = "Ошибка авторизации: " . trim($lastLine);
        return false;
    }

    private ?string $currentFolder = null;

    /**
     * Выбор папки (SELECT INBOX)
     */
    public function selectFolder(string $folder = 'INBOX'): array {
        if (!$this->connected) return ['success' => false, 'count' => 0];

        $tag = $this->nextTag();
        $cmd = "{$tag} SELECT \"{$folder}\"\r\n";
        fwrite($this->socket, $cmd);

        $lines = $this->readUntilTag($tag);
        $count = 0;
        $recent = 0;
        $unseen = 0;

        foreach ($lines as $line) {
            if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', $line, $m)) {
                $count = (int)$m[1];
            }
            if (preg_match('/^\*\s+(\d+)\s+RECENT/i', $line, $m)) {
                $recent = (int)$m[1];
            }
            if (preg_match('/UNSEEN\s+(\d+)/i', $line, $m)) {
                $unseen = (int)$m[1];
            }
        }

        $last = end($lines) ?: '';
        $success = str_contains($last, "{$tag} OK");
        if ($success) {
            $this->currentFolder = $folder;
        }

        return [
            'success' => $success,
            'count'   => $count,
            'recent'  => $recent,
            'unseen'  => $unseen
        ];
    }

    /**
     * Получить список всех папок на IMAP сервере
     */
    public function listFolders(): array {
        if (!$this->connected) return [];
        $tag = $this->nextTag();
        $cmd = "{$tag} LIST \"\" \"*\"\r\n";
        fwrite($this->socket, $cmd);
        $lines = $this->readUntilTag($tag);
        $folders = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\*\s+LIST\s+\(([^)]*)\)\s+("[^"]*"|\S+)\s+(.+)$/i', $line, $m)) {
                $flags = array_filter(explode(' ', trim($m[1])));
                $delimiter = trim($m[2], '"');
                $folderName = trim($m[3], " \t\r\n\"");
                $folders[] = [
                    'name' => $folderName,
                    'flags' => $flags,
                    'delimiter' => $delimiter,
                ];
            }
        }
        return $folders;
    }

    /**
     * Автоопределение имени папки «Отправленные» (Sent)
     */
    public function findSentFolder(): string {
        $folders = $this->listFolders();
        foreach ($folders as $f) {
            foreach ($f['flags'] as $fl) {
                if (strcasecmp($fl, '\\Sent') === 0) {
                    return $f['name'];
                }
            }
        }
        $candidates = ['[Gmail]/Sent Mail', 'Sent', 'INBOX.Sent', 'Sent Messages', 'Отправленные', 'Sent Items', '&BB4EQgQ,BEAEMAQyBDsENQQ9BD0ESwQ1-'];
        foreach ($candidates as $c) {
            foreach ($folders as $f) {
                if (strcasecmp($f['name'], $c) === 0) {
                    return $f['name'];
                }
            }
        }
        return 'Sent';
    }

    /**
     * Автоопределение имени папки «Корзина» (Trash)
     */
    public function findTrashFolder(): string {
        $folders = $this->listFolders();
        foreach ($folders as $f) {
            foreach ($f['flags'] as $fl) {
                if (strcasecmp($fl, '\\Trash') === 0) {
                    return $f['name'];
                }
            }
        }
        $candidates = ['[Gmail]/Trash', 'Trash', 'INBOX.Trash', 'Deleted Messages', 'Deleted Items', 'Корзина', 'Удаленные', '&BBoEPgRABDcEOAQ9BDA-'];
        foreach ($candidates as $c) {
            foreach ($folders as $f) {
                if (strcasecmp($f['name'], $c) === 0) {
                    return $f['name'];
                }
            }
        }
        return 'Trash';
    }

    /**
     * Пометить письмо как удаленное в IMAP
     */
    public function deleteMessage(int $msgSeq, string $folder = 'INBOX'): bool {
        if (!$this->connected) return false;
        if ($this->currentFolder !== $folder) {
            $this->selectFolder($folder);
        }
        $tag = $this->nextTag();
        $cmd = "{$tag} STORE {$msgSeq} +FLAGS (\\Deleted)\r\n";
        fwrite($this->socket, $cmd);
        $res = $this->readUntilTag($tag);
        $last = end($res) ?: '';
        if (!str_contains($last, "{$tag} OK")) return false;

        $tag2 = $this->nextTag();
        fwrite($this->socket, "{$tag2} EXPUNGE\r\n");
        $res2 = $this->readUntilTag($tag2);
        return str_contains(end($res2) ?: '', "{$tag2} OK");
    }

    /**
     * Получение списка последних писем из папки
     */
    public function getMessages(string $folder = 'INBOX', int $limit = 25): array {
        $sel = $this->selectFolder($folder);
        if (!$sel['success'] || $sel['count'] === 0) {
            return [];
        }

        $total = $sel['count'];
        $start = max(1, $total - $limit + 1);
        $range = "{$start}:{$total}";

        $tag = $this->nextTag();
        // Запрашиваем заголовки, UID и флаги
        $cmd = "{$tag} FETCH {$range} (UID FLAGS BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE CONTENT-TYPE)])\r\n";
        fwrite($this->socket, $cmd);

        $lines = $this->readUntilTag($tag);
        $messages = [];
        $currentMsg = null;
        $headerBuffer = '';
        $isReadingHeader = false;

        foreach ($lines as $line) {
            if (preg_match('/^\*\s+(\d+)\s+FETCH\s+\((.*)/i', $line, $m)) {
                if ($currentMsg !== null) {
                    $this->parseHeaderBuffer($currentMsg, $headerBuffer);
                    $messages[] = $currentMsg;
                }
                $currentMsg = [
                    'seq'       => (int)$m[1],
                    'uid'       => '',
                    'from'      => '',
                    'from_name' => '',
                    'to'        => '',
                    'subject'   => '(Без темы)',
                    'date'      => '',
                    'timestamp' => 0,
                    'raw_date'  => '',
                    'iso_date'  => '',
                    'seen'      => false,
                    'snippet'   => ''
                ];

                if (preg_match('/UID\s+(\d+)/i', $m[2], $um)) {
                    $currentMsg['uid'] = $um[1];
                }
                if (str_contains($m[2], '\\Seen')) {
                    $currentMsg['seen'] = true;
                }

                $headerBuffer = '';
                $isReadingHeader = str_contains($line, 'BODY[HEADER.FIELDS');
                continue;
            }

            if ($isReadingHeader) {
                if (trim($line) === ')' || preg_match('/^\*\s+\d+\s+FETCH/i', $line) || str_starts_with($line, $tag)) {
                    $isReadingHeader = false;
                } else {
                    $headerBuffer .= $line;
                }
            }
        }

        if ($currentMsg !== null) {
            $this->parseHeaderBuffer($currentMsg, $headerBuffer);
            $messages[] = $currentMsg;
        }

        // Сортируем: новые сверху
        usort($messages, function ($a, $b) {
            return ($b['seq'] ?? 0) <=> ($a['seq'] ?? 0);
        });

        return $messages;
    }

    /**
     * Получить полное тело письма по номеру последовательности (seq) или UID
     */
    public function getMessageBody(int $msgSeq, string $folder = 'INBOX', bool $isUid = false): array {
        if (!$this->connected) return ['text' => '', 'html' => ''];

        // Всегда гарантируем выбор папки перед выполнением FETCH
        if ($this->currentFolder !== $folder) {
            $this->selectFolder($folder);
        }

        $tag = $this->nextTag();
        $prefix = $isUid ? "UID " : "";
        $cmd = "{$tag} {$prefix}FETCH {$msgSeq} (BODY.PEEK[])\r\n";
        fwrite($this->socket, $cmd);

        $lines = $this->readUntilTag($tag);
        $raw = implode("", $lines);

        return $this->parseMimeMessage($raw);
    }

    /**
     * Парсер заголовков письма
     */
    private function parseHeaderBuffer(array &$msg, string $headerStr): void {
        // Разворачиваем перенесенные строки (folding)
        $unfolded = preg_replace("/\r?\n[ \t]+/", " ", $headerStr);
        $lines = explode("\n", $unfolded);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (stripos($line, 'From:') === 0) {
                $rawFrom = trim(substr($line, 5));
                if (preg_match('/(.*)<(.*)>/', $rawFrom, $fm)) {
                    $msg['from_name'] = $this->decodeMimeHeader(trim($fm[1], " \"'"));
                    $msg['from']      = trim($fm[2]);
                } else {
                    $msg['from']      = trim($rawFrom, " <>\t\r\n");
                    $msg['from_name'] = $msg['from'];
                }
            } elseif (stripos($line, 'To:') === 0) {
                $msg['to'] = trim(substr($line, 3));
            } elseif (stripos($line, 'Subject:') === 0) {
                $rawSubj = trim(substr($line, 8));
                $msg['subject'] = $this->decodeMimeHeader($rawSubj);
            } elseif (stripos($line, 'Date:') === 0) {
                $rawDate = trim(substr($line, 5));
                $time = strtotime($rawDate);
                $msg['date'] = $time ? date('d.m.Y H:i', $time) : $rawDate;
                $msg['timestamp'] = $time ?: 0;
                $msg['raw_date'] = $rawDate;
                $msg['iso_date'] = $time ? date('c', $time) : '';
            }
        }
    }

    /**
     * Декодирование MIME-заголовков (=?UTF-8?B?...?= или =?UTF-8?Q?...?=)
     */
    public function decodeMimeHeader(string $text): string {
        if (empty($text)) return '';
        
        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($text);
            if (!empty($decoded) && $decoded !== $text) {
                return $decoded;
            }
        }

        // Ручной парсер MIME кодировок
        $pattern = '/=\?([^?]+)\?([BQbq])\?([^?]+)\?=/';
        return preg_replace_callback($pattern, function ($matches) {
            $charset = strtoupper($matches[1]);
            $encoding = strtoupper($matches[2]);
            $encoded = $matches[3];

            if ($encoding === 'B') {
                $str = base64_decode($encoded);
            } else {
                $str = quoted_printable_decode(str_replace('_', ' ', $encoded));
            }

            if ($charset !== 'UTF-8' && function_exists('iconv')) {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $str);
                return $converted !== false ? $converted : $str;
            }
            return $str;
        }, $text);
    }

    /**
     * Парсер сырого MIME сообщения (рекурсивный, с поддержкой любой вложенности)
     */
    private function parseMimeMessage(string $raw): array {
        $result = ['text' => '', 'html' => '', 'attachments' => []];

        // Убираем первую строчку ответа IMAP FETCH (например: * 9753 FETCH (BODY[] {12345})
        if (preg_match('/^\*\s+\d+\s+FETCH\s+.*\{\d+\}\r?\n/i', $raw, $firstLineMatch)) {
            $raw = substr($raw, strlen($firstLineMatch[0]));
        }

        // Убираем завершающие скобки и статус OK от IMAP
        $raw = preg_replace('/\r?\n\)\r?\n[A-Z0-9]+\s+OK.*\r?\n?$/i', '', $raw);

        // Ищем границу между заголовками сообщения и его телом
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        if (count($parts) < 2) {
            return $result;
        }

        $headers = $parts[0];
        $body = $parts[1];

        $this->extractMimeParts($headers, $body, $result);

        // Если HTML пустой, но есть текст — формируем чистый читаемый вид
        if (empty($result['html']) && !empty($result['text'])) {
            $result['html'] = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; white-space:pre-wrap; line-height:1.6; color:#111827; padding:16px;">' . htmlspecialchars($result['text']) . '</div>';
        }

        return $result;
    }

    /**
     * Рекурсивное извлечение частей письма (text/plain, text/html)
     */
    private function extractMimeParts(string $headers, string $body, array &$result): void {
        // Проверяем наличие границы boundary
        if (preg_match('/boundary="?([^";\r\n]+)"?/i', $headers, $bm)) {
            $boundary = trim($bm[1]);
            $sections = explode("--" . $boundary, $body);

            foreach ($sections as $sec) {
                $sec = trim($sec);
                if ($sec === '--' || empty($sec)) continue;

                $secParts = preg_split("/\r?\n\r?\n/", $sec, 2);
                if (count($secParts) < 2) continue;

                $secHeaders = $secParts[0];
                $secBody = $secParts[1];

                // Если внутри снова multipart (например multipart/alternative внутри multipart/mixed/related)
                if (stripos($secHeaders, 'multipart/') !== false && preg_match('/boundary=/i', $secHeaders)) {
                    $this->extractMimeParts($secHeaders, $secBody, $result);
                } else {
                    $encoding = '7bit';
                    if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n;]+)/i', $secHeaders, $em)) {
                        $encoding = strtolower(trim($em[1]));
                    }

                    $contentType = 'text/plain';
                    if (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $secHeaders, $ctm)) {
                        $contentType = strtolower(trim($ctm[1]));
                    }

                    $charset = 'utf-8';
                    if (preg_match('/charset="?([^";\r\n]+)"?/i', $secHeaders, $csm)) {
                        $charset = strtolower(trim($csm[1]));
                    }

                    $decoded = $this->decodeContent($secBody, $encoding);
                    if ($charset !== 'utf-8' && !empty($charset)) {
                        if (function_exists('mb_convert_encoding')) {
                            $decoded = @mb_convert_encoding($decoded, 'UTF-8', $charset) ?: $decoded;
                        } elseif (function_exists('iconv')) {
                            $decoded = @iconv($charset, 'UTF-8//IGNORE', $decoded) ?: $decoded;
                        }
                    }

                    if (str_contains($contentType, 'text/html')) {
                        if (empty($result['html']) || strlen($decoded) > strlen($result['html'])) {
                            $result['html'] = $decoded;
                        }
                    } elseif (str_contains($contentType, 'text/plain')) {
                        if (empty($result['text']) || strlen($decoded) > strlen($result['text'])) {
                            $result['text'] = $decoded;
                        }
                    }
                }
            }
        } else {
            // Односоставное письмо без boundary
            $encoding = '7bit';
            if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n;]+)/i', $headers, $em)) {
                $encoding = strtolower(trim($em[1]));
            }
            $contentType = 'text/plain';
            if (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $headers, $ctm)) {
                $contentType = strtolower(trim($ctm[1]));
            }
            $charset = 'utf-8';
            if (preg_match('/charset="?([^";\r\n]+)"?/i', $headers, $csm)) {
                $charset = strtolower(trim($csm[1]));
            }

            $decoded = $this->decodeContent($body, $encoding);
            if ($charset !== 'utf-8' && !empty($charset)) {
                if (function_exists('mb_convert_encoding')) {
                    $decoded = @mb_convert_encoding($decoded, 'UTF-8', $charset) ?: $decoded;
                } elseif (function_exists('iconv')) {
                    $decoded = @iconv($charset, 'UTF-8//IGNORE', $decoded) ?: $decoded;
                }
            }

            if (str_contains($contentType, 'text/html')) {
                $result['html'] = $decoded;
            } else {
                $result['text'] = $decoded;
            }
        }
    }

    /**
     * Декодирование содержимого тела
     */
    private function decodeContent(string $data, string $encoding): string {
        $encoding = strtolower(trim($encoding));
        if ($encoding === 'base64') {
            return base64_decode($data);
        }
        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($data);
        }
        return $data;
    }

    private function nextTag(): string {
        $this->tagCount++;
        return sprintf("A%04d", $this->tagCount);
    }

    private function readUntilTag(string $tag): array {
        $lines = [];
        if (!$this->socket) return $lines;

        while (!feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $lines[] = $line;
            if (str_starts_with($line, $tag . " ")) {
                break;
            }
        }
        return $lines;
    }

    public function close(): void {
        if ($this->socket) {
            if ($this->connected) {
                $tag = $this->nextTag();
                @fwrite($this->socket, "{$tag} LOGOUT\r\n");
            }
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }
}
