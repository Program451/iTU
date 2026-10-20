/* Local dictionaries only: no translation requests or user content leave the browser. */
(function(global){
 'use strict';
 let language='ru';try{language=localStorage.getItem('aulieata.lang')==='kk'?'kk':'ru';}catch{}
 const catalog=global.AULIEATA_KK||{},originals=new WeakMap(),originalTitle=document.title;
 const normalize=s=>s.replace(/\s+/g,' ').trim();
 function t(value){const s=String(value??'');if(language!=='kk')return s;const key=normalize(s),translated=catalog[key];if(translated!==undefined)return s.replace(s.trim(),translated);return s;}
 // Only source-code HTML fragments call this. Never pass assembled HTML or user data.
 function html(source){if(language!=='kk')return source;let result=source.replace(/(placeholder|title|aria-label|data-empty)=("|')([^"']*)\2/g,(all,attr,quote,value)=>attr+'='+quote+t(value).replace(/"/g,'&quot;').replace(/'/g,'&#39;')+quote);
  return result.replace(/(^|>)([^<>]*)(?=<|$)/g,(all,start,text)=>{if(!/[а-яё]/i.test(text)||/[=]/.test(text))return all;return start+t(text);});
 }
 function apply(root=document){root.querySelectorAll('[data-i18n]').forEach(el=>{let original=originals.get(el);if(original===undefined){original=el.textContent;originals.set(el,original);}el.textContent=t(original);});root.querySelectorAll('[data-i18n-placeholder],[data-i18n-title],[data-i18n-aria-label]').forEach(el=>{for(const attribute of ['placeholder','title','aria-label']){const key='data-i18n-'+attribute;if(el.hasAttribute(key)){if(!el.getAttribute(key))el.setAttribute(key,el.getAttribute(attribute)||'');el.setAttribute(attribute,t(el.getAttribute(key)));}}});document.documentElement.lang=language;document.title=t(originalTitle);}
 function set(value){language=value==='kk'?'kk':'ru';try{localStorage.setItem('aulieata.lang',language);}catch{}apply();document.title=t(originalTitle);document.dispatchEvent(new CustomEvent('aulieata:language',{detail:language}));}
 global.I18n={t,html,apply,set,get language(){return language;},catalog};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>apply());else apply();
})(window);
