# IWT Mini Project (Single File)

A single-file PHP mini project for Internet & Web Technology labs. Includes:

- User auth (signup/login/logout) backed by MySQL (PDO)
- Dashboard with syllabus snapshot and quick links
- Advanced calculator with converters and per-user saved history
- Form validator, DB insert demo, activity log, event lab, hobby board, weekday helper
- Dark/light theme toggle

## Setup

1. Create a MySQL database and user (defaults are in the file: db `iwt_suite`, user `samarth`, pass `Sam@123`).
2. Place `MINI_PROJECT_IWT.php` in your web root (e.g., `htdocs`).
3. Start Apache/MySQL and open the file via your local server (e.g., `http://localhost/MINI_PROJECT_IWT.php`).

> Update the `$cfg` array at the top of the file if your DB credentials differ.

## Credits

- Built by Samarth Kapdi
- Contributor: Rohit Rajure
