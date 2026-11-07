# Panel translations

One file per language: `de.php` (German), `es.php` (Spanish), `fr.php` (French), `ru.php` (Russian),
`pt.php` (Portuguese, Brazil). Each returns an array `English text => translation`.

- An account picks its language in **My Account**. Every language with a file here is offered there.
- `ui_language::translate()` looks the text up in the account's file first, exactly as written (case
  and punctuation count). A text that is not in the file is looked up in the database table
  `x_translations` (where an administrator may add translations), and otherwise stays English.
- A text the panel shows that no translation knows yet is added to `x_translations` (with empty
  translations), so `SELECT tr_en_tx FROM x_translations` lists what is still missing.

## Adding or changing a translation
- Keep placeholders exactly: `%s`, `%d`, `%1$d`, `%done%`, `{{username}}` and the like (their order may
  change). Keep `**bold**`, the address in `[text](./?module=name)` / `[text](https://...)`, HTML tags and
  entities such as `&amp;`, line breaks, and leading or trailing spaces.
- Product names (Apache, PHP, MySQL, Roundcube, SOGo, ...), protocols (DNS, FTP, SMTP, ...) and example
  addresses stay as they are.
- Tone: German "Sie", Spanish "usted", French "vous", Russian "вы", Brazilian Portuguese "você".

## Adding a language
Copy one file to `<two-letter code>.php` and translate the right-hand sides. It appears in My Account
on the next page load (add its name to the list in `modules/my_account/code/controller.ext.php` to show
it in its own language rather than as the code).
