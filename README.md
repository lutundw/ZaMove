# Zamove frontend (demo)
Open index.html in a browser. Plain HTML, CSS and vanilla JS; no build step.
Flow: index > results > booking > checkout > ticket. Also: login (choose Passenger, Operator or Admin), bookings, cargo, operator, admin, 404.
Mock data and all page logic live in js/app.js. Replace these with fetch() calls to the PHP API:
- results: GET /api/schedules?origin&destination&date (TRIPS array)
- booking: POST /api/bookings (409 if seats taken; taken seats are hard-coded)
- checkout: POST /api/payments/checkout, then poll or receive a WebSocket status event
- login: POST /api/auth/login and /api/auth/register
- cargo: POST /api/cargo-requests, quotes from /api/cargo-requests/{id}/quotations
Booking state is kept in sessionStorage for the demo only. Server-side RBAC is still required.
