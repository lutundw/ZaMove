/* LAB 9: route chunk 'login' (loaded with import() by js/app.js, only on the page that needs it). */
export default function({$,$$,K,html,raw,put,S,TRIPS,TOWNS,opts}){
const P={
login(){const r=new URLSearchParams(location.search).get('r');if(r)$('[data-tab=reg]').click();
$$('form:not(#reg-form):not(#login-form)').forEach(f=>f.onsubmit=e=>{e.preventDefault();const v=(new FormData(f).get('role')||'customer');location=v=='operator'?'operator.html':v=='admin'?'admin.html':'bookings.html'})}
};
return P.login;
}
