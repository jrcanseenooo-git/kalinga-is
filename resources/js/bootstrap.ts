import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// Laravel rotates its CSRF token when the session is regenerated. Axios reads
// the current XSRF-TOKEN cookie for each same-origin request; a header captured
// from the initial page would override that fresh token after Inertia sign-in.
