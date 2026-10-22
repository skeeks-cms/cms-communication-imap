<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\imap;

/**
 * IMAP failure. The message is safe to show: it never contains credentials.
 */
class ImapException extends \RuntimeException
{
    /** The server's own explanation (after OK/NO/BAD). */
    public string $serverText = '';

    public function __construct(string $message, string $serverText = '')
    {
        parent::__construct($message);
        $this->serverText = $serverText;
    }
}
