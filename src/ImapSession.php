<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\imap;

use skeeks\cms\communication\mail\MailManageSessionInterface;

/**
 * A logged-in IMAP connection for the receive job and for actions with
 * letters on the server.
 */
class ImapSession implements MailManageSessionInterface
{
    /** Sent folder names of servers that do not mark it with \Sent. */
    private const SENT_NAMES = ['sent', 'sent items', 'sent messages', 'sent mail', 'отправленные', '&BB4EQgQ,BEAEMAQyBDsENQQ9BD0ESwQ1-'];

    /** Special folders by SPECIAL-USE (RFC 6154) and XLIST marks. */
    private const ROLE_FLAGS = [
        '\\sent' => self::ROLE_SENT,
        '\\trash' => self::ROLE_TRASH,
        '\\junk' => self::ROLE_JUNK,
        '\\spam' => self::ROLE_JUNK,
        '\\archive' => self::ROLE_ARCHIVE,
        '\\drafts' => self::ROLE_DRAFTS,
    ];

    /** Names of special folders on servers without the marks (compared decoded, in lower case). */
    private const ROLE_NAMES = [
        self::ROLE_SENT => ['sent', 'sent items', 'sent messages', 'sent mail', 'отправленные'],
        self::ROLE_TRASH => ['trash', 'deleted', 'deleted items', 'deleted messages', 'bin', 'корзина', 'удаленные', 'удалённые'],
        self::ROLE_JUNK => ['junk', 'spam', 'junk e-mail', 'junk email', 'bulk mail', 'спам', 'нежелательная почта'],
        self::ROLE_ARCHIVE => ['archive', 'archives', 'архив'],
        self::ROLE_DRAFTS => ['drafts', 'черновики'],
    ];

    /** Letters per command where a UID set may get long. */
    private const CHUNK = 500;

    private ?string $selected = null;
    /** The open folder was opened for changes (SELECT), not read-only (EXAMINE). */
    private bool $writable = false;
    private array $opened = [];

    public function __construct(private ImapClient $client)
    {
    }

    public function folders(): array
    {
        $folders = [];
        $sentFound = false;
        foreach ($this->client->listFolders() as $folder) {
            if (in_array('\\noselect', $folder['flags'], true) || in_array('\\nonexistent', $folder['flags'], true)) {
                continue;
            }
            $role = null;
            if (strcasecmp($folder['name'], 'INBOX') === 0) {
                $role = 'inbox';
            } elseif (in_array('\\sent', $folder['flags'], true)) {
                $role = 'sent';
                $sentFound = true;
            }
            $folders[] = ['name' => $folder['name'], 'role' => $role];
        }

        if (!$sentFound) {
            foreach ($folders as &$folder) {
                $base = mb_strtolower(preg_replace('/^.*[\/.]/', '', $folder['name']));
                if ($folder['role'] === null && in_array($base, self::SENT_NAMES, true)) {
                    $folder['role'] = 'sent';
                    break;
                }
            }
            unset($folder);
        }

        return $folders;
    }

    public function folderList(): array
    {
        $folders = [];
        $found = [];
        foreach ($this->client->listFolders() as $folder) {
            if (in_array('\\noselect', $folder['flags'], true) || in_array('\\nonexistent', $folder['flags'], true)) {
                continue;
            }
            $role = null;
            if (strcasecmp($folder['name'], 'INBOX') === 0) {
                $role = self::ROLE_INBOX;
            } elseif (in_array('\\templates', $folder['flags'], true)) {
                // Templates of the mail service are drafts too: not a place for letters.
                $role = self::ROLE_DRAFTS;
            } else {
                foreach (self::ROLE_FLAGS as $flag => $flagRole) {
                    if (in_array($flag, $folder['flags'], true) && !isset($found[$flagRole])) {
                        $role = $flagRole;
                        break;
                    }
                }
            }
            if ($role !== null) {
                $found[$role] = true;
            }
            $folders[] = ['name' => $folder['name'], 'role' => $role, 'delimiter' => $folder['delimiter'] ?? null];
        }

        // Servers without the marks: well-known names of top-level folders (or of INBOX children).
        foreach (self::ROLE_NAMES as $role => $names) {
            if (isset($found[$role])) {
                continue;
            }
            foreach ($folders as &$folder) {
                if ($folder['role'] !== null) {
                    continue;
                }
                $decoded = self::decodeName($folder['name']);
                $delimiter = (string)($folder['delimiter'] ?? '');
                $parts = $delimiter !== '' ? explode($delimiter, $decoded) : [$decoded];
                $base = (string)end($parts);
                $topLevel = count($parts) === 1 || (count($parts) === 2 && strcasecmp($parts[0], 'INBOX') === 0);
                if ($topLevel && in_array(mb_strtolower($base), $names, true)) {
                    $folder['role'] = $role;
                    $found[$role] = true;
                    break;
                }
            }
            unset($folder);
        }

        return $folders;
    }

    public function select(string $folder): array
    {
        // Always opened anew: the receive job needs fresh counters.
        $this->selected = null;

        return $this->open($folder);
    }

    public function status(string $folder): array
    {
        return $this->client->status($folder);
    }

    public function uidsAfter(int $uid): array
    {
        // "n:*" also returns the highest UID when nothing is newer.
        return array_values(array_filter($this->client->uidSearch('UID '.($uid + 1).':*'), static fn($u) => $u > $uid));
    }

    public function uidsSince(int $timestamp): array
    {
        return $this->client->uidSearch('SINCE '.gmdate('j-M-Y', $timestamp));
    }

    public function uidsAfterIn(string $folder, int $uid): array
    {
        $this->open($folder);

        return $this->uidsAfter($uid);
    }

    public function uidsSinceIn(string $folder, int $timestamp): array
    {
        $this->open($folder);

        return $this->uidsSince($timestamp);
    }

    public function existingUids(string $folder, array $uids): array
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        $this->open($folder);
        $result = [];
        foreach (array_chunk($uids, self::CHUNK) as $chunk) {
            foreach ($this->client->uidSearch('UID '.ImapClient::uidSet($chunk)) as $uid) {
                $result[] = (int)$uid;
            }
        }
        // A range past the last letter answers with the highest UID: keep only the asked ones.
        $result = array_values(array_unique(array_intersect($result, $uids)));
        sort($result);

        return $result;
    }

    public function messageIds(string $folder, array $uids): array
    {
        $this->open($folder);
        $result = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $uids))), 200) as $chunk) {
            foreach ($this->client->fetchHeader($chunk, 'MESSAGE-ID') as $uid => $header) {
                if (preg_match('/<([^>]+)>/', $header, $m) || preg_match('/^Message-ID:\s*(\S+)/im', $header, $m)) {
                    $result[(int)$uid] = trim($m[1]);
                }
            }
        }

        return $result;
    }

    public function move(string $folder, array $uids, string $target): array
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        $existing = $this->existingUids($folder, $uids);
        $result = ['uidvalidity' => null, 'uids' => array_fill_keys($existing, null), 'missing' => array_values(array_diff($uids, $existing))];
        if (!$existing) {
            return $result;
        }
        // For servers that do not answer with COPYUID: the letters are found again by Message-ID.
        $sourceIds = $this->messageIds($folder, $existing);
        $before = $this->client->status($target);
        $result['uidvalidity'] = $before['uidvalidity'];

        $this->open($folder, true);
        foreach (array_chunk($existing, self::CHUNK) as $chunk) {
            $moved = $this->client->uidMove($chunk, $target);
            if ($moved['uidvalidity']) {
                $result['uidvalidity'] = $moved['uidvalidity'];
            }
            foreach ($moved['uids'] as $old => $new) {
                if (array_key_exists((int)$old, $result['uids'])) {
                    $result['uids'][(int)$old] = (int)$new;
                }
            }
        }

        $unknown = array_keys(array_filter($result['uids'], static fn($new) => $new === null));
        if ($unknown && $before['uidnext']) {
            $byId = [];
            foreach ($this->messageIds($target, $this->uidsAfterIn($target, (int)$before['uidnext'] - 1)) as $newUid => $messageId) {
                $byId[strtolower($messageId)] = (int)$newUid;
            }
            foreach ($unknown as $old) {
                $id = strtolower((string)($sourceIds[$old] ?? ''));
                if ($id !== '' && isset($byId[$id])) {
                    $result['uids'][$old] = $byId[$id];
                }
            }
        }

        return $result;
    }

    public function createFolder(string $name): void
    {
        foreach ($this->client->listFolders() as $folder) {
            if ($folder['name'] === $name) {
                return;
            }
        }
        $this->client->create($name);
    }

    public function supportsKeywords(string $folder): bool
    {
        $opened = $this->open($folder);

        return in_array('\\*', (array)($opened['permanentflags'] ?? []), true);
    }

    public function flags(string $folder, array $uids): array
    {
        $this->open($folder);
        $result = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $uids))), self::CHUNK) as $chunk) {
            $result += $this->client->fetchFlags($chunk);
        }

        return $result;
    }

    public function storeFlags(string $folder, array $uids, array $add, array $remove = []): void
    {
        $this->open($folder, true);
        foreach (array_chunk(array_values(array_unique(array_map('intval', $uids))), self::CHUNK) as $chunk) {
            if ($add) {
                $this->client->storeFlags($chunk, '+', $add);
            }
            if ($remove) {
                $this->client->storeFlags($chunk, '-', $remove);
            }
        }
    }

    public function fetch(array $uids): array
    {
        return $this->client->fetchRaw($uids);
    }

    public function hasMessageId(string $folder, string $messageId): bool
    {
        $this->open($folder);
        $id = '<'.trim($messageId, '<>').'>';

        try {
            return (bool)$this->client->uidSearch('HEADER Message-ID '.$this->quoted($id));
        } catch (ImapException $e) {
            // Some servers (Yandex) refuse header search: look at the newest letters instead.
            return $this->recentHasMessageId(trim($messageId, '<>'));
        }
    }

    private function recentHasMessageId(string $messageId, int $depth = 30): bool
    {
        $uids = $this->client->uidSearch('ALL');
        $uids = array_slice($uids, -$depth);
        foreach ($this->client->fetchHeader($uids, 'MESSAGE-ID') as $header) {
            if (preg_match('/<([^>]+)>/', $header, $m) && strcasecmp(trim($m[1]), $messageId) === 0) {
                return true;
            }
        }

        return false;
    }

    public function append(string $folder, string $raw): void
    {
        $this->client->append($folder, $raw);
    }

    public function close(): void
    {
        $this->client->logout();
    }

    /** A folder name as people read it (IMAP names are in modified UTF-7). */
    public static function decodeName(string $name): string
    {
        $decoded = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');

        return is_string($decoded) && $decoded !== '' ? $decoded : $name;
    }

    /** Opens the folder unless it is open already in a fitting mode. */
    private function open(string $folder, bool $writable = false): array
    {
        if ($this->selected === $folder && ($this->writable || !$writable)) {
            return $this->opened;
        }
        $this->opened = $writable ? $this->client->select($folder) : $this->client->examine($folder);
        $this->selected = $folder;
        $this->writable = $writable;

        return $this->opened;
    }

    private function quoted(string $value): string
    {
        $string = $this->client->string($value);

        return is_string($string) ? $string : '"'.addcslashes(preg_replace('/[^\x20-\x7e]/', '', $value), '"\\').'"';
    }
}
