import{s as nt,f as Q,g as rt,n as ot,i as U,h as qe,j as st,k as $,m as Ue,p as ae,q as it,w as ct,u as at,l as lt}from"./index.CjtK8fPy.js";const yn=(e,t)=>{const n=e.__vccOpts||e;for(const[r,o]of t)n[r]=o;return n};/*!
  * vue-router v4.2.5
  * (c) 2023 Eduardo San Martin Morote
  * @license MIT
  */const q=typeof window<"u";function ut(e){return e.__esModule||e[Symbol.toStringTag]==="Module"}const S=Object.assign;function le(e,t){const n={};for(const r in t){const o=t[r];n[r]=M(o)?o.map(e):e(o)}return n}const F=()=>{},M=Array.isArray,ft=/\/$/,ht=e=>e.replace(ft,"");function ue(e,t,n="/"){let r,o={},a="",h="";const p=t.indexOf("#");let c=t.indexOf("?");return p<c&&p>=0&&(c=-1),c>-1&&(r=t.slice(0,c),a=t.slice(c+1,p>-1?p:t.length),o=e(a)),p>-1&&(r=r||t.slice(0,p),h=t.slice(p,t.length)),r=gt(r??t,n),{fullPath:r+(a&&"?")+a+h,path:r,query:o,hash:h}}function pt(e,t){const n=t.query?e(t.query):"";return t.path+(n&&"?")+n+(t.hash||"")}function Se(e,t){return!t||!e.toLowerCase().startsWith(t.toLowerCase())?e:e.slice(t.length)||"/"}function dt(e,t,n){const r=t.matched.length-1,o=n.matched.length-1;return r>-1&&r===o&&z(t.matched[r],n.matched[o])&&ze(t.params,n.params)&&e(t.query)===e(n.query)&&t.hash===n.hash}function z(e,t){return(e.aliasOf||e)===(t.aliasOf||t)}function ze(e,t){if(Object.keys(e).length!==Object.keys(t).length)return!1;for(const n in e)if(!mt(e[n],t[n]))return!1;return!0}function mt(e,t){return M(e)?ke(e,t):M(t)?ke(t,e):e===t}function ke(e,t){return M(t)?e.length===t.length&&e.every((n,r)=>n===t[r]):e.length===1&&e[0]===t}function gt(e,t){if(e.startsWith("/"))return e;if(!e)return t;const n=t.split("/"),r=e.split("/"),o=r[r.length-1];(o===".."||o===".")&&r.push("");let a=n.length-1,h,p;for(h=0;h<r.length;h++)if(p=r[h],p!==".")if(p==="..")a>1&&a--;else break;return n.slice(0,a).join("/")+"/"+r.slice(h-(h===r.length?1:0)).join("/")}var X;(function(e){e.pop="pop",e.push="push"})(X||(X={}));var Y;(function(e){e.back="back",e.forward="forward",e.unknown=""})(Y||(Y={}));function vt(e){if(!e)if(q){const t=document.querySelector("base");e=t&&t.getAttribute("href")||"/",e=e.replace(/^\w+:\/\/[^\/]+/,"")}else e="/";return e[0]!=="/"&&e[0]!=="#"&&(e="/"+e),ht(e)}const yt=/^[^#]+#/;function Rt(e,t){return e.replace(yt,"#")+t}function Et(e,t){const n=document.documentElement.getBoundingClientRect(),r=e.getBoundingClientRect();return{behavior:t.behavior,left:r.left-n.left-(t.left||0),top:r.top-n.top-(t.top||0)}}const te=()=>({left:window.pageXOffset,top:window.pageYOffset});function wt(e){let t;if("el"in e){const n=e.el,r=typeof n=="string"&&n.startsWith("#"),o=typeof n=="string"?r?document.getElementById(n.slice(1)):document.querySelector(n):n;if(!o)return;t=Et(o,e)}else t=e;"scrollBehavior"in document.documentElement.style?window.scrollTo(t):window.scrollTo(t.left!=null?t.left:window.pageXOffset,t.top!=null?t.top:window.pageYOffset)}function _e(e,t){return(history.state?history.state.position-t:-1)+e}const he=new Map;function Pt(e,t){he.set(e,t)}function St(e){const t=he.get(e);return he.delete(e),t}let kt=()=>location.protocol+"//"+location.host;function Ge(e,t){const{pathname:n,search:r,hash:o}=t,a=e.indexOf("#");if(a>-1){let p=o.includes(e.slice(a))?e.slice(a).length:1,c=o.slice(p);return c[0]!=="/"&&(c="/"+c),Se(c,"")}return Se(n,e)+r+o}function _t(e,t,n,r){let o=[],a=[],h=null;const p=({state:f})=>{const m=Ge(e,location),y=n.value,_=t.value;let b=0;if(f){if(n.value=m,t.value=f,h&&h===y){h=null;return}b=_?f.position-_.position:0}else r(m);o.forEach(E=>{E(n.value,y,{delta:b,type:X.pop,direction:b?b>0?Y.forward:Y.back:Y.unknown})})};function c(){h=n.value}function l(f){o.push(f);const m=()=>{const y=o.indexOf(f);y>-1&&o.splice(y,1)};return a.push(m),m}function s(){const{history:f}=window;f.state&&f.replaceState(S({},f.state,{scroll:te()}),"")}function u(){for(const f of a)f();a=[],window.removeEventListener("popstate",p),window.removeEventListener("beforeunload",s)}return window.addEventListener("popstate",p),window.addEventListener("beforeunload",s,{passive:!0}),{pauseListeners:c,listen:l,destroy:u}}function be(e,t,n,r=!1,o=!1){return{back:e,current:t,forward:n,replaced:r,position:window.history.length,scroll:o?te():null}}function bt(e){const{history:t,location:n}=window,r={value:Ge(e,n)},o={value:t.state};o.value||a(r.value,{back:null,current:r.value,forward:null,position:t.length-1,replaced:!0,scroll:null},!0);function a(c,l,s){const u=e.indexOf("#"),f=u>-1?(n.host&&document.querySelector("base")?e:e.slice(u))+c:kt()+e+c;try{t[s?"replaceState":"pushState"](l,"",f),o.value=l}catch(m){console.error(m),n[s?"replace":"assign"](f)}}function h(c,l){const s=S({},t.state,be(o.value.back,c,o.value.forward,!0),l,{position:o.value.position});a(c,s,!0),r.value=c}function p(c,l){const s=S({},o.value,t.state,{forward:c,scroll:te()});a(s.current,s,!0);const u=S({},be(r.value,c,null),{position:s.position+1},l);a(c,u,!1),r.value=c}return{location:r,state:o,push:p,replace:h}}function Ct(e){e=vt(e);const t=bt(e),n=_t(e,t.state,t.location,t.replace);function r(a,h=!0){h||n.pauseListeners(),history.go(a)}const o=S({location:"",base:e,go:r,createHref:Rt.bind(null,e)},t,n);return Object.defineProperty(o,"location",{enumerable:!0,get:()=>t.location.value}),Object.defineProperty(o,"state",{enumerable:!0,get:()=>t.state.value}),o}function At(e){return e=location.host?e||location.pathname+location.search:"",e.includes("#")||(e+="#"),Ct(e)}function Ot(e){return typeof e=="string"||e&&typeof e=="object"}function Ke(e){return typeof e=="string"||typeof e=="symbol"}const T={path:"/",name:void 0,params:{},query:{},hash:"",fullPath:"/",matched:[],meta:{},redirectedFrom:void 0},Ve=Symbol("");var Ce;(function(e){e[e.aborted=4]="aborted",e[e.cancelled=8]="cancelled",e[e.duplicated=16]="duplicated"})(Ce||(Ce={}));function G(e,t){return S(new Error,{type:e,[Ve]:!0},t)}function N(e,t){return e instanceof Error&&Ve in e&&(t==null||!!(e.type&t))}const Ae="[^/]+?",xt={sensitive:!1,strict:!1,start:!0,end:!0},Lt=/[.+*?^${}()[\]/\\]/g;function Mt(e,t){const n=S({},xt,t),r=[];let o=n.start?"^":"";const a=[];for(const l of e){const s=l.length?[]:[90];n.strict&&!l.length&&(o+="/");for(let u=0;u<l.length;u++){const f=l[u];let m=40+(n.sensitive?.25:0);if(f.type===0)u||(o+="/"),o+=f.value.replace(Lt,"\\$&"),m+=40;else if(f.type===1){const{value:y,repeatable:_,optional:b,regexp:E}=f;a.push({name:y,repeatable:_,optional:b});const P=E||Ae;if(P!==Ae){m+=10;try{new RegExp(`(${P})`)}catch(L){throw new Error(`Invalid custom RegExp for param "${y}" (${P}): `+L.message)}}let O=_?`((?:${P})(?:/(?:${P}))*)`:`(${P})`;u||(O=b&&l.length<2?`(?:/${O})`:"/"+O),b&&(O+="?"),o+=O,m+=20,b&&(m+=-8),_&&(m+=-20),P===".*"&&(m+=-50)}s.push(m)}r.push(s)}if(n.strict&&n.end){const l=r.length-1;r[l][r[l].length-1]+=.7000000000000001}n.strict||(o+="/?"),n.end?o+="$":n.strict&&(o+="(?:/|$)");const h=new RegExp(o,n.sensitive?"":"i");function p(l){const s=l.match(h),u={};if(!s)return null;for(let f=1;f<s.length;f++){const m=s[f]||"",y=a[f-1];u[y.name]=m&&y.repeatable?m.split("/"):m}return u}function c(l){let s="",u=!1;for(const f of e){(!u||!s.endsWith("/"))&&(s+="/"),u=!1;for(const m of f)if(m.type===0)s+=m.value;else if(m.type===1){const{value:y,repeatable:_,optional:b}=m,E=y in l?l[y]:"";if(M(E)&&!_)throw new Error(`Provided param "${y}" is an array but it is not repeatable (* or + modifiers)`);const P=M(E)?E.join("/"):E;if(!P)if(b)f.length<2&&(s.endsWith("/")?s=s.slice(0,-1):u=!0);else throw new Error(`Missing required param "${y}"`);s+=P}}return s||"/"}return{re:h,score:r,keys:a,parse:p,stringify:c}}function $t(e,t){let n=0;for(;n<e.length&&n<t.length;){const r=t[n]-e[n];if(r)return r;n++}return e.length<t.length?e.length===1&&e[0]===80?-1:1:e.length>t.length?t.length===1&&t[0]===80?1:-1:0}function Bt(e,t){let n=0;const r=e.score,o=t.score;for(;n<r.length&&n<o.length;){const a=$t(r[n],o[n]);if(a)return a;n++}if(Math.abs(o.length-r.length)===1){if(Oe(r))return 1;if(Oe(o))return-1}return o.length-r.length}function Oe(e){const t=e[e.length-1];return e.length>0&&t[t.length-1]<0}const Nt={type:0,value:""},Tt=/[a-zA-Z0-9_]/;function jt(e){if(!e)return[[]];if(e==="/")return[[Nt]];if(!e.startsWith("/"))throw new Error(`Invalid path "${e}"`);function t(m){throw new Error(`ERR (${n})/"${l}": ${m}`)}let n=0,r=n;const o=[];let a;function h(){a&&o.push(a),a=[]}let p=0,c,l="",s="";function u(){l&&(n===0?a.push({type:0,value:l}):n===1||n===2||n===3?(a.length>1&&(c==="*"||c==="+")&&t(`A repeatable param (${l}) must be alone in its segment. eg: '/:ids+.`),a.push({type:1,value:l,regexp:s,repeatable:c==="*"||c==="+",optional:c==="*"||c==="?"})):t("Invalid state to consume buffer"),l="")}function f(){l+=c}for(;p<e.length;){if(c=e[p++],c==="\\"&&n!==2){r=n,n=4;continue}switch(n){case 0:c==="/"?(l&&u(),h()):c===":"?(u(),n=1):f();break;case 4:f(),n=r;break;case 1:c==="("?n=2:Tt.test(c)?f():(u(),n=0,c!=="*"&&c!=="?"&&c!=="+"&&p--);break;case 2:c===")"?s[s.length-1]=="\\"?s=s.slice(0,-1)+c:n=3:s+=c;break;case 3:u(),n=0,c!=="*"&&c!=="?"&&c!=="+"&&p--,s="";break;default:t("Unknown state");break}}return n===2&&t(`Unfinished custom RegExp for param "${l}"`),u(),h(),o}function It(e,t,n){const r=Mt(jt(e.path),n),o=S(r,{record:e,parent:t,children:[],alias:[]});return t&&!o.record.aliasOf==!t.record.aliasOf&&t.children.push(o),o}function Ht(e,t){const n=[],r=new Map;t=Me({strict:!1,end:!0,sensitive:!1},t);function o(s){return r.get(s)}function a(s,u,f){const m=!f,y=qt(s);y.aliasOf=f&&f.record;const _=Me(t,s),b=[y];if("alias"in s){const O=typeof s.alias=="string"?[s.alias]:s.alias;for(const L of O)b.push(S({},y,{components:f?f.record.components:y.components,path:L,aliasOf:f?f.record:y}))}let E,P;for(const O of b){const{path:L}=O;if(u&&L[0]!=="/"){const I=u.record.path,B=I[I.length-1]==="/"?"":"/";O.path=u.record.path+(L&&B+L)}if(E=It(O,u,_),f?f.alias.push(E):(P=P||E,P!==E&&P.alias.push(E),m&&s.name&&!Le(E)&&h(s.name)),y.children){const I=y.children;for(let B=0;B<I.length;B++)a(I[B],E,f&&f.children[B])}f=f||E,(E.record.components&&Object.keys(E.record.components).length||E.record.name||E.record.redirect)&&c(E)}return P?()=>{h(P)}:F}function h(s){if(Ke(s)){const u=r.get(s);u&&(r.delete(s),n.splice(n.indexOf(u),1),u.children.forEach(h),u.alias.forEach(h))}else{const u=n.indexOf(s);u>-1&&(n.splice(u,1),s.record.name&&r.delete(s.record.name),s.children.forEach(h),s.alias.forEach(h))}}function p(){return n}function c(s){let u=0;for(;u<n.length&&Bt(s,n[u])>=0&&(s.record.path!==n[u].record.path||!De(s,n[u]));)u++;n.splice(u,0,s),s.record.name&&!Le(s)&&r.set(s.record.name,s)}function l(s,u){let f,m={},y,_;if("name"in s&&s.name){if(f=r.get(s.name),!f)throw G(1,{location:s});_=f.record.name,m=S(xe(u.params,f.keys.filter(P=>!P.optional).map(P=>P.name)),s.params&&xe(s.params,f.keys.map(P=>P.name))),y=f.stringify(m)}else if("path"in s)y=s.path,f=n.find(P=>P.re.test(y)),f&&(m=f.parse(y),_=f.record.name);else{if(f=u.name?r.get(u.name):n.find(P=>P.re.test(u.path)),!f)throw G(1,{location:s,currentLocation:u});_=f.record.name,m=S({},u.params,s.params),y=f.stringify(m)}const b=[];let E=f;for(;E;)b.unshift(E.record),E=E.parent;return{name:_,path:y,params:m,matched:b,meta:zt(b)}}return e.forEach(s=>a(s)),{addRoute:a,resolve:l,removeRoute:h,getRoutes:p,getRecordMatcher:o}}function xe(e,t){const n={};for(const r of t)r in e&&(n[r]=e[r]);return n}function qt(e){return{path:e.path,redirect:e.redirect,name:e.name,meta:e.meta||{},aliasOf:void 0,beforeEnter:e.beforeEnter,props:Ut(e),children:e.children||[],instances:{},leaveGuards:new Set,updateGuards:new Set,enterCallbacks:{},components:"components"in e?e.components||null:e.component&&{default:e.component}}}function Ut(e){const t={},n=e.props||!1;if("component"in e)t.default=n;else for(const r in e.components)t[r]=typeof n=="object"?n[r]:n;return t}function Le(e){for(;e;){if(e.record.aliasOf)return!0;e=e.parent}return!1}function zt(e){return e.reduce((t,n)=>S(t,n.meta),{})}function Me(e,t){const n={};for(const r in e)n[r]=r in t?t[r]:e[r];return n}function De(e,t){return t.children.some(n=>n===e||De(e,n))}const We=/#/g,Gt=/&/g,Kt=/\//g,Vt=/=/g,Dt=/\?/g,Qe=/\+/g,Wt=/%5B/g,Qt=/%5D/g,Fe=/%5E/g,Ft=/%60/g,Ye=/%7B/g,Yt=/%7C/g,Xe=/%7D/g,Xt=/%20/g;function me(e){return encodeURI(""+e).replace(Yt,"|").replace(Wt,"[").replace(Qt,"]")}function Zt(e){return me(e).replace(Ye,"{").replace(Xe,"}").replace(Fe,"^")}function pe(e){return me(e).replace(Qe,"%2B").replace(Xt,"+").replace(We,"%23").replace(Gt,"%26").replace(Ft,"`").replace(Ye,"{").replace(Xe,"}").replace(Fe,"^")}function Jt(e){return pe(e).replace(Vt,"%3D")}function en(e){return me(e).replace(We,"%23").replace(Dt,"%3F")}function tn(e){return e==null?"":en(e).replace(Kt,"%2F")}function ee(e){try{return decodeURIComponent(""+e)}catch{}return""+e}function nn(e){const t={};if(e===""||e==="?")return t;const r=(e[0]==="?"?e.slice(1):e).split("&");for(let o=0;o<r.length;++o){const a=r[o].replace(Qe," "),h=a.indexOf("="),p=ee(h<0?a:a.slice(0,h)),c=h<0?null:ee(a.slice(h+1));if(p in t){let l=t[p];M(l)||(l=t[p]=[l]),l.push(c)}else t[p]=c}return t}function $e(e){let t="";for(let n in e){const r=e[n];if(n=Jt(n),r==null){r!==void 0&&(t+=(t.length?"&":"")+n);continue}(M(r)?r.map(a=>a&&pe(a)):[r&&pe(r)]).forEach(a=>{a!==void 0&&(t+=(t.length?"&":"")+n,a!=null&&(t+="="+a))})}return t}function rn(e){const t={};for(const n in e){const r=e[n];r!==void 0&&(t[n]=M(r)?r.map(o=>o==null?null:""+o):r==null?r:""+r)}return t}const on=Symbol(""),Be=Symbol(""),ne=Symbol(""),Ze=Symbol(""),de=Symbol("");function W(){let e=[];function t(r){return e.push(r),()=>{const o=e.indexOf(r);o>-1&&e.splice(o,1)}}function n(){e=[]}return{add:t,list:()=>e.slice(),reset:n}}function j(e,t,n,r,o){const a=r&&(r.enterCallbacks[o]=r.enterCallbacks[o]||[]);return()=>new Promise((h,p)=>{const c=u=>{u===!1?p(G(4,{from:n,to:t})):u instanceof Error?p(u):Ot(u)?p(G(2,{from:t,to:u})):(a&&r.enterCallbacks[o]===a&&typeof u=="function"&&a.push(u),h())},l=e.call(r&&r.instances[o],t,n,c);let s=Promise.resolve(l);e.length<3&&(s=s.then(c)),s.catch(u=>p(u))})}function fe(e,t,n,r){const o=[];for(const a of e)for(const h in a.components){let p=a.components[h];if(!(t!=="beforeRouteEnter"&&!a.instances[h]))if(sn(p)){const l=(p.__vccOpts||p)[t];l&&o.push(j(l,n,r,a,h))}else{let c=p();o.push(()=>c.then(l=>{if(!l)return Promise.reject(new Error(`Couldn't resolve component "${h}" at "${a.path}"`));const s=ut(l)?l.default:l;a.components[h]=s;const f=(s.__vccOpts||s)[t];return f&&j(f,n,r,a,h)()}))}}return o}function sn(e){return typeof e=="object"||"displayName"in e||"props"in e||"__vccOpts"in e}function Ne(e){const t=U(ne),n=U(Ze),r=$(()=>t.resolve(Q(e.to))),o=$(()=>{const{matched:c}=r.value,{length:l}=c,s=c[l-1],u=n.matched;if(!s||!u.length)return-1;const f=u.findIndex(z.bind(null,s));if(f>-1)return f;const m=Te(c[l-2]);return l>1&&Te(s)===m&&u[u.length-1].path!==m?u.findIndex(z.bind(null,c[l-2])):f}),a=$(()=>o.value>-1&&un(n.params,r.value.params)),h=$(()=>o.value>-1&&o.value===n.matched.length-1&&ze(n.params,r.value.params));function p(c={}){return ln(c)?t[Q(e.replace)?"replace":"push"](Q(e.to)).catch(F):Promise.resolve()}return{route:r,href:$(()=>r.value.href),isActive:a,isExactActive:h,navigate:p}}const cn=qe({name:"RouterLink",compatConfig:{MODE:3},props:{to:{type:[String,Object],required:!0},replace:Boolean,activeClass:String,exactActiveClass:String,custom:Boolean,ariaCurrentValue:{type:String,default:"page"}},useLink:Ne,setup(e,{slots:t}){const n=st(Ne(e)),{options:r}=U(ne),o=$(()=>({[je(e.activeClass,r.linkActiveClass,"router-link-active")]:n.isActive,[je(e.exactActiveClass,r.linkExactActiveClass,"router-link-exact-active")]:n.isExactActive}));return()=>{const a=t.default&&t.default(n);return e.custom?a:Ue("a",{"aria-current":n.isExactActive?e.ariaCurrentValue:null,href:n.href,onClick:n.navigate,class:o.value},a)}}}),an=cn;function ln(e){if(!(e.metaKey||e.altKey||e.ctrlKey||e.shiftKey)&&!e.defaultPrevented&&!(e.button!==void 0&&e.button!==0)){if(e.currentTarget&&e.currentTarget.getAttribute){const t=e.currentTarget.getAttribute("target");if(/\b_blank\b/i.test(t))return}return e.preventDefault&&e.preventDefault(),!0}}function un(e,t){for(const n in t){const r=t[n],o=e[n];if(typeof r=="string"){if(r!==o)return!1}else if(!M(o)||o.length!==r.length||r.some((a,h)=>a!==o[h]))return!1}return!0}function Te(e){return e?e.aliasOf?e.aliasOf.path:e.path:""}const je=(e,t,n)=>e??t??n,fn=qe({name:"RouterView",inheritAttrs:!1,props:{name:{type:String,default:"default"},route:Object},compatConfig:{MODE:3},setup(e,{attrs:t,slots:n}){const r=U(de),o=$(()=>e.route||r.value),a=U(Be,0),h=$(()=>{let l=Q(a);const{matched:s}=o.value;let u;for(;(u=s[l])&&!u.components;)l++;return l}),p=$(()=>o.value.matched[h.value]);ae(Be,$(()=>h.value+1)),ae(on,p),ae(de,o);const c=it();return ct(()=>[c.value,p.value,e.name],([l,s,u],[f,m,y])=>{s&&(s.instances[u]=l,m&&m!==s&&l&&l===f&&(s.leaveGuards.size||(s.leaveGuards=m.leaveGuards),s.updateGuards.size||(s.updateGuards=m.updateGuards))),l&&s&&(!m||!z(s,m)||!f)&&(s.enterCallbacks[u]||[]).forEach(_=>_(l))},{flush:"post"}),()=>{const l=o.value,s=e.name,u=p.value,f=u&&u.components[s];if(!f)return Ie(n.default,{Component:f,route:l});const m=u.props[s],y=m?m===!0?l.params:typeof m=="function"?m(l):m:null,b=Ue(f,S({},y,t,{onVnodeUnmounted:E=>{E.component.isUnmounted&&(u.instances[s]=null)},ref:c}));return Ie(n.default,{Component:b,route:l})||b}}});function Ie(e,t){if(!e)return null;const n=e(t);return n.length===1?n[0]:n}const hn=fn;function pn(e){const t=Ht(e.routes,e),n=e.parseQuery||nn,r=e.stringifyQuery||$e,o=e.history,a=W(),h=W(),p=W(),c=nt(T);let l=T;q&&e.scrollBehavior&&"scrollRestoration"in history&&(history.scrollRestoration="manual");const s=le.bind(null,i=>""+i),u=le.bind(null,tn),f=le.bind(null,ee);function m(i,g){let d,v;return Ke(i)?(d=t.getRecordMatcher(i),v=g):v=i,t.addRoute(v,d)}function y(i){const g=t.getRecordMatcher(i);g&&t.removeRoute(g)}function _(){return t.getRoutes().map(i=>i.record)}function b(i){return!!t.getRecordMatcher(i)}function E(i,g){if(g=S({},g||c.value),typeof i=="string"){const R=ue(n,i,g.path),A=t.resolve({path:R.path},g),D=o.createHref(R.fullPath);return S(R,A,{params:f(A.params),hash:ee(R.hash),redirectedFrom:void 0,href:D})}let d;if("path"in i)d=S({},i,{path:ue(n,i.path,g.path).path});else{const R=S({},i.params);for(const A in R)R[A]==null&&delete R[A];d=S({},i,{params:u(R)}),g.params=u(g.params)}const v=t.resolve(d,g),k=i.hash||"";v.params=s(f(v.params));const C=pt(r,S({},i,{hash:Zt(k),path:v.path})),w=o.createHref(C);return S({fullPath:C,hash:k,query:r===$e?rn(i.query):i.query||{}},v,{redirectedFrom:void 0,href:w})}function P(i){return typeof i=="string"?ue(n,i,c.value.path):S({},i)}function O(i,g){if(l!==i)return G(8,{from:g,to:i})}function L(i){return K(i)}function I(i){return L(S(P(i),{replace:!0}))}function B(i){const g=i.matched[i.matched.length-1];if(g&&g.redirect){const{redirect:d}=g;let v=typeof d=="function"?d(i):d;return typeof v=="string"&&(v=v.includes("?")||v.includes("#")?v=P(v):{path:v},v.params={}),S({query:i.query,hash:i.hash,params:"path"in v?{}:i.params},v)}}function K(i,g){const d=l=E(i),v=c.value,k=i.state,C=i.force,w=i.replace===!0,R=B(d);if(R)return K(S(P(R),{state:typeof R=="object"?S({},k,R.state):k,force:C,replace:w}),g||d);const A=d;A.redirectedFrom=g;let D;return!C&&dt(r,v,d)&&(D=G(16,{to:A,from:v}),we(v,v,!0,!1)),(D?Promise.resolve(D):ve(A,v)).catch(x=>N(x)?N(x,2)?x:se(x):oe(x,A,v)).then(x=>{if(x){if(N(x,2))return K(S({replace:w},P(x.to),{state:typeof x.to=="object"?S({},k,x.to.state):k,force:C}),g||A)}else x=Re(A,v,!0,w,k);return ye(A,v,x),x})}function Je(i,g){const d=O(i,g);return d?Promise.reject(d):Promise.resolve()}function ge(i){const g=J.values().next().value;return g&&typeof g.runWithContext=="function"?g.runWithContext(i):i()}function ve(i,g){let d;const[v,k,C]=dn(i,g);d=fe(v.reverse(),"beforeRouteLeave",i,g);for(const R of v)R.leaveGuards.forEach(A=>{d.push(j(A,i,g))});const w=Je.bind(null,i,g);return d.push(w),H(d).then(()=>{d=[];for(const R of a.list())d.push(j(R,i,g));return d.push(w),H(d)}).then(()=>{d=fe(k,"beforeRouteUpdate",i,g);for(const R of k)R.updateGuards.forEach(A=>{d.push(j(A,i,g))});return d.push(w),H(d)}).then(()=>{d=[];for(const R of C)if(R.beforeEnter)if(M(R.beforeEnter))for(const A of R.beforeEnter)d.push(j(A,i,g));else d.push(j(R.beforeEnter,i,g));return d.push(w),H(d)}).then(()=>(i.matched.forEach(R=>R.enterCallbacks={}),d=fe(C,"beforeRouteEnter",i,g),d.push(w),H(d))).then(()=>{d=[];for(const R of h.list())d.push(j(R,i,g));return d.push(w),H(d)}).catch(R=>N(R,8)?R:Promise.reject(R))}function ye(i,g,d){p.list().forEach(v=>ge(()=>v(i,g,d)))}function Re(i,g,d,v,k){const C=O(i,g);if(C)return C;const w=g===T,R=q?history.state:{};d&&(v||w?o.replace(i.fullPath,S({scroll:w&&R&&R.scroll},k)):o.push(i.fullPath,k)),c.value=i,we(i,g,d,w),se()}let V;function et(){V||(V=o.listen((i,g,d)=>{if(!Pe.listening)return;const v=E(i),k=B(v);if(k){K(S(k,{replace:!0}),v).catch(F);return}l=v;const C=c.value;q&&Pt(_e(C.fullPath,d.delta),te()),ve(v,C).catch(w=>N(w,12)?w:N(w,2)?(K(w.to,v).then(R=>{N(R,20)&&!d.delta&&d.type===X.pop&&o.go(-1,!1)}).catch(F),Promise.reject()):(d.delta&&o.go(-d.delta,!1),oe(w,v,C))).then(w=>{w=w||Re(v,C,!1),w&&(d.delta&&!N(w,8)?o.go(-d.delta,!1):d.type===X.pop&&N(w,20)&&o.go(-1,!1)),ye(v,C,w)}).catch(F)}))}let re=W(),Ee=W(),Z;function oe(i,g,d){se(i);const v=Ee.list();return v.length?v.forEach(k=>k(i,g,d)):console.error(i),Promise.reject(i)}function tt(){return Z&&c.value!==T?Promise.resolve():new Promise((i,g)=>{re.add([i,g])})}function se(i){return Z||(Z=!i,et(),re.list().forEach(([g,d])=>i?d(i):g()),re.reset()),i}function we(i,g,d,v){const{scrollBehavior:k}=e;if(!q||!k)return Promise.resolve();const C=!d&&St(_e(i.fullPath,0))||(v||!d)&&history.state&&history.state.scroll||null;return ot().then(()=>k(i,g,C)).then(w=>w&&wt(w)).catch(w=>oe(w,i,g))}const ie=i=>o.go(i);let ce;const J=new Set,Pe={currentRoute:c,listening:!0,addRoute:m,removeRoute:y,hasRoute:b,getRoutes:_,resolve:E,options:e,push:L,replace:I,go:ie,back:()=>ie(-1),forward:()=>ie(1),beforeEach:a.add,beforeResolve:h.add,afterEach:p.add,onError:Ee.add,isReady:tt,install(i){const g=this;i.component("RouterLink",an),i.component("RouterView",hn),i.config.globalProperties.$router=g,Object.defineProperty(i.config.globalProperties,"$route",{enumerable:!0,get:()=>Q(c)}),q&&!ce&&c.value===T&&(ce=!0,L(o.location).catch(k=>{}));const d={};for(const k in T)Object.defineProperty(d,k,{get:()=>c.value[k],enumerable:!0});i.provide(ne,g),i.provide(Ze,rt(d)),i.provide(de,c);const v=i.unmount;J.add(i),i.unmount=function(){J.delete(i),J.size<1&&(l=T,V&&V(),V=null,c.value=T,ce=!1,Z=!1),v()}}};function H(i){return i.reduce((g,d)=>g.then(()=>ge(d)),Promise.resolve())}return Pe}function dn(e,t){const n=[],r=[],o=[],a=Math.max(t.matched.length,e.matched.length);for(let h=0;h<a;h++){const p=t.matched[h];p&&(e.matched.find(l=>z(l,p))?r.push(p):n.push(p));const c=e.matched[h];c&&(t.matched.find(l=>z(l,c))||o.push(c))}return[n,r,o]}function Rn(){return U(ne)}const En=(e,t)=>{const n=pn({history:At(`wp-admin/admin.php?page=broken-link-checker-${window.aioseoBrokenLinkChecker.page}`),routes:e,scrollBehavior(r,o,a){return a||(r.hash?{selector:r.hash}:{left:0,top:0})}});return n.beforeEach(async(r,o,a)=>(at().loaded||lt(t),a())),n},mn="modulepreload",gn=function(e,t){return new URL(e,t).href},He={},wn=function(t,n,r){let o=Promise.resolve();if(n&&n.length>0){const h=document.getElementsByTagName("link"),p=document.querySelector("meta[property=csp-nonce]"),c=(p==null?void 0:p.nonce)||(p==null?void 0:p.getAttribute("nonce"));o=Promise.allSettled(n.map(l=>{if(l=gn(l,r),l in He)return;He[l]=!0;const s=l.endsWith(".css"),u=s?'[rel="stylesheet"]':"";if(!!r)for(let y=h.length-1;y>=0;y--){const _=h[y];if(_.href===l&&(!s||_.rel==="stylesheet"))return}else if(document.querySelector(`link[href="${l}"]${u}`))return;const m=document.createElement("link");if(m.rel=s?"stylesheet":mn,s||(m.as="script"),m.crossOrigin="",m.href=l,c&&m.setAttribute("nonce",c),document.head.appendChild(m),s)return new Promise((y,_)=>{m.addEventListener("load",y),m.addEventListener("error",()=>_(new Error(`Unable to preload CSS for ${l}`)))})}))}function a(h){const p=new Event("vite:preloadError",{cancelable:!0});if(p.payload=h,window.dispatchEvent(p),!p.defaultPrevented)throw h}return o.then(h=>{for(const p of h||[])p.status==="rejected"&&a(p.reason);return t().catch(a)})},Pn=(e,t,n)=>{const r=e[t];return r?typeof r=="function"?r():Promise.resolve(r):new Promise((o,a)=>{(typeof queueMicrotask=="function"?queueMicrotask:setTimeout)(a.bind(null,new Error("Unknown variable dynamic import: "+t+(t.split("/").length!==n?". Note that variables only represent file names one level deep.":""))))})};export{yn as _,Pn as a,wn as b,En as s,Rn as u};
