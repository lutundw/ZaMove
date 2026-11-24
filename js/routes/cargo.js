/* LAB 9: route chunk 'cargo' (loaded with import() by js/app.js, only on the page that needs it). */
export default function({$,$$,K,html,raw,put,S,TRIPS,TOWNS,opts}){
const P={
cargo(){$('#rq').onsubmit=e=>{e.preventDefault();$('[data-tab=q]').click()};$$('.acc').forEach(b=>b.onclick=()=>$('[data-tab=s]').click())}
};
return P.cargo;
}
