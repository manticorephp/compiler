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
