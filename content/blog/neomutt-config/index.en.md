+++
title = 'Email for powerusers: neomutt, isync, msmtp & notmuch'
date = '2025-04-14'
lastmod = '2025-04-10'
draft = true
+++

Ever wanted a powerful yet minimalist solution for your email needs? Ever wanted
to have that in a TUI? *Of course you have!* And since I went through the pain
of figuring out how to do it, I intend to now share my arcane knowledge with
you, dear reader.

No seriously, in this guide I'll show you how to configure *neomutt, isync, msmtp
and notmuch* to interface with each other nicely. The result will be a setup
where you have all your mail downloaded locally, with a custom tagging system,
all in a single unified inbox for all your accounts.

![a screenshot of neomutt running on my machine](neomutt-screenshot.png)

## But first some theory

There are two basic protocols servicing internet mail: **SMTP** and **IMAP**. As
end users we utilize these to communicate with our *mailserver*, which receives
and holds our mail for us as well as making sure that whatever we send out gets
delivered.

IMAP, which stands for *"Internet Message Access Protocol"*, is what we use to
download our mail while SMTP, the *"Simple Mail Transfer Protocol"*, provides
the functionality of sending mail out to be delivered.

[Neomutt](https://neomutt.org/) is a **M**ail **U**ser **A**gent or a *MUA* for
short. The basic roles of a MUA are to help the user view, compose and manage
his mail. Neomutt also has a basic implementation of IMAP and SMTP, but it's way
better (and more UNIX-like) to leave those responsibilities to specialized
software. Hence we'll be using **isync** as our IMAP client and **msmtp** for
SMTP.

## isync

The [isync project](https://isync.sourceforge.io/) confusingly provides a
program called *mbsync*. Isync is our IMAP client software of choice which means
its role will be to synchronize our local mailbox with the remote server's and
vice versa.

The config file `$XDG_CONFIG_HOME/isyncrc` (unless you're weird that
translates to `~/.config/isyncrc`) is where you define all mailboxes you want
synced. For each mailbox you must specify an account, a remote store, a local
store and a channel. No worries − I'll explain what all of that means right away 😄

### Account

Pretty self explanatory. In this config section you define parameters of your
remote email account.

```py
IMAPAccount example
Host mail.example.com
User honza
Pass horsebatterystaple
TLSType IMAPS|STARTTLS # more likely IMAPS, but check with provider
CertificateFile /etc/ssl/certs/ca-certificates.crt # unless provider is weird
```

Instead of pasting your passwords into a config file, it would be better to use
the `PassCmd` directive to pass in an encrypted file. You could do it like this:

```sh
set +o history # temporarily disable shell history recording
printf 'horsebatterystaple' | gpg --recipient YOUR_KEYID --encrypt >passwd.gpg
set -o history
```

And in the config file replace `Pass ...` with

```
PassCmd "gpg --batch --no-tty -d ~/passwd.gpg 2>/dev/null"
```

This *might* bring its own set of challenges, depending on whether you have
`gpg-agent` configured, so maybe return to this step after you're done with the
initial setup to avoid frustration getting the better of you. It *might* also be
unnecessary if you encrypt your drives.

### Store(s)

A *store* defines a mailbox's location. As far as isync is concerned there are
two types of stores: *remote* and *local*.

```py
IMAPStore example-remote
Account example

MaildirStore example-local
Subfolders Verbatim
# The trailing "/" is important
Path /home/honza/mail/
Inbox /home/honza/mail/Inbox/
```

All we need to define a remote store is to give it a name and specify the
account to access it. With the local store we also define a storage path. The
`Subfolders Verbatim` directive says to mirror the directory structure on the
server when syncing.

### Channel

Finally a *channel* connects a remote and a local store.

```py
Channel example
Far :example-remote:
Near :example-local:
Patterns *
Create Both
Expunge Both
SyncState *
```

`Patterns *` commands to synchronize all mailboxes between the stores. You can
exclude some like so: `Patterns * !Drafts`

`{Create|Expunge} Both` specify to create or delete any mailbox whenever it is
created or deleted in one of the stores.

`SyncState *` tells isync to store mailbox state information inside the
mailboxes themselves instead of a special file in your `$HOME` directory.

### Wrapping up

That should be enough. Now try downloading your mail:

```
$ mbsync -a

Maildir notice: no UIDVALIDITY in /home/honza/mail/Inbox/, creating new.
Maildir notice: no UIDVALIDITY in /home/honza/mail/Sent, creating new.
Maildir notice: no UIDVALIDITY in /home/honza/mail/Spam, creating new.
Maildir notice: no UIDVALIDITY in /home/honza/mail/Trash, creating new.
Processed 4 box(es) in 1 channel(s),
pulled 1383 new message(s) and 0 flag update(s),
expunged 0 message(s) from near side,
pushed 0 new message(s) and 0 flag update(s),
expunged 0 message(s) from far side.
```

If all goes well you should see something like the above and below. If not...
well good luck and take [this](https://man.archlinux.org/man/mbsync.1.en) − it's
dangerous out there.

```
$ cd ~/mail
$ tree
.
├── Inbox
│   ├── cur
│   │   ├── 1737629102.8636_1.tp,U=15:2,S
│   │   ├── 1738666502.62865_5.tp,U=16:2,S
│   │   ├── 1739188368.47817_5.tp,U=17:2,S
│   │   └── 1739779927.5935_7.tp,U=21:2,S
│   ├── new
│   └── tmp
├── Sent
│   ├── cur
│   │   ├── 1738589122.R13569828200943344628.kam,U=11:2,S
│   │   └── 1739723599.R17470540795910965670.kam,U=17:2,S
│   ├── new
│   └── tmp
└── Trash
    ├── cur
    ├── new
    └── tmp
13 directories, 6 files
```

Now all that's left to do is set up a
[cron job](https://wiki.archlinux.org/title/Cron?&useskin=vector)
to automatically sync your mail every few minutes. In my crontab I have

```
# sync email every 5 minutes
*/5   *   *   *   *  mbsync -a
```

...well actually not really, but it's a useful little lie for you to believe
right now 😄

## Neomutt: a quick peek

I know you're all excited to read the mail you just downloaded with your new
MUA, so here's a little taste. Copy the following into your
`$XDG_CONFIG_HOME/neomutt/neomuttrc` and place [this](color.neomuttrc) in the
same directory. Be sure to read the explanatory comments and to consult the
[manpage](https://man.archlinux.org/man/neomutt.1.en) so that you understand
your configs.

```py
set folder = ~/mail # or wherever you decided to store your mail

# the '+' expands to the folder setting
# if your mailboxes are named differently, change these
set spool_file = "+Inbox" # "spool file" means "inbox"
set record     = "+Sent"
set postponed  = "+Drafts"
set trash      = "+Trash"
mailboxes =Inbox =Sent =Drafts =Trash

set sort             = "reverse-last-date"   # show newest at the top
set sidebar_visible  = yes
set sidebar_format   = "%D%?F? [%F]?%* %?N?%N/?%?S?%S?"
set sidebar_width    = 20
set date_format      = "%d/%m/%y %H:%M"
set index_format     = " %-25.25n %s %?M?(%M)? %*  %D"
set mail_check_stats = yes                   # autoupdate mailbox message counts

# colorscheme
source ./color.neomuttrc

# Sidebar
bind index,pager	\Ck	sidebar-prev # select previous mailbox
bind index,pager	\Cj	sidebar-next # select next mailbox
bind index,pager	\Co	sidebar-open # open selected mailbox
```

You should get an interface that looks a lot like the screenshot I placed at the
beggining of this article. More importantly you can use `j` and `k` to move
around, `<enter>` to display a message, `q` to quit and `ctrl-j`, `ctrl-k` and
`ctrl-o` to browse a different mailbox.

We're however limited to mapping our mailboxes to physical directories, which
means we can't really sort mail into topical categories. We will rectify this
later with *notmuch*.

## msmtp

Cool, so now you can read your mail, but how about sending some? *msmtp* is an
SMTP client which implements the classical
*[sendmail](https://wiki.archlinux.org/title/Sendmail?&useskin=vector)* API.
That means we can use it in conjuction with neomutt to compose and send emails.

The config file lives in `$XDG_CONFIG_HOME/msmtp/config`. Compared to what we've
done before, setting msmtp up is a breeze:

```sh
defaults
port 587 # check with mail service provider, but probably this
auth on
tls on
from_full_name "Your Name"

account example-acc         # msmtp internal account identifier
host example.com
from honza@example.com      # recipient will see this in the "From" field
user honza                  # mailserver username
password horsebatterystaple # mailserver password

# set "example-acc" as default
# used if no other account was specified via command flags
account default : example-acc
```

It would be optimal to replace the `password` directive with `passwordeval` so
that you can keep your passwords safe in encrypted files instead of pasting them
into your config. The setup to do that is the same as we've discussed with
isync.

And that's it! Now you can send mail from the shell:

```
printf "it's GNU/linux!\n" | msmtp -t torvalds@osdl.org
```

Telling neomutt to pass your outgoing mail to msmtp is as simple as adding the
following to your neomuttrc.

```go
set from = "youraddress@example.com"
set sendmail = "/usr/bin/msmtp"
```

Then simply enter neomutt, compose an email, send it and if everything went well
you should now have a fully functional email client!

## notmuch

...except that it's pretty crappy. Mailboxes are tethered to physical
directories, the search feature is extremely basic, the native solution for
multiaccount is hard to configure and a pain to use and so on and so forth.

*Notmuch* is a program which indexes your email and allows you to perform very
fast queries as well as tag your mail. You can search by content, sender, date,
tags and basically any other parameter you might want to think up. The great
thing for us neomutt users is, that neomutt has good notmuch integration.
