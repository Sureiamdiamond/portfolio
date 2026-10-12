# Portfolio

Plain PHP + SQLite portfolio with a small admin panel at `/admin`. No framework, no Composer.

## Setup on the host (once)

1. Create `config.php` in the site root from `config.sample.php`, with your admin password hash.
2. Make sure PHP is 8.1 or newer, and that `upload_max_filesize` allows the files you upload.
3. Log in at `/admin` to add projects, papers, presentations, education and experience, and upload the CV.

## Deploy

Every push to `main` uploads the code to `public_html` over FTP (see `.github/workflows/deploy.yml`).
The `data/` folder (SQLite database), `uploads/` and `config.php` are never overwritten by a deploy.

Required GitHub secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
