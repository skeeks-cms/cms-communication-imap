<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\imap;

use skeeks\cms\communication\mail\MailSessionInterface;

/**
 * A logged-in IMAP connection for the receive job.
 */
class ImapSession implements MailSessionInterface
{
    /** Sent folder names of servers that do not mark it with \Sent. */
    private const SENT_NAMES = ['sent', 'sent items', 'sent messages', 'sent mail', 'отправленные', '&BB4EQgQ,BEAEMAQyBDsENQQ9BD0ESwQ1-'];

    private ?string $selected = null;

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

    public function select(string $folder): array
    {
        $result = $this->client->examine($folder);
        $this->selected = $folder;

        return $result;
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

    public function fetch(array $uids): array
    {
        return $this->client->fetchRaw($uids);
    }

    public function hasMessageId(string $folder, string $messageId): bool
    {
        if ($this->selected !== $folder) {
            $this->select($folder);
        }
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

    private function quoted(string $value): string
    {
        $string = $this->client->string($value);

        return is_string($string) ? $string : '"'.addcslashes(preg_replace('/[^\x20-\x7e]/', '', $value), '"\\').'"';
    }
}
