# Accounts and recovery

TiffinDB has two roles. Admins manage people, projects, sharing, tables and fields, plugins, snapshots, and the activity log. Members use the projects shared with them. Accounts live in the system database, `system.sqlite`, in the data folder.

## Keep two Admins

Create a second Admin account as soon as TiffinDB is installed (People, add a person with the role Admin). If one Admin forgets their password, the other can set a temporary one in the browser. This is the simplest way back in, and the only one that needs neither file access nor a shell.

TiffinDB always keeps at least one active Admin: it refuses to deactivate the last one or make it a Member.

## A Member's or an Admin's forgotten password

An Admin opens People, chooses the person, and uses "Set temporary password". The person is signed out everywhere and chooses a new password at their next sign-in. Give them the temporary password yourself.

## Resetting a password with a shell

With shell access to the server (SSH), run from the TiffinDB folder:

```
php tools/admin/reset-password.php <username> [--data-dir=<path>]
```

for example `php tools/admin/reset-password.php example-admin`. The script finds the data folder as TiffinDB does (`data_dir` in `config/local.php`, then `../tiffindb-data`, then `data/`), or uses `--data-dir`. It reads the new password twice from standard input, without showing it when the terminal allows that, and applies the password rules (at least 10 characters, not the username). It then stores the password, with no change required at the next sign-in, ends every session of that person, clears the failed sign-in count for that username, and records the change in the activity log with no acting person. It works for any account; a deactivated account must still be reactivated by an Admin before it can sign in. Exit code 0 means success, 1 any problem, 2 that it was not run from the command line.

## Admin recovery without a shell

When no Admin can sign in and there is no shell, someone who can open the server's files with the host's file manager or an SFTP program can let you set a new Admin password. This works for active Admin accounts only. It can be turned off (see below).

The recovery pages are described here from their design; they are being built and have not been checked against the finished screens.

1. On the sign-in page, choose "Locked out of the Admin account?". This opens `/recover`, "Get back into an Admin account".
2. Under "1. Ask for a code", choose "Write a code". TiffinDB writes a file named `recovery-code.txt` into the data folder. The page says the same thing whether or not a file was written, and never shows the code or a path.
3. With the file manager or SFTP program, open `recovery-code.txt` in the data folder. It holds the line `TiffinDB Admin recovery code`, then the code (16 letters and digits, in groups of four), then the time it was made.
4. Back on the recovery page, under "2. Set a new password", type the Admin username, the code (dashes and spaces do not matter), and the new password twice. Choose "Change the password".
5. On success, TiffinDB sets the password (no change required at the next sign-in), ends every session of that account, clears its failed sign-in count, deletes `recovery-code.txt`, records the recovery in the activity log with no acting person, and sends you to the sign-in page. Sign in with the new password.

Rules:

- A code works once, for 30 minutes from when it was written. Asking again while a valid code exists does not replace it: use the code already in the file.
- Every failure gives the same message, whether the username is unknown, belongs to a Member or a deactivated account, or the code is wrong or expired. Password problems (too short, the same as the username, the two not equal) are reported as such.
- After 5 wrong attempts, TiffinDB deletes the code file; ask for a new code. Attempts and requests for a code are each limited: after 5, TiffinDB waits before accepting more, starting at 30 seconds and doubling up to 15 minutes. Failed attempts also count towards the sign-in limit of the visitor's address.
- If TiffinDB cannot delete the code file after a successful recovery, it writes a line to the server log; delete `recovery-code.txt` by hand. The code then stays usable until it expires.

### The limits are shared by every visitor

The attempt limit and the limit on asking for codes count every visitor together, not each address separately. Someone without access to the server's files can therefore use up the attempts (which also deletes the code file) and keep the limit reached, delaying your recovery for as long as they keep doing it. They cannot learn the code or change anything else. A limit per address would need TiffinDB to trust proxy headers that a visitor can forge, so it is not used.

If recovery keeps failing with "Too many tries":

- sign in with a second Admin account and set a temporary password (see above);
- or, with a shell, use `tools/admin/reset-password.php`;
- or wait: a limit starts again from zero when its last failure is more than 15 minutes old and it is not blocked.

### Turning recovery off

Set `admin_recovery` to `false` in `config/local.php` (see [Configuration](configuration.md#admin_recovery)). The link on the sign-in page is then not shown, and `/recover` is not found (404). Then keep a second Admin, or a shell, as your way back in.

Anyone who can read the data folder can already read every project and the system database, so this path gives a person with file access nothing they did not have. It gives a visitor without file access nothing but the delay described above.
