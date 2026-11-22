# M7 screen design: Admin recovery

Status: design for milestone M7 part 1 (no mock-up; P27, P49; session plan section 3 row 6, default applied). Logic: `docs/specs/M7-release.md` section 9; ADR 0022 decision 5. Implemented by M7-WP5. Strings: `strings/en/recovery.php`, `strings/en/account.php` (WP5). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Recovery | `GET /recover` (`?started=1` after starting) | 200; 404 when the operator turned recovery off |
| Start | `POST /recover/start` | 303 to `/recover?started=1`, always |
| Change the password | `POST /recover` with `username`, `code`, `password`, `password_again` | 303 to `/sign-in?recovered=1`; 422; 429 |

Shell: plain shell (lockup header, centred footer), as the sign-in page. Signed-in people are sent away by the `guest` access level (as built).

## 2. Sign-in page (changed)

- Below the form: `p.help` with `a` `signin.recover` "Locked out of the Admin account?" to `/recover`. Absent when recovery is off.
- After recovery (`?recovered=1`): above the form, `notice` (information) `notice.recovered` "The password has been changed. Sign in with the new password."

## 3. Recovery

Page title and `h1`: `recovery.title` "Get back into an Admin account".

1. `p.lead` `recovery.lead` "If no Admin can sign in, someone who can open the files on the server can let you set a new Admin password. This works for Admin accounts only. Members: ask an Admin to set a new password for you."
2. `h2` `recovery.step1.title` "1. Ask for a code"; `p` `recovery.step1.body` "TiffinDB writes a one-time code into a file named recovery-code.txt in its data folder. Open that file with your host's file manager, an SFTP program, or a shell. The code works once, for 30 minutes."; `form` POST `/recover/start` (`csrf`) with `button.button` `recovery.start` "Write a code".
   After starting (`?started=1`): above the button, `notice` (information) `recovery.started` "If recovery is allowed on this server, a code is now in recovery-code.txt in the data folder. If a code was already waiting there, it is still the one to use." (the same text whether or not a file was written).
3. `h2` `recovery.step2.title` "2. Set a new password"; `form` POST `/recover` (`csrf`):
   - `username`: `label` `recovery.username` "Admin username"; `input type="text" autocomplete="username"`; the typed value kept after 422.
   - `code`: `label` `recovery.code` "Code from the file"; `p.help` `recovery.code_help` "16 letters and digits. Dashes and spaces do not matter."; `input type="text" autocomplete="off" spellcheck="false" autocapitalize="characters"`; never kept after 422.
   - `password`: `label` `recovery.password` "New password"; `p.help` the password rules (the help text the change-password page shows, reused by key, as built); `input type="password" autocomplete="new-password"`.
   - `password_again`: `label` `recovery.password_again` "New password again"; `input type="password" autocomplete="new-password"`.
   - The show-password toggle (as built, `show-password` script, optional).
   - `div.form-actions`: `button.button.button-primary.button-large` `recovery.submit` "Change the password"; `a.button.button-large` `recovery.back` "Back to sign in" to `/sign-in`.
4. `p.help` `recovery.other_ways` "Other ways back in: another Admin can set your password on the People page; with a shell, the host documentation describes a command that does it."

## 4. States and results

| State | Shown |
|---|---|
| Password problems (too short, the same as the username, the two not equal) | 422: error summary and field errors (as built keys); the username kept; the code and passwords empty |
| Code, username, or account not right (any cause) | 422: error summary with `problem.recovery.failed` "That did not work. Check the username and the code, or start again for a new code.", linked to the code field; identical page for every cause |
| Too many tries | 429 with `Retry-After`: the page with `notice` (warning) `recovery.wait` "Too many tries. Wait {time}, then try again." (`{time}` as the sign-in page words it, as built) |
| Done | 303 to the sign-in page with the notice of section 2 |

No state says whether the username exists, is an Admin, or whether a code file was written. The code is never shown.

## 5. Keyboard and screen reader

Tab order: "Write a code", the four fields, "Show passwords", "Change the password", "Back to sign in". The two steps are headed, so a screen reader can move between them. Errors are tied to their fields; the summary is announced first (as built). Without script the passwords are not shown, which is the only difference.

## 6. Narrow layout

As the sign-in page: one column, full-width fields and buttons; no sideways scroll at 320 px Largest.
