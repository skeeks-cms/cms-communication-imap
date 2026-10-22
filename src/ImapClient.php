<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\imap;

/**
 * A small IMAP4rev1 client over a PHP stream: the commands a mailbox reader
 * needs (LOGIN, LIST, EXAMINE, UID SEARCH, UID FETCH, APPEND, LOGOUT).
 * It does not need ext-imap. Literals ({n}) are supported in both directions.
 *
 * Exception messages never contain the password or the LOGIN command.
 */
class ImapClient
{
    /** @var resource|null */
    private $stream;
    private int $tagCounter = 0;

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption = ImapDriver::ENCRYPTION_SSL,
        private int $timeout = 30,
    ) {
    }

    public function connect(): void
    {
        $scheme = $this->encryption === ImapDriver::ENCRYPTION_SSL ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
        ]]);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($scheme.'://'.$this->host.':'.$this->port, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$stream) {
            throw new ImapException("Не удалось подключиться к {$this->host}:{$this->port}: ".($errstr ?: 'нет ответа').'.');
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;

        $greeting = $this->readLine();
        if (!preg_match('/^\* (OK|PREAUTH)\b/i', $greeting)) {
            throw new ImapException('Сервер не ответил приветствием IMAP.');
        }

        if ($this->encryption === ImapDriver::ENCRYPTION_STARTTLS) {
            $this->command('STARTTLS');
            if (!@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new ImapException('Не удалось включить шифрование STARTTLS.');
            }
        }
    }

    public function login(string $username, string $password): void
    {
        try {
            $this->command(['LOGIN ', $this->string($username), ' ', $this->string($password)]);
        } catch (ImapException $e) {
            // The server text explains the reason; the command itself holds the password.
            throw new ImapException('Сервер не принял логин или пароль: '.$e->serverText.'.', $e->serverText);
        }
    }

    /**
     * @return array[] each: name, flags (lowercased, e.g. "\sent")
     */
    public function listFolders(): array
    {
        $folders = [];
        foreach ($this->command('LIST "" "*"') as $item) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (?:NIL|"(?:[^"\\\\]|\\\\.)*") (.+)$/is', $item['text'], $m)) {
                continue;
            }
            $folders[] = [
                'name' => $this->parseAstring(trim($m[2]), $item['literals']),
                'flags' => array_map('strtolower', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)),
            ];
        }

        return $folders;
    }

    /**
     * Opens a folder read-only.
     *
     * @return array uidvalidity, uidnext
     */
    public function examine(string $folder): array
    {
        $result = ['uidvalidity' => null, 'uidnext' => null];
        foreach ($this->command(['EXAMINE ', $this->string($folder)]) as $item) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $item['text'], $m)) {
                $result['uidvalidity'] = (int)$m[1];
            }
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $item['text'], $m)) {
                $result['uidnext'] = (int)$m[1];
            }
        }

        return $result;
    }

    /** @return int[] */
    public function uidSearch(string $criteria): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH '.$criteria) as $item) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $item['text'], $m)) {
                foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) as $uid) {
                    $uids[] = (int)$uid;
                }
            }
        }
        sort($uids);

        return array_values(array_unique($uids));
    }

    /**
     * @param int[] $uids
     * @return string[] uid => raw message
     */
    public function fetchRaw(array $uids): array
    {
        if (!$uids) {
            return [];
        }
        $result = [];
        foreach ($this->command('UID FETCH '.implode(',', array_map('intval', $uids)).' (UID BODY.PEEK[])') as $item) {
            if (!preg_match('/^\* \d+ FETCH /i', $item['text'])
                || !preg_match('/\bUID (\d+)/i', $item['text'], $uid)
                || !preg_match('/BODY\[\] \{LIT:(\d+)\}/i', $item['text'], $literal)) {
                continue;
            }
            $result[(int)$uid[1]] = $item['literals'][(int)$literal[1]] ?? '';
        }

        return $result;
    }

    /**
     * One header of each letter, without marking them read.
     *
     * @param int[] $uids
     * @return string[] uid => header lines
     */
    public function fetchHeader(array $uids, string $field): array
    {
        if (!$uids) {
            return [];
        }
        $field = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $field));
        $result = [];
        foreach ($this->command('UID FETCH '.implode(',', array_map('intval', $uids)).' (UID BODY.PEEK[HEADER.FIELDS ('.$field.')])') as $item) {
            if (preg_match('/\bUID (\d+)/i', $item['text'], $uid)
                && preg_match('/BODY\[HEADER\.FIELDS \([^)]*\)\] \{LIT:(\d+)\}/i', $item['text'], $literal)) {
                $result[(int)$uid[1]] = (string)($item['literals'][(int)$literal[1]] ?? '');
            }
        }

        return $result;
    }

    public function append(string $folder, string $raw, array $flags = ['\\Seen']): void
    {
        $this->command(['APPEND ', $this->string($folder), ' ('.implode(' ', $flags).') ', ['literal' => $raw]]);
    }

    public function logout(): void
    {
        if (!$this->stream) {
            return;
        }
        try {
            $this->command('LOGOUT');
        } catch (\Throwable $e) {
        }
        @fclose($this->stream);
        $this->stream = null;
    }

    /**
     * Quoted string, or a literal for values with line breaks or non-ASCII bytes.
     *
     * @return string|array
     */
    public function string(string $value)
    {
        if (preg_match('/[\r\n\x80-\xff]/', $value)) {
            return ['literal' => $value];
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * Sends a tagged command and reads its response.
     *
     * @param string|array $parts strings and ['literal' => string] parts
     * @return array[] untagged responses: text (literals replaced by {LIT:i}), literals
     */
    public function command($parts): array
    {
        if (!$this->stream) {
            throw new ImapException('Нет подключения к серверу IMAP.');
        }
        $tag = 'A'.(++$this->tagCounter);
        $parts = is_array($parts) ? $parts : [$parts];

        $buffer = $tag.' ';
        foreach ($parts as $part) {
            if (is_array($part)) {
                $this->write($buffer.'{'.strlen($part['literal']).'}'."\r\n");
                $buffer = '';
                $continuation = $this->readLine();
                if (strncmp($continuation, '+', 1) !== 0) {
                    throw new ImapException('Сервер отклонил команду.', trim($continuation));
                }
                $this->write($part['literal']);
            } else {
                $buffer .= $part;
            }
        }
        $this->write($buffer."\r\n");

        return $this->readResponse($tag);
    }

    private function readResponse(string $tag): array
    {
        $items = [];
        while (true) {
            $line = $this->readLine();
            if (strncmp($line, $tag.' ', strlen($tag) + 1) === 0) {
                $status = strtoupper((string)strtok(substr($line, strlen($tag) + 1), ' '));
                if ($status !== 'OK') {
                    $text = trim(substr($line, strlen($tag) + 1 + strlen($status)));
                    throw new ImapException('Сервер IMAP ответил '.$status.': '.$text, $text);
                }

                return $items;
            }

            $item = ['text' => '', 'literals' => []];
            while (true) {
                if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                    $item['text'] .= substr($line, 0, -strlen($m[0])).'{LIT:'.count($item['literals']).'}';
                    $item['literals'][] = $this->readBytes((int)$m[1]);
                    $line = $this->readLine();
                    continue;
                }
                $item['text'] .= rtrim($line, "\r\n");
                break;
            }
            $items[] = $item;
        }
    }

    private function parseAstring(string $value, array $literals): string
    {
        if (preg_match('/^\{LIT:(\d+)\}$/', $value, $m)) {
            return (string)($literals[(int)$m[1]] ?? '');
        }
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    private function write(string $data): void
    {
        $length = strlen($data);
        for ($written = 0; $written < $length; $written += $chunk) {
            $chunk = @fwrite($this->stream, substr($data, $written));
            if ($chunk === false || $chunk === 0) {
                throw new ImapException('Соединение с сервером IMAP прервано.');
            }
        }
    }

    private function readLine(): string
    {
        $line = @fgets($this->stream);
        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);
            throw new ImapException(!empty($meta['timed_out']) ? 'Сервер IMAP не ответил вовремя.' : 'Соединение с сервером IMAP прервано.');
        }

        return $line;
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($this->stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                if (!empty($meta['timed_out']) || feof($this->stream)) {
                    throw new ImapException('Соединение с сервером IMAP прервано при получении письма.');
                }
                continue;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
