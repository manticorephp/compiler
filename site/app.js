document.documentElement.classList.add("js");
var d=document.documentElement,b=document.getElementById("theme"),m=["auto","light","dark"];
function cur(){return d.dataset.theme||"auto"}
function show(){b.title="Theme: "+cur()}
b.onclick=function(){var n=m[(m.indexOf(cur())+1)%3];
  if(n=="auto")delete d.dataset.theme;else d.dataset.theme=n;
  try{n=="auto"?localStorage.removeItem("theme"):localStorage.setItem("theme",n)}catch(e){}show()};
show();
document.querySelectorAll(".copy").forEach(function(c){c.onclick=function(){
  navigator.clipboard.writeText(c.previousElementSibling.innerText).then(function(){
    c.textContent="Copied";setTimeout(function(){c.textContent="Copy"},1400)})}});
var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add("in");io.unobserve(e.target)}})},{threshold:.15});
document.querySelectorAll(".rv").forEach(function(e){io.observe(e)});

if(window.hljs)document.querySelectorAll("pre code[class^=lang-]").forEach(function(e){if(hljs.getLanguage(e.className.slice(5)))hljs.highlightElement(e)});
var qs=document.querySelectorAll(".sq"),idx=null;
function load(q){if(idx||!q.dataset.src)return;idx=[];fetch(q.dataset.src).then(function(r){return r.json()}).then(function(j){idx=j;find(q)})}
function esc(s){return s.replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[c]})}
function find(q){var box=q.nextElementSibling,list=q.parentNode.querySelector(".sl"),t=q.value.toLowerCase().split(/\s+/).filter(Boolean);
  if(!t.length){box.innerHTML="";box.hidden=true;list.hidden=false;return}
  var r=[];idx.forEach(function(e){var h=e[0].toLowerCase(),p=e[1].toLowerCase(),x=(e[3]||"").toLowerCase(),s=0;
    for(var i=0;i<t.length;i++){var k=t[i];if(h.indexOf(k)==0)s+=4;else if(h.indexOf(k)>=0)s+=3;else if(p.indexOf(k)>=0)s+=1;else if(x.indexOf(k)>=0)s+=0.5;else return}
    r.push([s,e])});
  r.sort(function(a,b){return b[0]-a[0]});
  box.innerHTML=r.slice(0,12).map(function(x){return"<a href=\""+q.dataset.base+x[1][2]+"\"><b>"+esc(x[1][0])+"</b><span>"+esc(x[1][1])+"</span></a>"}).join("")||"<p class=\"none\">No results</p>";
  box.hidden=false;list.hidden=true}
qs.forEach(function(q){q.onfocus=function(){load(q)};q.oninput=function(){load(q);if(idx.length)find(q)};
  q.onkeydown=function(e){if(e.key=="Escape"){q.value="";find(q);q.blur()}else if(e.key=="Enter"){var a=q.nextElementSibling.querySelector("a");if(a)location.href=a.href}}});
document.addEventListener("keydown",function(e){if(e.key=="/"&&!/INPUT|TEXTAREA/.test(document.activeElement.tagName)){var q=document.querySelector(".side .sq");if(q&&q.offsetParent){e.preventDefault();q.focus()}}});
