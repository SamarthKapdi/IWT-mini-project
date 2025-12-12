## Smart Student Toolkit

Smart Student Toolkit is a single-file PHP full-stack lab that bundles authentication, dashboards, calculators, converters, and productivity widgets. It is optimized for lightweight hosting (InfinityFree-ready) and showcases Internet & Web Technology (IWT) concepts end-to-end.

### Live Demo

- **URL:** https://samarthkapdi.infinityfreeapp.com/?view=dashboard
- **Demo Accounts:**
	- `samarth` / `11111111`
	- `rohit` / `11111111`

### Features

- **Authentication & Sessions:** Signup/login with password hashing, session handling, and logout flow.
- **Dashboard & Stats:** Live counts for database rows, activity log, calculator history, plus a real-time clock widget.
- **Database Utilities:** Add "people" entries from the UI, auto-refreshing the latest rows.
- **Productivity Tools:**
	- Scientific-style calculator with expression history.
	- Client-side registration validator.
	- Event lab tracking clicks, double-clicks, context menus, and text input.
	- Hobby board (localStorage), weekday helper, and converters (currency, temperature, weight, length).
	- Task board with priorities, toggle, delete, and clear actions backed by localStorage.
- **Contributors Tab:** Quick LinkedIn links for Samarth Kapdi and Rohit Rajure.
- **Theme Toggle:** Sticky navbar with dark/light support and persistence.
- **Auto DB Bootstrapping:** Creates `users`, `people`, `activities`, and `calc_history` tables on first load using PDO.

### Tech Stack

- PHP 8+, PDO, MySQL
- HTML5/CSS3 (custom design, no framework)
- Vanilla JavaScript (ES6)

### Local Setup

1. Clone/download the repo into your PHP server root.
2. Ensure `MINI_PROJECT_IWT.php` is accessible via the server (it contains backend + frontend).
3. Update the `$cfg` credentials if running locally; leave as-is for the hosted demo.
4. Load the page—tables auto-create if absent.
5. Use the demo accounts or register new users.

### Deployment Tips

- Ideal for shared hosts (InfinityFree, 000webhost, etc.); no Composer or build steps required.
- Keep DB credentials private when publishing the repository.
- For other hosts, switch `$cfg` to environment-driven config if needed.

### Credits

- **Samarth Kapdi** – Lead Developer (https://www.linkedin.com/in/samarthkapdi, https://samarthkapdi.github.io/MyPortfolioWebsite/)
- **Rohit Rajure** – Co-Developer (https://www.linkedin.com/in/rohit-rajure-bb9508302, https://rohitrajure.github.io/Portfolio-Rohit-Rajure/)
