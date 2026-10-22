<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\imap;

/**
 * A small IMAP4rev1 client over a PHP stream: the commands a mailbox reader
 * needs (LOGIN, LIST, EXAMINE, UID SEARCH, UID FETCH, APPEND, LOGOUT) and the
 * ones that change a mailbox (SELECT, STATUS, UID MOVE / COPY / STORE /
 * EXPUNGE, CREATE). It does not need ext-imap. Literals ({n}) are supported
 * in both directions.
 *
 * Exception messages never contain the password or the LOGIN command.
 */
class ImapClient
{
    /** @var resource|null */
    private $stream;
    private int $tagCounter = 0;
    /** @var string[]|null upper-case capabilities, asked once per connection */
    private ?array $capabilities = null;
    /** Text of the last tagged OK: COPYUID of UID COPY comes there. */
    private string $lastTagged = '';

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
     * @return array[] each: name, flags (lowercased, e.g. "\sent"), delimiter (string|null)
     */
    public function listFolders(): array
    {
        $folders = [];
        foreach ($this->command('LIST "" "*"') as $item) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (NIL|"(?:[^"\\\\]|\\\\.)*") (.+)$/is', $item['text'], $m)) {
                continue;
            }
            $folders[] = [
                'name' => $this->parseAstring(trim($m[3]), $item['literals']),
                'flags' => array_map('strtolower', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)),
                'delimiter' => strtoupper($m[2]) === 'NIL' ? null : stripcslashes(substr($m[2], 1, -1)),
            ];
        }

        return $folders;
    }

    /** @return string[] upper-case capabilities */
    public function capabilities(): array
    {
        if ($this->capabilities === null) {
            $this->capabilities = [];
            foreach ($this->command('CAPABILITY') as $item) {
                if (preg_match('/^\* CAPABILITY (.+)$/i', $item['text'], $m)) {
                    $this->capabilities = array_map('strtoupper', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY));
                }
            }
        }

        return $this->capabilities;
    }

    public function hasCapability(string $name): bool
    {
        return in_array(strtoupper($name), $this->capabilities(), true);
    }

    /**
     * Opens a folder read-only.
     *
     * @return array uidvalidity, uidnext, permanentflags (string[]|null)
     */
    public function examine(string $folder): array
    {
        return $this->open('EXAMINE', $folder);
    }

    /**
     * Opens a folder for changes.
     *
     * @return array uidvalidity, uidnext, permanentflags (string[]|null)
     */
    public function select(string $folder): array
    {
        return $this->open('SELECT', $folder);
    }

    private function open(string $command, string $folder): array
    {
        $result = ['uidvalidity' => null, 'uidnext' => null, 'permanentflags' => null];
        foreach ($this->command([$command.' ', $this->string($folder)]) as $item) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $item['text'], $m)) {
                $result['uidvalidity'] = (int)$m[1];
            }
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $item['text'], $m)) {
                $result['uidnext'] = (int)$m[1];
            }
            if (preg_match('/\[PERMANENTFLAGS \(([^)]*)\)\]/i', $item['text'], $m)) {
                $result['permanentflags'] = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        return $result;
    }

    /**
     * Folder counters without opening it.
     *
     * @return array uidvalidity, uidnext, messages (int|null each)
     */
    public function status(string $folder): array
    {
        $result = ['uidvalidity' => null, 'uidnext' => null, 'messages' => null];
        foreach ($this->command(['STATUS ', $this->string($folder), ' (MESSAGES UIDNEXT UIDVALIDITY)']) as $item) {
            foreach (['messages' => 'MESSAGES', 'uidnext' => 'UIDNEXT', 'uidvalidity' => 'UIDVALIDITY'] as $key => $word) {
                if (preg_match('/\b'.$word.' (\d+)/i', $item['text'], $m)) {
                    $result[$key] = (int)$m[1];
                }
            }
        }

        return $result;
    }

    /**
     * UID MOVE; where the server has no MOVE: UID COPY, UID STORE \Deleted and
     * UID EXPUNGE (plain EXPUNGE without UIDPLUS). The folder must be selected.
     *
     * @param int[] $uids
     * @return array uidvalidity (int|null) of the target, uids: old uid => new uid (from COPYUID, may be empty)
     */
    public function uidMove(array $uids, string $target): array
    {
        $set = self::uidSet($uids);
        if ($this->hasCapability('MOVE')) {
            $items = $this->command(['UID MOVE '.$set.' ', $this->string($target)]);
        } else {
            $items = $this->command(['UID COPY '.$set.' ', $this->string($target)]);
            $copied = $this->lastTagged;
            $this->command('UID STORE '.$set.' +FLAGS.SILENT (\\Deleted)');
            // Plain EXPUNGE also removes letters someone else marked deleted: only without UIDPLUS.
            $this->command($this->hasCapability('UIDPLUS') ? 'UID EXPUNGE '.$set : 'EXPUNGE');
            $this->lastTagged = $copied;
        }
        $texts = array_column($items, 'text');
        $texts[] = $this->lastTagged;
        foreach ($texts as $text) {
            if (preg_match('/\[COPYUID (\d+) ([0-9:,]+) ([0-9:,]+)\]/i', $text, $m)) {
                $from = self::expandSet($m[2]);
                $to = self::expandSet($m[3]);
                if (count($from) === count($to)) {
                    return ['uidvalidity' => (int)$m[1], 'uids' => array_combine($from, $to)];
                }
            }
        }

        return ['uidvalidity' => null, 'uids' => []];
    }

    /**
     * @param int[] $uids
     * @return array[] uid => flags and keywords
     */
    public function fetchFlags(array $uids): array
    {
        if (!$uids) {
            return [];
        }
        $result = [];
        foreach ($this->command('UID FETCH '.self::uidSet($uids).' (UID FLAGS)') as $item) {
            if (preg_match('/^\* \d+ FETCH /i', $item['text'])
                && preg_match('/\bUID (\d+)/i', $item['text'], $uid)
                && preg_match('/\bFLAGS \(([^)]*)\)/i', $item['text'], $flags)) {
                $result[(int)$uid[1]] = preg_split('/\s+/', trim($flags[1]), -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        return $result;
    }

    /**
     * @param int[] $uids
     * @param string $sign "+" adds, "-" removes
     * @param string[] $flags system flags (\Seen) or keywords (atoms)
     */
    public function storeFlags(array $uids, string $sign, array $flags): void
    {
        $flags = array_values(array_filter($flags, static fn($f) => (bool)preg_match('/^\\\\?[A-Za-z0-9$_.\-]+$/', (string)$f)));
        if (!$uids || !$flags) {
            return;
        }
        $this->command('UID STORE '.self::uidSet($uids).' '.($sign === '-' ? '-' : '+').'FLAGS.SILENT ('.implode(' ', $flags).')');
    }

    public function create(string $folder): void
    {
        $this->command(['CREATE ', $this->string($folder)]);
    }

    /**
     * A compact UID set: [1, 2, 3, 7] → "1:3,7".
     *
     * @param int[] $uids
     */
    public static function uidSet(array $uids): string
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids);
        $parts = [];
        $start = $prev = null;
        foreach ($uids as $uid) {
            if ($start !== null && $uid === $prev + 1) {
                $prev = $uid;
                continue;
            }
            if ($start !== null) {
                $parts[] = $start === $prev ? (string)$start : $start.':'.$prev;
            }
            $start = $prev = $uid;
        }
        if ($start !== null) {
            $parts[] = $start === $prev ? (string)$start : $start.':'.$prev;
        }

        return implode(',', $parts);
    }

    /** @return int[] "3:5,9" → [3, 4, 5, 9], in the order of the set */
    public static function expandSet(string $set): array
    {
        $result = [];
        foreach (explode(',', $set) as $part) {
            if (strpos($part, ':') !== false) {
                [$a, $b] = array_map('intval', explode(':', $part, 2));
                foreach (range($a, $b) as $uid) {
                    $result[] = $uid;
                }
            } elseif ($part !== '') {
                $result[] = (int)$part;
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
                $text = trim(substr($line, strlen($tag) + 1 + strlen($status)));
                if ($status !== 'OK') {
                    throw new ImapException('Сервер IMAP ответил '.$status.': '.$text, $text);
                }
                $this->lastTagged = $text;

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
