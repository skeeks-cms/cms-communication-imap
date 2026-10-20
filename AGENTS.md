# CMS Communication IMAP development

Read README.md and `skeeks/cms-communication` AGENTS.md and ARCHITECTURE.md
before changing this package. Follow the active shared-vendor
skeeks-cms-package-development skill.

This package is a transport driver only: IMAP fetching, MIME parsing and SMTP
sending for `cms-communication` connections. Conversations, linking, access,
notifications and the activity feed belong to `cms-communication`.

Do not depend on PHP `ext-imap` (removed from PHP 8.4). The IMAP cursor
(folder, UIDVALIDITY, last UID) is committed together with received rows.
Never log or expose passwords, tokens or raw authentication responses.
