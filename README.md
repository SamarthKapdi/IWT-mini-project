Smart Student Toolkit
A single-file PHP full-stack lab that bundles user auth, dashboards, database inserts, calculators, converters, task boards, and more—designed for lightweight hosting (InfinityFree-ready) and quick demos of IWT concepts.

Live Demo
App URL: https://samarthkapdi.infinityfreeapp.com/?view=dashboard
Demo Accounts
samarth / 11111111
rohit / 11111111
Feature Highlights
Auth & Session Flow: Secure signup/login with password hashing, session tracking, and quick logout.
Dashboard & Stats: Live counters for database rows, activity logs, calculator history, plus a real-time clock widget.
Database Utilities: Insert “people” records directly from the UI; recent rows display instantly.
Productivity Tools:
Scientific-style calculator with saved history
Client-side registration validator
Event logging lab (click, double-click, context menu, input tracking)
Hobby board & weekday helper, backed by localStorage
Multi-purpose converters (currency, temperature, weight, length)
Task board with priorities, status toggles, and persistence
Contributors Section: Quick LinkedIn links for Samarth Kapdi and Rohit Rajure.
Theme Toggle: Sticky navbar with dark/light theme support and localStorage persistence.
Auto DB Bootstrapping: On first load the script creates required tables (users, people, activities, calc_history) using PDO.
Tech Stack
PHP 8+, PDO, MySQL
HTML5/CSS3 with custom styling (no external frameworks)
Vanilla JavaScript throughout (ES6)
Local Setup
Clone the repo and place files under your PHP server root.
Copy MINI_PROJECT_IWT.php as-is (it includes both backend and frontend).
Provide MySQL credentials via $cfg (already set for the hosted demo; adjust for local runs).
Load the page—tables auto-create if they don’t exist.
Use the demo login or create new accounts via the signup tab.
Deployment Notes
Built to run on shared hosts like InfinityFree; no Composer or extra build steps required.
Uses a single entry point for easy FTP uploads.
Remember to rotate DB credentials if you publish the repo publicly.
Credits
Samarth Kapdi – Lead Developer (https://samarthkapdi.github.io/MyPortfolioWebsite/)
Rohit Rajure – Co-Developer (https://rohitrajure.github.io/Portfolio-Rohit-Rajure/)
Let me know if you’d like this saved into README.md or tailored further (screenshots, badges, deployment instructions, etc.).
