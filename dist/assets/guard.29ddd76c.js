(function () {'use strict';if (!window.ZamoveApi) return;var tag = document.currentScript;var roles = ((tag && tag.getAttribute('data-roles')) || '').split(',').filter(Boolean);var HOME = { customer: 'bookings.html', operator: 'operator.html', admin: 'admin.html' };var root = document.documentElement;root.style.visibility = 'hidden';function logout() {ZamoveApi.request('DELETE', 'sessions/current', undefined, { noRedirect401: true })
.catch(function () { })
.then(function () { location.replace('login.html?loggedout=1'); });}
function addUserBox(user) {var who = document.createElement('span');who.className = 'who';who.textContent = user.full_name;var out = document.createElement('button');out.type = 'button';out.className = 'btn s l';out.textContent = 'Log out';out.addEventListener('click', logout);var nav = document.querySelector('.nav nav');if (nav) {Array.prototype.forEach.call(nav.querySelectorAll('a[href^="login.html"]'), function (a) { a.remove(); });nav.appendChild(who);nav.appendChild(out);return;}
var side = document.querySelector('.side');if (side) {var box = document.createElement('p');box.style.color = '#cfe5db';box.appendChild(who);box.appendChild(document.createElement('br'));box.appendChild(out);side.appendChild(box);}
}
ZamoveApi.request('GET', 'users/me')
.then(function (r) {var user = r.data;if (roles.length && roles.indexOf(user.role) === -1) {location.replace(HOME[user.role] || 'index.html');return;}
addUserBox(user);root.style.visibility = '';})
.catch(function (err) {if (err.status === 401) return;root.style.visibility = '';var b = document.createElement('p');b.className = 'alert bad';b.textContent = 'Could not check your session: ' + err.message;document.body.insertBefore(b, document.body.firstChild);});})();