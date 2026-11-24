#Dual-layer validation (Zamove registration)

Setup (XAMPP): copy this folder to C:\xampp\htdocs\zamove, start Apache + MySQL,
import schema.sql in phpMyAdmin (creates the `zamove` database), then open
http://localhost/zamove/login.html and choose Register.

covers the registration parts only only. its not yet differentiating the different users and previlegs and differentiating int he db records

Config: api/config.php reads DB_HOST, DB_NAME, DB_USER, DB_PASS (defaults: localhost, zamove, root, empty).

Client layer : js/validation.js, js/register.js
Server layer : api/auth/register.php + api/lib/*.php (middleware, validator, sanitize, problem, db)
Bypass tests : tests/run_tests.sh http://localhost/zamove/api/auth/register.php   (needs curl; Git Bash on Windows)
Sample log   : evidence/curl_log.txt (run here with PHP's built-in server and SQLite)
